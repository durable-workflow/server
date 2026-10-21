use super::*;

async fn get(app: &Router, namespace: &str, path: &str) -> (StatusCode, Value) {
    namespaces::scoped(app, namespace, "GET", path, Value::Null).await
}

// Task queue views report the queue's registered pollers and ready work,
// scoped to the request's namespace.
#[tokio::test]
async fn task_queue_views_follow_registrations_and_tasks() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    namespaces::create(&app, "queues").await;
    register(&app, "queue-worker", json!(["echo"]), json!([])).await;
    start(&app, "queued").await;
    let (status, list) = get(&app, "default", "/api/task-queues").await;
    assert_eq!(status, StatusCode::OK, "{list}");
    assert_eq!(list["namespace"], "default");
    let queues = list["task_queues"].as_array().unwrap();
    assert_eq!(queues.len(), 1, "{list}");
    assert_eq!(queues[0]["name"], "test");
    assert_eq!(queues[0]["stats"]["workflow_tasks"]["ready_count"], 1, "{list}");
    let (status, queue) = get(&app, "default", "/api/task-queues/test").await;
    assert_eq!(status, StatusCode::OK, "{queue}");
    assert_eq!(queue["pollers"][0]["worker_id"], "queue-worker", "{queue}");
    assert_eq!(queue["admission"]["query_tasks"]["status"], "accepting", "{queue}");
    let (status, other) = get(&app, "queues", "/api/task-queues").await;
    assert_eq!(status, StatusCode::OK, "{other}");
    assert_eq!(other["task_queues"], json!([]), "{other}");
}

// Native runs have no durable streams: lists are empty, a valid name is not
// found and an invalid name, bad item paging or a foreign run is refused.
#[tokio::test]
async fn durable_stream_reads_answer_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    namespaces::create(&app, "streams").await;
    let started = start(&app, "streamed").await;
    let run = format!("/api/workflows/streamed/runs/{}", started["run_id"].as_str().unwrap());
    let (status, list) = get(&app, "default", &format!("{run}/streams")).await;
    assert_eq!(status, StatusCode::OK, "{list}");
    assert_eq!(list, json!({"workflow_id":"streamed","workflow_run_id":started["run_id"],"count":0,"streams":[]}));
    let (status, body) = get(&app, "default", &format!("{run}/streams/orders.v1")).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::NOT_FOUND, json!("stream_not_found")));
    let (status, body) = get(&app, "default", &format!("{run}/streams/-bad")).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::BAD_REQUEST, json!("invalid_stream_name")));
    let (status, body) = get(&app, "default", &format!("{run}/streams/orders.v1/items?from=05&max_items=501")).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["errors"], json!({"from":["The from field must be an integer."],
        "max_items":["The max items field must not be greater than 500."]}));
    let (status, body) = get(&app, "streams", &format!("{run}/streams")).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::NOT_FOUND, json!("instance_not_found")), "{body}");
}

// The workflow list filters cancelled and terminated runs by their exact
// status, as PHP does, instead of refusing those filters.
#[tokio::test]
async fn workflow_list_filters_cancelled_and_terminated_runs() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    for (workflow, action) in [("to-cancel", "cancel"), ("to-terminate", "terminate")] {
        start(&app, workflow).await;
        let (status, body) = request(&app, "POST", &format!("/api/workflows/{workflow}/{action}"), json!({})).await;
        assert_eq!(status, StatusCode::OK, "{body}");
    }
    start(&app, "still-open").await;
    for (status_filter, workflow) in [("cancelled", "to-cancel"), ("terminated", "to-terminate")] {
        let (status, body) = get(&app, "default", &format!("/api/workflows?status={status_filter}")).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        let ids: Vec<_> = body["workflows"].as_array().unwrap().iter().map(|run| run["workflow_id"].clone()).collect();
        assert_eq!(ids, vec![json!(workflow)], "{body}");
    }
}
