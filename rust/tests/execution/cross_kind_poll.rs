use super::*;
use std::time::Duration;

async fn call(app: &Router, namespace: &str, path: &str, body: Value) -> (StatusCode, Value) {
    // The default namespace deliberately uses 1.19: this capability has no
    // protocol floor. Named namespaces independently exercise 1.20.
    let (status, _, response) = admission::admission_http(
        app,
        "POST",
        path,
        path.starts_with("/api/worker/"),
        Some("test-token"),
        Some(if path.starts_with("/api/worker/") {
            if namespace == "default" {
                "1.19"
            } else {
                "1.20"
            }
        } else {
            "2"
        }),
        Some(namespace),
        body,
    )
    .await;
    (status, response)
}

async fn register_mixed(app: &Router, namespace: &str, opt_in: bool) {
    let mut capabilities = vec!["query_tasks"];
    if opt_in {
        capabilities.push("cross_kind_poll_wake");
    }
    let result = call(
        app,
        namespace,
        "/api/worker/register",
        json!({
            "worker_id":"mixed", "task_queue":"test", "runtime":"php",
            "supported_workflow_types":["echo"], "supported_activity_types":["echo_activity"],
            "capabilities":capabilities, "workflow_command_contracts":{"echo":{"queries":["state"],
                "query_contracts":[{"name":"state","parameters":[]}]}},
            // One process throughout. A re-registration still rotates the
            // incarnation token, so it ends a poll held under the previous one.
            "process_metrics":{"host":"mixed-host","process_id":4242,"process_started_at":"2026-10-24T00:00:00Z"}
        }),
    )
    .await;
    assert_eq!(result.0, StatusCode::CREATED, "{}", result.1);
}

async fn hold(
    app: &Router,
    namespace: &str,
    kind: &str,
    timeout: u64,
) -> tokio::task::JoinHandle<(StatusCode, Value)> {
    let app = app.clone();
    let namespace = namespace.to_owned();
    let path = format!("/api/worker/{kind}-tasks/poll");
    let poll = tokio::spawn(async move {
        call(
            &app,
            &namespace,
            &path,
            json!({"worker_id":"mixed","task_queue":"test","timeout_seconds":timeout}),
        )
        .await
    });
    tokio::time::sleep(Duration::from_millis(150)).await;
    assert!(
        !poll.is_finished(),
        "the producer must act while the request remains held"
    );
    poll
}

fn hint(result: (StatusCode, Value)) {
    assert_eq!(result.0, StatusCode::OK, "{}", result.1);
    assert_eq!(
        result.1["poll_status"], "task_queue_changed",
        "{}",
        result.1
    );
    assert_eq!(result.1.get("task"), Some(&Value::Null));
    assert!(result.1.get("lease_owner").is_none());
}

async fn admitted(app: &Router, namespace: &str, queue: &str, workflow: &str) -> Value {
    let result = call(
        app,
        namespace,
        "/api/workflows",
        json!({"workflow_id":workflow,
        "workflow_type":"echo","task_queue":queue,"input":envelope(Payload::Array(vec![]))}),
    )
    .await;
    assert_eq!(result.0, StatusCode::CREATED, "{}", result.1);
    result.1
}

#[tokio::test]
async fn remote_workflow_admission_interrupts_both_other_kinds_without_a_lease() {
    for namespace in ["default", "alpha"] {
        for kind in ["activity", "query"] {
            let database = TestDatabase::new().await;
            let first = database.open().await.unwrap();
            let second = database.open().await.unwrap();
            let app = router(first.clone());
            let producer = router(second.clone());
            if namespace != "default" {
                namespaces::create(&app, namespace).await;
            }
            register_mixed(&app, namespace, true).await;
            let held = hold(&app, namespace, kind, 2).await;
            let started = admitted(&producer, namespace, "test", "wake-original").await;
            hint(held.await.unwrap());
            let leased = call(
                &app,
                namespace,
                "/api/worker/workflow-tasks/poll",
                json!({
                    "worker_id":"mixed","task_queue":"test","timeout_seconds":0
                }),
            )
            .await;
            assert_eq!(leased.0, StatusCode::OK, "{}", leased.1);
            assert_eq!(leased.1["task"]["run_id"], started["run_id"]);
            assert_eq!(leased.1["task"]["workflow_task_attempt"], 1);
            assert_eq!(call(&app, namespace,
                &format!("/api/worker/workflow-tasks/{}/complete", leased.1["task"]["task_id"].as_str().unwrap()),
                completion(&leased.1["task"],json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]))).await.0,
                StatusCode::OK);
            first.close().await;
            second.close().await;
            database.remove().await;
        }
    }
}

