//! The health document of one namespace, as published PHP Server 2.5.14
//! (Workflow 2.5.5 `HealthCheck::snapshot`) derives it from the operator
//! metrics: the same twelve checks, categories, statuses and messages. Checks
//! about PHP-only machinery (projection tables native does not store, the
//! cache-backed long-poll wake) report what native does instead.
use serde_json::{Value, json};

const CORRECTNESS: &str = "correctness";
const ACCELERATION: &str = "acceleration";

fn integer(value: &Value) -> i64 {
    value.as_i64().unwrap_or_default()
}

fn check(name: &str, status: &str, message: &str, category: &str, data: Value) -> Value {
    json!({"name": name, "status": status, "category": category, "message": message, "data": data})
}

fn when(healthy: bool) -> &'static str {
    if healthy { "ok" } else { "warning" }
}

/// Copies the named fields, as PHP's checks echo them.
fn fields(source: &Value, names: &[(&str, &str)]) -> Value {
    let mut data = serde_json::Map::new();
    for (target, field) in names {
        data.insert((*target).to_owned(), source[*field].clone());
    }
    Value::Object(data)
}

fn backend_check(backend: &Value) -> Value {
    let issues = backend["issues"].clone();
    let severity = backend["severity"].as_str().unwrap_or("ok");
    let (status, message) = match severity {
        "error" => (
            "error",
            "One or more configured v2 backend capabilities are unsupported.",
        ),
        "warning" => (
            "warning",
            "One or more configured v2 backend capabilities are degraded but non-blocking.",
        ),
        _ => (
            "ok",
            "The configured database, queue, cache, and codec backends satisfy the v2 capability contract.",
        ),
    };
    let count = issues.as_array().map_or(0, Vec::len);
    check(
        "backend_capabilities",
        status,
        message,
        CORRECTNESS,
        json!({"severity": severity, "issue_count": count, "issues": issues}),
    )
}

fn run_summary_check(projection: &Value) -> Value {
    let needs_rebuild = integer(&projection["needs_rebuild"]);
    check(
        "run_summary_projection",
        when(needs_rebuild == 0),
        if needs_rebuild == 0 {
            "Run-summary projections are aligned with durable v2 runs."
        } else {
            "Run-summary projections are missing, stale, schema-outdated, or orphaned; rebuild them before trusting Waterline lists."
        },
        CORRECTNESS,
        json!({"needs_rebuild": needs_rebuild, "missing": integer(&projection["missing"]), "orphaned": integer(&projection["orphaned"]),
            "stale": integer(&projection["stale"]), "schema_outdated": integer(&projection["schema_outdated"]), "projection_schema_version": 2}),
    )
}

/// Native stores run waits; it reads timelines, timers and lineage from
/// history, so those cannot drift.
fn selected_run_check(projections: &Value) -> Value {
    let waits = &projections["run_waits"];
    let needs_rebuild = integer(&waits["needs_rebuild"]);
    check(
        "selected_run_projections",
        when(needs_rebuild == 0),
        if needs_rebuild == 0 {
            "Selected-run wait projections are aligned with durable v2 detail; timeline, timer, and lineage detail is read from history."
        } else {
            "Selected-run wait projections need rebuild before trusting Waterline detail."
        },
        CORRECTNESS,
        json!({"needs_rebuild": needs_rebuild, "run_waits_needs_rebuild": needs_rebuild,
            "run_waits_missing_current_open_waits": integer(&waits["missing_current_open_waits"]),
            "run_waits_orphaned": integer(&waits["orphaned"]),
            "timeline_maintained": false, "timer_maintained": false, "lineage_maintained": false}),
    )
}

fn history_check(history: &Value) -> Value {
    let orphaned = integer(&history["history_orphan_total"]);
    check(
        "history_retention_invariant",
        when(orphaned == 0),
        if orphaned == 0 {
            "Workflow history events all reference retained workflow runs."
        } else {
            "Workflow history events exist without retained workflow runs; retention cleanup must reconcile them."
        },
        CORRECTNESS,
        json!({"history_orphan_total": orphaned, "events": integer(&history["events"])}),
    )
}

fn command_contract_check(metrics: &Value) -> Value {
    let needed = integer(&metrics["backfill_needed_runs"]);
    let actionable = integer(&metrics["actionable_backfill_needed_runs"]);
    let message = if actionable > 0 {
        "Some open runs need WorkflowStarted command-contract backfill before operators can trust command forms."
    } else if needed > 0 {
        "Historical WorkflowStarted command-contract gaps are retained as informational metrics; no open run depends on them."
    } else {
        "WorkflowStarted command-contract snapshots are complete."
    };
    check(
        "command_contract_snapshots",
        when(actionable == 0),
        message,
        CORRECTNESS,
        metrics.clone(),
    )
}

