//! Explicit parallel group failure policies (.github#232, contract version 1).
//! Native records an admitted policy in every frame of each member, keeps it
//! across replay and a cold reopen, and refuses a malformed, inconsistent or
//! unsupported policy before any write. The shared vectors come from
//! `conformance/parallel-groups/fixtures-v1.json` in durable-workflow/.github.
use super::*;

const FIXTURES: &str = include_str!("../fixtures/parallel-group-policy-fixtures-v1.json");
const FLAG: &str = "parallel_group_policies";

fn fixtures() -> Value {
    serde_json::from_str(FIXTURES).unwrap()
}

fn affinity() -> Value {
    let entry = json!({"supported": false, "minimum_protocol_version": "1.18"});
    json!({"local_activities": entry, "worker_sessions": entry, "sticky_execution": entry})
}

/// A registration body as an SDK sends it, with the fixture's flag and
/// descriptor placement merged over a complete affinity manifest.
fn registration(worker: &str, declared: &Value) -> Value {
    let mut body = json!({"worker_id": worker, "task_queue": "test", "runtime": "python",
        "supported_workflow_types": ["echo"], "supported_activity_types": ["echo_activity"],
        "capability_manifest": affinity()});
    for (field, value) in declared.as_object().unwrap() {
        match (field.as_str(), value) {
            ("capability_manifest", Value::Object(entries)) => {
                for (name, entry) in entries {
                    body["capability_manifest"][name] = entry.clone();
                }
            }
            _ => body[field] = value.clone(),
        }
    }
    body
}

async fn register_declared(app: &Router, worker: &str, declared: &Value) -> (StatusCode, Value) {
    request_protocol(app, "POST", "/api/worker/register", registration(worker, declared), "1.20").await
}

async fn register_policies(app: &Router, worker: &str, policies: Option<&[&str]>) {
    let declared = match policies {
        Some(policies) => json!({"capabilities": [FLAG],
            "capability_manifest": {(FLAG): {"supported": true, "version": 1, "policies": policies}}}),
        None => json!({}),
    };
    let (status, body) = register_declared(app, worker, &declared).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

fn frame(base: i64, size: i64, index: i64, policy: Option<&str>) -> Value {
    let mut frame = json!({"parallel_group_id": format!("parallel-activities:{base}:{size}"),
        "parallel_group_kind": "activity", "parallel_group_base_sequence": base,
        "parallel_group_size": size, "parallel_group_index": index});
    if let Some(policy) = policy {
        frame["parallel_group_policy"] = json!(policy);
    }
    frame
}

/// One member: the innermost frame flattened, then the path, as SDKs write it.
fn member(index: i64, path: Vec<Value>) -> Value {
    let mut command = json!({"type": "schedule_activity", "activity_type": "echo_activity",
        "arguments": envelope(Payload::Array(vec![Payload::Long(index)]))});
    for (field, value) in path.last().unwrap().as_object().unwrap() {
        command[field] = value.clone();
    }
    command["parallel_group_path"] = json!(path);
    command
}

fn group(policies: &[Option<&str>]) -> Value {
    let size = policies.len() as i64;
    json!(policies.iter().enumerate()
        .map(|(index, policy)| member(index as i64, vec![frame(1, size, index as i64, *policy)]))
        .collect::<Vec<_>>())
}

async fn fail_activity(app: &Router, task: &Value) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/worker/activity-tasks/{}/fail", task["task_id"].as_str().unwrap()),
        json!({"lease_owner": task["lease_owner"], "activity_attempt_id": task["activity_attempt_id"],
            "failure": {"type": "RuntimeException", "message": "higher position failed first", "non_retryable": true}})).await
}

async fn complete_activity(app: &Router, task: &Value) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/worker/activity-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        json!({"lease_owner": task["lease_owner"], "activity_attempt_id": task["activity_attempt_id"],
            "result": envelope(Payload::String("late-loser".into()))})).await
}

fn assert_recorded_policy(event: &Value, policy: &str) {
    let payload = &event["payload"];
    assert_eq!(payload["parallel_group_policy"], policy, "{event}");
    let path = payload["parallel_group_path"].as_array().unwrap();
    assert!(path.iter().all(|frame| frame["parallel_group_policy"] == policy), "{event}");
}

