use super::*;

// Server #994, to Security's contract (issue comment 6613395001): a workflow
// task report (completion, failure or cancellation scope checkpoint) refused
// `task_not_leased` or `run_closed` has recorded nothing, and its 409 carries
// the full not-recorded receipt in the worker envelope: `recorded: false`,
// the request's exact `task_id` and `workflow_task_attempt`, and the task
// row's own `run_id`, beside the refusal's fields. Every other refusal stays
// fatal to a worker and gains none of it.

const ROUTES: [&str; 3] = ["complete", "fail", "checkpoint"];

/// A late report on `route` for `task`, claimed by `owner` at `attempt`.
async fn report(app: &Router, task: &Value, route: &str, owner: &str, attempt: i64) -> (StatusCode, Value) {
    let task_id = task["task_id"].as_str().unwrap();
    let claim = json!({"lease_owner":owner,"workflow_task_attempt":attempt});
    let with = |fields: Value| {
        let mut body = claim.clone();
        body.as_object_mut().unwrap().extend(fields.as_object().unwrap().clone());
        body
    };
    match route {
        "complete" => {
            let body = with(json!({"commands":[{"type":"complete_workflow","result":envelope(Payload::Long(7))}]}));
            request(app, "POST", &format!("/api/worker/workflow-tasks/{task_id}/complete"), body).await
        }
        "fail" => {
            let body = with(json!({"failure":{"type":"RuntimeException","message":"late report"}}));
            request(app, "POST", &format!("/api/worker/workflow-tasks/{task_id}/fail"), body).await
        }
        "waiting" => {
            let body = with(json!({"failure":{"type":"WorkflowTaskWaitingForHistory","message":"history is not ready yet"}}));
            request(app, "POST", &format!("/api/worker/workflow-tasks/{task_id}/fail"), body).await
        }
        _ => {
            let body = with(json!({"checkpoint_id":"late","start_sequence":1,
                "commands":[{"type":"start_timer","delay_seconds":60}]}));
            let path = format!("/api/worker/workflow-tasks/{task_id}/cancellation-scopes/checkpoint");
            request_protocol(app, "POST", &path, body, "1.20").await
        }
    }
}

/// The refusal's public worker body without its capability manifest, which
/// must be present.
fn public_body(refused: &(StatusCode, Value), label: &str) -> Value {
    let (status, body) = refused;
    assert_eq!(*status, StatusCode::CONFLICT, "{label}: {body}");
    let mut body = body.clone();
    let fields = body.as_object_mut().unwrap();
    assert!(fields.remove("server_capabilities").is_some_and(|manifest| manifest.is_object()), "{label}: {body}");
    body
}

/// The exact receipt a not-recorded refusal must carry.
fn receipt(task: &Value, attempt: i64) -> Value {
    json!({"task_id":task["task_id"],"workflow_task_attempt":attempt,"recorded":false,"run_id":task["run_id"]})
}

fn merged(base: Value, extra: Value) -> Value {
    let mut base = base;
    base.as_object_mut().unwrap().extend(extra.as_object().unwrap().clone());
    base
}

async fn claimed(app: &Router, workflow: &str) -> (Value, Value) {
    register(app, "nr-worker", json!(["echo"]), json!([])).await;
    let started = start(app, workflow).await;
    let task = poll(app, "nr-worker", "workflow").await;
    assert_eq!(task["run_id"], started["run_id"], "{task}");
    assert!(task["run_id"].as_str().is_some_and(|run| !run.is_empty()));
    (started, task)
}

/// Durable state a late report must leave unchanged: every table's row
/// count, the run's history and the stored completion receipts.
async fn durable(database: &TestDatabase, app: &Router, started: &Value) -> Value {
    json!({"tables":table_counts(database).await,
        "history":run_history(app, &started["workflow_id"], &started["run_id"]).await,
        "receipts":text_rows(database, "SELECT task_id, receipt FROM dw_task_completions ORDER BY task_id",
            "SELECT task_id, receipt::text FROM dw_task_completions ORDER BY task_id",
            "SELECT task_id, CAST(receipt AS CHAR) FROM dw_task_completions ORDER BY task_id").await})
}

