use super::*;

async fn observe(app: &Router, namespace: &str, path: &str) -> (StatusCode, Value) {
    let response = app.clone().oneshot(Request::builder().uri(path)
        .header("authorization", "Bearer test-token")
        .header("x-namespace", namespace)
        .header("x-durable-workflow-control-plane-version", "2")
        .body(Body::empty()).unwrap()).await.unwrap();
    let status = response.status();
    let bytes = to_bytes(response.into_body(), 4 * 1024 * 1024).await.unwrap();
    let body = if bytes.is_empty() { Value::Null } else { serde_json::from_slice(&bytes).unwrap() };
    (status, body)
}

#[tokio::test]
async fn run_observation_reads_current_and_selected_metadata_without_hydration_or_repair() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "observation-named").await;
    for (namespace, name) in [("default", "observation-default"), ("observation-named", "observation-named-run")] {
        let original = namespaces::start_scoped(&app, namespace, name).await;
        let run = original["run_id"].as_str().unwrap();
        let history_path = format!("/api/workflows/{name}/runs/{run}/history");
        let before = namespaces::scoped(&app, namespace, "GET", &history_path, Value::Null).await;
        for path in [format!("/api/workflows/{name}/observation"), format!("/api/workflows/{name}/runs/{run}/observation")] {
            let (status, body) = observe(&app, namespace, &path).await;
            assert_eq!(status, StatusCode::OK, "{body}");
            assert_eq!(body["control_plane"]["operation"], "observe_workflow");
            assert_eq!(body["workflow_id"], name);
            assert_eq!(body["run_id"], run);
            assert_eq!(body["selected_run_id"], run);
            assert_eq!(body["current_run_id"], run);
            assert_eq!(body["is_current_run"], true);
            assert_eq!(body["namespace"], namespace);
            assert_eq!(body["read_mode"], "bounded");
            assert_eq!(body["history_audit"], "not_evaluated");
            assert_eq!(body["current_run_audit"], "not_evaluated");
            assert_eq!(body["summary_state"], "unavailable");
            assert!(body["history_event_count"].is_null());
            assert_eq!(body["current_waits"]["state"], "unavailable");
            assert_eq!(body["current_waits"]["unavailable_reason"], "current_wait_projection_unavailable");
            assert_eq!(body["history"]["events"], before.1["events"]);
            assert_eq!(body["history"]["has_more"], false);
            assert!(body["history"]["next_page_token"].is_null());
            assert_eq!(body["search_attributes"], json!([]));
            for field in ["arguments", "input", "output", "memo", "cancellation_cascade"] {
                assert!(body.get(field).is_none(), "Unexpected hydrated field {field}");
            }
        }
        assert_eq!(namespaces::scoped(&app, namespace, "GET", &history_path, Value::Null).await, before);
    }
    database.execute("UPDATE workflow_instances SET current_run_id=(SELECT id FROM workflow_runs WHERE workflow_instance_id='observation-named-run') WHERE id='observation-default'").await;
    let current = observe(&app, "default", "/api/workflows/observation-default/observation").await;
    assert_eq!(current.0, StatusCode::NOT_FOUND);
    assert_eq!(current.1["reason"], "current_run_observation_unavailable");
    let original = request(&app, "GET", "/api/workflows/observation-named-run", Value::Null).await;
    assert_eq!(original.0, StatusCode::NOT_FOUND);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_history_retains_the_original_ceiling_and_pruned_state() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "observation-worker", json!(["echo"]), json!([])).await;
    let original = start(&app, "observation-window").await;
    let task = poll(&app, "observation-worker", "workflow").await;
    assert_eq!(finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await.0, StatusCode::OK);
    let run = original["run_id"].as_str().unwrap();
    let path = format!("/api/workflows/observation-window/runs/{run}/observation");
    let first = observe(&app, "default", &format!("{path}?history_page_size=1")).await;
    assert_eq!(first.0, StatusCode::OK, "{}", first.1);
    assert_eq!(first.1["history"]["returned_count"], 1);
    assert_eq!(first.1["current_waits"]["state"], "available");
    assert_eq!(first.1["current_waits"]["total_count"], 0);
    let ceiling = first.1["history"]["through_sequence"].as_i64().unwrap();
    let token = first.1["history"]["next_page_token"].as_str().unwrap();
    // A constructed later row checks the page boundary, not post-close admission.
    database.execute("INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,recorded_at) SELECT 'observation-later',id,last_event_sequence+1,'SignalReceived','{}','2026-10-15 00:00:00' FROM workflow_runs WHERE workflow_instance_id='observation-window'").await;
    let encoded: String = token.bytes().map(|byte| format!("%{byte:02X}")).collect();
    let next = observe(&app, "default", &format!("{path}?history_page_token={encoded}")).await;
    assert_eq!(next.0, StatusCode::OK, "{}", next.1);
    assert_eq!(next.1["history"]["through_sequence"], ceiling);
    assert!(next.1["history"]["events"].as_array().unwrap().iter().all(|event| event["sequence"].as_i64().unwrap() <= ceiling));
    assert!(next.1["history"]["next_page_token"].is_null());
    for suffix in ["history_page_size=0", "history_page_size=1001", "history_page_token=invalid"] {
        assert_eq!(observe(&app, "default", &format!("{path}?{suffix}")).await.0, StatusCode::UNPROCESSABLE_ENTITY);
    }
    database.execute("UPDATE workflow_runs SET details_pruned_at='2026-10-15 00:00:00' WHERE workflow_instance_id='observation-window'").await;
    database.execute("DELETE FROM workflow_history_events WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE workflow_instance_id='observation-window')").await;
    let pruned = observe(&app, "default", &path).await;
    assert_eq!(pruned.0, StatusCode::OK);
    assert_eq!(pruned.1["history"]["details_state"], "pruned");
    assert_eq!(pruned.1["history"]["returned_count"], 0);
    assert_eq!(pruned.1["current_waits"]["state"], "pruned");
    runtime.close().await;
    database.remove().await;
}