fn task_transport_check(tasks: &Value, backlog: &Value) -> Value {
    let unhealthy = integer(&tasks["unhealthy"]);
    let mut data = fields(
        tasks,
        &[
            ("lease_expired_tasks", "lease_expired"),
            ("oldest_lease_expired_at", "oldest_lease_expired_at"),
            ("max_lease_expired_age_ms", "max_lease_expired_age_ms"),
            ("ready_due_tasks", "ready_due"),
            ("oldest_ready_due_at", "oldest_ready_due_at"),
            ("max_ready_due_age_ms", "max_ready_due_age_ms"),
            ("dispatch_overdue_tasks", "dispatch_overdue"),
            (
                "oldest_dispatch_overdue_since",
                "oldest_dispatch_overdue_since",
            ),
            ("max_dispatch_overdue_age_ms", "max_dispatch_overdue_age_ms"),
            ("claim_failed_tasks", "claim_failed"),
            ("oldest_claim_failed_at", "oldest_claim_failed_at"),
            ("max_claim_failed_age_ms", "max_claim_failed_age_ms"),
            ("dispatch_failed_tasks", "dispatch_failed"),
            ("oldest_dispatch_failed_at", "oldest_dispatch_failed_at"),
            ("max_dispatch_failed_age_ms", "max_dispatch_failed_age_ms"),
            ("max_attempt_count", "max_attempt_count"),
            ("max_repair_count", "max_repair_count"),
        ],
    );
    data["unhealthy_tasks"] = json!(unhealthy);
    for (target, field) in [
        ("repair_needed_runs", "repair_needed_runs"),
        ("claim_failed_runs", "claim_failed_runs"),
        ("compatibility_blocked_runs", "compatibility_blocked_runs"),
        (
            "oldest_compatibility_blocked_started_at",
            "oldest_compatibility_blocked_started_at",
        ),
        (
            "max_compatibility_blocked_age_ms",
            "max_compatibility_blocked_age_ms",
        ),
    ] {
        data[target] = backlog[field].clone();
    }
    check(
        "task_transport",
        when(unhealthy == 0),
        if unhealthy == 0 {
            "No unhealthy durable task transport state is currently projected."
        } else {
            "One or more durable tasks have unhealthy transport, claim, dispatch, or lease state."
        },
        CORRECTNESS,
        data,
    )
}

fn activity_path_check(activities: &Value) -> Value {
    let overdue = integer(&activities["timeout_overdue"]);
    check(
        "activity_path",
        when(overdue == 0),
        if overdue == 0 {
            "No activity executions have an enforcement-overdue deadline; the activity timeout sweep is keeping up."
        } else {
            "One or more activity executions have a schedule-to-start, start-to-close, schedule-to-close, or heartbeat deadline past due without enforcement; the activity timeout sweep is lagging or stalled."
        },
        CORRECTNESS,
        fields(
            activities,
            &[
                ("timeout_overdue", "timeout_overdue"),
                ("oldest_timeout_overdue_at", "oldest_timeout_overdue_at"),
                ("max_timeout_overdue_age_ms", "max_timeout_overdue_age_ms"),
                ("retrying", "retrying"),
                ("oldest_retrying_started_at", "oldest_retrying_started_at"),
                ("max_retrying_age_ms", "max_retrying_age_ms"),
                ("failed_attempts", "failed_attempts"),
                ("max_attempt_count", "max_attempt_count"),
            ],
        ),
    )
}

