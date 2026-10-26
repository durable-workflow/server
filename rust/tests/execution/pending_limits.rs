use super::*;
use durable_workflow_server::runtime::StructuralLimits;

// Workflow #933: external-worker completions meet the pending-count limits
// the embedded executor applies. A command past its limit refuses the whole
// completion with PHP's `structural_limit_exceeded` answer, nothing of it is
// written, and the task keeps its lease.

fn command(kind: &str) -> Value {
    match kind {
        "activity" => json!({"type":"schedule_activity","activity_type":"activity-echo","arguments":envelope(Payload::Array(vec![]))}),
        "timer" => json!({"type":"start_timer","delay_seconds":60}),
        _ => child_command(1),
    }
}

#[tokio::test]
async fn a_pending_count_past_its_limit_refuses_the_whole_completion() {
    for (kind, limit_kind, noun, limits) in [
        ("activity", "pending_activity_count", "pending activities",
            StructuralLimits { pending_activities: Some(1), ..StructuralLimits::default() }),
        ("timer", "pending_timer_count", "pending timers",
            StructuralLimits { pending_timers: Some(1), ..StructuralLimits::default() }),
        ("child", "pending_child_count", "pending child workflows",
            StructuralLimits { pending_children: Some(1), ..StructuralLimits::default() }),
    ] {
        let database = TestDatabase::new().await;
        let app = router(database.open().await.unwrap().with_structural_limits(limits));
        register(&app, "worker", json!(["echo", "child"]), json!(["activity-echo"])).await;
        let run = start(&app, &format!("pending-{kind}")).await;
        let task = poll(&app, "worker", "workflow").await;
        let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;

        // The first command meets a limit of one; the second passes it.
        let (status, body) = finish_task(&app, &task, json!([command(kind), command(kind)])).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{kind}: {body}");
        assert_eq!(body, json!({"task_id":task["task_id"],"workflow_task_attempt":task["workflow_task_attempt"],
            "outcome":"rejected","recorded":false,"error":format!("Structural limit exceeded: 1 {noun} (limit 1)."),
            "reason":"structural_limit_exceeded","limit_kind":limit_kind,"current_value":1,"configured_limit":1}), "{kind}");
        assert_eq!(run_history(&app, &run["workflow_id"], &run["run_id"]).await, before, "{kind}: nothing written");

        // The lease is kept, and one command within the limit is admitted.
        let (status, body) = finish_task(&app, &task, json!([command(kind)])).await;
        assert_eq!(status, StatusCode::OK, "{kind}: {body}");
    }
}

#[tokio::test]
async fn the_snapshot_reports_the_pending_limits_it_enforces() {
    let database = TestDatabase::new().await;
    let limits = StructuralLimits { pending_activities: Some(7), pending_children: Some(8), pending_timers: Some(9),
        ..StructuralLimits::default() };
    let app = router(database.open().await.unwrap().with_structural_limits(limits));
    let (status, metrics) = request(&app, "GET", "/api/system/operator-metrics", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{metrics}");
    let (status, health) = request(&app, "GET", "/api/system/health", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{health}");
    for limits in [&metrics["operator_metrics"]["structural_limits"]["configured"], &health["health"]["structural_limits"]["configured"]] {
        assert_eq!((&limits["pending_activity_count"], &limits["pending_child_count"], &limits["pending_timer_count"]),
            (&json!(7), &json!(8), &json!(9)), "{limits}");
    }
}
