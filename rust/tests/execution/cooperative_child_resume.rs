use super::{cooperative_children::*, *};

async fn parent_task(database: &TestDatabase, run: &Value) -> Value {
    let query = "SELECT id FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased') ORDER BY id";
    macro_rules! task {
        ($pool:expr, $query:expr) => {{
            let pool = $pool;
            let ids: Vec<String> = sqlx::query_scalar($query).bind(run["run_id"].as_str().unwrap()).fetch_all(&pool).await.unwrap();
            pool.close().await;
            assert_eq!(ids.len(), 1);
            json!({"task_id":ids[0]})
        }};
    }
    match database {
        TestDatabase::Sqlite(dir) => task!(sqlx::SqlitePool::connect(&format!("sqlite://{}",dir.path().join("runtime.sqlite").display())).await.unwrap(), query),
        TestDatabase::Postgres { options, .. } => task!(sqlx::postgres::PgPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap(), query),
        TestDatabase::MySql { options, .. } => task!(sqlx::mysql::MySqlPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap(), "SELECT id FROM workflow_tasks WHERE workflow_run_id=? AND task_type='workflow' AND status IN ('ready','leased') ORDER BY id"),
    }
}

async fn wait(database: &TestDatabase, run: &Value) -> Value {
    let query = "SELECT wait_id,status,source_status,CAST(CASE WHEN task_backed THEN 1 ELSE 0 END AS BIGINT),task_id,task_type,task_status,history_authority,history_unsupported_reason FROM workflow_run_waits WHERE workflow_run_id=$1 AND kind='child'";
    macro_rules! row {
        ($pool:expr, $query:expr) => {{
            let pool = $pool;
            let row: Option<(String,String,String,i64,Option<String>,Option<String>,Option<String>,Option<String>,Option<String>)> = sqlx::query_as($query).bind(run["run_id"].as_str().unwrap()).fetch_optional(&pool).await.unwrap();
            pool.close().await;
            row.map_or(Value::Null, |row| json!({"wait_id":row.0,"status":row.1,"source_status":row.2,"task_backed":row.3 == 1,"task_id":row.4,"task_type":row.5,"task_status":row.6,"history_authority":row.7,"history_unsupported_reason":row.8}))
        }};
    }
    match database {
        TestDatabase::Sqlite(dir) => row!(sqlx::SqlitePool::connect(&format!("sqlite://{}",dir.path().join("runtime.sqlite").display())).await.unwrap(), query),
        TestDatabase::Postgres { options, .. } => row!(sqlx::postgres::PgPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap(), query),
        TestDatabase::MySql { options, .. } => row!(sqlx::mysql::MySqlPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap(), "SELECT wait_id,status,source_status,CAST(CASE WHEN task_backed THEN 1 ELSE 0 END AS SIGNED),task_id,task_type,task_status,history_authority,history_unsupported_reason FROM workflow_run_waits WHERE workflow_run_id=? AND kind='child'"),
    }
}

async fn child_outcome(app: &Router, child: &Value, outcome: &str) -> (StatusCode, Value) {
    if outcome == "cancelled" {
        request(app, "POST", &format!("/api/workflows/{}/runs/{}/cancel",child["workflow_id"].as_str().unwrap(),child["run_id"].as_str().unwrap()), json!({"reason":"independent child cancellation λ"})).await
    } else {
        let commands = if outcome == "failed" {
            json!([{"type":"fail_workflow","message":"independent child failure λ"}])
        } else {
            json!([{"type":"complete_workflow","result":envelope(Payload::Array(vec![Payload::Long(9007199254740993),Payload::Boolean(false)]))}])
        };
        finish_task(app, child, commands).await
    }
}

async fn export(database: &TestDatabase, app: &Router, run: &Value, task: &Value, status: &str, projected: &Value) {
    let events = run_history(app, &run["workflow_id"], &run["run_id"]).await;
    run_observation_summaries::export_reference_for(database,
        &json!({"run_id":run["run_id"],"workflow_id":run["workflow_id"],"task_id":task["task_id"],
            "task_status":status,"wait":projected}), &json!({"events":events}), "DW_TEST_CHILD_RESUME_REFERENCE").await;
}

