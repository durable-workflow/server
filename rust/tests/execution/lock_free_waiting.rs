//! A held long poll waits without the transition lock, so idle polls neither
//! wait behind transitions nor delay them. It still claims work, and refuses
//! a removed registration, committed by another server process.
use super::read_snapshots::hold_transition_lock;
use super::*;
use std::time::Duration;

fn held_poll(
    app: &Router,
    worker: &str,
    kind: &str,
    seconds: u64,
) -> tokio::task::JoinHandle<(StatusCode, Value)> {
    let app = app.clone();
    let path = format!("/api/worker/{kind}-tasks/poll");
    let body = json!({"worker_id":worker,"task_queue":"test","timeout_seconds":seconds});
    tokio::spawn(async move { request(&app, "POST", &path, body).await })
}

#[tokio::test]
async fn held_polls_reach_their_deadline_while_a_transition_holds_the_lock() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "waiting-worker", json!(["echo"]), json!(["echo-activity"])).await;
    query_worker(&app, "waiting-query-worker").await;
    let polls = [
        held_poll(&app, "waiting-worker", "workflow", 2),
        held_poll(&app, "waiting-worker", "activity", 2),
        held_poll(&app, "waiting-query-worker", "query", 2),
    ];
    // Each poll's first claim takes the lock; let them finish and wait.
    tokio::time::sleep(Duration::from_millis(500)).await;
    let writer = hold_transition_lock(&database).await;
    for poll in polls {
        // The polls end about 1.5 s from now. A probe that took the lock
        // would wait for it, and the lock is held past this bound.
        let (status, body) = tokio::time::timeout(Duration::from_secs(3), poll)
            .await
            .expect("an idle poll must reach its deadline without the transition lock")
            .unwrap();
        assert_eq!(status, StatusCode::OK, "{body}");
        assert!(body["task"].is_null(), "{body}");
    }
    writer.release().await;
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn held_polls_observe_work_and_removed_registrations_from_another_node() {
    let database = TestDatabase::new().await;
    let node_a = database.open().await.unwrap();
    let node_b = database.open().await.unwrap();
    let app_a = router(node_a.clone());
    let app_b = router(node_b.clone());
    register(&app_a, "observing-worker", json!(["echo"]), json!([])).await;
    query_worker(&app_a, "observing-query-worker").await;

    // Node B's commits cannot wake node A's poll in process.
    let waiting = held_poll(&app_a, "observing-worker", "workflow", 20);
    tokio::time::sleep(Duration::from_millis(300)).await;
    let started = start(&app_b, "observed-elsewhere").await;
    let (status, claimed) = tokio::time::timeout(Duration::from_secs(5), waiting)
        .await
        .expect("a held poll claims work another node committed")
        .unwrap();
    assert_eq!(status, StatusCode::OK, "{claimed}");
    assert_eq!(claimed["task"]["run_id"], started["run_id"], "{claimed}");
    let completed = request(
        &app_a,
        "POST",
        &format!(
            "/api/worker/workflow-tasks/{}/complete",
            claimed["task"]["task_id"].as_str().unwrap()
        ),
        completion(
            &claimed["task"],
            json!([{"type":"complete_workflow","result":envelope(Payload::Null)}]),
        ),
    )
    .await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);

    let waiting = held_poll(&app_a, "observing-query-worker", "query", 5);
    tokio::time::sleep(Duration::from_millis(300)).await;
    let query = {
        let app = app_b.clone();
        let path = format!(
            "/api/workflows/observed-elsewhere/runs/{}/query/state",
            started["run_id"].as_str().unwrap()
        );
        let input = json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))});
        tokio::spawn(async move { request(&app, "POST", &path, input).await })
    };
    let (status, claimed) = tokio::time::timeout(Duration::from_secs(3), waiting)
        .await
        .expect("a held query poll claims a query another node admitted")
        .unwrap();
    assert_eq!(status, StatusCode::OK, "{claimed}");
    assert_eq!(claimed["task"]["query_name"], "state", "{claimed}");
    query.abort();

    let waiting = held_poll(&app_a, "observing-worker", "workflow", 20);
    tokio::time::sleep(Duration::from_millis(300)).await;
    let removed = request(
        &app_b,
        "DELETE",
        "/api/worker/registrations/observing-worker",
        Value::Null,
    )
    .await;
    assert_eq!(removed.0, StatusCode::OK, "{}", removed.1);
    let (status, refused) = tokio::time::timeout(Duration::from_secs(5), waiting)
        .await
        .expect("a held poll refuses a registration another node removed")
        .unwrap();
    assert_eq!(status, StatusCode::CONFLICT, "{refused}");
    assert_eq!(refused["reason"], "worker_not_registered", "{refused}");
    node_a.close().await;
    node_b.close().await;
    database.remove().await;
}