#[tokio::test]
async fn explicit_propagate_is_recorded_per_frame_and_survives_a_cold_reopen() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    register_policies(&app, "policy-worker", Some(&["propagate"])).await;
    let run = start(&app, "explicit-propagate").await;
    let initial = poll(&app, "policy-worker", "workflow").await;
    let commands = group(&[Some("propagate"), Some("propagate")]);
    let admitted = finish_task(&app, &initial, commands.clone()).await;
    assert_eq!(admitted.0, StatusCode::OK, "{}", admitted.1);
    assert_eq!(finish_task(&app, &initial, commands).await.1["recorded"], false);
    let original = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let scheduled: Vec<_> = original.as_array().unwrap().iter()
        .filter(|event| event["event_type"] == "ActivityScheduled").collect();
    assert_eq!(scheduled.len(), 2);
    for event in &scheduled {
        assert_recorded_policy(event, "propagate");
    }
    drop(app);
    first.close().await;

    // A fresh pool reads the policy back from the stored rows and history.
    let second = database.open().await.unwrap();
    let app = router(second.clone());
    let slow = poll(&app, "policy-worker", "activity").await;
    let fast = poll(&app, "policy-worker", "activity").await;
    assert_eq!(slow["activity_execution_id"], scheduled[0]["payload"]["activity_execution_id"]);
    assert_eq!(fast["activity_execution_id"], scheduled[1]["payload"]["activity_execution_id"]);
    // The first recorded failure wakes the workflow without waiting for the
    // open sibling. A later sibling result wakes nothing.
    let failed = fail_activity(&app, &fast).await;
    assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
    let late = complete_activity(&app, &slow).await;
    assert_eq!(late.0, StatusCode::OK, "{}", late.1);
    assert_eq!(late.1["next_task_id"], Value::Null, "{}", late.1);
    let resumed = poll(&app, "policy-worker", "workflow").await;
    assert!(resumed.is_object(), "the first failure wakes the workflow");
    let delivered = resumed["history_events"].as_array().unwrap();
    let failure = delivered.iter().find(|event| event["event_type"] == "ActivityFailed").unwrap();
    assert_recorded_policy(failure, "propagate");
    for event in delivered.iter().filter(|event| event["event_type"] == "ActivityScheduled") {
        assert_recorded_policy(event, "propagate");
    }
    let finished = finish_task(&app, &resumed, json!([{"type": "fail_workflow",
        "message": "higher position failed first"}])).await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    assert!(poll(&app, "policy-worker", "workflow").await.is_null());
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(&history.as_array().unwrap()[..original.as_array().unwrap().len()], original.as_array().unwrap());
    let completed = history.as_array().unwrap().iter()
        .find(|event| event["event_type"] == "ActivityCompleted").unwrap();
    assert_recorded_policy(completed, "propagate");
    second.close().await;
    database.remove().await;
}

/// A three-member group whose first two members form a nested group.
fn nested(outer: Option<&str>, inner: Option<&str>) -> Value {
    json!((0..2)
        .map(|index| member(index, vec![frame(1, 3, index, outer), frame(1, 2, index, inner)]))
        .chain(std::iter::once(member(2, vec![frame(1, 3, 2, outer)])))
        .collect::<Vec<_>>())
}

