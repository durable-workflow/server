use super::*;

// Server #519: guarded worker build and task queue retirement. A preview
// counts live dependencies; the action repeats the count in the transition
// that records retirement and refuses while any remain.

const V1: &str = "/api/deployments/default/test@v1";
const V2: &str = "/api/deployments/default/test@v2";

async fn register_on(app: &Router, worker: &str, queue: &str, build: Option<&str>, workflows: Value, activities: Value) -> Value {
    let mut body = json!({"worker_id":worker,"task_queue":queue,"runtime":"php",
        "supported_workflow_types":workflows,"supported_activity_types":activities});
    if let Some(build) = build {
        body["build_id"] = json!(build);
    }
    let (status, body) = request(app, "POST", "/api/worker/register", body).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    body
}

async fn poll_on(app: &Router, worker: &str, queue: &str, kind: &str) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/worker/{kind}-tasks/poll"),
        json!({"worker_id":worker,"task_queue":queue,"timeout_seconds":0})).await
}

async fn start_on(app: &Router, workflow: &str, queue: &str) -> (StatusCode, Value) {
    request(app, "POST", "/api/workflows", json!({"workflow_id":workflow,"workflow_type":"echo","task_queue":queue,
        "input":envelope(Payload::Array(vec![]))})).await
}

async fn inspect(app: &Router, path: &str) -> Value {
    let (status, body) = request(app, "GET", path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body
}

async fn pin_of(app: &Router, workflow: &str) -> Value {
    request(app, "GET", &format!("/api/workflows/{workflow}"), Value::Null).await.1["compatibility"].clone()
}

fn blocker<'a>(view: &'a Value, kind: &str) -> &'a Value {
    view["blockers"].as_array().unwrap().iter().find(|blocker| blocker["kind"] == kind)
        .unwrap_or_else(|| panic!("no {kind} blocker: {view}"))
}

// An open run whose task queue was never recorded, as an older import left it.
async fn clear_queue(database: &TestDatabase, run_id: &str) {
    const SQL: &str = "UPDATE workflow_runs SET queue=NULL WHERE id=$1";
    match database {
        TestDatabase::Sqlite(directory) => {
            let pool = sqlx::SqlitePool::connect(&format!("sqlite://{}", directory.path().join("runtime.sqlite").display())).await.unwrap();
            sqlx::query(SQL).bind(run_id).execute(&pool).await.unwrap();
            pool.close().await;
        }
        TestDatabase::Postgres { options, .. } => {
            let pool = sqlx::postgres::PgPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
            sqlx::query(SQL).bind(run_id).execute(&pool).await.unwrap();
            pool.close().await;
        }
        TestDatabase::MySql { options, .. } => {
            let pool = sqlx::mysql::MySqlPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
            sqlx::query("UPDATE workflow_runs SET queue=NULL WHERE id=?").bind(run_id).execute(&pool).await.unwrap();
            pool.close().await;
        }
    }
}

