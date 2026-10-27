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
    // Published 2.11.3's poll diagnostics, reported as unobserved: native keeps
    // no record of worker polls that ended early.
    let unobserved = json!({"status":"unavailable","retention_seconds":900,"last_disconnect":null});
    assert_eq!(queue["poll_transport"], unobserved, "{queue}");
    assert_eq!(queues[0]["poll_transport"], unobserved, "{list}");
    let (status, other) = get(&app, "queues", "/api/task-queues").await;
    assert_eq!(status, StatusCode::OK, "{other}");
    assert_eq!(other["task_queues"], json!([]), "{other}");
}

// Durable stream reads: an empty run lists nothing, a valid name is not
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

// Producer writes: appends open the stream and keep their offsets across a
// retry, reads at the head clear the pending count, a close is final, and
// another namespace can neither read nor write the run's streams.
#[tokio::test]
async fn durable_stream_writes_answer_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    namespaces::create(&app, "streams").await;
    let started = start(&app, "producing").await;
    let run = format!("/api/workflows/producing/runs/{}", started["run_id"].as_str().unwrap());
    let items = format!("{run}/streams/tokens/items");
    let (status, body) = request(&app, "POST", &items, json!({"items":[
        {"payload":{"token":" Hello ","empty":{}},"idempotency_key":"k1"},{"payload":"plain","payload_codec":null}]})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!((body["accepted_offsets"].clone(), body["accepted"].clone(), body["deduped"].clone()), (json!([0, 1]), json!(2), json!(0)));
    assert_eq!(body["stream"]["pending_items"], 2);
    let opened = body["stream"]["opened_at"].as_str().unwrap().to_owned();
    assert!(opened.ends_with(".000000Z"), "{opened}");
    let (status, body) = request(&app, "POST", &items, json!({"items":[{"payload":1,"idempotency_key":"k1"},{"payload":2}],
        "max_pending_items":3})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!((body["accepted_offsets"].clone(), body["deduped"].clone()), (json!([0, 2]), json!(1)));
    // At the cap, the batch keeps nothing past it and answers 429.
    let (status, body) = request(&app, "POST", &items, json!({"items":[{"payload":3}],"max_pending_items":3})).await;
    assert_eq!(status, StatusCode::TOO_MANY_REQUESTS, "{body}");
    assert_eq!((body["reason"].clone(), body["pending_items"].clone(), body["max_pending_items"].clone()),
        (json!("stream_full"), json!(3), json!(3)));
    let (status, body) = get(&app, "default", &format!("{items}?from=0")).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["items"][0]["payload"], json!({"token":"Hello","empty":[]}));
    assert_eq!(body["items"][0]["origin"], "workflow_command");
    assert_eq!(body["items"][1]["payload_codec"], "avro");
    assert_eq!((body["next_offset"].clone(), body["terminal"].clone(), body["stream"]["pending_items"].clone()),
        (json!(3), json!(false), json!(0)));
    // Another namespace sees no such run.
    let (status, body) = namespaces::scoped(&app, "streams", "POST", &items, json!({"items":[{"payload":1}]})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::NOT_FOUND, json!("instance_not_found")), "{body}");
    let close = format!("{run}/streams/tokens/close");
    let (status, body) = request(&app, "POST", &close, json!({})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!((body["stream"]["status"].clone(), body["stream"]["retention_seconds"].clone()), (json!("closed"), json!(3600)));
    let (status, again) = request(&app, "POST", &close, json!({"error_reason":"late","retention_seconds":5})).await;
    assert_eq!(status, StatusCode::OK, "{again}");
    assert_eq!(again["stream"], body["stream"]);
    let (status, body) = request(&app, "POST", &items, json!({"items":[{"payload":1}]})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("stream_closed")), "{body}");
    let (status, body) = get(&app, "default", &format!("{items}?from=3&wait_seconds=5")).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!((body["items"].clone(), body["terminal"].clone()), (json!([]), json!(true)));
    let (status, body) = request(&app, "POST", &format!("{run}/streams/bad%20name/items"), json!({"items":[{"payload":1}]})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::BAD_REQUEST, json!("invalid_request")), "{body}");
    let (status, body) = request(&app, "POST", &items, json!({"items":[{"payload_reference":"dw-unstored"}]})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("external_payload_unsupported")), "{body}");
    let (status, body) = get(&app, "default", &format!("{run}/streams")).await;
    assert_eq!((status, body["count"].clone()), (StatusCode::OK, json!(1)), "{body}");
}

// A run's durable streams stay with it when the workflow continues as new,
// as PHP keeps them; the continuation is not refused.
#[tokio::test]
async fn continue_as_new_keeps_durable_streams_with_the_predecessor() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "stream-worker", json!(["echo"]), json!([])).await;
    let started = start(&app, "stream-continue").await;
    let first = started["run_id"].as_str().unwrap().to_owned();
    let (status, body) = request(&app, "POST", &format!("/api/workflows/stream-continue/runs/{first}/streams/out/items"),
        json!({"items":[{"payload":"before"}]})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let task = poll(&app, "stream-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"continue_as_new"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (_, runs) = request(&app, "GET", "/api/workflows/stream-continue/runs", Value::Null).await;
    assert_eq!(runs["runs"].as_array().unwrap().len(), 2, "{runs}");
    let (status, body) = get(&app, "default", &format!("/api/workflows/stream-continue/runs/{first}/streams")).await;
    assert_eq!((status, body["count"].clone()), (StatusCode::OK, json!(1)), "{body}");
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
