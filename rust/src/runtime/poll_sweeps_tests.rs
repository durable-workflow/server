//! Real SQL and transition controls shared by all supported storage suites.
use super::{backend::Backend, store::{Store, id, now}, poll_sweeps::{Config, Sweeps}};
use serde_json::json;
use sqlx::AssertSqlSafe;
use std::{collections::BTreeMap, sync::Arc, time::{Duration, Instant}};

fn status_index(line: &str) -> bool {
    ["dw_workflow_tasks_poll", "workflow_tasks_namespace_queue_status_idx",
        "workflow_tasks_fairness_class_index", "workflow_tasks_dispatch_order_index",
        "workflow_tasks_status_available_at_index"].iter().any(|name| line.contains(name))
}

fn postgres_live_status_seek(plan: &[String]) -> bool {
    let mut found = false;
    for (index, line) in plan.iter().enumerate() {
        if line.contains("Index Scan") && (line.contains("workflow_tasks_") || line.contains("dw_workflow_tasks_")) {
            if !status_index(line) || !plan.get(index + 1).is_some_and(|next| next.contains("Index Cond:") && next.contains("status")) {
                return false;
            }
            found = true;
        }
    }
    found && !plan.iter().any(|line| line.contains("Seq Scan on workflow_tasks") || line.contains("Index Only Scan using workflow_tasks_workflow_run_id_index"))
}

fn mysql_live_status_seek(plan: &serde_json::Value) -> bool {
    if let Some(table) = plan.get("table") {
        let key = table["key"].as_str();
        let parts = table["used_key_parts"].as_array();
        if matches!(table["table_name"].as_str(), Some("t" | "workflow_tasks"))
            && key.is_some_and(status_index)
            && parts.is_some_and(|parts| parts.iter().any(|value| value.as_str() == Some("status")))
        { return true; }
    }
    match plan {
        serde_json::Value::Object(fields) => fields.values().any(mysql_live_status_seek),
        serde_json::Value::Array(values) => values.iter().any(mysql_live_status_seek),
        _ => false,
    }
}

fn direct_tables(plan: &serde_json::Value, tables: &mut Vec<String>) {
    match plan {
        serde_json::Value::Object(fields) => {
            if let Some(name) = fields.get("table_name").and_then(serde_json::Value::as_str) {
                tables.push(name.to_owned());
            }
            for (key, value) in fields {
                // A derived table has already applied its branch limits.
                if key != "materialized" && key != "materialized_from_subquery" { direct_tables(value, tables); }
            }
        }
        serde_json::Value::Array(values) => { for value in values { direct_tables(value, tables); } }
        _ => {}
    }
}

fn mysql_sorts_only_bounded_union(plan: &serde_json::Value) -> bool {
    match plan {
        serde_json::Value::Object(fields) => fields.iter().all(|(key, value)| {
            if key == "filesort" || (key == "ordering_operation" && value["using_filesort"] == true) {
                let mut tables = Vec::new();
                direct_tables(value, &mut tables);
                if tables.is_empty() || tables.iter().any(|name| !name.starts_with("<derived")) { return false; }
            }
            mysql_sorts_only_bounded_union(value)
        }),
        serde_json::Value::Array(values) => values.iter().all(mysql_sorts_only_bounded_union),
        _ => true,
    }
}

