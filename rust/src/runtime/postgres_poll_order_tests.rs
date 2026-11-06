//! Compare the actual PostgreSQL candidate query before and after fixture ANALYZE.
use super::{backend::Backend, store::{StartMetadata, Store, id}, transaction::Transaction};
use crate::codec::{Value as Payload, ValueCodec};
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{AssertSqlSafe, Postgres, Row, types::Json};

async fn candidates(
    tx: &mut Transaction<'_, Postgres>, sql: &str, at: DateTime<Utc>, kind: &str, build: Option<&str>,
) -> Vec<Value> {
    sqlx::query(AssertSqlSafe(format!("SELECT row_to_json(candidate) AS candidate FROM ({sql}) candidate")))
        .bind("default").bind("pg-order").bind(kind).bind(Postgres::bind_time(at))
        .bind(Postgres::bind_time(at)).bind(build).fetch_all(&mut **tx).await.unwrap()
        .into_iter().map(|row| row.get::<Json<Value>, _>("candidate").0).collect()
}

async fn plan(tx: &mut Transaction<'_, Postgres>, sql: &str, at: DateTime<Utc>) -> Value {
    let row = sqlx::query(AssertSqlSafe(format!("EXPLAIN (ANALYZE, BUFFERS, TIMING OFF, FORMAT JSON) {sql}")))
        .bind("default").bind("pg-order").bind("workflow").bind(Postgres::bind_time(at))
        .bind(Postgres::bind_time(at)).bind(None::<&str>).fetch_one(&mut **tx).await.unwrap();
    row.get::<Json<Value>, _>("QUERY PLAN").0
}

fn index_ranges(plan: &Value, task_ranges: &mut usize, run_lookups: &mut usize) {
    if plan["Relation Name"] == "workflow_tasks" {
        assert!(matches!(plan["Node Type"].as_str(), Some("Index Scan" | "Index Only Scan")), "{plan}");
        assert!(plan["Index Cond"].as_str().is_some_and(|condition| condition.contains("status")), "{plan}");
        assert_eq!(plan["Index Name"], "workflow_tasks_dispatch_order_index", "the candidate range must arrive in dispatch order: {plan}");
        *task_ranges += 1;
    }
    if plan["Relation Name"] == "workflow_runs" {
        assert_eq!(plan["Index Name"], "workflow_runs_pkey", "{plan}");
        assert!(plan["Index Cond"].as_str().is_some_and(|condition| condition.contains("workflow_run_id")), "{plan}");
        *run_lookups += 1;
    }
    if let Some(children) = plan["Plans"].as_array() {
        for child in children { index_ranges(child, task_ranges, run_lookups); }
    }
}

impl Store<Postgres> {
    pub(super) async fn qualify_postgres_poll_order(&self) {
        let at = "2035-01-02T03:04:05.123456Z".parse::<DateTime<Utc>>().unwrap();
        let input = json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap()});
        // Keep every inserted row uncommitted until the comparison completes:
        // autovacuum cannot analyze this fixture between its two plan samples.
        let mut tx = self.begin().await.unwrap();
        let mut runs = Vec::new();
        for index in 0..100 {
            let started = Self::start_in(&mut tx, &json!({"workflow_id":format!("pg-order-{index}"),
                "workflow_type":"pg-order","task_queue":"pg-order","input":input}), "default",
                StartMetadata { memo: None, search_attributes: None }, None, None, 120).await.unwrap();
            let run = started["run_id"].as_str().unwrap().to_owned();
            Self::query("UPDATE workflow_runs SET compatibility=$1,status=$2 WHERE id=$3")
                .bind(if index % 3 == 0 {Some("build-a")} else {None})
                .bind(if index % 11 == 0 {"completed"} else {"pending"})
                .bind(&run).execute(&mut *tx).await.unwrap();
            runs.push(run);
        }
        // Both live statuses exceed the global limit. Completed, other-kind,
        // other-scope and future rows must never displace an eligible candidate.
        for index in 0..7200 {
            let run = &runs[index % runs.len()];
            let status = if index < 250 {"ready"} else if index < 500 {"leased"} else {"completed"};
            let available = if index % 7 == 0 {None} else {
                Some(at + chrono::Duration::seconds(if index % 13 == 0 {1} else {-1}))
            };
            Self::query("INSERT INTO workflow_tasks(id,workflow_run_id,namespace,queue,task_type,status,priority,available_at,lease_expires_at,compatibility) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)")
                .bind(id()).bind(run).bind(if index % 31 == 0 {"other"} else {"default"})
                .bind(if index % 29 == 0 {"other"} else {"pg-order"})
                .bind(if index % 19 == 0 {"activity"} else {"workflow"}).bind(status)
                .bind((index % 4) as i16).bind(Postgres::optional_time(available))
                .bind(Postgres::bind_time(at + chrono::Duration::seconds(if index % 17 == 0 {1} else {-1})))
                .bind(match index % 5 {0 => Some("build-b"),1 => Some(""),_ => None})
                .execute(&mut *tx).await.unwrap();
        }
        let statistics: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM pg_stats WHERE schemaname='public' AND tablename IN ('workflow_tasks','workflow_runs')")
            .fetch_one(&mut *tx).await.unwrap();
        assert_eq!(statistics, 0, "the first plan must really precede table analysis");
        let old = super::store::TASK_CANDIDATES_SQL;
        let new = Postgres::TASK_CANDIDATES_SQL;
        for analyzed in [false, true] {
            if analyzed {
                // Only these disposable test tables are analyzed. Production
                // SQL contains neither ANALYZE nor changed planner settings.
                Self::query("ANALYZE workflow_tasks, workflow_runs").execute(&mut *tx).await.unwrap();
            }
            for kind in ["workflow", "activity"] {
                for build in [None, Some("build-a"), Some("build-b")] {
                    let expected = candidates(&mut tx, old, at, kind, build).await;
                    let actual = candidates(&mut tx, new, at, kind, build).await;
                    assert_eq!(actual, expected, "analyzed={analyzed}, kind={kind}, build={build:?}");
                    if kind == "workflow" && build.is_none() { assert_eq!(actual.len(), 100); }
                }
            }
            let before = plan(&mut tx, old, at).await;
            let after = plan(&mut tx, new, at).await;
            println!("postgres_poll_order analyzed={analyzed} old={before} new={after}");
            let (mut task_ranges, mut run_lookups) = (0, 0);
            index_ranges(&after[0]["Plan"], &mut task_ranges, &mut run_lookups);
            assert_eq!(task_ranges, 2, "{after}");
            assert_eq!(run_lookups, 2, "{after}");
        }
        tx.rollback().await.unwrap();
    }
}
