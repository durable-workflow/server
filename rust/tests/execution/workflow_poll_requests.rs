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

// A duplicate with the same ID can lease after a held poll's last probe and
// before that poll records its no-task answer. The held poll then returns the
// duplicate's lease, and a retry whose original response was lost recovers
// it, rather than either seeing an `empty` or `task_queue_changed` written
// over a live lease. Debug builds stop the held poll between the two steps.
#[cfg(debug_assertions)]
mod terminal_races {
    use super::*;
    use durable_workflow_server::runtime::poll_pauses::{PollPoint, pause_workflow_poll};

    fn held_poll(app: &Router, request_id: &str, timeout: u64) -> tokio::task::JoinHandle<(StatusCode, Value)> {
        let app = app.clone();
        let body = json!({"worker_id":"replay-worker","task_queue":"test","timeout_seconds":timeout,"poll_request_id":request_id});
        tokio::spawn(async move { request(&app, "POST", "/api/worker/workflow-tasks/poll", body).await })
    }

    /// What a lease delivers, without the lease's end, which a replay shows
    /// as the task's current lease.
    fn lease(body: &Value) -> Value {
        let task = &body["task"];
        json!([body["poll_status"], task["task_kind"], task["task_id"], task["run_id"], task["workflow_task_attempt"], task["lease_owner"]])
    }

    async fn lost_response_lease(app: &Router, request_id: &str, run: &Value) -> Value {
        let leased = poll_id(app, request_id).await;
        assert_eq!((leased["poll_status"].clone(), leased["task"]["run_id"].clone()), (json!("leased"), run.clone()), "{leased}");
        leased
    }

    #[tokio::test(flavor = "multi_thread", worker_threads = 4)]
    async fn a_lease_committed_before_the_empty_answer_replays() {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        register(&app, "replay-worker", json!(["echo"]), json!([])).await;
        let id = "terminal-race-empty";
        let mut terminal = pause_workflow_poll(id, PollPoint::Terminal);

        // The held poll probes an empty queue and stops before answering.
        let held = held_poll(&app, id, 0);
        (&mut terminal.reached).await.unwrap();
        // A duplicate leases work that arrived meanwhile; its response is lost.
        let started = start(&app, "terminal-race-empty").await;
        let leased = lost_response_lease(&app, id, &started["run_id"]).await;
        terminal.release.send(()).unwrap();

        let (status, answered) = held.await.unwrap();
        assert_eq!((status, lease(&answered)), (StatusCode::OK, lease(&leased)), "{answered}");
        let retried = poll_id(&app, id).await;
        assert_eq!(lease(&retried), lease(&leased), "{retried}");
        let done = finish_task(&app, &retried["task"], json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
        assert_eq!(done.0, StatusCode::OK, "{}", done.1);
        runtime.close().await;
        database.remove().await;
    }

    #[tokio::test(flavor = "multi_thread", worker_threads = 4)]
    async fn a_lease_committed_before_the_queue_changed_answer_replays() {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"replay-worker","task_queue":"test",
            "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["echo_activity"],
            "capabilities":["cross_kind_poll_wake"]})).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
        start(&app, "queue-change-first").await;
        let first = poll(&app, "replay-worker", "workflow").await;
        let id = "terminal-race-queue-changed";
        let mut waiting = pause_workflow_poll(id, PollPoint::Waiting);
        let mut terminal = pause_workflow_poll(id, PollPoint::Terminal);

        // The held poll has its view of the queue and has probed once.
        let held = held_poll(&app, id, 60);
        (&mut waiting.reached).await.unwrap();
        // A new activity changes the queue; the held poll sees the change and
        // stops before answering.
        let scheduled = finish_task(&app, &first, json!([{"type":"schedule_activity","activity_type":"echo_activity",
            "arguments":envelope(Payload::Array(vec![]))}])).await;
        assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
        waiting.release.send(()).unwrap();
        (&mut terminal.reached).await.unwrap();
        // A duplicate leases a new workflow task; its response is lost.
        let started = start(&app, "queue-change-second").await;
        let leased = lost_response_lease(&app, id, &started["run_id"]).await;
        terminal.release.send(()).unwrap();

        let (status, answered) = held.await.unwrap();
        assert_eq!((status, lease(&answered)), (StatusCode::OK, lease(&leased)), "{answered}");
        let retried = poll_id(&app, id).await;
        assert_eq!(lease(&retried), lease(&leased), "{retried}");
        runtime.close().await;
        database.remove().await;
    }
}