/// The batch a contract refusal vector describes, or a native-only case.
fn refused_batches() -> Vec<(String, Value)> {
    let mut cases = Vec::new();
    for vector in fixtures()["refusal_vectors"].as_array().unwrap() {
        let id = vector["id"].as_str().unwrap().to_owned();
        let batch = match id.as_str() {
            // Malformed names, and `settle`, which native does not run yet.
            "unknown-policy" | "null-policy" | "unsupported-selected-policy" => {
                let mut batch = group(&[Some("propagate"), Some("propagate")]);
                for command in batch.as_array_mut().unwrap() {
                    command["parallel_group_policy"] = vector["policy"].clone();
                    command["parallel_group_path"][0]["parallel_group_policy"] = vector["policy"].clone();
                }
                batch
            }
            "inconsistent-member-policy" => {
                let policies: Vec<_> = vector["member_policies"].as_array().unwrap().iter()
                    .map(|policy| policy.as_str()).collect();
                group(&policies)
            }
            "policy-on-select-frame" => {
                let mut batch = json!([]);
                for (index, kind) in ["activity", "timer"].into_iter().enumerate() {
                    let mut entry = json!({"parallel_group_id": "select-calls:1:2", "parallel_group_kind": "mixed",
                        "parallel_group_mode": vector["parallel_group_mode"], "parallel_group_base_sequence": 1,
                        "parallel_group_size": 2, "parallel_group_index": index, "selection_member_key": kind,
                        "selection_member_index": index, "selection_member_base_sequence": 1 + index,
                        "selection_member_size": 1, "selection_member_kind": kind,
                        "parallel_group_policy": vector["parallel_group_policy"]});
                    let mut command = if kind == "activity" {
                        json!({"type": "schedule_activity", "activity_type": "echo_activity",
                            "arguments": envelope(Payload::Array(vec![]))})
                    } else {
                        json!({"type": "start_timer", "delay_seconds": 60})
                    };
                    for (field, value) in entry.as_object().unwrap() {
                        command[field] = value.clone();
                    }
                    entry.as_object_mut().unwrap().remove("parallel_group_policy");
                    command["parallel_group_path"] = json!([entry]);
                    batch.as_array_mut().unwrap().push(command);
                }
                // Both the frame and the flattened field are refused.
                let mut framed = batch.clone();
                for command in framed.as_array_mut().unwrap() {
                    command.as_object_mut().unwrap().remove("parallel_group_policy");
                    command["parallel_group_path"][0]["parallel_group_policy"] = vector["parallel_group_policy"].clone();
                }
                cases.push((format!("{id}/frame"), framed));
                batch
            }
            // The producer always implements `propagate`; `unsupported-selected-policy`
            // covers a producer without the selected policy. A replacement worker's
            // claim belongs to the routing fence.
            "missing-producer-capability" | "legacy-replacement-worker" => continue,
            other => panic!("unmapped refusal vector {other}"),
        };
        cases.push((id, batch));
    }
    let mut native = vec![
        ("empty-policy", group(&[Some(""), Some("")])),
        ("mixed-presence", group(&[Some("propagate"), None])),
        ("cancel-remaining-unadmitted", group(&[Some("cancel_remaining"), Some("cancel_remaining")])),
    ];
    let mut numeric = group(&[Some("propagate"), Some("propagate")]);
    for command in numeric.as_array_mut().unwrap() {
        command["parallel_group_policy"] = json!(1);
        command["parallel_group_path"][0]["parallel_group_policy"] = json!(1);
    }
    native.push(("numeric-policy", numeric));
    let mut flattened_only = group(&[None, None]);
    let mut frame_only = group(&[Some("propagate"), Some("propagate")]);
    for index in 0..2 {
        flattened_only[index]["parallel_group_policy"] = json!("propagate");
        frame_only[index].as_object_mut().unwrap().remove("parallel_group_policy");
    }
    native.push(("flattened-without-frame", flattened_only));
    native.push(("frame-without-flattened", frame_only));
    native.push(("policy-without-group", json!([{"type": "schedule_activity", "activity_type": "echo_activity",
        "arguments": envelope(Payload::Array(vec![])), "parallel_group_policy": "propagate"}])));
    // Nested frames record one policy, or none, until mixed composition is qualified.
    native.push(("nested-explicit-over-legacy", nested(Some("propagate"), None)));
    native.push(("nested-legacy-over-explicit", nested(None, Some("propagate"))));
    native.push(("nested-mixed-explicit", nested(Some("propagate"), Some("settle"))));
    let mut not_a_member = group(&[None, None]);
    not_a_member.as_array_mut().unwrap().push(json!({"type": "complete_workflow",
        "result": envelope(Payload::Null), "parallel_group_policy": "propagate"}));
    native.push(("policy-on-terminal-command", not_a_member));
    cases.extend(native.into_iter().map(|(id, batch)| (id.to_owned(), batch)));
    cases
}

#[tokio::test]
async fn malformed_inconsistent_or_unsupported_policies_are_refused_before_any_write() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_policies(&app, "policy-worker", Some(&["propagate", "cancel_remaining", "settle"])).await;
    let run = start(&app, "refused-policies").await;
    let initial = poll(&app, "policy-worker", "workflow").await;
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let cases = refused_batches();
    for required in ["unknown-policy", "null-policy", "inconsistent-member-policy", "unsupported-selected-policy",
        "policy-on-select-frame", "policy-on-select-frame/frame"] {
        assert!(cases.iter().any(|(id, _)| id == required), "{required}");
    }
    for (id, commands) in cases {
        let refused = finish_task(&app, &initial, commands).await;
        assert!(matches!(refused.0, StatusCode::UNPROCESSABLE_ENTITY | StatusCode::CONFLICT), "{id}: {}", refused.1);
        // The normalizer's field checks, group validation or policy admission.
        assert!(matches!(refused.1["reason"].as_str(), Some("validation_failed" | "development_parallel_group_unqualified"
            | "parallel_group_policy_not_supported")), "{id}: {}", refused.1);
        assert_ne!(refused.1["recorded"], true, "{id}: {}", refused.1);
        if matches!(id.as_str(), "unsupported-selected-policy" | "cancel-remaining-unadmitted") {
            assert_eq!(refused.0, StatusCode::CONFLICT, "{id}");
            assert_eq!(refused.1["reason"], "parallel_group_policy_not_supported", "{id}: {}", refused.1);
            assert_eq!(refused.1["unsupported_by"], "server", "{id}: {}", refused.1);
        }
        assert_eq!(run_history(&app, &run["workflow_id"], &run["run_id"]).await, before, "{id}");
        assert!(poll(&app, "policy-worker", "activity").await.is_null(), "{id}");
    }
    // The original lease still admits the same group without a policy, so a
    // refused policy was never recorded as legacy.
    let legacy = finish_task(&app, &initial, group(&[None, None])).await;
    assert_eq!(legacy.0, StatusCode::OK, "{}", legacy.1);
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    for event in history.as_array().unwrap().iter().filter(|event| event["event_type"] == "ActivityScheduled") {
        assert!(event["payload"].get("parallel_group_policy").is_none(), "{event}");
        assert!(event["payload"]["parallel_group_path"][0].get("parallel_group_policy").is_none(), "{event}");
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_worker_without_the_selected_policy_cannot_admit_it() {
    for (worker, policies) in [("legacy-worker", None), ("settle-only-worker", Some(&["settle"][..]))] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        register_policies(&app, worker, policies).await;
        let run = start(&app, "worker-policy").await;
        let task = poll(&app, worker, "workflow").await;
        let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        let refused = finish_task(&app, &task, group(&[Some("propagate"), Some("propagate")])).await;
        assert_eq!(refused.0, StatusCode::CONFLICT, "{worker}: {}", refused.1);
        assert_eq!(refused.1["reason"], "parallel_group_policy_not_supported", "{worker}: {}", refused.1);
        assert_eq!((refused.1["unsupported_by"].clone(), refused.1["parallel_group_policy"].clone()),
            (json!("worker"), json!("propagate")), "{worker}: {}", refused.1);
        assert_eq!(refused.1["worker_id"], worker);
        assert_eq!(run_history(&app, &run["workflow_id"], &run["run_id"]).await, before, "{worker}");
        assert!(poll(&app, worker, "activity").await.is_null(), "{worker}");
        // The same worker keeps admitting legacy groups.
        assert_eq!(finish_task(&app, &task, group(&[None, None])).await.0, StatusCode::OK, "{worker}");
        runtime.close().await;
        database.remove().await;
    }
}

