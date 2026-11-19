//! The maintenance scans read open work, not retained history (server#1256):
//! with many closed runs, each scan seeks an open-status or retention index
//! and never walks a whole run, activity or summary table.
use super::store::{Store, id, now};
use chrono::Duration;
use sqlx::AssertSqlSafe;

/// The tables whose retained rows a maintenance scan must not walk, under
/// the names and aliases the scans give them.
const RETAINED: [&str; 5] = ["workflow_runs", "r", "activity_executions", "a", "workflow_run_summaries"];

/// A SQLite plan line that walks a retained table, with or without an index.
fn sqlite_full_scan(line: &str) -> bool {
    RETAINED.iter().any(|table| line == format!("SCAN {table}") || line.starts_with(&format!("SCAN {table} ")))
}

fn postgres_full_scan(line: &str) -> bool {
    ["workflow_runs", "activity_executions", "workflow_run_summaries"].iter().any(|table| line.contains(&format!("Seq Scan on {table}")))
}

/// A MySQL or MariaDB JSON plan node that reads a retained table in full,
/// by table scan or by full index scan.
fn mysql_full_scan(plan: &serde_json::Value) -> bool {
    if let Some(table) = plan.get("table")
        && RETAINED.contains(&table["table_name"].as_str().unwrap_or_default())
        && matches!(table["access_type"].as_str(), Some("ALL" | "index"))
    {
        return true;
    }
    match plan {
        serde_json::Value::Object(fields) => fields.values().any(mysql_full_scan),
        serde_json::Value::Array(values) => values.iter().any(mysql_full_scan),
        _ => false,
    }
}

