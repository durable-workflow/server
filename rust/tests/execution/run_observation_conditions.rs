use super::*;

async fn register_observer(app: &Router, namespace: &str) {
    let result = namespaces::scoped(app, namespace, "POST", "/api/worker/register", json!({
        "worker_id":"condition-observer","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[],
        "workflow_command_contracts":{"echo":{"signals":["payload"],"signal_contracts":[{
            "name":"payload","parameters":[{"name":"value","position":0,"type":"array",
                "required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}
    })).await;
    assert_eq!(result.0, StatusCode::CREATED, "{}", result.1);
}

async fn claim(app: &Router, namespace: &str) -> Value {
    let result = namespaces::scoped(app, namespace, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"condition-observer","task_queue":"test","timeout_seconds":0})).await;
    assert_eq!(result.0, StatusCode::OK, "{}", result.1);
    assert!(result.1["task"].is_object(), "{}", result.1);
    result.1["task"].clone()
}

async fn finish(app: &Router, namespace: &str, task: &Value, commands: Value) -> (StatusCode, Value) {
    namespaces::scoped(app, namespace, "POST",
        &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        completion(task, commands)).await
}

async fn read(app: &Router, namespace: &str, run: &Value, suffix: &str) -> Value {
    let result = namespaces::scoped(app, namespace, "GET",
        &format!("/api/workflows/{}/runs/{}/{suffix}", run["workflow_id"].as_str().unwrap(), run["run_id"].as_str().unwrap()),
        Value::Null).await;
    assert_eq!(result.0, StatusCode::OK, "{}", result.1);
    result.1
}

async fn signal(app: &Router, namespace: &str, run: &Value) {
    let result = namespaces::scoped(app, namespace, "POST",
        &format!("/api/workflows/{}/runs/{}/signal/payload", run["workflow_id"].as_str().unwrap(), run["run_id"].as_str().unwrap()),
        json!({"input":envelope(Payload::Array(vec![Payload::Array(vec![Payload::Long(9007199254740993)])]))})).await;
    assert_eq!(result.0, StatusCode::ACCEPTED, "{}", result.1);
}

fn open(occurrence: &str) -> Value {
    json!([{"type":"open_condition_wait","condition_key":"ready 日本語",
        "condition_definition_fingerprint":format!("sha256:{}", "a".repeat(64)),
        "condition_wait_occurrence_id":occurrence}])
}

fn complete() -> Value {
    json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
}

// Inspect persisted resolutions as well as the public current-wait filter.
async fn stored(database: &TestDatabase) -> Vec<(String, String, String, String, String)> {
    let query = "SELECT wait_id,status,source_status,summary,resume_source_kind FROM workflow_run_waits WHERE kind='condition' ORDER BY position,wait_id";
    macro_rules! rows {
        ($pool:expr) => {{
            let pool = $pool;
            let rows = sqlx::query_as(query).fetch_all(&pool).await.unwrap();
            pool.close().await;
            rows
        }};
    }
    match database {
        TestDatabase::Sqlite(dir) => rows!(sqlx::SqlitePool::connect(&format!("sqlite://{}",dir.path().join("runtime.sqlite").display())).await.unwrap()),
        TestDatabase::Postgres { options, .. } => rows!(sqlx::postgres::PgPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap()),
        TestDatabase::MySql { options, .. } => rows!(sqlx::mysql::MySqlPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap()),
    }
}

#[tokio::test]
async fn run_observation_conditions_follow_authored_occurrences_across_cold_nodes() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "condition-observation").await;
    let mut originals = Vec::new();
    for namespace in ["default", "condition-observation"] {
        register_observer(&app, namespace).await;
        let run = namespaces::start_scoped(&app, namespace, "condition-original").await;
        let task = claim(&app, namespace).await;
        let refused_history = read(&app, namespace, &run, "history").await;
        let mut timeout = open("occurrence-0");
        timeout[0]["timeout_seconds"] = json!(1);
        assert_eq!(finish(&app, namespace, &task, timeout).await.0, StatusCode::UNPROCESSABLE_ENTITY);
        assert_eq!(read(&app, namespace, &run, "history").await, refused_history);
        let result = finish(&app, namespace, &task, open("occurrence-0")).await;
        assert_eq!(result.0, StatusCode::OK, "{}", result.1);
        assert_eq!(finish(&app, namespace, &task, open("occurrence-0")).await.1["recorded"], false);
        let history = read(&app, namespace, &run, "history").await;
        let observation = read(&app, namespace, &run, "observation").await;
        assert_eq!(observation["current_waits"]["returned_count"], 1, "{observation}");
        let wait = observation["current_waits"]["waits"][0].clone();
        assert_eq!(wait["id"], history["events"][2]["payload"]["condition_wait_id"]);
        assert_eq!(wait["kind"], "condition");
        assert_eq!(wait["target_name"], "ready 日本語");
        assert_eq!(wait["dependency_type"], "condition");
        assert_eq!(wait["reason"], "Waiting for condition.");
        assert_eq!(wait["state"], "resume_time_unknown");
        assert_eq!(wait["task_metadata_state"], "unavailable");
        for field in ["dependency_id", "task_id", "deadline_at", "next_scheduled_resume_at", "unavailable_reason"] {
            assert!(wait[field].is_null(), "{field}: {wait}");
        }
        assert_eq!(read(&app, namespace, &run, "history").await, history);
        originals.push((namespace, run, wait, history));
    }
    runtime.close().await;
    let (a, b) = tokio::join!(database.open(), database.open());
    let (a, b) = (a.unwrap(), b.unwrap());
    let (app_a, app_b) = (router(a.clone()), router(b.clone()));
    for (namespace, run, wait, prefix) in originals {
        assert_eq!(read(&app_b, namespace, &run, "observation").await["current_waits"]["waits"][0], wait);
        signal(&app_a, namespace, &run).await;
        // Receipt is external input; the worker has not yet satisfied the condition.
        assert_eq!(read(&app_b, namespace, &run, "observation").await["current_waits"]["waits"][0], wait);
        let task = claim(&app_b, namespace).await;
        let result = finish(&app_a, namespace, &task, open("occurrence-1")).await;
        assert_eq!(result.0, StatusCode::OK, "{}", result.1);
        assert_eq!(finish(&app_b, namespace, &task, open("occurrence-1")).await.1["recorded"], false);
        let observation = read(&app_b, namespace, &run, "observation").await;
        assert_eq!(observation["current_waits"]["returned_count"], 1);
        let next = &observation["current_waits"]["waits"][0];
        assert_ne!(next["id"], wait["id"]);
        assert_eq!(next["target_name"], wait["target_name"]);
        let rows = stored(&database).await;
        let prior = rows.iter().find(|row| row.0 == wait["id"].as_str().unwrap()).unwrap();
        assert_eq!((&prior.1, &prior.2, &prior.3, &prior.4), (&"resolved".into(), &"satisfied".into(), &"Condition satisfied.".into(), &"external_input".into()));
        signal(&app_a, namespace, &run).await;
        let task = claim(&app_b, namespace).await;
        let result = finish(&app_a, namespace, &task, complete()).await;
        assert_eq!(result.0, StatusCode::OK, "{}", result.1);
        let history = read(&app_b, namespace, &run, "history").await;
        assert_eq!(&history["events"].as_array().unwrap()[..3], prefix["events"].as_array().unwrap().as_slice());
        for event_type in ["ConditionWaitOpened", "ConditionWaitSatisfied"] {
            assert_eq!(history["events"].as_array().unwrap().iter().filter(|event| event["event_type"] == event_type).count(), 2);
        }
        assert_eq!(read(&app_a, namespace, &run, "observation").await["current_waits"]["returned_count"], 0);
        assert_eq!(read(&app_a, namespace, &run, "history").await, history);
    }
    let rows = stored(&database).await;
    assert_eq!(rows.len(), 4);
    assert!(rows.iter().all(|row| row.1 == "resolved" && row.2 == "satisfied"));
    a.close().await;
    b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_conditions_missing_projection_is_not_recreated() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_observer(&app, "default").await;
    let run = start(&app, "condition-missing").await;
    let task = claim(&app, "default").await;
    assert_eq!(finish(&app, "default", &task, open("first")).await.0, StatusCode::OK);
    assert_eq!(stored(&database).await.len(), 1);
    database.execute("DELETE FROM workflow_run_waits").await;
    let observation = read(&app, "default", &run, "observation").await;
    assert_eq!(observation["current_waits"]["unavailable_reason"], "current_wait_projection_unavailable");
    signal(&app, "default", &run).await;
    assert!(stored(&database).await.is_empty());
    let task = claim(&app, "default").await;
    assert_eq!(finish(&app, "default", &task, open("next")).await.0, StatusCode::OK);
    let rows = stored(&database).await;
    assert_eq!(rows.len(), 1);
    assert_eq!(rows[0].1, "open");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_conditions_projection_failure_rolls_back_open_and_application() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_observer(&app, "default").await;
    let run = start(&app, "condition-rollback").await;
    let task = claim(&app, "default").await;
    let history = read(&app, "default", &run, "history").await;
    database.execute("CREATE TRIGGER reject_condition_insert BEFORE INSERT ON workflow_run_waits BEGIN SELECT RAISE(ABORT, 'condition projection insert failed'); END").await;
    assert_eq!(finish(&app, "default", &task, open("first")).await.0, StatusCode::SERVICE_UNAVAILABLE);
    assert_eq!(read(&app, "default", &run, "history").await, history);
    database.execute("DROP TRIGGER reject_condition_insert").await;
    assert_eq!(finish(&app, "default", &task, open("first")).await.0, StatusCode::OK);
    signal(&app, "default", &run).await;
    let task = claim(&app, "default").await;
    let history = read(&app, "default", &run, "history").await;
    database.execute("CREATE TRIGGER reject_condition_update BEFORE UPDATE ON workflow_run_waits WHEN OLD.kind='condition' BEGIN SELECT RAISE(ABORT, 'condition projection update failed'); END").await;
    assert_eq!(finish(&app, "default", &task, complete()).await.0, StatusCode::SERVICE_UNAVAILABLE);
    assert_eq!(read(&app, "default", &run, "history").await, history);
    assert_eq!(stored(&database).await[0].1, "open");
    database.execute("DROP TRIGGER reject_condition_update").await;
    assert_eq!(finish(&app, "default", &task, complete()).await.0, StatusCode::OK);
    assert_eq!(stored(&database).await[0].1, "resolved");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_conditions_cancellation_closes_the_persisted_wait() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_observer(&app, "default").await;
    let run = start(&app, "condition-cancel").await;
    let task = claim(&app, "default").await;
    assert_eq!(finish(&app, "default", &task, open("first")).await.0, StatusCode::OK);
    let cancelled = request(&app, "POST", &format!("/api/workflows/condition-cancel/runs/{}/cancel", run["run_id"].as_str().unwrap()), json!({"reason":"stop condition"})).await;
    assert_eq!(cancelled.0, StatusCode::OK, "{}", cancelled.1);
    let rows = stored(&database).await;
    assert_eq!(rows.len(), 1);
    assert_eq!((&rows[0].1, &rows[0].2, &rows[0].3), (&"cancelled".into(), &"cancelled".into(), &"Condition wait ended when the run was cancelled.".into()));
    assert_eq!(read(&app, "default", &run, "observation").await["current_waits"]["returned_count"], 0);
    runtime.close().await;
    database.remove().await;
}