store_methods! {
    async fn poll_work_count(&self, table: &'static str) -> i64 {
        Self::scalar(table).fetch_one(&self.pool).await.unwrap()
    }

    async fn poll_work_family_count(&self, workflow: bool) -> usize {
        let prefix = if workflow { "dw-native-workflow-poll:" } else { "dw-native-query-poll:" };
        let rows = Self::query(DB::QUERY_CACHE_PAGE).bind("").fetch_all(&self.pool).await.unwrap();
        rows.iter().filter(|row| DB::string(row, "key").unwrap().starts_with(prefix)).count()
    }

    async fn poll_work_seed(&self) -> String {
        let run = self.start(json!({"workflow_id":format!("poll-work-{}",id()), "workflow_type":"bounds",
            "task_queue":"bounds", "input":{"codec":"avro","blob":"AA=="}}), BTreeMap::new(), None, None).await.unwrap();
        let task = Self::query("SELECT id FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow'")
            .bind(run["run_id"].as_str().unwrap()).fetch_one(&self.pool).await.unwrap();
        DB::string(&task, "id").unwrap()
    }

    async fn poll_work_receipt(&self, task: &str, request: &str, seconds: i64) {
        Self::query("INSERT INTO dw_poll_receipts(namespace,worker_id,kind,request_id,task_id,attempt,response,expires_at) VALUES ($1,'bounds-worker','activity',$2,$3,1,$4,$5)")
            .bind(self.namespace.as_ref()).bind(request).bind(task).bind(DB::document(&json!({"original":"receipt"})))
            .bind(DB::bind_time(now() + chrono::TimeDelta::seconds(seconds))).execute(&self.pool).await.unwrap();
    }

    pub(super) async fn qualify_poll_sweeps(&mut self) {
        self.poll_sweep_config = Config { interval: Duration::from_secs(3600), batch: 7 };
        self.poll_sweeps = Arc::new(Sweeps::default());
        let task = self.poll_work_seed().await;
        Self::query("UPDATE workflow_tasks SET status='completed' WHERE id=$1").bind(&task).execute(&self.pool).await.unwrap();
        for index in 0..20 {
            for prefix in ["dw-native-workflow-poll:", "dw-native-query-poll:"] {
                Self::query(DB::QUERY_CACHE_INSERT).bind(format!("{prefix}{index:03}"))
                    .bind("{\"expired\":true}").bind(1_i64).execute(&self.pool).await.unwrap();
            }
            self.poll_work_receipt(&task, &format!("receipt-{index:03}"), index - 100).await;
        }
        // A sweep removes at most one configured batch, and namespaces share
        // that interval. Other families have independent throttle slots.
        let mut tx = self.begin().await.unwrap();
        self.sweep_workflow_poll_rows(&mut tx).await.unwrap();
        tx.commit().await.unwrap();
        assert_eq!(self.poll_work_family_count(true).await, 13);
        let other = self.in_namespace("other");
        let mut tx = other.begin().await.unwrap();
        other.sweep_workflow_poll_rows(&mut tx).await.unwrap();
        self.sweep_poll_rows(&mut tx).await.unwrap();
        self.sweep_poll_receipts(&mut tx).await.unwrap();
        tx.commit().await.unwrap();
        assert_eq!(self.poll_work_family_count(true).await, 13);
        assert_eq!(self.poll_work_family_count(false).await, 13);
        assert_eq!(self.poll_work_count("SELECT COUNT(*) FROM dw_poll_receipts").await, 13);
        let mut tx = self.begin().await.unwrap();
        self.sweep_poll_rows(&mut tx).await.unwrap();
        self.sweep_poll_receipts(&mut tx).await.unwrap();
        // Expired point reads remain absent while the sweep is throttled.
        assert!(Self::workflow_poll_row(&mut tx, "dw-native-workflow-poll:019").await.unwrap().is_none());
        assert!(Self::poll_row(&mut tx, "dw-native-query-poll:019").await.unwrap().is_none());
        tx.commit().await.unwrap();
        assert_eq!(self.poll_work_family_count(true).await, 12);
        assert_eq!(self.poll_work_count("SELECT COUNT(*) FROM dw_poll_receipts").await, 13);
        self.register(json!({"worker_id":"bounds-worker","task_queue":"bounds","runtime":"php",
            "supported_workflow_types":["bounds"],"supported_activity_types":["bounds"]}), &super::QueryTaskConfig::default(), None).await.unwrap();
        let body = json!({"worker_id":"bounds-worker","task_queue":"bounds","poll_request_id":"receipt-019","timeout_seconds":0});
        let mut tx = self.begin().await.unwrap();
        assert!(self.claim_in(&mut tx, &body, "activity", "1.20", None, None).await.unwrap().is_none());
        tx.commit().await.unwrap();
        assert_eq!(self.poll_work_count("SELECT COUNT(*) FROM dw_poll_receipts").await, 12);
        // Unexpired receipts and expired receipts whose task is still leased
        // retain the original fence. Cleanup must not loosen either one.
        self.poll_work_receipt(&task, "future", 60).await;
        let mut tx = self.begin().await.unwrap();
        let mut future = body.clone();
        future["poll_request_id"] = json!("future");
        let error = self.claim_in(&mut tx, &future, "activity", "1.20", None, None).await.unwrap_err();
        assert!(format!("{error:?}").contains("poll_cached_task_expired"));
        tx.rollback().await.unwrap();
        Self::query("UPDATE workflow_tasks SET status='leased',lease_owner='bounds-worker',attempt_count=1,lease_expires_at=$1 WHERE id=$2")
            .bind(DB::bind_time(now() + chrono::TimeDelta::seconds(60))).bind(&task).execute(&self.pool).await.unwrap();
        let mut tx = self.begin().await.unwrap();
        let mut old = body.clone();
        old["poll_request_id"] = json!("receipt-018");
        assert_eq!(self.claim_in(&mut tx, &old, "activity", "1.20", None, None).await.unwrap(), Some(json!({"original":"receipt"})));
        tx.commit().await.unwrap();
        // An expired receipt with an unverifiable/null lease deadline is also
        // protected, exactly as the old SQL's three-valued predicate was.
        Self::query("UPDATE workflow_tasks SET lease_expires_at=NULL WHERE id=$1").bind(&task).execute(&self.pool).await.unwrap();
        self.poll_sweeps = Arc::new(Sweeps::default());
        let mut tx = self.begin().await.unwrap();
        self.sweep_poll_receipts(&mut tx).await.unwrap();
        tx.commit().await.unwrap();
        assert_eq!(self.poll_work_count("SELECT COUNT(*) FROM dw_poll_receipts").await, 13);
        // Rollback publishes no deletion, but still consumes this server's
        // physical sweep interval. Logical point expiry remains independent.
        Self::query("UPDATE workflow_tasks SET status='completed' WHERE id=$1").bind(&task).execute(&self.pool).await.unwrap();
        self.poll_sweeps = Arc::new(Sweeps::default());
        let mut tx = self.begin().await.unwrap();
        self.sweep_poll_receipts(&mut tx).await.unwrap();
        tx.rollback().await.unwrap();
        let mut tx = self.begin().await.unwrap();
        self.sweep_poll_receipts(&mut tx).await.unwrap();
        tx.commit().await.unwrap();
        assert_eq!(self.poll_work_count("SELECT COUNT(*) FROM dw_poll_receipts").await, 13);
    }

    async fn poll_work_candidates(&self, sql: &str, wake: bool, branches: usize) -> Vec<String> {
        let at = now();
        let mut query = sqlx::query::<DB>(AssertSqlSafe(sql));
        for _ in 0..branches {
            query = query.bind(self.namespace.as_ref()).bind("bounds").bind(if wake {"activity"} else {"workflow"})
                .bind(DB::bind_time(at)).bind(DB::bind_time(at));
            if !wake { query = query.bind(None::<&str>); }
        }
        query.fetch_all(&self.pool).await.unwrap().iter().map(|row| DB::string(row, "id").unwrap()).collect()
    }

    async fn poll_work_plan(&self, sql: &'static str, wake: bool, branches: usize) -> Vec<String> {
        let explain = match DB::NAME {
            "SQLite" => "EXPLAIN QUERY PLAN ",
            "MySQL" => "EXPLAIN FORMAT=JSON ",
            _ => "EXPLAIN ",
        };
        let mut query = sqlx::query::<DB>(AssertSqlSafe(format!("{explain}{}", DB::statement(sql))));
        let at = now();
        for _ in 0..branches {
            query = query.bind(self.namespace.as_ref()).bind("bounds").bind(if wake {"activity"} else {"workflow"})
                .bind(DB::bind_time(at)).bind(DB::bind_time(at));
            if !wake { query = query.bind(None::<&str>); }
        }
        let rows = query.fetch_all(&self.pool).await.unwrap();
        let mut plan = Vec::new();
        for row in rows {
            let line = match DB::NAME {
                "SQLite" => DB::string(&row, "detail").unwrap(),
                "PostgreSQL" => DB::string(&row, "QUERY PLAN").unwrap(),
                "MySQL" => DB::string(&row, "EXPLAIN").unwrap(),
                _ => unreachable!(),
            };
            plan.push(line);
        }
        plan
    }

    pub(super) async fn qualify_poll_query_plans(&self) {
        let task = self.poll_work_seed().await;
        for size in [1, 7200] {
            if size > 1 {
                let mut tx = self.begin().await.unwrap();
                for index in 1..size {
                    Self::query("INSERT INTO workflow_tasks(id,workflow_run_id,namespace,queue,task_type,status,priority,available_at) SELECT $1,workflow_run_id,namespace,$2,$3,$4,priority,available_at FROM workflow_tasks WHERE id=$5")
                        .bind(id()).bind(if (100..350).contains(&index) {"other"} else {"bounds"})
                        .bind(if (350..600).contains(&index) {"activity"} else {"workflow"})
                        .bind(if index < 600 {"ready"} else {"completed"}).bind(&task).execute(&mut *tx).await.unwrap();
                }
                tx.commit().await.unwrap();
            }
            for (sql, wake) in [(DB::TASK_CANDIDATES_SQL, false),(DB::POLL_WAKE_TASKS_SQL,true)] {
                let compiled = DB::statement(sql);
                let predecessor = if !wake && DB::NAME == "PostgreSQL" {
                    DB::statement(super::store::TASK_CANDIDATES_SQL)
                } else if !wake && DB::NAME == "MySQL" {
                    DB::statement(super::store::task_candidates_sql!("FROM workflow_tasks t FORCE INDEX (dw_workflow_tasks_poll) STRAIGHT_JOIN workflow_runs r", 100))
                } else {
                    compiled.replace(" AND t.status IN ('ready','leased')", "")
                        .replace(" FORCE INDEX (dw_workflow_tasks_poll) STRAIGHT_JOIN ", " JOIN ")
                };
                let branches = if wake {1} else {DB::TASK_CANDIDATE_BRANCHES};
                let expected = self.poll_work_candidates(&predecessor, wake, 1).await;
                let actual = self.poll_work_candidates(&compiled, wake, branches).await;
                assert_eq!(actual, expected, "{size} tasks / wake={wake}");
                assert_eq!(actual.len(), if size == 1 {1} else {100});
                if !wake {
                    let first = self.poll_work_candidates(&DB::statement(DB::TASK_CANDIDATE_BATCH_SQL), false, branches).await;
                    assert_eq!(first, expected.iter().take(super::priority_fairness::FAIRNESS_BATCH).cloned().collect::<Vec<_>>());
                }
                let mut samples = Vec::new();
                for _ in 0..5 {
                    let start = Instant::now();
                    self.poll_work_candidates(&predecessor, wake, 1).await;
                    let old = start.elapsed().as_nanos();
                    let start = Instant::now();
                    self.poll_work_candidates(&compiled, wake, branches).await;
                    samples.push((old, start.elapsed().as_nanos()));
                }
                println!("poll_sql_source_samples backend={} tasks={size} wake={wake} old_new_ns={samples:?}", DB::NAME);
                if size == 1 { continue; }
                let raw = self.poll_work_plan(sql, wake, branches).await;
                println!("poll_query_plan backend={} wake={wake} statistics=before_explicit_refresh: {raw:?}", DB::NAME);
                // The disposable fixture was bulk-loaded in one transaction.
                // Refresh its statistics, never production planner settings.
                Self::query(if DB::NAME == "MySQL" { "ANALYZE TABLE workflow_tasks" } else { "ANALYZE workflow_tasks" })
                    .execute(&self.pool).await.unwrap();
                let plan = self.poll_work_plan(sql, wake, branches).await;
                println!("poll_query_plan backend={} wake={wake} statistics=after_explicit_refresh: {plan:?}", DB::NAME);
                // Inspect actual status key use. Bitmap alternatives may be
                // cheaper than the widest poll index; scope/run-only walks
                // still fail this control.
                match DB::NAME {
                    "SQLite" => assert!(plan.iter().any(|line| status_index(line) && line.contains("status=?")), "{plan:?}"),
                    "PostgreSQL" => assert!(postgres_live_status_seek(&plan), "{plan:?}"),
                    "MySQL" => {
                        assert!(plan.iter().any(|line| mysql_live_status_seek(&serde_json::from_str(line).unwrap())), "{plan:?}");
                        if !wake {
                            assert!(plan.iter().all(|line| mysql_sorts_only_bounded_union(&serde_json::from_str(line).unwrap())), "{plan:?}");
                            let old = self.poll_work_plan(super::store::task_candidates_sql!("FROM workflow_tasks t FORCE INDEX (dw_workflow_tasks_poll) STRAIGHT_JOIN workflow_runs r", 100), false, 1).await;
                            assert!(old.iter().any(|line| !mysql_sorts_only_bounded_union(&serde_json::from_str(line).unwrap())), "the predecessor must reproduce the task-row sort: {old:?}");
                        }
                    }
                    _ => unreachable!(),
                }
                assert!(!plan.iter().any(|line| line.contains("Seq Scan on workflow_tasks") || line == "SCAN t"), "{plan:?}");
            }
        }
    }
}