/// `run_closed` for every terminal status the guard recognizes, on every
/// report route: the guard's closed-run body plus the receipt, nothing
/// recorded.
#[tokio::test]
async fn a_report_on_every_closed_run_status_is_run_closed_with_the_full_receipt() {
    for close in ["completed", "failed", "timed_out", "cancelled", "terminated"] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let workflow = format!("nr-closed-{close}");
        let (started, task) = claimed(&app, &workflow).await;
        let run_id = started["run_id"].as_str().unwrap();
        // How the run closes while the worker's report is late.
        let (run_status, stop_reason, closed_reason, task_status, cancel_requested) = match close {
            "completed" | "failed" => {
                let command = if close == "completed" {
                    json!({"type":"complete_workflow","result":envelope(Payload::Long(1))})
                } else {
                    json!({"type":"fail_workflow","message":"boom"})
                };
                let done = finish_task(&app, &task, json!([command])).await;
                assert_eq!(done.0, StatusCode::OK, "{close}: {}", done.1);
                // A terminal fail_workflow leaves its task failed.
                let (stop, task_status) = if close == "completed" { ("run_completed", "completed") } else { ("run_failed", "failed") };
                (close, stop, close, task_status, false)
            }
            "timed_out" => {
                database.execute("UPDATE workflow_runs SET run_deadline_at='2000-01-01 00:00:00'").await;
                let timed_out = report(&app, &task, "complete", "nr-worker", 1).await;
                assert_eq!(
                    (timed_out.0, &timed_out.1["reason"], &timed_out.1["recorded"]),
                    (StatusCode::CONFLICT, &json!("run_timed_out"), &json!(false)),
                    "{}",
                    timed_out.1
                );
                ("failed", "run_failed", "timed_out", "completed", false)
            }
            _ => {
                let path = format!("/api/workflows/{workflow}/runs/{run_id}/{}", if close == "cancelled" { "cancel" } else { "terminate" });
                let closed = request(&app, "POST", &path, json!({"reason":"operator"})).await;
                assert_eq!(closed.0, StatusCode::OK, "{close}: {}", closed.1);
                let stop = if close == "cancelled" { "run_cancelled" } else { "run_terminated" };
                (close, stop, close, "cancelled", true)
            }
        };
        let before = durable(&database, &app, &started).await;
        for route in ROUTES {
            let label = format!("{close} {route}");
            let mut body = public_body(&report(&app, &task, route, "nr-worker", 1).await, &label);
            let closed_at = body.as_object_mut().unwrap().remove("run_closed_at").unwrap_or_default();
            assert!(closed_at.as_str().is_some_and(|at| at.ends_with('Z') && at.len() == 27), "{label}: {closed_at}");
            assert_eq!(
                body,
                merged(
                    receipt(&task, 1),
                    json!({"error":"Workflow run is already closed.","reason":"run_closed","protocol_version":"1.20",
                        "stop_reason":stop_reason,"cancel_requested":cancel_requested,"can_continue":false,
                        "run_status":run_status,"run_closed_reason":closed_reason,"task_status":task_status,
                        "lease_owner":"nr-worker","lease_expires_at":null})
                ),
                "{label}"
            );
        }
        assert_eq!(durable(&database, &app, &started).await, before, "{close}: the late reports recorded nothing");
        runtime.close().await;
        database.remove().await;
    }
}

/// `task_not_leased` for a task completed on a run that is still open, and
/// for the waiting acknowledgement's duplicate path, whose task holds no
/// completion receipt.
#[tokio::test]
async fn a_report_for_a_finished_task_on_an_open_run_is_task_not_leased_with_the_full_receipt() {
    for finish in ["completed", "waiting"] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let (started, task) = claimed(&app, &format!("nr-not-leased-{finish}")).await;
        if finish == "completed" {
            let done = finish_task(&app, &task, json!([{"type":"start_timer","delay_seconds":3600}])).await;
            assert_eq!(done.0, StatusCode::OK, "{}", done.1);
        } else {
            let acknowledged = report(&app, &task, "waiting", "nr-worker", 1).await;
            assert_eq!(
                (acknowledged.0, &acknowledged.1["outcome"]),
                (StatusCode::OK, &json!("waiting_for_history")),
                "{}",
                acknowledged.1
            );
        }
        let before = durable(&database, &app, &started).await;
        for route in ["complete", "fail", "waiting", "checkpoint"] {
            let label = format!("{finish} {route}");
            assert_eq!(
                public_body(&report(&app, &task, route, "nr-worker", 1).await, &label),
                merged(
                    receipt(&task, 1),
                    json!({"error":"Workflow task is not currently leased.","reason":"task_not_leased",
                        "protocol_version":"1.20","task_status":"completed"})
                ),
                "{label}"
            );
        }
        assert_eq!(durable(&database, &app, &started).await, before, "{finish}: the late reports recorded nothing");
        runtime.close().await;
        database.remove().await;
    }
}