/// The contract's registration expectations. Claim eligibility and the held
/// poll vector belong to the routing fence.
#[tokio::test]
async fn contract_registration_vectors_accept_or_refuse_before_any_write() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = durable_workflow_server::runtime::router(runtime.clone());
    for (offset, vector) in fixtures()["registration_claim_vectors"].as_array().unwrap().iter().enumerate() {
        let Some(declared) = vector.get("registration") else { continue };
        let id = vector["id"].as_str().unwrap();
        let worker = format!("vector-worker-{offset}");
        let (status, body) = register_declared(&app, &worker, declared).await;
        let (described_status, described) = request(&app, "GET", &format!("/api/workers/{worker}"), Value::Null).await;
        match vector["expected"]["registration"].as_str().unwrap() {
            "accepted" => {
                assert_eq!(status, StatusCode::CREATED, "{id}: {body}");
                assert_eq!(described_status, StatusCode::OK, "{id}: {described}");
                let stored = &described["capability_manifest"][FLAG];
                match vector["expected"].get("stored_descriptor") {
                    Some(descriptor) => assert_eq!(stored, descriptor, "{id}: {described}"),
                    None if declared["capabilities"].as_array().unwrap().is_empty() => assert!(stored.is_null(), "{id}"),
                    None => assert_eq!(stored, &declared["capability_manifest"][FLAG], "{id}"),
                }
            }
            "refused" => {
                assert_eq!(status, StatusCode::CONFLICT, "{id}: {body}");
                assert_eq!(body["registered"], false, "{id}: {body}");
                assert_eq!(vector["expected"]["writes"], false, "{id}");
                assert_eq!(described_status, StatusCode::NOT_FOUND, "{id}: {described}");
            }
            other => panic!("{id}: {other}"),
        }
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn uniform_nested_propagate_is_recorded_in_every_frame() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_policies(&app, "policy-worker", Some(&["propagate"])).await;
    let run = start(&app, "nested-propagate").await;
    let task = poll(&app, "policy-worker", "workflow").await;
    let admitted = finish_task(&app, &task, nested(Some("propagate"), Some("propagate"))).await;
    assert_eq!(admitted.0, StatusCode::OK, "{}", admitted.1);
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let scheduled: Vec<_> = history.as_array().unwrap().iter()
        .filter(|event| event["event_type"] == "ActivityScheduled").collect();
    assert_eq!(scheduled.iter().map(|event| event["payload"]["parallel_group_path"].as_array().unwrap().len())
        .collect::<Vec<_>>(), [2, 2, 1]);
    for event in scheduled {
        assert_recorded_policy(event, "propagate");
        // The flattened fields repeat the innermost frame.
        let innermost = event["payload"]["parallel_group_path"].as_array().unwrap().last().unwrap();
        assert_eq!(event["payload"]["parallel_group_id"], innermost["parallel_group_id"]);
    }
    runtime.close().await;
    database.remove().await;
}