#[tokio::test]
async fn scoped_hints_require_original_opt_in_and_a_new_ready_identity() {
    for scenario in [
        "foreign-queue-case",
        "foreign-queue-accent",
        "no-opt-in",
        "foreign-namespace",
        "foreign-queue",
        "replacement",
        "preexisting",
    ] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        namespaces::create(&app, "alpha").await;
        register_mixed(&app, "default", scenario != "no-opt-in").await;
        if scenario == "preexisting" {
            admitted(&app, "default", "test", "preexisting").await;
        }
        let held = hold(&app, "default", "activity", 1).await;
        if scenario == "replacement" {
            register_mixed(&app, "default", true).await;
        }
        admitted(
            &app,
            if scenario == "foreign-namespace" {
                "alpha"
            } else {
                "default"
            },
            match scenario {
                "foreign-queue-case" => "Test",
                "foreign-queue-accent" => "tést",
                "foreign-queue" | "preexisting" => "elsewhere",
                _ => "test",
            },
            "unrelated",
        )
        .await;
        let result = held.await.unwrap();
        assert_eq!(result.0, StatusCode::OK, "{scenario}: {}", result.1);
        // A replacement registration fences the held poll off; every other
        // scenario lets it run out empty. Neither wakes it with a task.
        let expected = if scenario == "replacement" { "stale_worker_registration" } else { "empty" };
        assert_eq!(
            result.1["poll_status"], expected,
            "{scenario}: {}",
            result.1
        );
        assert_eq!(result.1.get("task"), Some(&Value::Null));
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn activity_admission_wakes_other_polls_but_same_kind_claim_wins() {
    for kind in ["workflow", "query"] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        register_mixed(&app, "default", true).await;
        start(&app, "activity-wake").await;
        let initial = poll(&app, "mixed", "workflow").await;
        let held = hold(&app, "default", kind, 2).await;
        assert_eq!(
            finish_task(
                &app,
                &initial,
                json!([{"type":"schedule_activity","activity_type":"echo_activity",
        "arguments":envelope(Payload::Array(vec![]))}])
            )
            .await
            .0,
            StatusCode::OK
        );
        hint(held.await.unwrap());
        let activity = poll(&app, "mixed", "activity").await;
        assert!(activity["activity_attempt_id"].is_string());
        let held = hold(&app, "default", "workflow", 2).await;
        assert_eq!(request(&app,"POST", &format!("/api/worker/activity-tasks/{}/complete",activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":"mixed","activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Null)})).await.0,StatusCode::OK);
        let result = held.await.unwrap();
        assert_eq!(result.0, StatusCode::OK, "{}", result.1);
        assert_eq!(result.1["poll_status"], "leased");
        assert!(result.1["task"].is_object());
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn query_admission_wakes_workflow_and_activity_without_leasing_query() {
    for kind in ["workflow", "activity"] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        register_mixed(&app, "default", true).await;
        let started = start(&app, "query-wake").await;
        let initial = poll(&app, "mixed", "workflow").await;
        assert_eq!(
            finish_task(
                &app,
                &initial,
                json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
            )
            .await
            .0,
            StatusCode::OK
        );
        let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        let held = hold(&app, "default", kind, 2).await;
        let query = {
            let app = app.clone();
            tokio::spawn(async move {
                request(
                    &app,
                    "POST",
                    "/api/workflows/query-wake/query/state",
                    json!({"input":envelope(Payload::Array(vec![]))}),
                )
                .await
            })
        };
        let wake = held.await.unwrap();
        if query.is_finished() {
            panic!(
                "query ended before its worker claim: {:?}",
                query.await.unwrap()
            );
        }
        hint(wake);
        let task = poll(&app, "mixed", "query").await;
        assert_eq!(task["query_task_attempt"], 1);
        let result = envelope(Payload::Long(9007199254740993));
        assert_eq!(
            request(
                &app,
                "POST",
                &format!(
                    "/api/worker/query-tasks/{}/complete",
                    task["query_task_id"].as_str().unwrap()
                ),
                json!({"lease_owner":"mixed","query_task_attempt":1,"result_envelope":result})
            )
            .await
            .0,
            StatusCode::OK
        );
        let reply = query.await.unwrap();
        assert_eq!(reply.0, StatusCode::OK, "{}", reply.1);
        assert_eq!(reply.1["result_envelope"], result);
        assert_eq!(
            run_history(&app, &started["workflow_id"], &started["run_id"]).await,
            prefix
        );
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn zero_timeout_polls_keep_the_existing_empty_reply() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_mixed(&app, "default", true).await;
    admitted(&app, "default", "test", "already-ready").await;
    for kind in ["activity", "query"] {
        let result = call(
            &app,
            "default",
            &format!("/api/worker/{kind}-tasks/poll"),
            json!({
                "worker_id":"mixed","task_queue":"test","timeout_seconds":0
            }),
        )
        .await;
        assert_eq!(result.0, StatusCode::OK, "{}", result.1);
        assert_eq!(result.1["poll_status"], "empty");
        assert_eq!(result.1.get("task"), Some(&Value::Null));
    }
    runtime.close().await;
    database.remove().await;
}
