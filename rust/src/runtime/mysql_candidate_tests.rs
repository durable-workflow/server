//! Preserve candidate identity/order and require index-ordered status ranges.
use super::store::{id, now};
use serde_json::{Value, json};
use sqlx::AssertSqlSafe;

fn empty_range_message(range: &Value) -> Option<&str> {
    let block = range.get("query_block")?.as_object()?;
    if block.keys().all(|key| matches!(key.as_str(), "select_id" | "message")) {
        return block.get("message")?.as_str();
    }
    // MariaDB wraps a pruned UNION branch's message in an empty table node.
    if block.keys().any(|key| !matches!(key.as_str(), "select_id" | "operation" | "table"))
        || block.get("operation")?.as_str()? != "UNION"
    { return None; }
    let table = block.get("table")?.as_object()?;
    if table.len() != 1 { return None; }
    table.get("message")?.as_str()
}

fn ordered_ranges(plan: &Value, activity: bool) -> bool {
    fn branches<'a>(value: &'a Value, found: &mut Vec<&'a Value>) {
        match value {
            Value::Object(fields) => {
                for (key, child) in fields {
                    if key == "query_specifications" {
                        if let Some(items) = child.as_array() { found.extend(items); }
                    }
                    branches(child, found);
                }
            }
            Value::Array(items) => for item in items { branches(item, found); },
            _ => {}
        }
    }
    fn sorted(value: &Value) -> bool {
        match value {
            Value::Object(fields) => fields.iter().any(|(key, child)| key == "filesort"
                || key == "using_filesort" && child == &Value::Bool(true) || sorted(child)),
            Value::Array(items) => items.iter().any(sorted),
            _ => false,
        }
    }
    fn task_access(value: &Value, found: &mut Vec<bool>) {
        match value {
            Value::Object(fields) => {
                if fields.get("table_name").and_then(Value::as_str) == Some("t") {
                    found.push(fields.get("key").and_then(Value::as_str) == Some("dw_workflow_tasks_scoped_dispatch")
                        && fields.get("used_key_parts").and_then(Value::as_array)
                            .is_some_and(|parts| ["namespace", "queue", "task_type", "status"].iter()
                                .all(|part| parts.iter().any(|value| value.as_str() == Some(*part)))));
                }
                for child in fields.values() { task_access(child, found); }
            }
            Value::Array(items) => for item in items { task_access(item, found); },
            _ => {}
        }
    }
    let mut ranges = Vec::new();
    branches(plan, &mut ranges);
    let mut scans = 0;
    let ordered = ranges.len() == 2 && ranges.iter().enumerate().all(|(position, range)| {
        let mut access = Vec::new();
        task_access(range, &mut access);
        // An activity cannot reclaim a leased workflow. MySQL eliminates
        // that second branch as Impossible WHERE without visiting a table.
        let message = empty_range_message(range);
        let pruned = access.is_empty()
            && (message == Some("no matching row in const table")
                || activity && position == 1 && message == Some("Impossible WHERE"));
        if access == [true] { scans += 1; }
        (access == [true] || pruned) && !sorted(range)
    });
    ordered && scans > 0
}

#[test]
fn the_plan_guard_rejects_a_queue_sort_even_when_another_range_uses_the_index() {
    let range = json!({"query_block":{"table":{"table_name":"t","key":"dw_workflow_tasks_scoped_dispatch",
        "used_key_parts":["namespace","queue","task_type","status"]}}});
    let valid = json!({"query_block":{"ordering_operation":{"using_filesort":true,
        "union_result":{"query_specifications":[range,range]}}}});
    assert!(ordered_ranges(&valid, false), "the outer merge sorts two bounded pages");
    let mut pruned = valid.clone();
    pruned["query_block"]["ordering_operation"]["union_result"]["query_specifications"][1] = json!({"query_block":{"message":"Impossible WHERE"}});
    assert!(ordered_ranges(&pruned, true));
    assert!(!ordered_ranges(&pruned, false));
    pruned["query_block"]["ordering_operation"]["union_result"]["query_specifications"][1]["query_block"]["message"] = json!("no matching row in const table");
    assert!(ordered_ranges(&pruned, false));
    let mut extra = pruned.clone();
    extra["query_block"]["ordering_operation"]["union_result"]["query_specifications"][1]["query_block"]["nested_loop"] = json!([]);
    assert!(!ordered_ranges(&extra, false));
    let mut maria = valid.clone();
    maria["query_block"]["ordering_operation"]["union_result"]["query_specifications"][1] = json!({"query_block":{"select_id":3,"operation":"UNION","table":{"message":"Impossible WHERE"}}});
    assert!(ordered_ranges(&maria, true));
    assert!(!ordered_ranges(&maria, false));
    maria["query_block"]["ordering_operation"]["union_result"]["query_specifications"][1]["query_block"]["table"]["key"] = json!("PRIMARY");
    assert!(!ordered_ranges(&maria, true));
    pruned["query_block"]["ordering_operation"]["union_result"]["query_specifications"][1]["query_block"]["message"] = json!("unknown");
    assert!(!ordered_ranges(&pruned, true));
    for failure in ["mysql-sort", "mariadb-sort", "wrong-key", "missing-namespace", "missing-kind", "missing-status"] {
        let mut plan = valid.clone();
        let first = &mut plan["query_block"]["ordering_operation"]["union_result"]["query_specifications"][0]["query_block"];
        match failure {
            "mysql-sort" => first["ordering_operation"] = json!({"using_filesort":true}),
            "mariadb-sort" => first["filesort"] = json!({"sort_key":"t.priority,t.available_at,t.id"}),
            "wrong-key" => first["table"]["key"] = json!("dw_workflow_tasks_poll"),
            "missing-namespace" => first["table"]["used_key_parts"] = json!(["queue","task_type","status"]),
            "missing-kind" => first["table"]["used_key_parts"] = json!(["namespace","queue","status"]),
            "missing-status" => first["table"]["used_key_parts"] = json!(["namespace", "queue", "task_type"]),
            _ => unreachable!(),
        }
        assert!(!ordered_ranges(&plan, false), "{failure}");
    }
    assert!(!ordered_ranges(&json!({"query_block":{}}), false), "unknown plans refuse");
}

