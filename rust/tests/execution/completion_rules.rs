use super::*;

fn path(task: &Value) -> String {
    format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap())
}

/// The refusal's first message and its field errors.
async fn refused(app: &Router, task: &Value, body: Value) -> (String, Value) {
    let (status, response) = request(app, "POST", &path(task), body).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{response}");
    assert_eq!(response["reason"], "validation_failed", "{response}");
    assert_eq!(response["protocol_version"], "1.20", "{response}");
    (response["message"].as_str().unwrap().to_owned(), response["errors"].clone())
}

// Published PHP 2.11.1 checks a workflow task completion against
// `WorkflowTaskCompletionValidation::rules` before any guard, ownership
// included, and turns digit strings in its integer fields into integers. Each
// expectation below is what the published image answered.
#[tokio::test]
async fn completions_follow_php_s_transport_rules() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!(["a"])).await;
    start(&app, "rules").await;
    let task = poll(&app, "worker", "workflow").await;
    let timer = json!({"type":"start_timer","delay_seconds":3600});

    // The rules come before the lease owner is checked.
    let (message, _) = refused(&app, &task, json!({"lease_owner":"someone-else",
        "workflow_task_attempt":task["workflow_task_attempt"],"commands":[{"type":5}]})).await;
    assert_eq!(message, "The commands.0.type field must be a string.");
    let (message, errors) = refused(&app, &task, json!({"workflow_task_attempt":"x","commands":[timer]})).await;
    assert_eq!(message, "The lease owner field is required. (and 1 more error)");
    assert_eq!(errors["workflow_task_attempt"], json!(["The workflow task attempt field must be an integer."]));
    for (commands, expected) in [
        (json!([]), "The commands field is required."),
        (json!("x"), "The commands field must be an array."),
        (json!(["x"]), "The commands.0.type field is required."),
        (json!([{"type":"schedule_activity","activity_type":"a","retry_policy":{"max_attempts":0,"backoff_seconds":[1,"x",-1],
            "non_retryable_error_types":[5]}}]), "The commands.0.retry_policy.max_attempts field must be at least 1. (and 3 more errors)"),
        (json!([{"type":"complete_workflow","principal_subject":"x","principal_roles":null}]),
            "The commands.0.principal_subject field is prohibited."),
        (json!([{"type":"start_timer","delay_seconds":1,"cancellation_cleanup":{"scope_id":"a"}}]),
            "The commands.0.cancellation_cleanup.request_id field is required when commands.0.cancellation_cleanup is present. (and 1 more error)"),
        (json!([{"type":"schedule_activity","activity_type":"a","execution_mode":"remote"}]),
            "The selected commands.0.execution_mode is invalid."),
        (json!([{"type":"start_timer","delay_seconds":"x"},{"type":"complete_workflow","message":[]}]),
            "The commands.0.delay_seconds field must be an integer. (and 1 more error)"),
    ] {
        let (message, _) = refused(&app, &task, completion(&task, commands)).await;
        assert_eq!(message, expected);
    }
    let (message, errors) = refused(&app, &task, json!({"lease_owner":task["lease_owner"],
        "workflow_task_attempt":task["workflow_task_attempt"],"commands":[timer],"sticky_cache":{"worker_id":"w"}})).await;
    assert_eq!(message, "The sticky cache.workflow id field is required when sticky cache is present. (and 3 more errors)");
    assert_eq!(errors["sticky_cache.ttl_seconds"], json!(["The sticky cache.ttl seconds field is required when sticky cache is present."]));

    // Digit strings are integers once the rules pass.
    let (status, body) = request(&app, "POST", &path(&task), json!({"lease_owner":task["lease_owner"],
        "workflow_task_attempt":task["workflow_task_attempt"].to_string(),"commands":[{"type":"start_timer","delay_seconds":"7"}]})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    runtime.close().await;
    database.remove().await;
}

// PHP's command normalizer takes a bare string payload as the Avro blob
// itself, and continue-as-new arguments that are plain values as no
// arguments, so the next run keeps the current input.
#[tokio::test]
async fn bare_payload_strings_and_plain_continue_as_new_arguments_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!(["a"])).await;
    let blob = envelope(Payload::Array(vec![Payload::String("x".into())]))["blob"].clone();

    start(&app, "bare-result").await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":blob}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (_, described) = request(&app, "GET", "/api/workflows/bare-result", Value::Null).await;
    assert_eq!(described["output_envelope"], json!({"codec":"avro","blob":blob}), "{described}");

    start(&app, "bare-arguments").await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task,
        json!([{"type":"schedule_activity","activity_type":"a","arguments":blob}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let activity = poll(&app, "worker", "activity").await;
    assert_eq!(activity["arguments"], json!({"codec":"avro","blob":blob}), "{activity}");

    let started = start(&app, "plain-continue").await;
    let (_, original) = request(&app, "GET", "/api/workflows/plain-continue", Value::Null).await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"continue_as_new","arguments":[1,"two"]}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (_, continued) = request(&app, "GET", "/api/workflows/plain-continue", Value::Null).await;
    assert_ne!(continued["run_id"], started["run_id"], "{continued}");
    assert_eq!(continued["input_envelope"], original["input_envelope"], "{continued}");
    let task = poll(&app, "worker", "workflow").await;
    let (status, refusal) = finish_task(&app, &task, json!([{"type":"continue_as_new","arguments":5}])).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refusal}");
    assert_eq!(refusal["errors"]["commands.0.arguments"],
        json!(["Workflow task command field [arguments] must be a string or a payload envelope when provided."]));
    runtime.close().await;
    database.remove().await;
}

// PHP's executor leaves a run that a completed task did not close waiting,
// even when the task recorded only a version marker.
#[tokio::test]
async fn a_task_that_records_only_markers_leaves_the_run_waiting_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    start(&app, "marker-only").await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task,
        json!([{"type":"record_version_marker","change_id":"only","version":1,"min_supported":1,"max_supported":1}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["run_status"], "waiting", "{body}");
    let (_, described) = request(&app, "GET", "/api/workflows/marker-only", Value::Null).await;
    assert_eq!(described["status"], "waiting", "{described}");
    runtime.close().await;
    database.remove().await;
}