/// A run closed by another path while its task kept the lease passes the
/// ownership guard and is refused by the open-run check: the bare refusal
/// becomes the same receipt in the worker envelope.
#[tokio::test]
async fn a_report_on_a_closed_run_whose_task_is_still_leased_carries_the_full_receipt() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (started, task) = claimed(&app, "nr-leased-closed").await;
    database.execute("UPDATE workflow_runs SET status='failed'").await;
    let before = durable(&database, &app, &started).await;
    for route in ["complete", "fail"] {
        assert_eq!(
            public_body(&report(&app, &task, route, "nr-worker", 1).await, route),
            merged(
                receipt(&task, 1),
                json!({"reason":"run_closed","message":"run_closed","protocol_version":"1.20","task_status":"leased"})
            ),
            "{route}"
        );
    }
    assert_eq!(durable(&database, &app, &started).await, before);
    runtime.close().await;
    database.remove().await;
}

/// The refusals that are not settlement authority keep their published
/// bodies: no `recorded`, no `run_id`.
#[tokio::test]
async fn fatal_report_refusals_gain_no_receipt() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (started, replaced) = claimed(&app, "nr-fatal").await;
    // Registering the same worker again releases its lease; the task is
    // claimed again as attempt 2.
    register(&app, "nr-worker", json!(["echo"]), json!([])).await;
    let current = poll(&app, "nr-worker", "workflow").await;
    assert_eq!((&current["task_id"], &current["workflow_task_attempt"]), (&replaced["task_id"], &json!(2)));
    let before = durable(&database, &app, &started).await;
    let fence = |attempt: i64, fields: Value| {
        merged(json!({"task_id":replaced["task_id"],"workflow_task_attempt":attempt,"protocol_version":"1.20"}), fields)
    };
    for route in ROUTES {
        assert_eq!(
            public_body(&report(&app, &replaced, route, "nr-worker", 1).await, route),
            fence(1, json!({"error":"Workflow task lease attempt does not match the current claim.",
                "reason":"workflow_task_attempt_mismatch","current_attempt":2})),
            "{route}: attempt mismatch"
        );
        assert_eq!(
            public_body(&report(&app, &replaced, route, "nr-other-worker", 2).await, route),
            fence(2, json!({"error":"Workflow task lease is owned by another worker.",
                "reason":"lease_owner_mismatch","lease_owner":"nr-worker"})),
            "{route}: owner mismatch"
        );
    }
    database.set_time(false, "2000-01-01T00:00:00Z").await;
    for route in ROUTES {
        let body = public_body(&report(&app, &replaced, route, "nr-worker", 2).await, route);
        assert_eq!(body["reason"], "lease_expired", "{route}: {body}");
        assert!(body.get("recorded").is_none() && body.get("run_id").is_none(), "{route}: {body}");
    }
    assert_eq!(durable(&database, &app, &started).await, before);
    runtime.close().await;
    database.remove().await;
}

/// The shared checkpoint wrapper: a cooperative claim still leased whose
/// run closes after the ownership guard reaches Core's checkpoint, whose
/// refused `run_closed` answer carries the same receipt.
#[tokio::test]
async fn a_checkpoint_whose_run_closed_after_the_guard_carries_the_full_receipt() {
    for status in ["completed", "failed", "cancelled", "terminated"] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let registered = request_protocol(&app, "POST", "/api/worker/register",
            json!({"worker_id":"nr-worker","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
                "supported_activity_types":[],"capabilities":["cooperative_cancellation"]}), "1.20").await;
        assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
        let started = start(&app, &format!("nr-checkpoint-{status}")).await;
        let claimed = request_protocol(&app, "POST", "/api/worker/workflow-tasks/poll",
            json!({"worker_id":"nr-worker","task_queue":"test","timeout_seconds":2}), "1.20").await;
        let task = claimed.1["task"].clone();
        assert_eq!(task["run_id"], started["run_id"], "{}", claimed.1);
        let sql: &'static str = Box::leak(format!("UPDATE workflow_runs SET status='{status}'").into_boxed_str());
        database.execute(sql).await;
        let before = durable(&database, &app, &started).await;
        let label = format!("{status} checkpoint");
        assert_eq!(
            public_body(&report(&app, &task, "checkpoint", "nr-worker", 1).await, &label),
            merged(
                receipt(&task, 1),
                json!({"checkpointed":false,"duplicate":false,"reason":"run_closed","task_status":"leased",
                    "history_refresh_page_token":"MA==","protocol_version":"1.20"})
            ),
            "{label}"
        );
        assert_eq!(durable(&database, &app, &started).await, before, "{label}: nothing recorded");
        runtime.close().await;
        database.remove().await;
    }
}
