use super::*;

async fn described(app: &Router, workflow: &str) -> Value {
    let (status, body) = request(app, "GET", &format!("/api/workflows/{workflow}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body
}

// Published PHP's describe tells operators and Waterline which actions a run
// accepts: signal, cancel, terminate and repair on the current open run,
// queries only through a query-capable worker, archive once closed.
#[tokio::test]
async fn describe_reports_php_actions_and_fields() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    start(&app, "acted").await;
    let open = described(&app, "acted").await;
    assert_eq!(
        open["actions"],
        json!({"can_signal":true,"can_query":false,"can_update":false,"can_cancel":true,
            "can_terminate":true,"can_repair":true,"can_archive":false})
    );
    assert_eq!(open["memo"], json!([]));
    assert_eq!(open["search_attributes"], json!([]));
    assert_eq!(open["updates_scope"], "selected_run");
    assert_eq!(open["updates"], json!([]));
    assert_eq!(open["compatibility_status"], "compatible");
    assert_eq!(open["compatibility_supported_in_fleet"], true);
    assert_eq!(open["payload_previews"], json!({"input_omitted":false,"output_omitted":false,"max_encoded_bytes":2097152}));
    assert!(open["business_key"].is_null() && open.get("business_key").is_some());
    assert!(open["last_progress_at"].as_str().unwrap().ends_with("+00:00"), "{open}");
    // A worker that cannot take query tasks gives no query route.
    let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"plain","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    assert_eq!(described(&app, "acted").await["actions"]["can_query"], false);
    let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"querying","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":["query_tasks"]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    assert_eq!(described(&app, "acted").await["actions"]["can_query"], true);
    let (status, body) = request(&app, "POST", "/api/workflows/acted/terminate", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(
        described(&app, "acted").await["actions"],
        json!({"can_signal":false,"can_query":false,"can_update":false,"can_cancel":false,
            "can_terminate":false,"can_repair":false,"can_archive":true})
    );
}

// The run list answers with PHP's run count and list entries.
#[tokio::test]
async fn run_list_matches_php_entries() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let started = start(&app, "listed").await;
    let (status, body) = request(&app, "GET", "/api/workflows/listed/runs", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["workflow_id"], "listed");
    assert_eq!(body["run_count"], 1);
    let entry = &body["runs"][0];
    assert_eq!(entry["run_id"], started["run_id"]);
    for (field, expected) in [
        ("run_number", json!(1)),
        ("workflow_type", json!("echo")),
        ("business_key", Value::Null),
        ("status", json!("pending")),
        ("status_bucket", json!("running")),
        ("is_terminal", json!(false)),
        ("is_current_run", json!(true)),
        ("task_queue", json!("test")),
        ("compatibility", Value::Null),
        ("compatibility_status", json!("compatible")),
        ("compatibility_supported_in_fleet", json!(true)),
        ("compatibility_fleet_reason", Value::Null),
        ("closed_at", Value::Null),
        ("memo", json!([])),
    ] {
        assert_eq!(entry[field], expected, "{field}: {entry}");
        assert!(entry.get(field).is_some(), "{field}");
    }
    assert!(entry.get("closed_reason").is_none(), "{entry}");
    assert_eq!(body["control_plane"]["operation"], "list_runs");
    assert_eq!(body["control_plane"]["run_count"], 1);
    let (status, missing) = request(&app, "GET", "/api/workflows/missing/runs", Value::Null).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "{missing}");
    assert_eq!(missing["message"], "Workflow not found.");
    assert_eq!(missing["control_plane"]["operation"], "list_runs");
}