async fn resolution(leased: bool, outcome: &str, missing: bool, rollback: bool) {
    let database = if rollback { TestDatabase::Sqlite(tempfile::tempdir().unwrap()) } else { TestDatabase::new().await };
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (parent, child) = setup(&app).await;
    let pending = root_request(&app, 30).await;
    let root = if leased { cooperative_poll(&app, "workflow").await } else { parent_task(&database, &parent).await };
    let original = child_claim(&database, &root).await;
    let child_original = child_claim(&database, &child).await;
    let parent_prefix = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    let child_prefix = run_history(&app, &child["workflow_id"], &child["run_id"]).await;
    if missing { database.execute("DELETE FROM workflow_run_waits WHERE kind='child'").await; }
    let before = wait(&database, &parent).await;
    if rollback {
        database.execute("CREATE TRIGGER reject_child_resume_link BEFORE UPDATE ON workflow_run_waits WHEN NEW.kind='child' AND NEW.task_backed=1 BEGIN SELECT RAISE(ABORT, 'child resume association rejected'); END").await;
        let rejected = child_outcome(&app, &child, outcome).await;
        assert_eq!(rejected.0, StatusCode::SERVICE_UNAVAILABLE, "{}", rejected.1);
        assert_eq!(run_history(&app, &parent["workflow_id"], &parent["run_id"]).await, parent_prefix);
        assert_eq!(run_history(&app, &child["workflow_id"], &child["run_id"]).await, child_prefix);
        assert_eq!(child_claim(&database, &root).await, original);
        assert_eq!(child_claim(&database, &child).await, child_original);
        assert_eq!(wait(&database, &parent).await, before);
        assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_failures").await, 0);
        assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_child_calls WHERE status='started' AND closed_at IS NULL").await, 1);
        database.execute("DROP TRIGGER reject_child_resume_link").await;
    }
    let result = child_outcome(&app, &child, outcome).await;
    assert_eq!(result.0, StatusCode::OK, "{}", result.1);
    assert_eq!(child_claim(&database, &root).await, original);
    let projected = wait(&database, &parent).await;
    if missing {
        assert!(projected.is_null());
    } else {
        assert_eq!(projected["task_backed"], true, "{projected}");
        assert_eq!(projected["task_id"], root["task_id"]);
        assert_eq!(projected["task_type"], "workflow");
        assert_eq!(projected["task_status"], if leased {"leased"} else {"ready"});
        assert_eq!(projected["source_status"], outcome);
        assert_eq!(projected["status"], if outcome == "cancelled" {"cancelled"} else {"resolved"});
        assert_eq!(projected["history_authority"], "typed_history");
        assert!(projected["history_unsupported_reason"].is_null());
    }
    assert_eq!(parent_task(&database, &parent).await["task_id"], root["task_id"]);
    export(&database, &app, &parent, &root, if leased {"leased"} else {"ready"}, &projected).await;
    let parent_events = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    let duplicate = child_outcome(&app, &child, outcome).await;
    assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
    assert_eq!(run_history(&app, &parent["workflow_id"], &parent["run_id"]).await, parent_events);
    assert_eq!(wait(&database, &parent).await, projected);
    runtime.close().await;
    let cold = database.open().await.unwrap();
    let app = router(cold.clone());
    assert_eq!(wait(&database, &parent).await, projected);
    assert_eq!(child_claim(&database, &root).await, original);
    let root = if leased {root} else {cooperative_poll(&app, "workflow").await};
    if !leased {
        let claimed = wait(&database, &parent).await;
        if !missing {
            assert_eq!(claimed["task_id"], root["task_id"]);
            assert_eq!(claimed["task_status"], "leased");
        }
        export(&database, &app, &parent, &root, "leased", &claimed).await;
    }
    let delivered = deliver(&app, &root, &pending, 1, "child").await;
    assert_eq!(delivered.0, StatusCode::OK, "{}", delivered.1);
    let terminal = finish_task(&app, &root, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(terminal.0, StatusCode::OK, "{}", terminal.1);
    closed(&app, &parent, &pending, "completed", 1).await;
    assert!(cooperative_poll(&app, "workflow").await.is_null());
    cold.close().await;
    database.remove().await;
}

#[tokio::test]
async fn cooperative_child_resume_completion_ready() { resolution(false, "completed", false, false).await; }
#[tokio::test]
async fn cooperative_child_resume_completion_leased() { resolution(true, "completed", false, false).await; }
#[tokio::test]
async fn cooperative_child_resume_failure_ready() { resolution(false, "failed", false, false).await; }
#[tokio::test]
async fn cooperative_child_resume_failure_leased() { resolution(true, "failed", false, false).await; }
#[tokio::test]
async fn cooperative_child_resume_cancellation_ready() { resolution(false, "cancelled", false, false).await; }
#[tokio::test]
async fn cooperative_child_resume_cancellation_leased() { resolution(true, "cancelled", false, false).await; }
#[tokio::test]
async fn cooperative_child_resume_missing_wait_stays_missing() { resolution(true, "completed", true, false).await; }
#[tokio::test]
async fn cooperative_child_resume_sqlite_write_failure_rolls_back_resolution_and_claim() { resolution(true, "failed", false, true).await; }
