//! server#1382 (.github#232): query and update-validation tasks replay the
//! run's history, so they take the same parallel group policy eligibility as
//! workflow tasks. A worker without a recorded policy never receives them.
use super::*;

const FLAG: &str = "parallel_group_policies";

fn int_contract(name: &str) -> Value {
    json!({"name": name, "parameters": [{"name": "value", "position": 0, "type": "int", "required": true, "variadic": false,
        "default_available": false, "allows_null": false}]})
}

/// A worker on `test` that answers queries and validates `approve_me`, with
/// or without the policy descriptor.
async fn register_replayer(app: &Router, worker: &str, policies: Option<&[&str]>) {
    let entry = json!({"supported": false, "minimum_protocol_version": "1.18"});
    let mut manifest = json!({"local_activities": entry, "worker_sessions": entry, "sticky_execution": entry});
    let mut capabilities = vec!["query_tasks", "workflow_updates", "update_validation_tasks"];
    if let Some(policies) = policies {
        capabilities.push(FLAG);
        manifest[FLAG] = json!({"supported": true, "version": 1, "policies": policies});
    }
    let contract = json!({"queries": ["state"], "query_contracts": [int_contract("state")], "signals": [], "signal_contracts": [],
        "updates": ["approve_me"], "update_contracts": [int_contract("approve_me")], "update_validators": ["approve_me"]});
    let (status, body) = request_protocol(app, "POST", "/api/worker/register", json!({"worker_id": worker, "task_queue": "test",
        "runtime": "python", "supported_workflow_types": ["echo"], "supported_activity_types": ["echo_activity"],
        "capabilities": capabilities, "capability_manifest": manifest, "workflow_command_contracts": {"echo": contract}}), "1.20").await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

/// A run whose first task admitted an explicit `propagate` group. Its
/// members stay open, so no workflow task holds a query, and the author is
/// then silent.
async fn explicit_run(database: &TestDatabase, app: &Router, workflow: &str) -> Value {
    register_replayer(app, "author", Some(&["propagate"])).await;
    let run = start(app, workflow).await;
    let task = poll(app, "author", "workflow").await;
    let commands = json!((0..2).map(|index| {
        let frame = json!({"parallel_group_id": "parallel-activities:1:2", "parallel_group_kind": "activity",
            "parallel_group_base_sequence": 1, "parallel_group_size": 2, "parallel_group_index": index,
            "parallel_group_policy": "propagate"});
        let mut command = json!({"type": "schedule_activity", "activity_type": "echo_activity", "arguments": envelope(Payload::Array(vec![]))});
        for (field, value) in frame.as_object().unwrap() {
            command[field] = value.clone();
        }
        command["parallel_group_path"] = json!([frame]);
        command
    }).collect::<Vec<_>>());
    let admitted = finish_task(app, &task, commands).await;
    assert_eq!(admitted.0, StatusCode::OK, "{}", admitted.1);
    database.execute("UPDATE workflow_worker_registrations SET last_heartbeat_at='2000-01-01 00:00:00' WHERE worker_id='author'").await;
    run
}

#[tokio::test]
async fn a_legacy_worker_never_receives_an_explicit_runs_query() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let run = explicit_run(&database, &app, "policy-query").await;
    register_replayer(&app, "legacy", None).await;
    register_replayer(&app, "capable", Some(&["propagate"])).await;
    let pending = {
        let app = app.clone();
        let path = format!("/api/workflows/policy-query/runs/{}/query/state", run["run_id"].as_str().unwrap());
        tokio::spawn(async move { request(&app, "POST", &path, json!({"input": envelope(Payload::Array(vec![Payload::Long(1)]))})).await })
    };
    // The legacy worker keeps polling and never gets it.
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_millis(800);
    while tokio::time::Instant::now() < deadline {
        assert!(poll(&app, "legacy", "query").await.is_null(), "a legacy worker cannot replay the explicit run");
        tokio::time::sleep(std::time::Duration::from_millis(50)).await;
    }
    let claim = await_query(&app, "capable").await;
    assert_eq!(claim["run_id"], run["run_id"], "{claim}");
    pending.abort();
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_legacy_validator_never_receives_an_explicit_runs_update_validation() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let run = explicit_run(&database, &app, "policy-update").await;
    register_replayer(&app, "legacy", None).await;
    let body = |request_id: &str| json!({"input": envelope(Payload::Array(vec![Payload::Long(1)])), "request_id": request_id});
    // With only a legacy validator, the update is refused at once with a
    // stable reason, and nothing is recorded.
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let (status, refused) = request(&app, "POST", "/api/workflows/policy-update/update/approve_me", body("u1")).await;
    assert_eq!(status, StatusCode::CONFLICT, "{refused}");
    assert_eq!(refused["reason"], "update_validator_parallel_group_policy_unsupported", "{refused}");
    assert_eq!(run_history(&app, &run["workflow_id"], &run["run_id"]).await, before);
    // A capable validator arrives: the task goes to it and never to the
    // legacy validator.
    register_replayer(&app, "capable", Some(&["propagate"])).await;
    let pending = {
        let app = app.clone();
        tokio::spawn(async move { request(&app, "POST", "/api/workflows/policy-update/update/approve_me", body("u2")).await })
    };
    tokio::time::sleep(std::time::Duration::from_millis(300)).await;
    let (status, legacy) = request(&app, "POST", "/api/worker/update-validation-tasks/poll",
        json!({"worker_id": "legacy", "task_queue": "test", "timeout_seconds": 0})).await;
    assert_eq!(status, StatusCode::OK, "{legacy}");
    assert!(legacy["task"].is_null(), "{legacy}");
    let (status, capable) = request(&app, "POST", "/api/worker/update-validation-tasks/poll",
        json!({"worker_id": "capable", "task_queue": "test", "timeout_seconds": 5})).await;
    assert_eq!(status, StatusCode::OK, "{capable}");
    assert_eq!(capable["poll_status"], "leased", "{capable}");
    assert_eq!(capable["task"]["workflow_run_id"].as_str().or(capable["task"]["run_id"].as_str()), run["run_id"].as_str(), "{capable}");
    pending.abort();
    runtime.close().await;
    database.remove().await;
}
