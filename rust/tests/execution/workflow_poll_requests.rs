//! Workflow-task poll request IDs replay their last answer as published PHP
//! does (`WorkflowTaskPollRequestStore`): an empty answer stays empty for the
//! pending window, a lease replays while deliverable, and an ID whose task is
//! no longer deliverable polls afresh rather than being refused.
use super::*;

async fn poll_id(app: &Router, request_id: &str) -> Value {
    let (status, body) = request(app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"replay-worker","task_queue":"test","timeout_seconds":0,"poll_request_id":request_id})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body
}

#[tokio::test]
async fn poll_request_ids_replay_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "replay-worker", json!(["echo"]), json!([])).await;

    // An empty answer replays even after work arrives.
    assert_eq!(poll_id(&app, "empty-id").await["poll_status"], "empty");
    let first = start(&app, "replay-first").await;
    assert_eq!(poll_id(&app, "empty-id").await["poll_status"], "empty");

    // A fresh ID leases; the same ID, padded or not, replays that lease.
    let leased = poll_id(&app, "lease-id").await;
    assert_eq!((leased["poll_status"].clone(), leased["task"]["run_id"].clone()), (json!("leased"), first["run_id"].clone()));
    for id in ["lease-id", "  lease-id  "] {
        let replay = poll_id(&app, id).await;
        assert_eq!(replay["task"]["task_id"], leased["task"]["task_id"], "{id}: {replay}");
        assert_eq!(replay["task"]["workflow_task_attempt"], leased["task"]["workflow_task_attempt"], "{id}");
    }

    // Once its task completes, the ID polls afresh: the next ready task, then
    // nothing, never an expired-cache refusal.
    let second = start(&app, "replay-second").await;
    let done = finish_task(&app, &leased["task"], json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let next = poll_id(&app, "lease-id").await;
    assert_eq!((next["poll_status"].clone(), next["task"]["run_id"].clone()), (json!("leased"), second["run_id"].clone()), "{next}");
    assert_eq!(poll_id(&app, "lease-id").await["task"]["task_id"], next["task"]["task_id"]);
    let done = finish_task(&app, &next["task"], json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let drained = poll_id(&app, "lease-id").await;
    assert_eq!((drained["poll_status"].clone(), drained["task"].clone()), (json!("empty"), Value::Null), "{drained}");
    runtime.close().await;
    database.remove().await;
}
