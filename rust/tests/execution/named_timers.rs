use super::namespaces::{create, scoped, start_scoped};
use super::*;
use std::time::Duration;

async fn register_timer_worker(app: &Router, namespace: &str) {
    let (status, body) = scoped(
        app,
        namespace,
        "POST",
        "/api/worker/register",
        json!({
            "worker_id":"timer-worker","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":[]
        }),
    )
    .await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn claim(app: &Router, namespace: &str, request_id: &str) -> Value {
    let (status, body) = scoped(
        app,
        namespace,
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({
            "worker_id":"timer-worker","task_queue":"test","timeout_seconds":0,
            "poll_request_id":request_id
        }),
    )
    .await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["task"].clone()
}

async fn finish(
    app: &Router,
    namespace: &str,
    task: &Value,
    commands: Value,
) -> (StatusCode, Value) {
    scoped(
        app,
        namespace,
        "POST",
        &format!(
            "/api/worker/workflow-tasks/{}/complete",
            task["task_id"].as_str().unwrap()
        ),
        completion(task, commands),
    )
    .await
}

#[tokio::test]
async fn named_timers_keep_original_namespaces_across_cold_two_node_recovery() {
    let deadline = tokio::time::Instant::now() + Duration::from_secs(35);
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let mut originals = Vec::new();
    for namespace in ["alpha", "beta", "default"] {
        if namespace != "default" {
            create(&app, namespace).await;
        }
        register_timer_worker(&app, namespace).await;
        let workflow = format!("named-timer-{namespace}");
        let run = start_scoped(&app, namespace, &workflow).await;
        let task = claim(&app, namespace, "original-claim").await;
        assert_eq!(task["run_id"], run["run_id"]);
        originals.push((namespace, workflow, run, task));
    }
    let mut snapshots = Vec::new();
    for (namespace, workflow, run, task) in &originals {
        let commands = json!([{"type":"start_timer","delay_seconds":10}]);
        let accepted = finish(&app, namespace, task, commands.clone()).await;
        assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
        assert_eq!(
            finish(&app, namespace, task, commands).await.1["recorded"],
            false
        );
        let path = format!(
            "/api/workflows/{workflow}/runs/{}/history",
            run["run_id"].as_str().unwrap()
        );
        let before = scoped(&app, namespace, "GET", &path, Value::Null).await;
        assert_eq!(before.0, StatusCode::OK, "{}", before.1);
        assert_eq!(before.1["events"].as_array().unwrap().len(), 3);
        assert_eq!(before.1["events"][2]["event_type"], "TimerScheduled");
        snapshots.push((path, before.1));
    }
    runtime.close().await;
    let (a, b) = tokio::join!(database.open(), database.open());
    let (a, b) = (a.unwrap(), b.unwrap());
    let app_a = router(a.clone());
    let app_b = router(b.clone());
    for ((namespace, _, run, _), (path, before)) in originals.iter().zip(&snapshots) {
        let fired = loop {
            let response = scoped(&app_a, namespace, "GET", path, Value::Null).await;
            assert_eq!(response.0, StatusCode::OK, "{}", response.1);
            if response.1["events"].as_array().unwrap().len() == 4 {
                break response.1;
            }
            assert!(
                tokio::time::Instant::now() < deadline,
                "original timer never fired: {}",
                response.1
            );
            tokio::time::sleep(Duration::from_millis(50)).await;
        };
        assert_eq!(
            &fired["events"].as_array().unwrap()[..3],
            before["events"].as_array().unwrap().as_slice()
        );
        let scheduled = &before["events"][2]["payload"];
        let event = &fired["events"][3];
        assert_eq!(event["event_type"], "TimerFired");
        for field in ["timer_id", "sequence", "delay_seconds", "fire_at"] {
            assert_eq!(event["payload"][field], scheduled[field], "{field}");
        }
        assert!(
            chrono::DateTime::parse_from_rfc3339(event["payload"]["fired_at"].as_str().unwrap())
                .unwrap()
                >= chrono::DateTime::parse_from_rfc3339(scheduled["fire_at"].as_str().unwrap())
                    .unwrap()
        );
        let (first, second) = tokio::join!(
            claim(&app_a, namespace, "recovered-a"),
            claim(&app_b, namespace, "recovered-b")
        );
        assert_ne!(first.is_null(), second.is_null());
        let task = if first.is_null() { second } else { first };
        assert_eq!(task["run_id"], run["run_id"]);
        assert_eq!(task["workflow_task_attempt"], 1);
        let foreign = if *namespace == "alpha" {
            "beta"
        } else {
            "alpha"
        };
        let commands = json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]);
        assert_eq!(
            finish(&app_b, foreign, &task, commands.clone()).await.0,
            StatusCode::NOT_FOUND
        );
        assert_eq!(
            scoped(&app_a, namespace, "GET", path, Value::Null).await.1,
            fired
        );
        let completed = finish(&app_b, namespace, &task, commands).await;
        assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
        // Other namespaces still have due work on this exact queue/worker ID.
        assert!(
            claim(&app_a, namespace, "no-foreign-delivery")
                .await
                .is_null()
        );
    }
    tokio::time::sleep(Duration::from_millis(300)).await;
    for ((namespace, _, _, _), (path, before)) in originals.iter().zip(&snapshots) {
        let after = scoped(&app_b, namespace, "GET", path, Value::Null).await.1;
        assert_eq!(after["events"].as_array().unwrap().len(), 5);
        assert_eq!(
            &after["events"].as_array().unwrap()[..3],
            before["events"].as_array().unwrap().as_slice()
        );
        assert_eq!(after["events"][4]["event_type"], "WorkflowCompleted");
    }
    a.close().await;
    b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn named_timer_invalid_batch_is_atomic_and_zero_delay_keeps_its_identity() {
    let deadline = tokio::time::Instant::now() + Duration::from_secs(15);
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    create(&app, "alpha").await;
    register_timer_worker(&app, "alpha").await;
    let run = start_scoped(&app, "alpha", "named-timer-atomic").await;
    let task = claim(&app, "alpha", "original-claim").await;
    let path = format!(
        "/api/workflows/named-timer-atomic/runs/{}/history",
        run["run_id"].as_str().unwrap()
    );
    let before = scoped(&app, "alpha", "GET", &path, Value::Null).await;
    let refused = finish(
        &app,
        "alpha",
        &task,
        json!([
            {"type":"start_timer","delay_seconds":1},{"type":"start_timer","delay_seconds":-1}
        ]),
    )
    .await;
    assert_eq!(refused.0, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(refused.1["reason"], "invalid_timer_delay");
    assert_eq!(
        scoped(&app, "alpha", "GET", &path, Value::Null).await,
        before
    );
    let scheduled = finish(
        &app,
        "alpha",
        &task,
        json!([{"type":"start_timer","delay_seconds":0}]),
    )
    .await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let history = loop {
        let history = scoped(&app, "alpha", "GET", &path, Value::Null).await.1;
        if history["events"].as_array().unwrap().len() == 4 {
            break history;
        }
        assert!(
            tokio::time::Instant::now() < deadline,
            "zero timer never fired: {history}"
        );
        tokio::time::sleep(Duration::from_millis(20)).await;
    };
    for field in ["timer_id", "sequence", "delay_seconds", "fire_at"] {
        assert_eq!(
            history["events"][2]["payload"][field],
            history["events"][3]["payload"][field]
        );
    }
    assert_eq!(history["events"][2]["payload"]["delay_seconds"], 0);
    let resumed = claim(&app, "alpha", "zero-timer-wakeup").await;
    assert_eq!(resumed["run_id"], run["run_id"]);
    assert_eq!(
        finish(
            &app,
            "alpha",
            &resumed,
            json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
        )
        .await
        .0,
        StatusCode::OK
    );
    runtime.close().await;
    database.remove().await;
}