fn routing_check(metrics: &Value) -> Value {
    let (tasks, backlog, role, workers) = (
        &metrics["tasks"],
        &metrics["backlog"],
        &metrics["matching_role"],
        &metrics["workers"],
    );
    let blocked = integer(&backlog["compatibility_blocked_runs"]) > 0;
    let overdue = integer(&tasks["dispatch_overdue"]);
    let claim_failed = integer(&tasks["claim_failed"]);
    let required = workers["required_compatibility"].as_str();
    let supporting = integer(&workers["active_workers_supporting_required"]);
    let fleet_supports = required.is_none() || supporting > 0;
    let signals = usize::from(blocked) + usize::from(overdue > 0) + usize::from(claim_failed > 0);
    let message = match () {
        _ if signals == 0 => {
            "No routing drains, compatibility blocks, or uncleared claim failures are currently projected."
        }
        _ if signals > 1 => {
            "Routing health is degraded: compatibility blocks, dispatch lag, or uncleared claim failures are visible in durable state."
        }
        _ if blocked && !fleet_supports => {
            "One or more runs are blocked because no active worker heartbeat advertises the required compatibility marker."
        }
        _ if blocked => {
            "One or more runs are ready but waiting for a compatible worker in the active fleet."
        }
        _ if overdue > 0 => {
            "One or more ready tasks have waited past the redispatch window without a successful dispatch wake."
        }
        _ => "One or more ready tasks still carry an uncleared claim failure.",
    };
    let mut data = fields(
        backlog,
        &[
            ("compatibility_blocked_runs", "compatibility_blocked_runs"),
            (
                "oldest_compatibility_blocked_started_at",
                "oldest_compatibility_blocked_started_at",
            ),
            (
                "max_compatibility_blocked_age_ms",
                "max_compatibility_blocked_age_ms",
            ),
        ],
    );
    for (target, field) in [
        ("dispatch_overdue_tasks", "dispatch_overdue"),
        (
            "oldest_dispatch_overdue_since",
            "oldest_dispatch_overdue_since",
        ),
        ("max_dispatch_overdue_age_ms", "max_dispatch_overdue_age_ms"),
        ("claim_failed_tasks", "claim_failed"),
        ("oldest_claim_failed_at", "oldest_claim_failed_at"),
        ("max_claim_failed_age_ms", "max_claim_failed_age_ms"),
    ] {
        data[target] = tasks[field].clone();
    }
    data["queue_wake_enabled"] = role["queue_wake_enabled"].clone();
    data["matching_shape"] = role["shape"].clone();
    data["wake_owner"] = role["wake_owner"].clone();
    data["task_dispatch_mode"] = role["task_dispatch_mode"].clone();
    data["active_worker_scopes"] = workers["active_worker_scopes"].clone();
    data["required_compatibility"] = workers["required_compatibility"].clone();
    data["active_workers"] = workers["active_workers"].clone();
    data["active_workers_supporting_required"] = json!(supporting);
    data["fleet_supports_required"] = json!(fleet_supports);
    check(
        "routing_health",
        when(signals == 0),
        message,
        CORRECTNESS,
        data,
    )
}

fn durable_resume_check(metrics: &Value) -> Value {
    let (runs, repair) = (&metrics["runs"], &metrics["repair"]);
    let needed = integer(&metrics["backlog"]["repair_needed_runs"]);
    check(
        "durable_resume_paths",
        when(needed == 0),
        if needed == 0 {
            "Every open v2 run has a projected durable resume path."
        } else {
            "One or more open v2 runs are missing their durable next-resume source and need repair."
        },
        CORRECTNESS,
        json!({"repair_needed_runs": needed, "oldest_repair_needed_at": runs["oldest_repair_needed_at"],
            "max_repair_needed_age_ms": runs["max_repair_needed_age_ms"],
            "missing_task_candidates": repair["missing_task_candidates"], "selected_missing_task_candidates": repair["selected_missing_task_candidates"],
            "oldest_missing_run_started_at": repair["oldest_missing_run_started_at"], "max_missing_run_age_ms": repair["max_missing_run_age_ms"],
            "waiting_runs": runs["waiting"], "oldest_wait_started_at": runs["oldest_wait_started_at"], "max_wait_age_ms": runs["max_wait_age_ms"]}),
    )
}

fn worker_compatibility_check(workers: &Value) -> Value {
    let required = workers["required_compatibility"].as_str();
    let supporting = integer(&workers["active_workers_supporting_required"]);
    let data = json!({"required_compatibility": required, "active_workers": integer(&workers["active_workers"]),
        "active_worker_scopes": integer(&workers["active_worker_scopes"]), "active_workers_supporting_required": supporting,
        "validation_mode": "warn"});
    if required.is_none() || supporting > 0 {
        let message = if required.is_none() {
            "No current v2 compatibility marker is required."
        } else {
            "At least one active worker heartbeat advertises the current v2 compatibility marker."
        };
        return check("worker_compatibility", "ok", message, CORRECTNESS, data);
    }
    check(
        "worker_compatibility",
        "warning",
        "No active worker heartbeat advertises the current v2 compatibility marker.",
        CORRECTNESS,
        data,
    )
}

fn scheduler_check(schedules: &Value) -> Value {
    let missed = integer(&schedules["missed"]);
    check(
        "scheduler_role",
        when(missed == 0),
        if missed == 0 {
            "The scheduler tick is caught up to every active schedule in the namespace."
        } else {
            "One or more active schedules are past their next_fire_at; the scheduler tick has not yet caught up."
        },
        CORRECTNESS,
        schedules.clone(),
    )
}