store_methods! {
    /// Closed runs, each with a summary and a completed activity, plus two
    /// open runs and two open activities. One run in ten, including the first
    /// open one, has a cooperative cancellation request.
    async fn seed_retained_runs(&self, closed: usize) {
        let mut tx = self.begin().await.unwrap();
        let closed_at = now() - Duration::days(1);
        for index in 0..closed + 2 {
            let open = index >= closed;
            let run = id();
            let status = if open { "waiting" } else { "completed" };
            let cancellation = (index % 10 == 0).then(id);
            Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,run_number,workflow_class,workflow_type,namespace,status,closed_at,cancellation_request_command_id,cancellation_deadline_at) VALUES ($1,$2,1,'scan','scan',$3,$4,$5,$6,$7)")
                .bind(&run).bind(format!("scan-{index}")).bind(self.namespace.as_ref()).bind(status)
                .bind(DB::optional_time((!open).then_some(closed_at)))
                .bind(cancellation.as_deref())
                .bind(DB::optional_time(open.then(|| now() + Duration::hours(1))))
                .execute(&mut *tx).await.unwrap();
            Self::query("INSERT INTO workflow_run_summaries(id,workflow_instance_id,run_number,class,workflow_type,namespace,status,status_bucket,closed_at) VALUES ($1,$2,1,'scan','scan',$3,$4,$5,$6)")
                .bind(&run).bind(format!("scan-{index}")).bind(self.namespace.as_ref()).bind(status)
                .bind(if open { "running" } else { "completed" }).bind(DB::optional_time((!open).then_some(closed_at)))
                .execute(&mut *tx).await.unwrap();
            Self::query("INSERT INTO activity_executions(id,workflow_run_id,sequence,activity_class,activity_type,status,close_deadline_at) VALUES ($1,$2,1,'scan','scan',$3,$4)")
                .bind(id()).bind(&run).bind(if open { "running" } else { "completed" })
                .bind(DB::optional_time(open.then(|| now() + Duration::hours(1))))
                .execute(&mut *tx).await.unwrap();
        }
        tx.commit().await.unwrap();
        for table in ["workflow_runs", "workflow_run_summaries", "activity_executions"] {
            let refresh = match DB::NAME { "MySQL" => format!("ANALYZE TABLE {table}"), _ => format!("ANALYZE {table}") };
            sqlx::query::<DB>(AssertSqlSafe(refresh)).fetch_all(&self.pool).await.unwrap();
        }
    }

    fn plan_lines(rows: Vec<DB::Row>) -> Vec<String> {
        rows.iter().map(|row| match DB::NAME {
            "SQLite" => DB::string(row, "detail").unwrap(),
            "PostgreSQL" => DB::string(row, "QUERY PLAN").unwrap(),
            _ => DB::string(row, "EXPLAIN").unwrap(),
        }).collect()
    }

    /// Each scan's plan, with the index it is expected to seek, if one.
    async fn maintenance_scan_plans(&self) -> Vec<(&'static str, Option<&'static str>, Vec<String>)> {
        let explain = match DB::NAME { "SQLite" => "EXPLAIN QUERY PLAN ", "MySQL" => "EXPLAIN FORMAT=JSON ", _ => "EXPLAIN " };
        let at = now();
        let stamp = super::timeout_cleanup::iso(at);
        let explained = |sql: &'static str| AssertSqlSafe(format!("{explain}{}", DB::statement(sql)));
        let mut plans = Vec::new();
        let rows = sqlx::query::<DB>(explained(super::cooperative::COOPERATIVE_DEADLINES_EXPIRED)).bind(DB::bind_time(at))
            .fetch_all(&self.pool).await.unwrap();
        plans.push(("cooperative deadlines", None, Self::plan_lines(rows)));
        let rows = sqlx::query::<DB>(explained(DB::TIMEOUT_CLEANUP_DUE)).bind(stamp.as_str()).bind(stamp.as_str())
            .fetch_all(&self.pool).await.unwrap();
        plans.push(("timeout cleanup", Some("dw_workflow_runs_status_deadline"), Self::plan_lines(rows)));
        let rows = sqlx::query::<DB>(explained(super::activity_deadlines::ACTIVITY_DEADLINES_DUE))
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(DB::bind_time(at))
            .fetch_all(&self.pool).await.unwrap();
        plans.push(("activity deadlines", None, Self::plan_lines(rows)));
        let rows = sqlx::query::<DB>(explained(super::operator_metrics::RETENTION_CANDIDATES)).bind(self.namespace.as_ref())
            .bind(DB::bind_time(at - Duration::days(30))).bind(100_i64).bind(0_i64)
            .fetch_all(&self.pool).await.unwrap();
        plans.push(("retention candidates", Some("dw_workflow_run_summaries_retention"), Self::plan_lines(rows)));
        let mut repair = self.maintenance_scan_rows(explain, at).await.into_iter();
        plans.push(("repair deadline runs", Some("dw_workflow_runs_status_deadline"), Self::plan_lines(repair.next().unwrap())));
        plans.push(("repair expired activities", Some("dw_activity_executions_status"), Self::plan_lines(repair.next().unwrap())));
        plans
    }

    pub(super) async fn qualify_maintenance_scan_plans(&self) {
        self.seed_retained_runs(3000).await;
        for (scan, index, plan) in self.maintenance_scan_plans().await {
            println!("maintenance_scan_plan backend={} scan={scan}: {plan:?}", DB::NAME);
            let full = match DB::NAME {
                "SQLite" => plan.iter().any(|line| sqlite_full_scan(line)),
                "PostgreSQL" => plan.iter().any(|line| postgres_full_scan(line)),
                _ => plan.iter().any(|line| mysql_full_scan(&serde_json::from_str(line).unwrap())),
            };
            assert!(!full, "{scan} walks a retained table: {plan:?}");
            if let Some(index) = index {
                assert!(plan.iter().any(|line| line.contains(index)), "{scan} does not seek {index}: {plan:?}");
            }
        }
        // The scans themselves still find exactly the open work they did.
        let due = Self::query(super::activity_deadlines::ACTIVITY_DEADLINES_DUE)
            .bind(DB::bind_time(now() + Duration::hours(2))).bind(DB::bind_time(now() + Duration::hours(2)))
            .bind(DB::bind_time(now() + Duration::hours(2))).bind(DB::bind_time(now() + Duration::hours(2)))
            .fetch_all(&self.pool).await.unwrap();
        assert_eq!(due.len(), 2);
        let expired = Self::query(super::cooperative::COOPERATIVE_DEADLINES_EXPIRED)
            .bind(DB::bind_time(now() + Duration::hours(2))).fetch_all(&self.pool).await.unwrap();
        assert_eq!(expired.len(), 1, "only the open run with a cooperative request, not the retained cancelled runs");
        let retained = Self::query(super::operator_metrics::RETENTION_CANDIDATES).bind(self.namespace.as_ref())
            .bind(DB::bind_time(now())).bind(5000_i64).bind(0_i64).fetch_all(&self.pool).await.unwrap();
        assert_eq!(retained.len(), 3000);
    }
}

#[tokio::test]
async fn sqlite_maintenance_scans_seek_open_work_only() {
    let dir = tempfile::tempdir().unwrap();
    let store = Store::<sqlx::Sqlite>::new(super::sqlite::open(dir.path().join("scans.sqlite").to_str().unwrap()).await.unwrap());
    store.qualify_maintenance_scan_plans().await;
    store.close().await;
}
