use super::*;

async fn visible(app: &Router, query: &str) -> Value {
    let response = app
        .clone()
        .oneshot(
            Request::builder()
                .uri(format!("/api/workflows?{query}"))
                .header("authorization", "Bearer test-token")
                .header("x-namespace", "default")
                .header("x-durable-workflow-control-plane-version", "2")
                .body(Body::empty())
                .unwrap(),
        )
        .await
        .unwrap();
    assert_eq!(
        response.status(),
        StatusCode::OK,
        "visibility listing endpoint"
    );
    serde_json::from_slice(&to_bytes(response.into_body(), 1024 * 1024).await.unwrap()).unwrap()
}

#[tokio::test]
async fn visibility_pages_filter_original_runs_and_preserve_cancelled_peer() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "visibility-worker",
        json!(["echo", "peer"]),
        json!([]),
    )
    .await;
    let root = start(&app, "visibility-root").await;
    let task = poll(&app, "visibility-worker", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &task,
            json!([{"type":"complete_workflow",
        "result":envelope(Payload::Long(9007199254740993))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let peer = request(
        &app,
        "POST",
        "/api/workflows",
        json!({"workflow_id":"visibility-root-pending",
        "workflow_type":"peer", "task_queue":"test", "input":envelope(Payload::Array(vec![]))}),
    )
    .await;
    assert_eq!(peer.0, StatusCode::CREATED);
    start(&app, "foreign-workflow").await;
    let first = visible(&app, "query=visibility-root&page_size=1").await;
    assert_eq!(first["workflow_count"], 1);
    assert_eq!(first["workflows"][0]["run_id"], peer.1["run_id"]);
    assert_eq!(first["workflows"][0]["status_bucket"], "running");
    assert_eq!(first["next_page_token"], "MQ==");
    let second = visible(
        &app,
        "query=visibility-root&page_size=1&next_page_token=MQ%3D%3D",
    )
    .await;
    assert_eq!(second["workflows"][0]["run_id"], root["run_id"]);
    assert_eq!(second["workflows"][0]["status_bucket"], "completed");
    assert_eq!(second["next_page_token"], Value::Null);
    assert_eq!(
        visible(
            &app,
            "query=visibility-root&status=running&workflow_type=peer"
        )
        .await["workflow_count"],
        1
    );
    assert_eq!(
        visible(
            &app,
            "query=visibility-root&status=running&workflow_type=echo"
        )
        .await["workflow_count"],
        0
    );
    assert_eq!(
        visible(
            &app,
            "query=visibility-root&status=completed&workflow_type=peer"
        )
        .await["workflow_count"],
        0
    );
    assert_eq!(
        visible(&app, "query=visibility-root&status=failed").await["workflow_count"],
        0
    );
    let cancelled = request(
        &app,
        "POST",
        &format!(
            "/api/workflows/visibility-root-pending/runs/{}/cancel",
            peer.1["run_id"].as_str().unwrap()
        ),
        json!({"reason":"visibility cleanup λ"}),
    )
    .await;
    assert_eq!(cancelled.0, StatusCode::OK);
    let failed = visible(&app, "query=visibility-root&status=failed").await;
    assert_eq!(failed["workflow_count"], 1);
    assert_eq!(failed["workflows"][0]["run_id"], peer.1["run_id"]);
    assert_eq!(failed["workflows"][0]["status"], "cancelled");
    assert_eq!(failed["workflows"][0]["is_terminal"], true);
    assert!(failed["workflows"][0]["closed_at"].is_string());
    let described = request(&app, "GET", "/api/workflows/visibility-root", Value::Null).await;
    assert_eq!(described.1["status_bucket"], "completed");
    assert_eq!(described.1["is_current_run"], true);
    assert_eq!(described.1["run_count"], 1);
    assert_eq!(described.1["control_plane"]["operation"], "describe");
    let selected = request(
        &app,
        "GET",
        &format!(
            "/api/workflows/visibility-root/runs/{}",
            root["run_id"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(selected.0, StatusCode::OK);
    assert_eq!(
        selected.1["control_plane"]["schema"],
        "durable-workflow.v2.control-plane-response"
    );
    assert_eq!(selected.1["control_plane"]["operation"], "describe_run");
    assert_eq!(selected.1["control_plane"]["run_id"], root["run_id"]);
    assert_eq!(
        selected.1["control_plane"]["contract"]["required_fields"],
        json!(["workflow_id"])
    );
    assert_eq!(
        selected.1["control_plane"]["contract"]["success_fields"],
        json!(["run_id"])
    );
    let history = request(
        &app,
        "GET",
        &format!(
            "/api/workflows/visibility-root/runs/{}/history?page_size=1",
            root["run_id"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(history.0, StatusCode::OK);
    assert_eq!(history.1["control_plane"]["operation"], "history");
    assert_eq!(history.1["control_plane"]["workflow_id"], "visibility-root");
    assert_eq!(history.1["control_plane"]["run_id"], root["run_id"]);
    assert_eq!(
        history.1["control_plane"]["next_page_token"],
        history.1["next_page_token"]
    );
    assert_eq!(
        history.1["control_plane"]["contract"]["required_fields"],
        json!(["workflow_id", "run_id"])
    );
    assert_eq!(
        history.1["control_plane"]["contract"]["success_fields"],
        json!(["next_page_token"])
    );
    assert_eq!(history.1["events"].as_array().unwrap().len(), 1);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn visibility_advertises_canonical_cli_contract_and_bounds_pages() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let cluster = request(&app, "GET", "/api/cluster/info", Value::Null).await;
    assert_eq!(
        cluster.1["control_plane"]["request_contract"]["operations"]["list"]["fields"]["status"]["canonical_values"],
        json!(["running", "completed", "failed"])
    );
    for status in ["pending", "waiting", "cancelled", "terminated", "unknown"] {
        let rejected = request(
            &app,
            "GET",
            &format!("/api/workflows?status={status}"),
            Value::Null,
        )
        .await;
        assert_eq!(rejected.0, StatusCode::UNPROCESSABLE_ENTITY);
        assert_eq!(rejected.1["reason"], "validation_failed");
        assert!(rejected.1["validation_errors"]["status"].is_array());
    }
    for size in ["0", "201", "nonnumeric"] {
        let rejected = request(
            &app,
            "GET",
            &format!("/api/workflows?page_size={size}"),
            Value::Null,
        )
        .await;
        assert_eq!(rejected.0, StatusCode::UNPROCESSABLE_ENTITY);
        assert!(rejected.1["validation_errors"]["page_size"].is_array());
    }
    assert_eq!(visible(&app, "page_size=200").await["workflow_count"], 0);
    // Frozen PHP treats an invalid offset cursor as the first page.
    assert_eq!(
        visible(&app, "page_size=1&next_page_token=invalid").await["next_page_token"],
        Value::Null
    );
    runtime.close().await;
    database.remove().await;
}