store_methods! {
    async fn mysql_candidate_read_next(connection: &mut DB::Connection) -> u64 {
        let row = Self::query("SHOW SESSION STATUS LIKE 'Handler_read_next'")
            .fetch_one(connection).await.unwrap();
        DB::string(&row, "Value").unwrap().parse().unwrap()
    }

    async fn mysql_candidate_scope_work(&self, queue: &str, at: chrono::DateTime<chrono::Utc>) {
        let mut connection = self.pool.acquire().await.unwrap();
        let before = Self::mysql_candidate_read_next(&mut connection).await;
        let expected = self.task_candidate_query(queue,"workflow",None,at,at)
            .fetch_all(&mut *connection).await.unwrap();
        let bounded = Self::mysql_candidate_read_next(&mut connection).await - before;
        // Reproduce the rejected cross-tenant index choice with the same
        // query/filter/page shape and the corrected positional parameters.
        let sql = DB::statement(DB::TASK_CANDIDATES_SQL).replace("FORCE INDEX (dw_workflow_tasks_scoped_dispatch)",
            "FORCE INDEX (workflow_tasks_dispatch_order_index)");
        let mut old = sqlx::query::<DB>(AssertSqlSafe(sql));
        for _ in 0..2 {
            old = old.bind(self.namespace.as_ref()).bind(queue).bind("workflow")
                .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(None::<&str>);
        }
        let before = Self::mysql_candidate_read_next(&mut connection).await;
        let actual = old.fetch_all(&mut *connection).await.unwrap();
        let cross_tenant = Self::mysql_candidate_read_next(&mut connection).await - before;
        let identities = |rows: &[DB::Row]| rows.iter().map(|row| DB::string(row,"id").unwrap()).collect::<Vec<_>>();
        assert_eq!(identities(&actual),identities(&expected));
        assert!(bounded <= 840, "the new range must exclude foreign namespace/kind/queue rows: {bounded}");
        assert!(cross_tenant > bounded + 2000, "the rejected index must expose the foreign prefix: old={cross_tenant}, new={bounded}");
        println!("mysql_candidate_scope_work old_cross_tenant_next={cross_tenant} new_scoped_next={bounded}");
    }

    pub(super) async fn qualify_mysql_candidate_ranges(&self) {
        let namespace = format!("{}-owned", "n".repeat(80));
        self.in_namespace(&namespace).mysql_candidate_ranges().await;
    }

    async fn mysql_candidate_ranges(&self) {
        use crate::codec::{Value as Payload, ValueCodec};
        assert_eq!(DB::NAME, "MySQL");
        let input = json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap()});
        let queue = format!("{}-owned", "q".repeat(80));
        let mut runs = Vec::new();
        for (workflow, build) in [("range-open",None),("range-build",Some("build")),("range-closed",None)] {
            let started = self.start(json!({"workflow_id":workflow,"workflow_type":"bounds","task_queue":queue,"input":input}),
                Default::default(), None, None).await.unwrap();
            let run = started["run_id"].as_str().unwrap().to_owned();
            Self::query("UPDATE workflow_runs SET compatibility=$1,status=$2 WHERE id=$3")
                .bind(build).bind(if workflow == "range-closed" {"completed"} else {"running"}).bind(&run)
                .execute(&self.pool).await.unwrap();
            runs.push(run);
        }
        // Keep the start records outside this query's queue.
        for run in &runs {
            Self::query("UPDATE workflow_tasks SET queue='initial-outside' WHERE workflow_run_id=$1")
                .bind(run).execute(&self.pool).await.unwrap();
        }
        let at = now();
        let past = at - chrono::TimeDelta::seconds(30);
        let future = at + chrono::TimeDelta::seconds(30);
        let mut transaction = self.begin().await.unwrap();
        for kind in ["workflow", "activity"] {
            for index in 0..420 {
                let available = if index % 5 == 0 {None} else {Some(if index % 11 == 0 {future} else {past})};
                let lease = if index % 16 == 0 {None} else {Some(if index % 8 == 0 {past} else {future})};
                let build = match index % 7 { 0 => Some("other"), 1 => Some("build"), 2 => Some(""), _ => None };
                Self::query("INSERT INTO workflow_tasks(id,workflow_run_id,namespace,queue,task_type,status,priority,available_at,lease_expires_at,compatibility) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)")
                    .bind(id()).bind(&runs[index % runs.len()]).bind(self.namespace.as_ref())
                    .bind(if index % 19 == 0 {"elsewhere"} else {queue.as_str()}).bind(kind)
                    .bind(if index % 4 == 0 {"leased"} else {"ready"}).bind(if index == 24 {1} else {5 + (index % 3) as i64})
                    .bind(DB::optional_time(available)).bind(DB::optional_time(lease)).bind(build)
                    .execute(&mut *transaction).await.unwrap();
            }
        }
        let foreign_namespace = format!("{}-foreign", "n".repeat(80));
        let foreign_queue = format!("{}-foreign", "q".repeat(80));
        // All these rows sort ahead of the target population in the old
        // queue/status index. They must not enter the target equality range.
        for index in 0..6000 {
            Self::query("INSERT INTO workflow_tasks(id,workflow_run_id,namespace,queue,task_type,status,priority,available_at,lease_expires_at) VALUES ($1,$2,$3,$4,$5,$6,0,$7,$8)")
                .bind(id()).bind(&runs[0])
                .bind(if index < 2400 {foreign_namespace.as_str()} else {self.namespace.as_ref()})
                .bind(if index >= 4800 {foreign_queue.as_str()} else {queue.as_str()})
                .bind(if (2400..4800).contains(&index) {"activity"} else {"workflow"})
                .bind(if index < 2400 && index % 2 == 0 {"leased"} else {"ready"})
                .bind(DB::optional_time(None)).bind(DB::bind_time(past))
                .execute(&mut *transaction).await.unwrap();
        }
        transaction.commit().await.unwrap();
        let predecessor = super::store::task_candidates_sql!("FROM workflow_tasks t FORCE INDEX (dw_workflow_tasks_poll) STRAIGHT_JOIN workflow_runs r");
        for statistics in ["before", "after"] {
            if statistics == "after" {
                Self::query("ANALYZE TABLE workflow_tasks,workflow_runs").execute(&self.pool).await.unwrap();
            }
            self.mysql_candidate_scope_work(&queue, at).await;
            for kind in ["workflow", "activity"] {
                for build in [None,Some("build"),Some("other")] {
                    let old = Self::query(predecessor).bind(self.namespace.as_ref()).bind(&queue).bind(kind)
                        .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(build).fetch_all(&self.pool).await.unwrap();
                    let new = self.task_candidate_query(&queue, kind, build, at, at).fetch_all(&self.pool).await.unwrap();
                    let identities = |rows: &[DB::Row]| rows.iter().map(|row|
                        (DB::string(row,"id").unwrap(),DB::string(row,"workflow_type").unwrap())).collect::<Vec<_>>();
                    assert!(!old.is_empty());
                    assert_eq!(identities(&new), identities(&old), "{statistics} / {kind} / {build:?}");
                    assert!(new.len() <= 100);
                    if kind == "activity" {
                        assert!(new.iter().all(|row| DB::string(row,"status").unwrap() == "ready"));
                    } else if build.is_none() {
                        assert!(new.iter().any(|row| DB::string(row,"status").unwrap() == "leased"));
                    }
                    let mut explain = sqlx::query::<DB>(AssertSqlSafe(format!("EXPLAIN FORMAT=JSON {}",DB::statement(DB::TASK_CANDIDATES_SQL))));
                    for _ in 0..2 {
                        explain = explain.bind(self.namespace.as_ref()).bind(&queue).bind(kind)
                            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(build);
                    }
                    let row = explain.fetch_one(&self.pool).await.unwrap();
                    let plan: Value = serde_json::from_str(&DB::string(&row,"EXPLAIN").unwrap()).unwrap();
                    println!("mysql_status_candidate_plan statistics={statistics} kind={kind} build={build:?}: {plan}");
                    assert!(ordered_ranges(&plan, kind == "activity"), "each status range must arrive in dispatch order: {plan}");
                }
            }
        }
    }
}
