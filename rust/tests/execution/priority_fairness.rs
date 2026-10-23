use super::*;

// Published PHP 2.11.1's priority-fairness snapshot: ready tasks by priority
// tier and fairness class, and a score of 0 for each class, since PHP's
// fairness state starts empty on every request. A poll takes the oldest
// ready task of the most urgent tier.
#[tokio::test]
async fn priority_fairness_reports_tiers_and_classes_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, empty) = request(&app, "GET", "/api/task-queues/test/priority-fairness", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{empty}");
    assert_eq!(empty, json!({"namespace":"default","queue":"test",
        "workflow_task":{"ready_tasks":0,"priority_tiers":[],"recent_dispatch":[]},
        "activity_task":{"ready_tasks":0,"priority_tiers":[],"recent_dispatch":[]}}));
    for (workflow, extra) in [("a1", json!({"fairness_key":"Tenant-A"})), ("a2", json!({"fairness_key":"tenant-a","fairness_weight":3})),
        ("b1", json!({"fairness_key":"tenant-b","fairness_weight":2})), ("n1", json!({})), ("u1", json!({"priority":1,"fairness_key":"urgent"}))] {
        let mut body = json!({"workflow_id":workflow,"workflow_type":"echo","task_queue":"test","input":envelope(Payload::Array(vec![]))});
        body.as_object_mut().unwrap().extend(extra.as_object().unwrap().clone());
        let (status, started) = request(&app, "POST", "/api/workflows", body).await;
        assert_eq!(status, StatusCode::CREATED, "{started}");
    }
    let (_, snapshot) = request(&app, "GET", "/api/task-queues/test/priority-fairness", Value::Null).await;
    assert_eq!(snapshot["workflow_task"], json!({"ready_tasks":5,"priority_tiers":[
        {"priority":1,"count":1,"classes":[{"fairness_key":"urgent","count":1,"fairness_weight":1}]},
        {"priority":5,"count":4,"classes":[{"fairness_key":null,"count":1,"fairness_weight":1},
            {"fairness_key":"tenant-a","count":2,"fairness_weight":1},{"fairness_key":"tenant-b","count":1,"fairness_weight":2}]}],
        "recent_dispatch":[{"fairness_key":"tenant-a","score":0},{"fairness_key":"tenant-b","score":0},
            {"fairness_key":null,"score":0},{"fairness_key":"urgent","score":0}]}));
    let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"fair-worker","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let mut order = Vec::new();
    for _ in 0..5 {
        order.push(poll(&app, "fair-worker", "workflow").await["workflow_id"].clone());
    }
    assert_eq!(order, ["u1", "a1", "a2", "b1", "n1"]);
    runtime.close().await;
    database.remove().await;
}
