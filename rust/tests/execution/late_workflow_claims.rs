use super::*;

async fn late(app: &Router, task: &Value, action: &str) -> (StatusCode, Value) {
    let path = format!(
        "/api/worker/workflow-tasks/{}/{action}",
        task["task_id"].as_str().unwrap()
    );
    let body = match action {
        "complete" => completion(
            task,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}]),
        ),
        "fail" => {
            json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
            "failure":{"message":"late","type":"RuntimeException"}})
        }
        _ => {
            json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"]})
        }
    };
    let (status, mut response) = request(app, "POST", &path, body).await;
    assert_eq!(status, StatusCode::CONFLICT, "{action}: {response}");
    assert_eq!(response["protocol_version"], "1.20", "{action}");
    let object = response.as_object_mut().unwrap();
    assert!(object.remove("server_capabilities").is_some(), "{action}");
    object.remove("protocol_version");
    (status, response)
}

/// A replaced claim's late requests are refused with PHP's ownership guard
/// bodies, which a worker reads to recognize that its claim was lost.
#[tokio::test]
async fn a_replaced_workflow_claim_is_refused_with_the_published_ownership_fields() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "late-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "late-claim").await;
    let replaced = poll(&app, "late-worker", "workflow").await;
    register(&app, "late-worker", json!(["echo"]), json!([])).await;
    let current = poll(&app, "late-worker", "workflow").await;
    assert_eq!(current["task_id"], replaced["task_id"]);
    assert_eq!(
        (
            replaced["workflow_task_attempt"].as_i64(),
            current["workflow_task_attempt"].as_i64()
        ),
        (Some(1), Some(2))
    );
    let fence = json!({"task_id":replaced["task_id"],"workflow_task_attempt":1});
    let with = |fields: Value| {
        let mut body = fence.clone();
        body.as_object_mut()
            .unwrap()
            .extend(fields.as_object().unwrap().clone());
        body
    };
    let mismatch = with(
        json!({"error":"Workflow task lease attempt does not match the current claim.",
        "reason":"workflow_task_attempt_mismatch","current_attempt":2}),
    );
    for action in ["complete", "heartbeat", "history"] {
        assert_eq!(late(&app, &replaced, action).await.1, mismatch, "{action}");
    }
    let mut owner = replaced.clone();
    owner["lease_owner"] = json!("other-worker");
    owner["workflow_task_attempt"] = json!(2);
    assert_eq!(
        late(&app, &owner, "complete").await.1,
        json!({"task_id":replaced["task_id"],"workflow_task_attempt":2,"error":"Workflow task lease is owned by another worker.",
            "reason":"lease_owner_mismatch","lease_owner":"late-worker"})
    );
    let history_path = format!(
        "/api/workflows/late-claim/runs/{}/history",
        run["run_id"].as_str().unwrap()
    );
    let before = request(&app, "GET", &history_path, Value::Null).await.1;
    let done = finish_task(
        &app,
        &current,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(2))}]),
    )
    .await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let after = request(&app, "GET", &history_path, Value::Null).await.1;
    for action in ["heartbeat", "fail", "complete"] {
        let (_, refused) = late(&app, &replaced, action).await;
        let closed_at = refused["run_closed_at"]
            .as_str()
            .unwrap_or_default()
            .to_owned();
        assert!(
            closed_at.ends_with('Z') && closed_at.len() == 27,
            "{action}: {refused}"
        );
        assert_eq!(
            refused,
            with(
                json!({"error":"Workflow run is already closed.","reason":"run_closed","stop_reason":"run_completed",
                "cancel_requested":false,"can_continue":false,"run_status":"completed","run_closed_reason":"completed",
                "run_closed_at":closed_at,"task_status":"completed","lease_owner":"late-worker","lease_expires_at":null})
            ),
            "{action}"
        );
    }
    assert_eq!(
        request(&app, "GET", &history_path, Value::Null).await.1,
        after
    );
    assert_ne!(before, after);
    runtime.close().await;
    database.remove().await;
}
