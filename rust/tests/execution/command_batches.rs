use super::*;

// Each expectation below is what published Server 2.11.1 answered for the
// same completion (`DefaultWorkflowTaskBridge::complete`).

fn kinds(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

fn first<'a>(history: &'a Value, kind: &str) -> &'a Value {
    history.as_array().unwrap().iter().find(|event| event["event_type"] == kind).unwrap_or_else(|| panic!("no {kind}"))
}

fn all<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

async fn task_for(app: &Router, worker: &str, workflow: &str) -> Value {
    for _ in 0..20 {
        let task = poll(app, worker, "workflow").await;
        if task["workflow_id"] == workflow {
            return task;
        }
    }
    panic!("no workflow task for {workflow}");
}

async fn current(app: &Router, workflow: &str) -> Value {
    let (status, body) = request(app, "GET", &format!("/api/workflows/{workflow}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body
}

fn activity() -> Value {
    json!({"type":"schedule_activity","activity_type":"a","arguments":envelope(Payload::Array(vec![]))})
}

fn child() -> Value {
    json!({"type":"start_child_workflow","workflow_type":"echo","arguments":envelope(Payload::Array(vec![]))})
}

async fn complete_activity(app: &Router, worker: &str) {
    let task = poll(app, worker, "activity").await;
    let (status, body) = request(app, "POST", &format!("/api/worker/activity-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"activity_attempt_id":task["activity_attempt_id"],"result":envelope(Payload::Null)})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
}

// `parseCommands` takes one terminal command anywhere in a batch and applies
// it after every other command. Two terminal commands are refused before
// anything is recorded.
#[tokio::test]
async fn a_terminal_command_applies_after_the_rest_of_its_batch_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!(["a"])).await;

    let started = start(&app, "terminal-first").await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))},
        {"type":"start_timer","delay_seconds":3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["run_status"], "completed", "{body}");
    // PHP reports the tasks the batch's commands created, and its protocol.
    assert_eq!(body["created_task_ids"].as_array().map(Vec::len), Some(1), "{body}");
    assert_eq!(body["protocol_version"], "1.20", "{body}");
    let order = kinds(&run_history(&app, &started["workflow_id"], &started["run_id"]).await);
    let timer = order.iter().position(|kind| kind == "TimerScheduled").expect("the timer is recorded");
    assert!(order.iter().position(|kind| kind == "WorkflowCompleted").expect("the run completes") > timer, "{order:?}");

    let started = start(&app, "fail-first").await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"fail_workflow","message":"boom"}, activity()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["run_status"], "failed", "{body}");
    let order = kinds(&run_history(&app, &started["workflow_id"], &started["run_id"]).await);
    let scheduled = order.iter().position(|kind| kind == "ActivityScheduled").expect("the activity is recorded");
    assert!(order.iter().position(|kind| kind == "WorkflowFailed").unwrap() > scheduled, "{order:?}");

    let started = start(&app, "two-terminals").await;
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))},
        {"type":"fail_workflow","message":"x"}])).await;
    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!(body["reason"], "invalid_commands", "{body}");
    assert_eq!(body["outcome"], "completed", "{body}");
    assert_eq!(body["recorded"], false, "{body}");
    assert!(body["run_id"].is_null(), "{body}");
    assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, before);
}

// A task may start several children alongside timers and activities, and a
// child's result wakes its parent even while another resume task waits.
#[tokio::test]
async fn children_start_alongside_other_open_work_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!(["a"])).await;
    let started = start(&app, "mixed-parent").await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([child(), child(), {"type":"start_timer","delay_seconds":3600}, activity()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["run_status"], "waiting", "{body}");
    assert_eq!(body["created_task_ids"].as_array().map(Vec::len), Some(4), "{body}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let children: Vec<String> = all(&history, "ChildRunStarted").iter()
        .map(|event| event["payload"]["child_workflow_instance_id"].as_str().unwrap().to_owned()).collect();
    assert_eq!(children.len(), 2, "{history}");
    assert_eq!(all(&history, "TimerScheduled").len(), 1);
    assert_eq!(all(&history, "ActivityScheduled").len(), 1);

    for id in &children {
        let task = task_for(&app, "worker", id).await;
        let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(2))}])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
    }
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(all(&history, "ChildRunCompleted").len(), 2, "{history}");
    let resumed = task_for(&app, "worker", "mixed-parent").await;
    assert!(resumed["task_id"].is_string(), "{resumed}");
}

