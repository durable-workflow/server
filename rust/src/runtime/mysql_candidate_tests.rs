//! Preserve candidate identity/order and require index-ordered status ranges.
use super::store::{id, now};
use serde_json::{Value, json};
use sqlx::AssertSqlSafe;

fn ordered_ranges(plan: &Value) -> bool {
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
                    found.push(fields.get("key").and_then(Value::as_str) == Some("workflow_tasks_dispatch_order_index")
                        && fields.get("used_key_parts").and_then(Value::as_array)
                            .is_some_and(|parts| ["queue", "status"].iter()
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
    ranges.len() == 2 && ranges.iter().all(|range| {
        let mut access = Vec::new();
        task_access(range, &mut access);
        access == [true] && !sorted(range)
    })
}

#[test]
fn the_plan_guard_rejects_a_queue_sort_even_when_another_range_uses_the_index() {
    let range = json!({"query_block":{"table":{"table_name":"t","key":"workflow_tasks_dispatch_order_index",
        "used_key_parts":["queue","status"]}}});
    let valid = json!({"query_block":{"ordering_operation":{"using_filesort":true,
        "union_result":{"query_specifications":[range,range]}}}});
    assert!(ordered_ranges(&valid), "the outer merge sorts two bounded pages");
    for failure in ["mysql-sort", "mariadb-sort", "wrong-key", "missing-status"] {
        let mut plan = valid.clone();
        let first = &mut plan["query_block"]["ordering_operation"]["union_result"]["query_specifications"][0]["query_block"];
        match failure {
            "mysql-sort" => first["ordering_operation"] = json!({"using_filesort":true}),
            "mariadb-sort" => first["filesort"] = json!({"sort_key":"t.priority,t.available_at,t.id"}),
            "wrong-key" => first["table"]["key"] = json!("dw_workflow_tasks_poll"),
            "missing-status" => first["table"]["used_key_parts"] = json!(["queue"]),
            _ => unreachable!(),
        }
        assert!(!ordered_ranges(&plan), "{failure}");
    }
    assert!(!ordered_ranges(&json!({"query_block":{}})), "unknown plans refuse");
}

store_methods! {
    pub(super) async fn qualify_mysql_candidate_ranges(&self) {
        use crate::codec::{Value as Payload, ValueCodec};
        assert_eq!(DB::NAME, "MySQL");
        let input = json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap()});
        let mut runs = Vec::new();
        for (workflow, build) in [("range-open",None),("range-build",Some("build")),("range-closed",None)] {
            let started = self.start(json!({"workflow_id":workflow,"workflow_type":"bounds","task_queue":"bounds","input":input}),
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
                    .bind(if index % 19 == 0 {"elsewhere"} else {"bounds"}).bind(kind)
                    .bind(if index % 4 == 0 {"leased"} else {"ready"}).bind(if index == 24 {-10} else {(index % 3) as i64})
                    .bind(DB::optional_time(available)).bind(DB::optional_time(lease)).bind(build)
                    .execute(&mut *transaction).await.unwrap();
            }
        }
        transaction.commit().await.unwrap();
        let predecessor = super::store::task_candidates_sql!("FROM workflow_tasks t FORCE INDEX (dw_workflow_tasks_poll) STRAIGHT_JOIN workflow_runs r");
        for statistics in ["before", "after"] {
            if statistics == "after" {
                Self::query("ANALYZE TABLE workflow_tasks,workflow_runs").execute(&self.pool).await.unwrap();
            }
            for kind in ["workflow", "activity"] {
                for build in [None,Some("build"),Some("other")] {
                    let old = Self::query(predecessor).bind(self.namespace.as_ref()).bind("bounds").bind(kind)
                        .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(build).fetch_all(&self.pool).await.unwrap();
                    let new = self.task_candidate_query("bounds", kind, build, at, at).fetch_all(&self.pool).await.unwrap();
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
                        explain = explain.bind(self.namespace.as_ref()).bind("bounds").bind(kind)
                            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(build);
                    }
                    let row = explain.fetch_one(&self.pool).await.unwrap();
                    let plan: Value = serde_json::from_str(&DB::string(&row,"EXPLAIN").unwrap()).unwrap();
                    println!("mysql_status_candidate_plan statistics={statistics} kind={kind} build={build:?}: {plan}");
                    assert!(ordered_ranges(&plan), "each status range must arrive in dispatch order: {plan}");
                }
            }
        }
    }
}