#[tokio::test]
async fn sqlite_sweeps_and_poll_query_plans_are_bounded() {
    let dir = tempfile::tempdir().unwrap();
    let mut store = Store::<sqlx::Sqlite>::new(super::sqlite::open(dir.path().join("sweeps.sqlite").to_str().unwrap()).await.unwrap());
    store.qualify_poll_sweeps().await;
    let plan: Vec<(i64,i64,i64,String)> = sqlx::query_as(AssertSqlSafe(format!("EXPLAIN QUERY PLAN {}", sqlx::Sqlite::POLL_RECEIPT_CLEANUP_SQL)))
        .bind(sqlx::Sqlite::bind_time(now())).bind(sqlx::Sqlite::bind_time(now())).bind(7_i64).fetch_all(&store.pool).await.unwrap();
    assert!(plan.iter().any(|row| row.3.contains("SEARCH p USING INDEX dw_poll_receipts_expiration")), "{plan:?}");
    store.close().await;
    let store = Store::<sqlx::Sqlite>::new(super::sqlite::open(dir.path().join("plans.sqlite").to_str().unwrap()).await.unwrap());
    store.qualify_poll_query_plans().await;
    store.close().await;
}

#[tokio::test]
async fn a_due_sweep_waits_for_the_database_transition_lock() {
    let dir = tempfile::tempdir().unwrap();
    let store = Store::<sqlx::Sqlite>::new(super::sqlite::open(dir.path().join("locked.sqlite").to_str().unwrap()).await.unwrap());
    Store::<sqlx::Sqlite>::query(sqlx::Sqlite::QUERY_CACHE_INSERT).bind("dw-native-query-poll:locked").bind("{}").bind(1_i64).execute(&store.pool).await.unwrap();
    let held = store.begin().await.unwrap();
    let other = store.in_namespace("other");
    let (sent, started) = tokio::sync::oneshot::channel();
    let pending = tokio::spawn(async move {
        sent.send(()).unwrap();
        let mut tx = other.begin().await.unwrap();
        other.sweep_poll_rows(&mut tx).await.unwrap();
        tx.commit().await.unwrap();
    });
    started.await.unwrap();
    tokio::time::sleep(Duration::from_millis(25)).await;
    assert!(!pending.is_finished());
    assert_eq!(store.poll_work_count("SELECT COUNT(*) FROM dw_query_cache").await, 1);
    held.rollback().await.unwrap();
    tokio::time::timeout(Duration::from_secs(5), pending).await.unwrap().unwrap();
    assert_eq!(store.poll_work_count("SELECT COUNT(*) FROM dw_query_cache").await, 0);
    store.close().await;
}
