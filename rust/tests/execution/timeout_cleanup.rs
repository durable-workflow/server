//! Server #1201: opt-in deadline-triggered timeout cleanup. Payload shapes
//! follow embedded Workflow 2.29.0 (`TimeoutCleanup`, `timeoutRun`).
use super::*;

const EXECUTION: i64 = 12;
const ALLOWANCE: i64 = 10;

fn events_of<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

fn types(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

/// A worker that delivers the typed timeout interruption, or one that only
/// supports ordinary cooperative cancellation.
async fn cleanup_worker(app: &Router, worker: &str, capable: bool) {
    let mut capabilities = vec!["cooperative_cancellation"];
    let mut manifest = json!({
        "local_activities": {"supported": false, "minimum_protocol_version": "1.18"},
        "worker_sessions": {"supported": false, "minimum_protocol_version": "1.18"},
        "sticky_execution": {"supported": false, "minimum_protocol_version": "1.18"}});
    if capable {
        capabilities.push("timeout_cleanup");
        manifest["timeout_cleanup"] = json!({"supported": true, "minimum_protocol_version": "1.20", "version": 1});
    }
    let (status, body) = request_protocol(app, "POST", "/api/worker/register", json!({"worker_id": worker, "task_queue": "test",
        "runtime": "php", "supported_workflow_types": ["echo"], "supported_activity_types": ["echo-activity"],
        "capabilities": capabilities, "capability_manifest": manifest}), "1.20").await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn start_with(app: &Router, workflow: &str, extra: Value) -> (StatusCode, Value) {
    let mut body = json!({"workflow_id": workflow, "workflow_type": "echo", "task_queue": "test",
        "input": envelope(Payload::Array(vec![]))});
    for (key, value) in extra.as_object().unwrap() {
        body[key] = value.clone();
    }
    request(app, "POST", "/api/workflows", body).await
}

async fn start_opted_in(app: &Router, workflow: &str) -> Value {
    let (status, started) = start_with(app, workflow, json!({"execution_timeout_seconds": EXECUTION,
        "timeout_cleanup_seconds": ALLOWANCE})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    started
}

async fn cleanup_poll(app: &Router, worker: &str) -> Value {
    let (status, body) = request_protocol(app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id": worker, "task_queue": "test", "timeout_seconds": 0}), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["task"].clone()
}

async fn cleanup_finish(app: &Router, task: &Value, commands: Value) -> (StatusCode, Value) {
    request_protocol(app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        completion(task, commands), "1.20").await
}

/// Waits for the background idle wake, never for a worker.
async fn wait_for(app: &Router, workflow: &str, run: &Value, kind: &str) -> Value {
    for _ in 0..200 {
        let history = run_history(app, &json!(workflow), run).await;
        if !events_of(&history, kind).is_empty() {
            return history;
        }
        tokio::time::sleep(std::time::Duration::from_millis(100)).await;
    }
    panic!("{workflow}: no {kind} within 20 s");
}

/// Starts an opted-in run whose worker leaves it idle on a long timer, then
/// waits for the engine to enter cleanup at the work deadline.
async fn idle_in_cleanup(app: &Router, workflow: &str) -> (Value, Value) {
    let started = start_opted_in(app, workflow).await;
    let first = cleanup_poll(app, "cleanup-worker").await;
    assert!(!first.is_null());
    let (status, body) = cleanup_finish(app, &first, json!([{"type": "start_timer", "delay_seconds": 3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let run = started["run_id"].clone();
    let history = wait_for(app, workflow, &run, "WorkflowTimeoutCleanupStarted").await;
    (run, history)
}

async fn deliver(app: &Router, task: &Value) {
    let request_id = task["cancellation_request"]["request_id"].clone();
    assert!(request_id.is_string(), "{task}");
    let (status, body) = request_protocol(app, "POST",
        &format!("/api/worker/workflow-tasks/{}/deliver-cancellation", task["task_id"].as_str().unwrap()),
        json!({"lease_owner": task["lease_owner"], "workflow_task_attempt": task["workflow_task_attempt"],
            "request_id": request_id, "sequence": 1, "call_kind": "timer"}), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{body}");
}

/// With `DW_TIMEOUT_CLEANUP_DUMP` set to a directory, writes a scenario's
/// history there for the key-for-key comparison with embedded 2.29.0.
fn dump(scenario: &str, history: &Value) {
    if let Ok(directory) = std::env::var("DW_TIMEOUT_CLEANUP_DUMP") {
        std::fs::write(format!("{directory}/native-{scenario}.json"), serde_json::to_string_pretty(&json!({"events": history})).unwrap()).unwrap();
    }
}

/// workflow#863: both run lists carry the bounded run observation's
/// `timeout_cleanup`, and omit it whenever the observation does.
async fn assert_listed(app: &Router, workflow: &str, run: &Value, present: bool) {
    let run_id = run.as_str().unwrap();
    let (status, observed) = request(app, "GET", &format!("/api/workflows/{workflow}/runs/{run_id}/observation"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{observed}");
    let expected = observed.get("timeout_cleanup");
    assert_eq!(expected.is_some(), present, "{workflow}: {observed}");
    let (status, page) = request(app, "GET", "/api/workflows?page_size=200", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{page}");
    let listed = page["workflows"].as_array().unwrap().iter().find(|item| item["run_id"] == *run)
        .unwrap_or_else(|| panic!("{workflow}: not listed in {page}"));
    assert_eq!(listed.get("timeout_cleanup"), expected, "{workflow}: {listed}");
    let (status, runs) = request(app, "GET", &format!("/api/workflows/{workflow}/runs"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{runs}");
    let entry = runs["runs"].as_array().unwrap().iter().find(|item| item["run_id"] == *run)
        .unwrap_or_else(|| panic!("{workflow}: not in {runs}"));
    assert_eq!(entry.get("timeout_cleanup"), expected, "{workflow}: {entry}");
}

fn terminal<'a>(history: &'a Value, kind: &str) -> &'a Value {
    let events = events_of(history, kind);
    assert_eq!(events.len(), 1, "{kind}: {history}");
    &events[0]["payload"]
}

#[tokio::test]
async fn admission_refuses_invalid_allowances_and_queues_without_a_capable_worker() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let opted = json!({"execution_timeout_seconds": EXECUTION, "timeout_cleanup_seconds": ALLOWANCE});
    // No worker, then only an ordinary cooperative worker: refused, never a
    // silent hard timeout, and nothing is written.
    let (status, refused) = start_with(&app, "tc-admission", opted.clone()).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
    assert_eq!(refused["reason"], "timeout_cleanup_not_supported", "{refused}");
    cleanup_worker(&app, "plain-worker", false).await;
    let (status, refused) = start_with(&app, "tc-admission", opted.clone()).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
    assert_eq!(refused["reason"], "timeout_cleanup_not_supported", "{refused}");
    assert_eq!(request(&app, "GET", "/api/workflows/tc-admission", Value::Null).await.0, StatusCode::NOT_FOUND);
    cleanup_worker(&app, "cleanup-worker", true).await;
    for (extra, message) in [
        (json!({"execution_timeout_seconds": 10, "timeout_cleanup_seconds": 10}),
            "Workflow v2 timeout cleanup (10 s) must be shorter than the execution timeout (10 s)."),
        (json!({"timeout_cleanup_seconds": 10}), "Workflow v2 timeout cleanup needs an execution or run timeout to reserve time from."),
        (json!({"execution_timeout_seconds": 100, "timeout_cleanup_seconds": 0}), "The timeout cleanup seconds field must be at least 1."),
        (json!({"execution_timeout_seconds": 100_000, "timeout_cleanup_seconds": 86_401}),
            "The timeout cleanup seconds field must not be greater than 86400."),
        (json!({"execution_timeout_seconds": 70, "run_timeout_seconds": 40, "timeout_cleanup_seconds": 40}),
            "Workflow v2 timeout cleanup (40 s) must be shorter than the run timeout (40 s)."),
    ] {
        let (status, refused) = start_with(&app, "tc-admission", extra.clone()).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{extra}: {refused}");
        assert_eq!(refused["errors"]["timeout_cleanup_seconds"], json!([message]), "{extra}: {refused}");
    }
    assert_eq!(request(&app, "GET", "/api/workflows/tc-admission", Value::Null).await.0, StatusCode::NOT_FOUND);
    // A capable worker admits the start; the allowance and the derived work
    // deadline are recorded once, in WorkflowStarted.
    let started = start_opted_in(&app, "tc-admission").await;
    let history = run_history(&app, &json!("tc-admission"), &started["run_id"]).await;
    let payload = terminal(&history, "WorkflowStarted");
    let hard = chrono::DateTime::parse_from_rfc3339(payload["execution_deadline_at"].as_str().unwrap()).unwrap();
    let work = (hard - chrono::Duration::seconds(ALLOWANCE)).format("%Y-%m-%dT%H:%M:%S+00:00").to_string();
    assert_eq!(payload["timeout_cleanup"], json!({"allowance_seconds": ALLOWANCE, "work_deadlines": {"execution": work}}));
    // Both discovery surfaces advertise version 1.
    let advertised = json!({"supported": true, "version": 1});
    let (_, cluster) = request(&app, "GET", "/api/cluster/info", Value::Null).await;
    assert_eq!(cluster["capabilities"]["timeout_cleanup"], advertised, "{}", cluster["capabilities"]);
    assert_eq!(cluster["worker_protocol"]["server_capabilities"]["timeout_cleanup"], advertised);
}

// Vector 11: without the option nothing changes.
#[tokio::test]
async fn a_start_without_the_option_keeps_the_hard_timeout_unchanged() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    cleanup_worker(&app, "cleanup-worker", true).await;
    let (status, started) = start_with(&app, "tc-default", json!({"execution_timeout_seconds": 3})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let run = started["run_id"].clone();
    let task = cleanup_poll(&app, "cleanup-worker").await;
    let (status, body) = cleanup_finish(&app, &task, json!([{"type": "start_timer", "delay_seconds": 3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    tokio::time::sleep(std::time::Duration::from_millis(1500)).await;
    let history = run_history(&app, &json!("tc-default"), &run).await;
    assert!(terminal(&history, "WorkflowStarted").get("timeout_cleanup").is_none());
    assert!(events_of(&history, "WorkflowTimeoutCleanupStarted").is_empty());
    assert!(events_of(&history, "CooperativeCancellationRequested").is_empty());
    database.execute("UPDATE workflow_runs SET execution_deadline_at='2000-01-01 00:00:00'").await;
    let (status, body) = request(&app, "POST", "/api/system/repair/pass", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let resumed = cleanup_poll(&app, "cleanup-worker").await;
    assert!(!resumed.is_null());
    let (status, body) = cleanup_finish(&app, &resumed, json!([{"type": "complete_workflow", "result": envelope(Payload::Null)}])).await;
    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!(body["reason"], "run_timed_out");
    let history = run_history(&app, &json!("tc-default"), &run).await;
    let timed_out = terminal(&history, "WorkflowTimedOut");
    let keys: Vec<&str> = timed_out.as_object().unwrap().keys().map(String::as_str).collect();
    assert_eq!(keys, ["failure_id", "timeout_kind", "failure_category", "message", "exception_class",
        "execution_deadline_at", "run_deadline_at", "task"]);
    assert_eq!(timed_out["message"], "Workflow execution deadline expired at 2000-01-01T00:00:00+00:00.");
    let (_, observed) = request(&app, "GET", &format!("/api/workflows/tc-default/runs/{}/observation", run.as_str().unwrap()), Value::Null).await;
    assert_eq!(observed["status"], "failed", "{observed}");
    assert!(observed.get("timeout_cleanup").is_none(), "{observed}");
    assert_listed(&app, "tc-default", &run, false).await;
}

// Vectors 1 and 6, the idle wake and the audited user request.
#[tokio::test]
async fn an_idle_run_enters_cleanup_once_at_its_work_deadline_and_completes_timed_out() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    cleanup_worker(&app, "cleanup-worker", true).await;
    let (run, history) = idle_in_cleanup(&app, "tc-completed").await;
    let started = terminal(&history, "WorkflowStarted");
    let entered = terminal(&history, "WorkflowTimeoutCleanupStarted");
    let hard = chrono::DateTime::parse_from_rfc3339(started["execution_deadline_at"].as_str().unwrap()).unwrap();
    let iso = |at: chrono::DateTime<chrono::FixedOffset>| at.format("%Y-%m-%dT%H:%M:%S+00:00").to_string();
    // Like Core's record with a command, the command's snapshot follows.
    let keys: Vec<&str> = entered.as_object().unwrap().keys().map(String::as_str).collect();
    assert_eq!(keys, ["workflow_command_id", "timeout_kind", "work_deadline_at", "cleanup_deadline_at", "hard_deadline_at", "detected_at", "command"]);
    assert_eq!(entered["command"]["source"], "system", "{entered}");
    assert_eq!(entered["timeout_kind"], "execution");
    assert_eq!(entered["work_deadline_at"], started["timeout_cleanup"]["work_deadlines"]["execution"]);
    assert_eq!(entered["cleanup_deadline_at"], json!(iso(hard)));
    assert_eq!(entered["hard_deadline_at"], json!(iso(hard)));
    let detected = chrono::DateTime::parse_from_rfc3339(entered["detected_at"].as_str().unwrap()).unwrap();
    assert!(detected >= hard - chrono::Duration::seconds(ALLOWANCE + 1) && detected < hard, "{entered}");
    // The engine-owned request: cooperative delivery with `cause: timeout`.
    let names = types(&history);
    let at = names.iter().position(|name| name == "WorkflowTimeoutCleanupStarted").unwrap();
    assert_eq!(names[at + 1], "CooperativeCancellationRequested", "{names:?}");
    let requested = &history[at + 1]["payload"];
    let keys: Vec<&str> = requested.as_object().unwrap().keys().map(String::as_str).collect();
    assert_eq!(keys, ["workflow_command_id", "workflow_instance_id", "workflow_run_id", "command_type", "reason",
        "cleanup_deadline_at", "cancellation", "command"]);
    assert_eq!(requested["workflow_command_id"], entered["workflow_command_id"]);
    assert_eq!(requested["reason"], "Workflow work deadline passed; timeout cleanup requested.");
    let context = &requested["cancellation"];
    assert_eq!(context["cause"], "timeout");
    assert_eq!(context["source"], "system");
    assert_eq!(context["requester"], json!({"type": "system", "id": "timeout_cleanup"}));
    assert_eq!(context["schema"], "durable-workflow.cancellation-context/v1");
    assert_eq!(context["request_id"], entered["workflow_command_id"]);
    let keys: Vec<&str> = context.as_object().unwrap().keys().map(String::as_str).collect();
    assert_eq!(keys, ["schema", "request_id", "root_request_id", "root_workflow_instance_id", "root_workflow_run_id",
        "parent_request_id", "reason", "requester", "source", "requested_at", "cleanup_deadline_at", "lineage", "cause"]);
    // Entered once, whatever notices later.
    tokio::time::sleep(std::time::Duration::from_millis(800)).await;
    let task = cleanup_poll(&app, "cleanup-worker").await;
    assert!(!task.is_null());
    assert_eq!(task["cancellation_request"]["request_id"], entered["workflow_command_id"]);
    let deadline = task["cancellation_request"]["cleanup_deadline_at"].clone();
    // A user's request during cleanup is audited, without replacing the
    // engine request or renewing its deadline.
    let before = task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_commands WHERE command_type='request_cancellation'").await;
    let (status, duplicate) = request(&app, "POST", &format!("/api/workflows/tc-completed/runs/{}/request-cancellation",
        run.as_str().unwrap()), json!({"reason": "user stop", "cleanup_timeout_seconds": 3600})).await;
    assert_eq!(status, StatusCode::OK, "{duplicate}");
    assert_eq!(duplicate["duplicate"], true);
    assert_eq!(duplicate["cancellation_request"]["request_id"], entered["workflow_command_id"]);
    assert_eq!(duplicate["cancellation_request"]["cleanup_deadline_at"], deadline);
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_commands WHERE command_type='request_cancellation'").await, before + 1);
    deliver(&app, &task).await;
    let (status, body) = cleanup_finish(&app, &task, json!([{"type": "complete_workflow", "result": envelope(Payload::Long(7))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &json!("tc-completed"), &run).await;
    assert_eq!(events_of(&history, "WorkflowTimeoutCleanupStarted").len(), 1);
    assert!(events_of(&history, "WorkflowCompleted").is_empty() && events_of(&history, "WorkflowCancelled").is_empty());
    let timed_out = terminal(&history, "WorkflowTimedOut");
    assert_eq!(timed_out["timeout_kind"], "execution_timeout");
    assert_eq!(timed_out["failure_category"], "timeout");
    assert_eq!(timed_out["message"], format!("Workflow execution deadline expired at {}. Timeout cleanup completed.", iso(hard)));
    let cleanup = &timed_out["timeout_cleanup"];
    let keys: Vec<&str> = cleanup.as_object().unwrap().keys().map(String::as_str).collect();
    assert_eq!(keys, ["outcome", "timeout_kind", "started_at", "cleanup_deadline_at", "finished_at"]);
    assert_eq!(cleanup["outcome"], "completed");
    assert_eq!(cleanup["timeout_kind"], "execution");
    assert_eq!(cleanup["started_at"], entered["detected_at"]);
    assert_eq!(cleanup["cleanup_deadline_at"], entered["cleanup_deadline_at"]);
    assert_eq!(names_tail(&history), ["CooperativeCancellationDelivered", "WorkflowTimedOut"]);
    dump("completed", &history);
    let (status, described) = request(&app, "GET", "/api/workflows/tc-completed", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    assert_eq!(described["status"], "failed");
    assert_eq!(described["closed_reason"], "timed_out");
    // The export says how cleanup ended, and replays the same history.
    let (status, export) = request(&app, "GET", &format!("/api/workflows/tc-completed/runs/{}/history/export", run.as_str().unwrap()), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{export}");
    let text = export.to_string();
    assert!(text.contains("Work deadline passed; timeout cleanup runs until"), "{text}");
    assert!(text.contains("Timeout cleanup completed."), "{text}");
    assert_eq!(run_history(&app, &json!("tc-completed"), &run).await, history);
}

fn names_tail(history: &Value) -> Vec<String> {
    let names = types(history);
    names[names.len() - 2..].to_vec()
}

// Vector 2: an ordinary failure during cleanup is linked; the run is still
// timed out. Only the reserved marker means the interruption went uncaught.
#[tokio::test]
async fn cleanup_failures_are_linked_and_the_interruption_marker_is_a_closed_set() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    cleanup_worker(&app, "cleanup-worker", true).await;
    // Outside cleanup, a marker is refused before any write.
    let (status, started) = start_with(&app, "tc-plain", json!({"execution_timeout_seconds": 600})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let plain = cleanup_poll(&app, "cleanup-worker").await;
    let before = run_history(&app, &json!("tc-plain"), &started["run_id"]).await;
    let marked = json!({"type": "fail_workflow", "message": "interrupted", "exception_class": "RuntimeException",
        "failure": {"interruption": "timeout_cleanup"}});
    let (status, body) = cleanup_finish(&app, &plain, json!([marked.clone()])).await;
    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!(body["reason"], "timeout_cleanup_interruption_not_active", "{body}");
    let mut unknown = marked.clone();
    unknown["failure"]["interruption"] = json!("cancellation");
    let (status, body) = cleanup_finish(&app, &plain, json!([unknown])).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["reason"], "invalid_workflow_failure_interruption", "{body}");
    assert_eq!(run_history(&app, &json!("tc-plain"), &started["run_id"]).await, before);

    for (workflow, marker) in [("tc-failed", false), ("tc-rethrown", true)] {
        let (run, _) = idle_in_cleanup(&app, workflow).await;
        let task = cleanup_poll(&app, "cleanup-worker").await;
        deliver(&app, &task).await;
        let failure = if marker { marked.clone() } else {
            json!({"type": "fail_workflow", "message": "compensation broke", "exception_class": "RuntimeException"})
        };
        let (status, body) = cleanup_finish(&app, &task, json!([failure])).await;
        assert_eq!(status, StatusCode::OK, "{workflow}: {body}");
        let history = run_history(&app, &json!(workflow), &run).await;
        if !marker {
            dump("failed", &history);
        }
        assert!(events_of(&history, "WorkflowFailed").is_empty(), "{workflow}");
        let timed_out = terminal(&history, "WorkflowTimedOut");
        assert_eq!(timed_out["failure_category"], "timeout", "{workflow}");
        let cleanup = &timed_out["timeout_cleanup"];
        if marker {
            assert_eq!(cleanup["outcome"], "completed", "{workflow}");
            assert!(cleanup.get("failure_id").is_none());
        } else {
            assert_eq!(cleanup["outcome"], "failed", "{workflow}");
            let failure_id = cleanup["failure_id"].as_str().unwrap();
            assert_ne!(Some(failure_id), timed_out["failure_id"].as_str());
            assert!(timed_out["message"].as_str().unwrap().ends_with("Timeout cleanup failed."), "{timed_out}");
            let keys: Vec<&str> = cleanup.as_object().unwrap().keys().map(String::as_str).collect();
            assert_eq!(keys, ["outcome", "timeout_kind", "started_at", "cleanup_deadline_at", "finished_at", "failure_id"]);
            let linked = task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_failures WHERE propagation_kind='timeout_cleanup' AND message='compensation broke'").await;
            assert_eq!(linked, 1);
        }
        let (_, described) = request(&app, "GET", &format!("/api/workflows/{workflow}"), Value::Null).await;
        assert_eq!((described["status"].as_str(), described["closed_reason"].as_str()), (Some("failed"), Some("timed_out")));
        assert_listed(&app, workflow, &run, true).await;
    }
}

// Vector 3 and an absent worker: the engine closes at the cleanup deadline,
// settling open work before the terminal event.
#[tokio::test]
async fn cleanup_expires_at_the_hard_deadline_without_a_worker() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    cleanup_worker(&app, "cleanup-worker", true).await;
    let (run, _) = idle_in_cleanup(&app, "tc-expired").await;
    database.execute("UPDATE workflow_runs SET execution_deadline_at='2000-01-01 00:00:00',cancellation_deadline_at='2000-01-01 00:00:00'").await;
    let history = wait_for(&app, "tc-expired", &run, "WorkflowTimedOut").await;
    assert_eq!(names_tail(&history), ["TimerCancelled", "WorkflowTimedOut"]);
    dump("expired", &history);
    let timed_out = terminal(&history, "WorkflowTimedOut");
    assert_eq!(timed_out["timeout_cleanup"]["outcome"], "expired");
    assert!(timed_out["timeout_cleanup"]["started_at"].is_string());
    assert!(timed_out["message"].as_str().unwrap().ends_with("Timeout cleanup did not finish before the hard deadline."));
    assert!(cleanup_poll(&app, "cleanup-worker").await.is_null());
    assert_listed(&app, "tc-expired", &run, true).await;
}

// A hard deadline that passes before cleanup was entered also expires it.
#[tokio::test]
async fn a_hard_deadline_before_detection_expires_without_entering() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    cleanup_worker(&app, "cleanup-worker", true).await;
    let started = start_opted_in(&app, "tc-late").await;
    database.execute("UPDATE workflow_runs SET execution_deadline_at='2000-01-01 00:00:00'").await;
    let history = wait_for(&app, "tc-late", &started["run_id"], "WorkflowTimedOut").await;
    assert!(events_of(&history, "WorkflowTimeoutCleanupStarted").is_empty());
    let cleanup = &terminal(&history, "WorkflowTimedOut")["timeout_cleanup"];
    assert_eq!(cleanup["outcome"], "expired");
    assert!(cleanup.get("started_at").is_none(), "{cleanup}");
    assert_eq!(cleanup["cleanup_deadline_at"], "2000-01-01T00:00:00+00:00");
    assert_listed(&app, "tc-late", &started["run_id"], true).await;
}

// Vector 4: terminate and hard cancel preempt cleanup.
#[tokio::test]
async fn terminate_and_hard_cancel_preempt_cleanup() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    cleanup_worker(&app, "cleanup-worker", true).await;
    for (workflow, verb, event) in [("tc-terminated", "terminate", "WorkflowTerminated"), ("tc-cancelled", "cancel", "WorkflowCancelled")] {
        let (run, _) = idle_in_cleanup(&app, workflow).await;
        let (status, body) = request(&app, "POST", &format!("/api/workflows/{workflow}/{verb}"), json!({"reason": "operator"})).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        let history = run_history(&app, &json!(workflow), &run).await;
        assert!(events_of(&history, "WorkflowTimedOut").is_empty());
        dump(if verb == "terminate" { "preempted_terminate" } else { "preempted_cancel" }, &history);
        let closed = terminal(&history, event);
        assert_eq!(closed["timeout_cleanup"]["outcome"], "preempted", "{closed}");
        assert_eq!(closed["timeout_cleanup"]["timeout_kind"], "execution");
        let (_, observed) = request(&app, "GET", &format!("/api/workflows/{workflow}/runs/{}/observation", run.as_str().unwrap()), Value::Null).await;
        assert_eq!(observed["timeout_cleanup"], closed["timeout_cleanup"], "{observed}");
        assert_listed(&app, workflow, &run, true).await;
    }
}

// Vector 10: an earlier user request owns cleanup, capped at the hard
// deadline; the hard timeout wins and records it preempted.
#[tokio::test]
async fn an_earlier_user_request_owns_cleanup_and_loses_to_the_hard_timeout() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    cleanup_worker(&app, "cleanup-worker", true).await;
    let started = start_opted_in(&app, "tc-user").await;
    let run = started["run_id"].clone();
    let first = cleanup_poll(&app, "cleanup-worker").await;
    let (status, body) = cleanup_finish(&app, &first, json!([{"type": "start_timer", "delay_seconds": 3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, accepted) = request(&app, "POST", &format!("/api/workflows/tc-user/runs/{}/request-cancellation", run.as_str().unwrap()),
        json!({"reason": "user", "cleanup_timeout_seconds": 120})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{accepted}");
    let history = run_history(&app, &json!("tc-user"), &run).await;
    let hard = chrono::DateTime::parse_from_rfc3339(terminal(&history, "WorkflowStarted")["execution_deadline_at"].as_str().unwrap()).unwrap();
    let capped = chrono::DateTime::parse_from_rfc3339(accepted["cancellation_request"]["cleanup_deadline_at"].as_str().unwrap()).unwrap();
    assert!((capped - hard).num_milliseconds().abs() < 1000, "{capped} vs {hard}");
    tokio::time::sleep(std::time::Duration::from_secs(3)).await;
    let history = run_history(&app, &json!("tc-user"), &run).await;
    assert!(events_of(&history, "WorkflowTimeoutCleanupStarted").is_empty(), "an earlier request owns cleanup");
    database.execute("UPDATE workflow_runs SET execution_deadline_at='2000-01-01 00:00:00',cancellation_deadline_at='2000-01-01 00:00:00'").await;
    let history = wait_for(&app, "tc-user", &run, "WorkflowTimedOut").await;
    dump("user_request_preempted", &history);
    assert!(events_of(&history, "WorkflowCancelled").is_empty());
    let timed_out = terminal(&history, "WorkflowTimedOut");
    assert!(timed_out.get("timeout_cleanup").is_none(), "{timed_out}");
    assert_eq!(timed_out["cancellation_cleanup"]["outcome"], "preempted");
    assert_eq!(timed_out["cancellation_cleanup"]["request_id"], accepted["cancellation_request"]["request_id"]);
}

// Vectors 7 and 8: continue-as-new carries the allowance; the execution
// work deadline stays, the run clock is the successor's own.
#[tokio::test]
async fn continue_as_new_carries_the_allowance_and_execution_clock() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    cleanup_worker(&app, "cleanup-worker", true).await;
    let (status, started) = start_with(&app, "tc-continued", json!({"execution_timeout_seconds": 600, "run_timeout_seconds": 300,
        "timeout_cleanup_seconds": ALLOWANCE})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let original = run_history(&app, &json!("tc-continued"), &started["run_id"]).await;
    let original = terminal(&original, "WorkflowStarted")["timeout_cleanup"].clone();
    let task = cleanup_poll(&app, "cleanup-worker").await;
    let (status, body) = cleanup_finish(&app, &task, json!([{"type": "continue_as_new", "arguments": envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (_, described) = request(&app, "GET", "/api/workflows/tc-continued", Value::Null).await;
    let next = described["run_id"].clone();
    assert_ne!(next, started["run_id"]);
    let history = run_history(&app, &json!("tc-continued"), &next).await;
    let successor = terminal(&history, "WorkflowStarted");
    let cleanup = &successor["timeout_cleanup"];
    assert_eq!(cleanup["allowance_seconds"], ALLOWANCE);
    assert_eq!(cleanup["work_deadlines"]["execution"], original["work_deadlines"]["execution"]);
    let (_, run) = request(&app, "GET", &format!("/api/workflows/tc-continued/runs/{}", next.as_str().unwrap()), Value::Null).await;
    let run_deadline = chrono::DateTime::parse_from_rfc3339(run["run_deadline_at"].as_str().unwrap()).unwrap();
    let expected = (run_deadline - chrono::Duration::seconds(ALLOWANCE)).format("%Y-%m-%dT%H:%M:%S+00:00").to_string();
    assert_eq!(cleanup["work_deadlines"]["run"], json!(expected));
}

/// Renames the recorded work deadlines so the idle wake does not see them:
/// the run stays opted in (its allowance is unchanged) and only a claim or a
/// completion can notice the work deadline.
async fn hide_from_idle_wake(database: &TestDatabase) {
    match database {
        TestDatabase::Sqlite(_) => database.execute("UPDATE workflow_history_events SET payload=replace(payload,'\"work_deadlines\"','\"hidden_work_deadlines\"') WHERE event_type='WorkflowStarted'").await,
        TestDatabase::Postgres { .. } => database.execute("UPDATE workflow_history_events SET payload=replace(payload::text,'\"work_deadlines\"','\"hidden_work_deadlines\"')::json WHERE event_type='WorkflowStarted'").await,
        TestDatabase::MySql { .. } => database.execute("UPDATE workflow_history_events SET payload=REPLACE(payload,'\"work_deadlines\"','\"hidden_work_deadlines\"') WHERE event_type='WorkflowStarted'").await,
    }
}

fn instant(value: &Value) -> chrono::DateTime<chrono::FixedOffset> {
    chrono::DateTime::parse_from_rfc3339(value.as_str().unwrap()).unwrap()
}

async fn until_work_deadline_passed(history: &Value) {
    let hard = instant(&terminal(history, "WorkflowStarted")["execution_deadline_at"]);
    let work = hard - chrono::Duration::seconds(ALLOWANCE) + chrono::Duration::milliseconds(400);
    let wait = (work.with_timezone(&chrono::Utc) - chrono::Utc::now()).to_std().unwrap_or_default();
    tokio::time::sleep(wait).await;
}

// Vector 5 at a claim and at a completion: whichever notices the work
// deadline enters cleanup; the cleanup deadline stays the hard deadline,
// never detection plus the allowance.
#[tokio::test]
async fn a_claim_or_a_completion_that_first_notices_the_work_deadline_enters_cleanup() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    cleanup_worker(&app, "cleanup-worker", true).await;
    // At a claim.
    let started = start_opted_in(&app, "tc-claimed").await;
    hide_from_idle_wake(&database).await;
    let history = run_history(&app, &json!("tc-claimed"), &started["run_id"]).await;
    until_work_deadline_passed(&history).await;
    tokio::time::sleep(std::time::Duration::from_millis(600)).await;
    let before = run_history(&app, &json!("tc-claimed"), &started["run_id"]).await;
    assert!(events_of(&before, "WorkflowTimeoutCleanupStarted").is_empty(), "the idle wake must not see a hidden deadline");
    let claimed = cleanup_poll(&app, "cleanup-worker").await;
    assert!(!claimed.is_null());
    let history = run_history(&app, &json!("tc-claimed"), &started["run_id"]).await;
    let entered = terminal(&history, "WorkflowTimeoutCleanupStarted");
    assert_eq!(claimed["cancellation_request"]["request_id"], entered["workflow_command_id"], "{claimed}");
    let hard = instant(&terminal(&history, "WorkflowStarted")["execution_deadline_at"]);
    let detected = instant(&entered["detected_at"]);
    assert!(detected > hard - chrono::Duration::seconds(ALLOWANCE), "{entered}");
    assert_eq!(entered["cleanup_deadline_at"], json!(hard.format("%Y-%m-%dT%H:%M:%S+00:00").to_string()));
    assert_eq!(entered["hard_deadline_at"], entered["cleanup_deadline_at"]);

    // At a completion: it enters before its commands apply.
    let started = start_opted_in(&app, "tc-completion").await;
    hide_from_idle_wake(&database).await;
    let task = cleanup_poll(&app, "cleanup-worker").await;
    assert_eq!(task["workflow_id"], "tc-completion");
    let history = run_history(&app, &json!("tc-completion"), &started["run_id"]).await;
    until_work_deadline_passed(&history).await;
    let (status, body) = cleanup_finish(&app, &task, json!([{"type": "start_timer", "delay_seconds": 3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &json!("tc-completion"), &started["run_id"]).await;
    let names = types(&history);
    let at = names.iter().position(|name| name == "WorkflowTimeoutCleanupStarted").expect("entered at completion");
    assert_eq!(&names[at..at + 3], ["WorkflowTimeoutCleanupStarted", "CooperativeCancellationRequested", "TimerScheduled"]);
    let resumed = cleanup_poll(&app, "cleanup-worker").await;
    assert_eq!(resumed["cancellation_request"]["request_id"], terminal(&history, "WorkflowTimeoutCleanupStarted")["workflow_command_id"]);
}

// Vector 9: the run deadline is earlier, so cleanup is for kind `run` and
// ends at the run deadline. Describe follows the state.
#[tokio::test]
async fn the_earliest_hard_deadline_names_the_kind_and_describe_follows_the_state() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    cleanup_worker(&app, "cleanup-worker", true).await;
    let (status, started) = start_with(&app, "tc-run-kind", json!({"execution_timeout_seconds": 30, "run_timeout_seconds": EXECUTION,
        "timeout_cleanup_seconds": ALLOWANCE})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let run = started["run_id"].clone();
    let (_, described) = request(&app, "GET", "/api/workflows/tc-run-kind", Value::Null).await;
    assert_eq!(described["timeout_cleanup"]["state"], "armed", "{described}");
    assert_eq!(described["timeout_cleanup"]["cause"], Value::Null);
    assert_eq!(described["timeout_cleanup"]["outcome"], Value::Null);
    let observation = format!("/api/workflows/tc-run-kind/runs/{}/observation", run.as_str().unwrap());
    let (_, observed) = request(&app, "GET", &observation, Value::Null).await;
    assert!(observed.get("timeout_cleanup").is_none(), "nothing recorded while armed: {observed}");
    assert_listed(&app, "tc-run-kind", &run, false).await;
    assert_eq!(described["timeout_cleanup"]["allowance_seconds"], ALLOWANCE);
    assert_eq!(described["timeout_cleanup"]["timeout_kind"], "run");
    let first = cleanup_poll(&app, "cleanup-worker").await;
    let (status, body) = cleanup_finish(&app, &first, json!([{"type": "start_timer", "delay_seconds": 3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = wait_for(&app, "tc-run-kind", &run, "WorkflowTimeoutCleanupStarted").await;
    let entered = terminal(&history, "WorkflowTimeoutCleanupStarted");
    let run_deadline = instant(&terminal(&history, "WorkflowStarted")["run_deadline_at"]);
    let iso = run_deadline.format("%Y-%m-%dT%H:%M:%S+00:00").to_string();
    assert_eq!(entered["timeout_kind"], "run");
    assert_eq!(entered["cleanup_deadline_at"], json!(iso));
    assert_eq!(entered["work_deadline_at"], terminal(&history, "WorkflowStarted")["timeout_cleanup"]["work_deadlines"]["run"]);
    let (_, described) = request(&app, "GET", "/api/workflows/tc-run-kind", Value::Null).await;
    let view = &described["timeout_cleanup"];
    assert_eq!((view["state"].as_str(), view["cause"].as_str()), (Some("cancellation_cleanup_running"), Some("timeout")), "{view}");
    assert_eq!(view["outcome"], "running", "the portable open-cleanup outcome");
    let (_, observed) = request(&app, "GET", &observation, Value::Null).await;
    assert_eq!(observed["timeout_cleanup"], json!({"outcome": "running", "cause": "timeout", "timeout_kind": "run",
        "started_at": entered["detected_at"], "work_deadline_at": entered["work_deadline_at"],
        "cleanup_deadline_at": entered["cleanup_deadline_at"]}), "{observed}");
    assert_listed(&app, "tc-run-kind", &run, true).await;
    assert_eq!(view["started_at"], entered["detected_at"]);
    assert_eq!(view["cleanup_deadline_at"], json!(iso));
    let task = cleanup_poll(&app, "cleanup-worker").await;
    deliver(&app, &task).await;
    let (status, body) = cleanup_finish(&app, &task, json!([{"type": "complete_workflow", "result": envelope(Payload::Null)}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &json!("tc-run-kind"), &run).await;
    let timed_out = terminal(&history, "WorkflowTimedOut");
    assert_eq!(timed_out["timeout_kind"], "run_timeout");
    assert_eq!(timed_out["timeout_cleanup"]["timeout_kind"], "run");
    assert!(timed_out["message"].as_str().unwrap().starts_with(&format!("Workflow run deadline expired at {iso}.")), "{timed_out}");
    let (_, described) = request(&app, "GET", &format!("/api/workflows/tc-run-kind/runs/{}", run.as_str().unwrap()), Value::Null).await;
    assert_eq!(described["timeout_cleanup"]["state"], "finished", "{described}");
    assert_eq!(described["timeout_cleanup"]["outcome"], "completed");
    let (_, observed) = request(&app, "GET", &observation, Value::Null).await;
    assert_eq!(observed["timeout_cleanup"], timed_out["timeout_cleanup"], "{observed}");
    assert_eq!(observed["timeout_cleanup"]["outcome"], "completed");
    assert_listed(&app, "tc-run-kind", &run, true).await;
}

// Vector 6 and worker replacement: the task holding the cleanup turn moves
// to another worker; nothing is entered twice and no deadline moves.
#[tokio::test]
async fn a_replaced_worker_keeps_one_entry_and_the_original_deadline() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    cleanup_worker(&app, "cleanup-worker", true).await;
    let (run, _) = idle_in_cleanup(&app, "tc-replaced").await;
    let original = cleanup_poll(&app, "cleanup-worker").await;
    assert!(!original.is_null());
    database.execute("UPDATE workflow_tasks SET lease_expires_at='2000-01-01 00:00:00' WHERE status='leased'").await;
    cleanup_worker(&app, "replacement-worker", true).await;
    let replacement = cleanup_poll(&app, "replacement-worker").await;
    assert_eq!(replacement["task_id"], original["task_id"], "{replacement}");
    assert_ne!(replacement["workflow_task_attempt"], original["workflow_task_attempt"]);
    assert_eq!(replacement["cancellation_request"]["request_id"], original["cancellation_request"]["request_id"]);
    assert_eq!(replacement["cancellation_request"]["cleanup_deadline_at"], original["cancellation_request"]["cleanup_deadline_at"]);
    let (status, stale) = cleanup_finish(&app, &original, json!([{"type": "complete_workflow", "result": envelope(Payload::Null)}])).await;
    assert_eq!(status, StatusCode::CONFLICT, "{stale}");
    deliver(&app, &replacement).await;
    let (status, body) = cleanup_finish(&app, &replacement, json!([{"type": "complete_workflow", "result": envelope(Payload::Null)}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &json!("tc-replaced"), &run).await;
    assert_eq!(events_of(&history, "WorkflowTimeoutCleanupStarted").len(), 1);
    assert_eq!(events_of(&history, "CooperativeCancellationRequested").len(), 1);
    assert_eq!(terminal(&history, "WorkflowTimedOut")["timeout_cleanup"]["outcome"], "completed");
}

// A replay-blocking task failure during cleanup stays blocked for repair:
// it is never a cleanup failure. The hard deadline still expires it.
#[tokio::test]
async fn a_replay_blocking_failure_during_cleanup_stays_blocked_and_then_expires() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    cleanup_worker(&app, "cleanup-worker", true).await;
    let (run, _) = idle_in_cleanup(&app, "tc-blocked").await;
    let task = cleanup_poll(&app, "cleanup-worker").await;
    let (status, body) = request_protocol(&app, "POST", &format!("/api/worker/workflow-tasks/{}/fail", task["task_id"].as_str().unwrap()),
        json!({"lease_owner": task["lease_owner"], "workflow_task_attempt": task["workflow_task_attempt"],
            "failure": {"type": "NondeterministicReplayException", "message": "nondeterministic replay of cleanup"}}), "1.20").await;
    assert!(status.is_success(), "{status}: {body}");
    let history = run_history(&app, &json!("tc-blocked"), &run).await;
    assert!(events_of(&history, "WorkflowTimedOut").is_empty() && events_of(&history, "WorkflowFailed").is_empty(), "{history}");
    let (_, described) = request(&app, "GET", "/api/workflows/tc-blocked", Value::Null).await;
    assert_eq!(described["timeout_cleanup"]["state"], "cancellation_cleanup_running", "{described}");
    database.execute("UPDATE workflow_runs SET execution_deadline_at='2000-01-01 00:00:00',cancellation_deadline_at='2000-01-01 00:00:00'").await;
    let history = wait_for(&app, "tc-blocked", &run, "WorkflowTimedOut").await;
    assert_eq!(terminal(&history, "WorkflowTimedOut")["timeout_cleanup"]["outcome"], "expired");
}

// A child opts in on its own: the start's admission rules, on the child's
// queue. The parent's allowance never carries over.
#[tokio::test]
async fn a_child_opts_in_under_the_same_admission_rules() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    cleanup_worker(&app, "cleanup-worker", true).await;
    let child = |extra: Value| {
        let mut command = json!({"type": "start_child_workflow", "workflow_type": "echo", "arguments": envelope(Payload::Array(vec![]))});
        for (key, value) in extra.as_object().unwrap() {
            command[key] = value.clone();
        }
        command
    };
    let (status, parent) = start_with(&app, "tc-parent", json!({})).await;
    assert_eq!(status, StatusCode::CREATED, "{parent}");
    let task = cleanup_poll(&app, "cleanup-worker").await;
    for (extra, message) in [
        (json!({"timeout_cleanup_seconds": 10}), "Workflow v2 timeout cleanup needs an execution or run timeout to reserve time from."),
        (json!({"execution_timeout_seconds": 10, "timeout_cleanup_seconds": 10}),
            "Workflow v2 timeout cleanup (10 s) must be shorter than the execution timeout (10 s)."),
        (json!({"execution_timeout_seconds": 100, "timeout_cleanup_seconds": 0}), "The commands.0.timeout_cleanup_seconds field must be at least 1."),
    ] {
        let (status, refused) = cleanup_finish(&app, &task, json!([child(extra.clone())])).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{extra}: {refused}");
        assert_eq!(refused["errors"]["commands.0.timeout_cleanup_seconds"], json!([message]), "{extra}: {refused}");
    }
    let (status, refused) = cleanup_finish(&app, &task, json!([{"type": "start_timer", "delay_seconds": 5, "timeout_cleanup_seconds": 2}])).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
    // No live capable worker on the child's queue: refused, nothing written.
    let before = run_history(&app, &json!("tc-parent"), &parent["run_id"]).await;
    let (status, refused) = cleanup_finish(&app, &task, json!([child(json!({"queue": "elsewhere", "execution_timeout_seconds": 600,
        "timeout_cleanup_seconds": ALLOWANCE}))])).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
    assert_eq!(refused["reason"], "timeout_cleanup_not_supported", "{refused}");
    assert_eq!(run_history(&app, &json!("tc-parent"), &parent["run_id"]).await, before);
    let (status, body) = cleanup_finish(&app, &task, json!([child(json!({"execution_timeout_seconds": 600, "run_timeout_seconds": 300,
        "timeout_cleanup_seconds": ALLOWANCE}))])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &json!("tc-parent"), &parent["run_id"]).await;
    assert!(terminal(&history, "WorkflowStarted").get("timeout_cleanup").is_none(), "the parent did not opt in");
    let started = &events_of(&history, "ChildRunStarted")[0]["payload"];
    let child_history = run_history(&app, &started["child_workflow_instance_id"], &started["child_workflow_run_id"]).await;
    let child_started = terminal(&child_history, "WorkflowStarted");
    let hard = instant(&child_started["execution_deadline_at"]);
    let run_hard = instant(&child_started["run_deadline_at"]);
    let iso = |at: chrono::DateTime<chrono::FixedOffset>| at.format("%Y-%m-%dT%H:%M:%S+00:00").to_string();
    assert_eq!(child_started["timeout_cleanup"], json!({"allowance_seconds": ALLOWANCE, "work_deadlines": {
        "execution": iso(hard - chrono::Duration::seconds(ALLOWANCE)), "run": iso(run_hard - chrono::Duration::seconds(ALLOWANCE))}}));
}

// Scoped descendants never inherit the root's cause: a child cancelled
// through the timeout request sees an ordinary cancellation.
#[tokio::test]
async fn descendants_of_the_timeout_request_do_not_inherit_its_cause() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    cleanup_worker(&app, "cleanup-worker", true).await;
    let started = start_opted_in(&app, "tc-descendants").await;
    let run = started["run_id"].clone();
    let first = cleanup_poll(&app, "cleanup-worker").await;
    let (status, body) = cleanup_finish(&app, &first, json!([{"type": "start_child_workflow", "workflow_type": "echo",
        "queue": "test", "arguments": envelope(Payload::Array(vec![])), "cancellation_policy": "try_cancel"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = wait_for(&app, "tc-descendants", &run, "WorkflowTimeoutCleanupStarted").await;
    let request_id = terminal(&history, "WorkflowTimeoutCleanupStarted")["workflow_command_id"].clone();
    let child = &events_of(&history, "ChildRunStarted")[0]["payload"];
    // The parent's turn delivers at the child boundary.
    let mut task = cleanup_poll(&app, "cleanup-worker").await;
    while task["workflow_id"] != "tc-descendants" {
        assert!(!task.is_null(), "the parent's cleanup turn");
        task = cleanup_poll(&app, "cleanup-worker").await;
    }
    let (status, delivered) = request_protocol(&app, "POST",
        &format!("/api/worker/workflow-tasks/{}/deliver-cancellation", task["task_id"].as_str().unwrap()),
        json!({"lease_owner": task["lease_owner"], "workflow_task_attempt": task["workflow_task_attempt"],
            "request_id": request_id, "sequence": 1, "call_kind": "child"}), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{delivered}");
    let child_history = run_history(&app, &child["child_workflow_instance_id"], &child["child_workflow_run_id"]).await;
    let requested = events_of(&child_history, "CooperativeCancellationRequested");
    assert_eq!(requested.len(), 1, "{child_history}");
    let context = &requested[0]["payload"]["cancellation"];
    assert!(context.get("cause").is_none(), "{context}");
    assert_eq!(context["root_request_id"], request_id);
    assert_eq!(context["parent_request_id"], request_id);
    assert!(events_of(&child_history, "WorkflowTimeoutCleanupStarted").is_empty());
}