// Continue-as-new leaves the predecessor's open activities, timers and
// commands applied before it in the same batch where they are, and closes it.
#[tokio::test]
async fn continue_as_new_leaves_open_work_with_the_predecessor_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!(["a"])).await;
    let started = start(&app, "continue-open").await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"start_timer","delay_seconds":3600}, activity(), activity()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    complete_activity(&app, "worker").await;
    let resumed = task_for(&app, "worker", "continue-open").await;
    let (status, body) = finish_task(&app, &resumed, json!([activity(), {"type":"continue_as_new"},
        {"type":"start_timer","delay_seconds":60}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["run_status"], "completed", "{body}");
    // The activity's and timer's tasks, then the continued run's first task.
    let created = body["created_task_ids"].as_array().unwrap();
    assert_eq!(created.len(), 3, "{body}");
    let order = kinds(&run_history(&app, &started["workflow_id"], &started["run_id"]).await);
    assert_eq!(order.last().map(String::as_str), Some("WorkflowContinuedAsNew"), "{order:?}");
    assert_eq!(order.iter().filter(|kind| *kind == "ActivityScheduled").count(), 3, "{order:?}");
    assert_eq!(order.iter().filter(|kind| *kind == "TimerScheduled").count(), 2, "{order:?}");
    let described = current(&app, "continue-open").await;
    assert_ne!(described["run_id"], started["run_id"], "{described}");
    assert_eq!(described["status"], "pending", "{described}");
    let next = task_for(&app, "worker", "continue-open").await;
    assert_eq!(next["run_id"], described["run_id"], "{next}");
    assert_eq!(next["task_id"], created[2], "{next}");
}

// A child that continues as new stays its parent's child: the parent records
// `ChildRunStarted` for the new run under the same call and a new link, and
// the new run's result resumes the parent.
#[tokio::test]
async fn a_child_that_continues_as_new_stays_its_parents_child_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let parent = start(&app, "continuing-parent").await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([child()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    let first_start = first(&history, "ChildRunStarted")["payload"].clone();
    let kid = first_start["child_workflow_instance_id"].as_str().unwrap().to_owned();

    let task = task_for(&app, "worker", &kid).await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"continue_as_new"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let continued = current(&app, &kid).await;
    assert_ne!(continued["run_id"], first_start["child_workflow_run_id"], "{continued}");
    let kid_history = run_history(&app, &json!(kid), &continued["run_id"]).await;
    let started = &first(&kid_history, "WorkflowStarted")["payload"];
    assert_eq!(started["parent_workflow_run_id"], parent["run_id"], "{started}");
    assert_eq!(started["parent_sequence"], first_start["sequence"], "{started}");
    assert_eq!(started["child_call_id"], first_start["child_call_id"], "{started}");
    assert_eq!(started["continued_from_run_id"], first_start["child_workflow_run_id"], "{started}");

    let history = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    let starts = all(&history, "ChildRunStarted");
    assert_eq!(starts.len(), 2, "{history}");
    let second_start = &starts[1]["payload"];
    assert_eq!(second_start["child_workflow_run_id"], continued["run_id"], "{second_start}");
    assert_eq!(second_start["child_run_number"], 2, "{second_start}");
    assert_eq!(second_start["child_call_id"], first_start["child_call_id"], "{second_start}");
    assert_ne!(second_start["workflow_link_id"], first_start["workflow_link_id"], "{second_start}");

    let task = task_for(&app, "worker", &kid).await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(3))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    let completed = &first(&history, "ChildRunCompleted")["payload"];
    assert_eq!(completed["child_workflow_run_id"], continued["run_id"], "{completed}");
    assert_eq!(completed["workflow_link_id"], second_start["workflow_link_id"], "{completed}");
    assert_eq!(completed["child_call_id"], first_start["child_call_id"], "{completed}");
    assert_eq!(completed["child_run_number"], 2, "{completed}");
    let resumed = task_for(&app, "worker", "continuing-parent").await;
    assert!(resumed["task_id"].is_string(), "{resumed}");
}

// A child that continues as new into another workflow type still resolves
// its parent's call, which keeps the type it started with.
#[tokio::test]
async fn a_child_continued_into_another_type_resolves_its_parent_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo", "other"]), json!([])).await;
    let parent = start(&app, "retyped-parent").await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([child()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    let kid = first(&history, "ChildRunStarted")["payload"]["child_workflow_instance_id"].as_str().unwrap().to_owned();
    let task = task_for(&app, "worker", &kid).await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"continue_as_new","workflow_type":"other"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let task = task_for(&app, "worker", &kid).await;
    assert_eq!(task["workflow_type"], "other", "{task}");
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(4))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    let completed = &first(&history, "ChildRunCompleted")["payload"];
    assert_eq!(completed["child_workflow_type"], "other", "{completed}");
    let resumed = task_for(&app, "worker", "retyped-parent").await;
    assert!(resumed["task_id"].is_string(), "{resumed}");
}

// `validateUpdateCommands`: an update completes alongside other commands, and
// an update task may leave its update for a later task.
#[tokio::test]
async fn an_update_completes_alongside_other_commands_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    update_worker(&app, "worker").await;
    let started = start(&app, "update-batch").await;
    open_update_wait(&app, "worker").await;
    let (status, body) = request(&app, "POST", "/api/workflows/update-batch/update/set",
        json!({"input":[1],"wait_for":"accepted"})).await;
    assert!(status.is_success(), "{body}");
    let update = body["update_id"].clone();

    let task = poll(&app, "worker", "workflow").await;
    assert_eq!(task["workflow_update_id"], update, "{task}");
    // The update task leaves its update open.
    let (status, body) = finish_task(&app, &task, json!([{"type":"start_timer","delay_seconds":3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let (status, body) = request(&app, "POST", "/api/workflows/update-batch/signal/finish",
        json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let task = poll(&app, "worker", "workflow").await;
    assert!(task["task_id"].is_string(), "{task}");
    let (status, body) = finish_task(&app, &task, json!([{"type":"start_timer","delay_seconds":60},
        {"type":"complete_update","update_id":update,"result":envelope(Payload::Long(5))},
        {"type":"start_timer","delay_seconds":120}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let order = kinds(&run_history(&app, &started["workflow_id"], &started["run_id"]).await);
    let completed = order.iter().position(|kind| kind == "UpdateCompleted").expect("the update completes");
    assert!(order[completed..].iter().any(|kind| kind == "TimerScheduled"), "{order:?}");
}

// `applyContinueAsNew` takes a new workflow type, which also becomes the
// class, and a new queue from the command.
#[tokio::test]
async fn continue_as_new_takes_a_new_type_and_queue_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo", "other"]), json!([])).await;
    let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"far","task_queue":"elsewhere",
        "runtime":"php","supported_workflow_types":["echo","other"],"supported_activity_types":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    start(&app, "rerouted").await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"continue_as_new","workflow_type":"other"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let described = current(&app, "rerouted").await;
    assert_eq!(described["workflow_type"], "other", "{described}");
    let next = task_for(&app, "worker", "rerouted").await;
    assert_eq!(next["workflow_type"], "other", "{next}");
    let history = run_history(&app, &json!("rerouted"), &described["run_id"]).await;
    let started = &first(&history, "WorkflowStarted")["payload"];
    assert_eq!(started["workflow_class"], "other", "{started}");

    let (status, body) = finish_task(&app, &next, json!([{"type":"continue_as_new","queue":"elsewhere"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, polled) = request(&app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"far","task_queue":"elsewhere","timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    assert_eq!(polled["task"]["workflow_id"], "rerouted", "{polled}");
}