async fn promote(app: &Router, build: &str) {
    let (status, body) = request(app, "POST", "/api/task-queues/test/build-ids/promote", json!({"build_id":build})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
}

#[tokio::test]
async fn a_parked_old_build_run_blocks_retirement_until_it_closes() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_on(&app, "v1-worker", "test", Some("v1"), json!(["echo"]), json!([])).await;
    promote(&app, "v1").await;
    assert_eq!(start_on(&app, "parked", "test").await.0, StatusCode::CREATED);
    assert_eq!(pin_of(&app, "parked").await, "v1");
    // The old build parks its run on a long timer.
    let (_, polled) = poll_on(&app, "v1-worker", "test", "workflow").await;
    assert_eq!(polled["task"]["workflow_id"], "parked", "{polled}");
    let (status, body) = finish_task(&app, &polled["task"], json!([{"type":"start_timer","delay_seconds":3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    // The same build id on another queue is a separate deployment.
    register_on(&app, "other-v1", "other", Some("v1"), json!(["echo"]), json!([])).await;
    assert_eq!(start_on(&app, "elsewhere", "other").await.0, StatusCode::CREATED);
    assert_eq!(pin_of(&app, "elsewhere").await, "v1");

    // The promoted new fleet is healthy and takes new starts.
    register_on(&app, "v2-worker", "test", Some("v2"), json!(["echo"]), json!([])).await;
    promote(&app, "v2").await;
    assert_eq!(start_on(&app, "fresh", "test").await.0, StatusCode::CREATED);
    assert_eq!(pin_of(&app, "fresh").await, "v2");

    let preview = inspect(&app, &format!("{V1}/retirement")).await;
    assert_eq!((preview["retirable"].clone(), preview["reason"].clone(), preview["scope"].clone()),
        (json!(false), json!("live_dependencies"), json!("build")), "{preview}");
    assert_eq!((preview["observation"]["status"].clone(), preview["observation"]["atomic_with_action"].clone()),
        (json!("complete"), json!(false)), "{preview}");
    assert_eq!(preview["counts"]["open_runs"], 1, "{preview}");
    let runs = blocker(&preview, "open_runs");
    assert_eq!((runs["waiting_runs"].clone(), runs["sample"][0]["workflow_id"].clone(), runs["sample"][0]["build_id"].clone()),
        (json!(1), json!("parked"), json!("v1")), "{preview}");
    assert!(runs["recovery"].as_str().unwrap().contains("Keep a worker"), "{preview}");
    assert_eq!(preview["deployment"]["name"], "default/test@v1");
    let blocked_revision = preview["observation"]["inventory_revision"].as_str().unwrap().to_owned();

    let (status, refused) = request(&app, "POST", &format!("{V1}/retire"), Value::Null).await;
    assert_eq!(status, StatusCode::CONFLICT, "{refused}");
    for (field, expected) in [("reason", json!("live_dependencies")), ("outcome", json!("rejected_live_dependencies")),
        ("command_status", json!("rejected")), ("changed", json!(false)), ("retirable", json!(false))] {
        assert_eq!(refused[field], expected, "{field}: {refused}");
    }
    assert_eq!(refused["observation"]["atomic_with_action"], true, "{refused}");
    assert_ne!(refused["deployment"]["state"], "drained", "{refused}");
    // Nothing changed: the old build's worker still claims.
    assert_eq!(poll_on(&app, "v1-worker", "test", "workflow").await.0, StatusCode::OK);
    // The build new starts are pinned to is never retired.
    let (status, selected) = request(&app, "POST", &format!("{V2}/retire"), json!({})).await;
    assert_eq!((status, selected["reason"].clone()), (StatusCode::CONFLICT, json!("selected_for_new_starts")), "{selected}");

    // Close the parked run; its recorded pin and history stay.
    let (status, terminated) = request(&app, "POST", "/api/workflows/parked/terminate", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{terminated}");
    assert_eq!(pin_of(&app, "parked").await, "v1");
    let clear = inspect(&app, &format!("{V1}/retirement")).await;
    assert_eq!((clear["retirable"].clone(), clear["reason"].clone()), (json!(true), Value::Null), "{clear}");
    assert_eq!(clear["blockers"], json!([]), "{clear}");

    // A retire bound to the stale inspection is refused, then the current one succeeds.
    let (status, stale) = request(&app, "POST", &format!("{V1}/retire"), json!({"expected_inventory_revision":blocked_revision})).await;
    assert_eq!((status, stale["reason"].clone()), (StatusCode::CONFLICT, json!("inventory_changed")), "{stale}");
    let (status, invalid) = request(&app, "POST", &format!("{V1}/retire"), json!({"expected_inventory_revision":7})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    let (status, retired) = request(&app, "POST", &format!("{V1}/retire"),
        json!({"expected_inventory_revision":clear["observation"]["inventory_revision"]})).await;
    assert_eq!(status, StatusCode::OK, "{retired}");
    for (field, expected) in [("outcome", json!("retired")), ("changed", json!(true)), ("build_ids", json!(["v1"]))] {
        assert_eq!(retired[field], expected, "{field}: {retired}");
    }
    assert_eq!((retired["deployment"]["state"].clone(), retired["deployment"]["accepts_new_work"].clone()),
        (json!("drained"), json!(false)), "{retired}");
    assert!(retired["retired_at"].as_str().unwrap().ends_with('Z'), "{retired}");

    // The retired build's workers claim nothing and stay draining when they
    // register again; new starts stay on the promoted build; the build-id
    // view shows the terminal intent; promotion is refused.
    let (status, draining) = poll_on(&app, "v1-worker", "test", "workflow").await;
    assert_eq!((status, draining["reason"].clone()), (StatusCode::CONFLICT, json!("worker_draining")), "{draining}");
    assert_eq!(register_on(&app, "v1-worker", "test", Some("v1"), json!(["echo"]), json!([])).await["status"], "draining");
    assert_eq!(start_on(&app, "after", "test").await.0, StatusCode::CREATED);
    assert_eq!(pin_of(&app, "after").await, "v2");
    let builds = inspect(&app, "/api/task-queues/test/build-ids").await;
    let v1 = builds["build_ids"].as_array().unwrap().iter().find(|entry| entry["build_id"] == "v1").unwrap();
    assert_eq!(v1["drain_intent"], "drained", "{builds}");
    let (status, promoted) = request(&app, "POST", &format!("{V1}/promote"), Value::Null).await;
    assert_eq!(status, StatusCode::CONFLICT, "{promoted}");
    // The other queue's v1 deployment is untouched.
    assert_eq!(poll_on(&app, "other-v1", "other", "workflow").await.1["task"]["workflow_id"], "elsewhere");

    // Retirement survives a restart and repeats idempotently.
    runtime.close().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let reopened = inspect(&app, &format!("{V1}/retirement")).await;
    assert_eq!((reopened["retirable"].clone(), reopened["deployment"]["state"].clone()), (json!(true), json!("drained")), "{reopened}");
    assert_eq!(request(&app, "POST", &format!("{V1}/retire"), Value::Null).await.0, StatusCode::OK);
    assert_eq!(request(&app, "GET", "/api/deployments/default/test@v9/retirement", Value::Null).await.0, StatusCode::OK);
    let (status, unknown) = request(&app, "POST", "/api/deployments/default/test@v9/retire", Value::Null).await;
    assert_eq!((status, unknown["reason"].clone()), (StatusCode::CONFLICT, json!("unknown_deployment")), "{unknown}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_delayed_old_queue_activity_retry_blocks_queue_retirement() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "workflow-worker", json!(["echo"]), json!([])).await;
    register_on(&app, "old-activities", "old-acts", None, json!([]), json!(["charge"])).await;
    register_on(&app, "new-activities", "new-acts", None, json!([]), json!(["charge"])).await;
    let started = start(&app, "billing").await;
    let task = poll(&app, "workflow-worker", "workflow").await;
    assert_eq!(task["workflow_id"], "billing");
    let (status, body) = finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"charge","queue":"old-acts",
        "arguments":envelope(Payload::Array(vec![])),"retry_policy":{"max_attempts":3,"backoff_seconds":[2]}}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (_, first) = poll_on(&app, "old-activities", "old-acts", "activity").await;
    let first = first["task"].clone();
    assert_eq!(first["attempt_number"], 1, "{first}");
    let (status, failed) = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/fail", first["task_id"].as_str().unwrap()),
        json!({"lease_owner":"old-activities","activity_attempt_id":first["activity_attempt_id"],
            "failure":{"type":"RuntimeException","message":"declined","non_retryable":false}})).await;
    assert_eq!(status, StatusCode::OK, "{failed}");

    let preview = inspect(&app, "/api/task-queues/old-acts/retirement").await;
    assert_eq!((preview["retirable"].clone(), preview["reason"].clone(), preview["scope"].clone()),
        (json!(false), json!("live_dependencies"), json!("task_queue")), "{preview}");
    let retries = blocker(&preview, "delayed_tasks");
    assert_eq!((retries["count"].clone(), retries["activity_retries"].clone()), (json!(1), json!(1)), "{preview}");
    let activities = blocker(&preview, "open_activities");
    assert_eq!(activities["recorded_task_queues"], json!(["old-acts"]), "{preview}");
    assert_eq!(activities["sample"][0]["run_id"], started["run_id"], "{preview}");
    // The healthy new queue has no dependency, and an empty ready count
    // alone never made the old one retirable.
    assert_eq!(preview["counts"]["ready_tasks"], 0, "{preview}");
    assert_eq!(inspect(&app, "/api/task-queues/new-acts/retirement").await["retirable"], true);
    let (status, refused) = request(&app, "POST", "/api/task-queues/old-acts/retire", Value::Null).await;
    assert_eq!((status, refused["reason"].clone()), (StatusCode::CONFLICT, json!("live_dependencies")), "{refused}");

    // The retry comes due on the old queue, whose worker still claims it.
    let mut retry = Value::Null;
    for _ in 0..40 {
        tokio::time::sleep(std::time::Duration::from_millis(250)).await;
        let (status, polled) = poll_on(&app, "old-activities", "old-acts", "activity").await;
        assert_eq!(status, StatusCode::OK, "{polled}");
        if !polled["task"].is_null() {
            retry = polled["task"].clone();
            break;
        }
    }
    assert_eq!(retry["attempt_number"], 2, "{retry}");
    let (status, completed) = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/complete", retry["task_id"].as_str().unwrap()),
        json!({"lease_owner":"old-activities","activity_attempt_id":retry["activity_attempt_id"],"result":envelope(Payload::Long(1))})).await;
    assert_eq!(status, StatusCode::OK, "{completed}");

    let clear = inspect(&app, "/api/task-queues/old-acts/retirement").await;
    assert_eq!((clear["retirable"].clone(), clear["blockers"].clone()), (json!(true), json!([])), "{clear}");
    let (status, retired) = request(&app, "POST", "/api/task-queues/old-acts/retire", json!({})).await;
    assert_eq!((status, retired["outcome"].clone(), retired["build_ids"].clone()), (StatusCode::OK, json!("retired"), json!([null])), "{retired}");
    let (status, draining) = poll_on(&app, "old-activities", "old-acts", "activity").await;
    assert_eq!((status, draining["reason"].clone()), (StatusCode::CONFLICT, json!("worker_draining")), "{draining}");
    let (status, closed) = start_on(&app, "late", "old-acts").await;
    assert_eq!((status, closed["reason"].clone(), closed["routing_status"].clone()),
        (StatusCode::CONFLICT, json!("task_queue_draining"), json!("retired")), "{closed}");
    // The workflow's own queue is untouched and resumes it.
    assert_eq!(poll(&app, "workflow-worker", "workflow").await["workflow_id"], "billing");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn continued_chains_keep_their_pin_and_unattributed_runs_make_inventory_unavailable() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_on(&app, "v1-worker", "test", Some("v1"), json!(["echo"]), json!([])).await;
    promote(&app, "v1").await;
    assert_eq!(start_on(&app, "chain", "test").await.0, StatusCode::CREATED);
    let (_, polled) = poll_on(&app, "v1-worker", "test", "workflow").await;
    let (status, body) = finish_task(&app, &polled["task"], json!([{"type":"continue_as_new"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    register_on(&app, "v2-worker", "test", Some("v2"), json!(["echo"]), json!([])).await;
    promote(&app, "v2").await;

    // The continued run keeps the old pin and blocks its build.
    assert_eq!(pin_of(&app, "chain").await, "v1");
    let preview = inspect(&app, &format!("{V1}/retirement")).await;
    let runs = blocker(&preview, "open_runs");
    assert_eq!((runs["count"].clone(), runs["continued_runs"].clone(), runs["sample"][0]["run_number"].clone()),
        (json!(1), json!(1), json!(2)), "{preview}");
    assert_eq!(preview["counts"]["ready_tasks"], 1, "{preview}");

    // An open run with no recorded queue could depend on any queue.
    let started = start_on(&app, "orphan", "elsewhere").await.1;
    let orphan = json!({"run_id":started["run_id"]});
    clear_queue(&database, orphan["run_id"].as_str().unwrap()).await;
    let incomplete = inspect(&app, "/api/task-queues/test/retirement").await;
    assert_eq!((incomplete["observation"]["status"].clone(), incomplete["retirable"].clone()), (json!("incomplete"), json!(false)), "{incomplete}");
    assert_eq!(blocker(&incomplete, "unattributed_runs")["sample"][0]["workflow_id"], "orphan", "{incomplete}");
    let (status, refused) = request(&app, "POST", "/api/task-queues/test/retire", Value::Null).await;
    assert_eq!((status, refused["reason"].clone()), (StatusCode::CONFLICT, json!("inventory_incomplete")), "{refused}");
    let (status, _) = request(&app, "POST", "/api/workflows/orphan/terminate", json!({})).await;
    assert_eq!(status, StatusCode::OK);

    // The old build's worker completes the continued run; then v1 retires.
    let (_, polled) = poll_on(&app, "v1-worker", "test", "workflow").await;
    assert_eq!(polled["task"]["workflow_id"], "chain", "{polled}");
    let (status, body) = finish_task(&app, &polled["task"], json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, retired) = request(&app, "POST", &format!("{V1}/retire"), Value::Null).await;
    assert_eq!((status, retired["deployment"]["state"].clone()), (StatusCode::OK, json!("drained")), "{retired}");
    runtime.close().await;
    database.remove().await;
}
