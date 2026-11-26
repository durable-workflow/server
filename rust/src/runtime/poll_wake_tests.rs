//! Which held polls each hot commit wakes (Server #1372).
use super::{Runtime, Storage};
use std::time::Duration;
use tokio::time::timeout;

/// Server #1372: each hot commit wakes the held polls of the queue it
/// made ready, and not those of another queue: a start, a workflow task
/// completion that schedules an activity on another queue (and the run's
/// own queue, whose other tasks it unfences), an activity completion and a
/// signal.
#[tokio::test]
async fn hot_commits_wake_the_queues_they_make_ready() {
    use crate::codec::{Value as Payload, ValueCodec};
    use axum::{body::{Body, to_bytes}, http::{Request, StatusCode}};
    use serde_json::json;
    use tower::ServiceExt;
    let envelope = |value: Payload| json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&value).unwrap()});
    let directory = tempfile::tempdir().unwrap();
    let runtime = Runtime::open(directory.path().join("runtime.sqlite").to_str().unwrap(), "test-token".into()).await.unwrap();
    for (worker, queue) in [("wakes-workflows", "wakes-wq"), ("wakes-activities", "wakes-aq")] {
        runtime.register_unversioned(json!({"worker_id":worker,"task_queue":queue,"runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":["act"],
            "workflow_command_contracts":{"echo":{"signals":["note"],"signal_contracts":[{"name":"note","parameters":[{"name":"args",
                "position":0,"type":null,"required":false,"variadic":true,"default_available":false,"default":null,"allows_null":true}]}]}}}))
            .await.unwrap();
    }
    let Storage::Sqlite(store) = runtime.storage.as_ref() else { unreachable!() };
    let wakes = store.poll_wakes.clone();
    let (workflows, activities, elsewhere) =
        (wakes.subscribe("default", "wakes-wq"), wakes.subscribe("default", "wakes-aq"), wakes.subscribe("default", "wakes-elsewhere"));
    // Each check registers its waiters before the commit it observes.
    macro_rules! wakes_only {
        ($commit:expr, [$($queue:expr),*], $label:expr) => {{
            let (w, a, e) = (Box::pin(workflows.notify.clone().notified_owned()), Box::pin(activities.notify.clone().notified_owned()),
                Box::pin(elsewhere.notify.clone().notified_owned()));
            let mut expected = std::collections::BTreeSet::new();
            $(expected.insert($queue);)*
            $commit;
            let observed: std::collections::BTreeSet<&str> = [
                ("wakes-wq", timeout(Duration::from_millis(50), w).await.is_ok()),
                ("wakes-aq", timeout(Duration::from_millis(50), a).await.is_ok()),
                ("wakes-elsewhere", timeout(Duration::from_millis(50), e).await.is_ok()),
            ].into_iter().filter(|(_, woken)| *woken).map(|(queue, _)| queue).collect();
            assert_eq!(observed, expected, $label);
        }};
    }
    wakes_only!(runtime.start(json!({"workflow_id":"wakes","workflow_type":"echo","task_queue":"wakes-wq",
        "input":envelope(Payload::Array(vec![]))}), None).await.unwrap(), ["wakes-wq"], "a start");
    let task = runtime.poll(json!({"worker_id":"wakes-workflows","task_queue":"wakes-wq","timeout_seconds":0}), "workflow", "1.20")
        .await.unwrap()["task"].clone();
    wakes_only!(runtime.complete_workflow(task["task_id"].as_str().unwrap(), json!({"lease_owner":"wakes-workflows",
        "workflow_task_attempt":task["workflow_task_attempt"],"commands":[{"type":"schedule_activity","activity_type":"act",
            "queue":"wakes-aq","arguments":envelope(Payload::Array(vec![]))}]}), None, Some("1.20")).await.unwrap(),
        ["wakes-wq", "wakes-aq"], "a workflow task completion");
    let activity = runtime.poll(json!({"worker_id":"wakes-activities","task_queue":"wakes-aq","timeout_seconds":0}), "activity", "1.20")
        .await.unwrap()["task"].clone();
    let app = crate::runtime::router(runtime.clone());
    let request = |path: String, body: serde_json::Value| {
        let app = app.clone();
        async move {
            let response = app.oneshot(Request::builder().method("POST").uri(path)
                .header("authorization", "Bearer test-token").header("x-namespace", "default")
                .header("x-durable-workflow-control-plane-version", "2").header("x-durable-workflow-protocol-version", "1.20")
                .header("content-type", "application/json").body(Body::from(body.to_string())).unwrap()).await.unwrap();
            let status = response.status();
            let bytes = to_bytes(response.into_body(), 1 << 20).await.unwrap();
            (status, String::from_utf8_lossy(&bytes).into_owned())
        }
    };
    wakes_only!({
        let (status, body) = request(format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
            json!({"lease_owner":"wakes-activities","activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Long(7))})).await;
        assert_eq!(status, StatusCode::OK, "{body}");
    }, ["wakes-wq"], "an activity completion");
    // The run now has a ready workflow task; take it and leave the run
    // waiting, so the signal is what makes it ready again.
    let task = runtime.poll(json!({"worker_id":"wakes-workflows","task_queue":"wakes-wq","timeout_seconds":0}), "workflow", "1.20")
        .await.unwrap()["task"].clone();
    runtime.complete_workflow(task["task_id"].as_str().unwrap(), json!({"lease_owner":"wakes-workflows",
        "workflow_task_attempt":task["workflow_task_attempt"],"commands":[{"type":"start_timer","delay_seconds":3600}]}), None, Some("1.20"))
        .await.unwrap();
    wakes_only!({
        let (status, body) = request("/api/workflows/wakes/signal/note".into(), json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))})).await;
        assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    }, ["wakes-wq"], "a signal");
    let task = runtime.poll(json!({"worker_id":"wakes-workflows","task_queue":"wakes-wq","timeout_seconds":0}), "workflow", "1.20")
        .await.unwrap()["task"].clone();
    assert!(!task.is_null(), "the signal made the run's workflow task ready");
    drop((workflows, activities, elsewhere));
    runtime.close().await;
}
