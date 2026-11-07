//! Compare the complete candidate rows and physical plans with fresh statistics.
use super::{backend::Backend, store::{StartMetadata, Store, id}, transaction::Transaction};
use crate::codec::{Value as Payload, ValueCodec};
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{AssertSqlSafe, Postgres, Row, types::Json};

async fn candidates(tx: &mut Transaction<'_, Postgres>, sql: &str, at: DateTime<Utc>, kind: &str, build: Option<&str>) -> Vec<Value> {
    sqlx::query(AssertSqlSafe(format!("SELECT row_to_json(candidate) AS candidate FROM ({sql}) candidate")))
        .bind("default").bind("ordered").bind(kind).bind(Postgres::bind_time(at)).bind(Postgres::bind_time(at)).bind(build)
        .fetch_all(&mut **tx).await.unwrap().into_iter().map(|row| row.get::<Json<Value>, _>("candidate").0).collect()
}

async fn explain(tx: &mut Transaction<'_, Postgres>, sql: &str, at: DateTime<Utc>) -> Value {
    let row = sqlx::query(AssertSqlSafe(format!("EXPLAIN (ANALYZE, BUFFERS, TIMING OFF, FORMAT JSON) {sql}")))
        .bind("default").bind("ordered").bind("workflow").bind(Postgres::bind_time(at))
        .bind(Postgres::bind_time(at)).bind(None::<&str>).fetch_one(&mut **tx).await.unwrap();
    row.get::<Json<Value>, _>("QUERY PLAN").0
}

fn require_index_ranges(node: &Value, tasks: &mut usize, runs: &mut usize, in_range: bool) {
    assert!(!in_range || !matches!(node["Node Type"].as_str(), Some("Sort" | "Incremental Sort")),
        "a status range must retain index order: {node}");
    match node["Relation Name"].as_str() {
        Some("workflow_tasks") => {
            assert!(matches!(node["Node Type"].as_str(),Some("Index Scan" | "Index Only Scan")),"{node}");
            assert_eq!(node["Index Name"],"dw_workflow_tasks_scoped_dispatch",
                "every status range must use the fully scoped ordered index: {node}");
            assert!(node["Index Cond"].as_str().is_some_and(|condition|
                ["namespace","queue","task_type","status"].iter().all(|key| condition.contains(key))),"{node}");
            *tasks += 1;
        }
        Some("workflow_runs") => {
            assert_eq!(node["Index Name"],"workflow_runs_pkey","each correlated run lookup must use its primary key: {node}");
            assert!(node["Index Cond"].as_str().is_some_and(|condition| condition.contains("workflow_run_id")),"{node}");
            *runs += 1;
        }
        _ => {}
    }
    if let Some(children) = node["Plans"].as_array() {
        let in_range = in_range || matches!(node["Node Type"].as_str(), Some("Append" | "Merge Append"));
        for child in children { require_index_ranges(child,tasks,runs,in_range); }
    }
}

async fn require_scope_equality(tx: &mut Transaction<'_, Postgres>) {
    let columns = sqlx::query("SELECT c.collname,c.collisdeterministic FROM pg_attribute a JOIN pg_collation c ON c.oid=a.attcollation WHERE a.attrelid='workflow_tasks'::regclass AND a.attname IN ('namespace','queue','task_type','status')")
        .fetch_all(&mut **tx).await.unwrap();
    assert_eq!(columns.len(),4);
    for column in columns {
        assert_eq!(column.get::<String,_>("collname"),"default");
        assert!(column.get::<bool,_>("collisdeterministic"));
    }
    for (left,right) in [("",""),("default","default"),("default","Default"),
        ("ordered","ordered "),("é","e\u{301}"),("workflow","workflow\u{200b}"),
        ("ready","READY"),("é","é")] {
        let row = sqlx::query("SELECT $1::text = $2::text AS retained, $1::text COLLATE \"C\" = $2::text AS scoped")
            .bind(left).bind(right).fetch_one(&mut **tx).await.unwrap();
        assert_eq!(row.get::<bool,_>("retained"),left==right);
        assert_eq!(row.get::<bool,_>("scoped"),left==right);
    }
}

impl Store<Postgres> {
    async fn candidate_runs(transaction: &mut Transaction<'_,Postgres>, input: &Value) -> Vec<String> {
        let mut runs = Vec::new();
        for index in 0..100 {
            let started = Self::start_in(transaction, &json!({"workflow_id":format!("ordered-{index}"),
                "workflow_type":"ordered","task_queue":"ordered","input":input}), "default",
                StartMetadata {memo:None,search_attributes:None}, None,None,120).await.unwrap();
            let run = started["run_id"].as_str().unwrap().to_owned();
            Self::query("UPDATE workflow_runs SET compatibility=$1,status=$2 WHERE id=$3")
                .bind(if index % 3 == 0 {Some("build")} else {None})
                .bind(if index % 11 == 0 {"completed"} else {"pending"}).bind(&run)
                .execute(&mut **transaction).await.unwrap();
            runs.push(run);
        }
        runs
    }