/// Native wakes long polls in its own process. Other nodes still discover
/// work by bounded database polling, which is the durable path.
fn long_poll_check() -> Value {
    check(
        "long_poll_wake_acceleration",
        "ok",
        "Wake acceleration runs in-process; dispatch discovery on this node benefits from sub-second signalling, and other nodes discover work by bounded polling.",
        ACCELERATION,
        json!({"multi_node": false, "backend": "in_process", "capable": false, "safe": true,
            "reason": "In-process wake signals reach only this node; other nodes discover work by bounded polling."}),
    )
}

fn status(checks: &[Value]) -> &'static str {
    if checks.iter().any(|check| check["status"] == "error") {
        "error"
    } else if checks.iter().any(|check| check["status"] == "warning") {
        "warning"
    } else {
        "ok"
    }
}

/// `HealthCheck::snapshot` for the metrics document.
pub(super) fn snapshot(metrics: &Value) -> Value {
    let checks = vec![
        backend_check(&metrics["backend"]),
        run_summary_check(&metrics["projections"]["run_summaries"]),
        selected_run_check(&metrics["projections"]),
        history_check(&metrics["history"]),
        command_contract_check(&metrics["command_contracts"]),
        task_transport_check(&metrics["tasks"], &metrics["backlog"]),
        activity_path_check(&metrics["activities"]),
        routing_check(metrics),
        durable_resume_check(metrics),
        worker_compatibility_check(&metrics["workers"]),
        scheduler_check(&metrics["schedules"]),
        long_poll_check(),
    ];
    let overall = status(&checks);
    let category = |name: &str| {
        let entries: Vec<Value> = checks
            .iter()
            .filter(|check| check["category"] == name)
            .cloned()
            .collect();
        json!({"status": status(&entries), "check_count": entries.len()})
    };
    json!({
        "generated_at": metrics["generated_at"],
        "status": overall,
        "healthy": overall != "error",
        "categories": {CORRECTNESS: category(CORRECTNESS), ACCELERATION: category(ACCELERATION)},
        "operator_metrics": metrics,
        "structural_limits": metrics["structural_limits"],
        "routing_drains": [],
        "checks": checks,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    fn quiet() -> Value {
        json!({"generated_at": "2026-10-20T23:06:14.000000Z", "backend": {"severity": "ok", "issues": []},
            "projections": {"run_summaries": {"needs_rebuild": 0}, "run_waits": {"needs_rebuild": 0}},
            "history": {"history_orphan_total": 0, "events": 0}, "command_contracts": {"backfill_needed_runs": 0, "actionable_backfill_needed_runs": 0},
            "tasks": {"unhealthy": 0, "dispatch_overdue": 0, "claim_failed": 0}, "backlog": {"repair_needed_runs": 0, "compatibility_blocked_runs": 0},
            "activities": {"timeout_overdue": 0}, "matching_role": {}, "workers": {"required_compatibility": null},
            "runs": {}, "repair": {}, "schedules": {"missed": 0}, "structural_limits": {}})
    }

    #[test]
    fn checks_follow_php_names_categories_and_rules() {
        let health = snapshot(&quiet());
        let names: Vec<_> = health["checks"]
            .as_array()
            .unwrap()
            .iter()
            .map(|check| check["name"].as_str().unwrap())
            .collect();
        assert_eq!(
            names,
            [
                "backend_capabilities",
                "run_summary_projection",
                "selected_run_projections",
                "history_retention_invariant",
                "command_contract_snapshots",
                "task_transport",
                "activity_path",
                "routing_health",
                "durable_resume_paths",
                "worker_compatibility",
                "scheduler_role",
                "long_poll_wake_acceleration"
            ]
        );
        assert_eq!(health["status"], "ok");
        assert_eq!(
            health["categories"],
            json!({"correctness": {"status": "ok", "check_count": 11}, "acceleration": {"status": "ok", "check_count": 1}})
        );

        let mut overdue = quiet();
        overdue["tasks"] = json!({"unhealthy": 1, "dispatch_overdue": 1, "claim_failed": 0});
        let health = snapshot(&overdue);
        let statuses: Vec<_> = health["checks"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|check| check["status"] != "ok")
            .map(|check| check["name"].as_str().unwrap())
            .collect();
        assert_eq!(statuses, ["task_transport", "routing_health"]);
        assert_eq!(
            (health["status"].as_str(), health["healthy"].as_bool()),
            (Some("warning"), Some(true))
        );
        assert_eq!(
            health["checks"][7]["message"],
            "One or more ready tasks have waited past the redispatch window without a successful dispatch wake."
        );

        let mut broken = quiet();
        broken["backend"] = json!({"severity": "error", "issues": [{"code": "x"}]});
        assert_eq!(
            (
                snapshot(&broken)["status"].as_str(),
                snapshot(&broken)["healthy"].as_bool()
            ),
            (Some("error"), Some(false))
        );
    }
}