    async fn candidate_fixture(transaction: &mut Transaction<'_,Postgres>, at: DateTime<Utc>, input: &Value) {
        let runs = Self::candidate_runs(transaction,input).await;
        for index in 0..7200 {
            let status = if index < 250 {"ready"} else if index < 500 {"leased"} else {"completed"};
            let available = if index % 7 == 0 {None} else {Some(at + chrono::TimeDelta::seconds(if index % 13 == 0 {1} else {-1}))};
            let lease = if index % 23 == 0 {None} else {Some(at + chrono::TimeDelta::seconds(if index % 17 == 0 {1} else {-1}))};
            Self::query("INSERT INTO workflow_tasks(id,workflow_run_id,namespace,queue,task_type,status,priority,available_at,lease_expires_at,compatibility) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)")
                .bind(id()).bind(&runs[index % runs.len()]).bind(if index % 31 == 0 {"other"} else {"default"})
                .bind(if index % 29 == 0 {"elsewhere"} else {"ordered"})
                .bind(if index % 19 == 0 {"activity"} else {"workflow"}).bind(status).bind((index % 4) as i64)
                .bind(Postgres::optional_time(available)).bind(Postgres::optional_time(lease))
                .bind(match index % 5 {0=>Some("other"),1=>Some(""),_=>None})
                .execute(&mut **transaction).await.unwrap();
        }
        Self::candidate_foreign_rows(transaction,&runs).await;
    }

    async fn candidate_foreign_rows(transaction: &mut Transaction<'_,Postgres>, runs: &[String]) {
        // A global queue/status index encounters these earlier rows first.
        // The scoped equality range must exclude each foreign dimension.
        for index in 0..6000 {
            Self::query("INSERT INTO workflow_tasks(id,workflow_run_id,namespace,queue,task_type,status,priority,available_at) VALUES ($1,$2,$3,$4,$5,'ready',0,$6)")
                .bind(id()).bind(&runs[1])
                .bind(if index < 2400 {"foreign"} else {"default"})
                .bind(if index >= 4800 {"elsewhere"} else {"ordered"})
                .bind(if (2400..4800).contains(&index) {"activity"} else {"workflow"})
                .bind(Postgres::optional_time(None)).execute(&mut **transaction).await.unwrap();
        }
    }

    async fn require_candidate_parity(transaction: &mut Transaction<'_,Postgres>, at: DateTime<Utc>, old: &str, new: &str, analyzed: bool) {
        for kind in ["workflow","activity"] {
            for build in [None,Some("build"),Some("other")] {
                let expected = candidates(transaction,old,at,kind,build).await;
                let actual = candidates(transaction,new,at,kind,build).await;
                assert_eq!(actual,expected,"analyzed={analyzed} / kind={kind} / build={build:?}");
                if kind=="workflow" && build.is_none() { assert_eq!(actual.len(),100); }
            }
        }
    }

    pub(super) async fn qualify_postgres_candidate_ranges(&self) {
        let at = "2035-01-02T03:04:05.123456Z".parse::<DateTime<Utc>>().unwrap();
        let input = json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap()});
        // Uncommitted fixture rows cannot be analyzed by autovacuum between
        // the first plan and the explicit refresh inside this transaction.
        let mut transaction = self.begin().await.unwrap();
        require_scope_equality(&mut transaction).await;
        Self::candidate_fixture(&mut transaction,at,&input).await;
        let statistics:i64 = sqlx::query_scalar("SELECT COUNT(*) FROM pg_stats WHERE schemaname='public' AND tablename IN ('workflow_tasks','workflow_runs')")
            .fetch_one(&mut *transaction).await.unwrap();
        assert_eq!(statistics,0,"the first plan must precede actual table analysis");
        let old = super::store::TASK_CANDIDATES_SQL;
        let new = Postgres::TASK_CANDIDATES_SQL;
        for analyzed in [false,true] {
            if analyzed { Self::query("ANALYZE workflow_tasks,workflow_runs").execute(&mut *transaction).await.unwrap(); }
            Self::require_candidate_parity(&mut transaction,at,old,new,analyzed).await;
            let before = explain(&mut transaction,old,at).await;
            let after = explain(&mut transaction,new,at).await;
            println!("postgres_status_candidate_plan analyzed={analyzed} old={before} new={after}");
            let (mut tasks,mut run_lookups) = (0,0);
            require_index_ranges(&after[0]["Plan"],&mut tasks,&mut run_lookups,false);
            assert_eq!((tasks,run_lookups),(2,2),"{after}");
        }
        transaction.rollback().await.unwrap();
    }
}
