use super::*;

// The durable stream producer contract of Server #774, natively: bounded
// producer controls, whole-batch capacity under contention, expiry at read
// and a bounded, resumable retention pass.

fn route(workflow: &Value, run: &Value, stream: &str, tail: &str) -> String {
    format!("/api/workflows/{}/runs/{}/streams/{stream}{tail}", workflow.as_str().unwrap(), run.as_str().unwrap())
}

fn items(count: usize) -> Value {
    json!((0..count).map(|n| json!({"payload": n})).collect::<Vec<_>>())
}

async fn usage(app: &Router, resource: &str) -> i64 {
    let (status, body) = request(app, "GET", "/api/system/metrics", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["metrics"]["dw_namespace_durable_state_usage"]["usage"][resource].as_i64().unwrap()
}

#[tokio::test]
async fn a_capacity_refusal_writes_nothing_of_the_batch() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let run = start(&app, "stream-batch").await;
    let (workflow, run) = (&run["workflow_id"], &run["run_id"]);
    let append = |body: Value| {
        let app = app.clone();
        let path = route(workflow, run, "tokens", "/items");
        async move { request(&app, "POST", &path, body).await }
    };
    // Three fresh items past a cap of two: nothing is written and the stream
    // the append would have opened does not exist.
    let (status, refused) = append(json!({"max_pending_items":2,"items":items(3)})).await;
    assert_eq!((status, &refused["reason"]), (StatusCode::TOO_MANY_REQUESTS, &json!("stream_full")), "{refused}");
    let (_, listed) = request(&app, "GET", &route(workflow, run, "", "").trim_end_matches('/').to_owned(), Value::Null).await;
    assert_eq!(listed["streams"], json!([]), "{listed}");
    assert_eq!(usage(&app, "workflow_stream_items").await, 0);

    let (status, body) = append(json!({"max_pending_items":3,"items":[{"payload":0,"idempotency_key":"k0"},{"payload":1},{"payload":2}]})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    // At the cap one fresh item is refused, and a stored key with it is not
    // answered either; duplicates alone take no capacity.
    let (status, _) = append(json!({"max_pending_items":3,"items":[{"payload":3}]})).await;
    assert_eq!(status, StatusCode::TOO_MANY_REQUESTS);
    let (status, _) = append(json!({"max_pending_items":3,"items":[{"payload":0,"idempotency_key":"k0"},{"payload":4}]})).await;
    assert_eq!(status, StatusCode::TOO_MANY_REQUESTS);
    let (status, body) = append(json!({"max_pending_items":3,"items":[{"payload":0,"idempotency_key":"k0"},{"payload":0,"idempotency_key":"k0"}]})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!((&body["accepted"], &body["deduped"], &body["accepted_offsets"]), (&json!(0), &json!(2), &json!([0, 0])), "{body}");
    assert_eq!(usage(&app, "workflow_stream_items").await, 3);
}

#[tokio::test]
async fn producer_controls_are_bounded() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let run = start(&app, "stream-bounds").await;
    let (workflow, run) = (&run["workflow_id"], &run["run_id"]);
    let (status, refused) = request(&app, "POST", &route(workflow, run, "tokens", "/items"),
        json!({"max_pending_items":10_001,"items":items(1)})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
    assert_eq!(refused["errors"]["max_pending_items"], json!(["The max pending items field must not be greater than 10000."]));
    assert_eq!(usage(&app, "workflow_streams").await, 0);
    let (status, _) = request(&app, "POST", &route(workflow, run, "tokens", "/items"),
        json!({"max_pending_items":10_000,"items":items(1)})).await;
    assert_eq!(status, StatusCode::OK);
    let (status, refused) = request(&app, "POST", &route(workflow, run, "tokens", "/close"), json!({"retention_seconds":2_592_001})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
    assert_eq!(refused["errors"]["retention_seconds"], json!(["The retention seconds field must not be greater than 2592000."]));
    let (status, closed) = request(&app, "POST", &route(workflow, run, "tokens", "/close"), json!({"retention_seconds":2_592_000})).await;
    assert_eq!(status, StatusCode::OK, "{closed}");
    assert_eq!(closed["stream"]["retention_seconds"], 2_592_000);
}

/// Eight appends of three items at once through two pools, against a cap of
/// five: `stored` items exist first. Returns the accepted and refused counts.
async fn contend(stored: usize) -> (usize, usize, i64) {
    let database = TestDatabase::new().await;
    let apps = [router(database.open().await.unwrap()), router(database.open().await.unwrap())];
    let run = start(&apps[0], "stream-race").await;
    let path = route(&run["workflow_id"], &run["run_id"], "tokens", "/items");
    if stored > 0 {
        let (status, _) = request(&apps[0], "POST", &path, json!({"items":items(stored)})).await;
        assert_eq!(status, StatusCode::OK);
    }
    let mut tasks = tokio::task::JoinSet::new();
    for index in 0..8 {
        let app = apps[index % 2].clone();
        let path = path.clone();
        tasks.spawn(async move { request(&app, "POST", &path, json!({"max_pending_items":5,"items":items(3)})).await });
    }
    let (mut accepted, mut refused) = (0, 0);
    while let Some(answer) = tasks.join_next().await {
        let (status, body) = answer.unwrap();
        match status {
            StatusCode::OK => accepted += 1,
            StatusCode::TOO_MANY_REQUESTS => refused += 1,
            other => panic!("{other}: {body}"),
        }
    }
    (accepted, refused, usage(&apps[0], "workflow_stream_items").await)
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn concurrent_appends_never_pass_the_cap() {
    // Concurrent first appends open one stream; the first batch fits, every
    // other one is refused whole.
    assert_eq!(contend(0).await, (1, 7, 3));
    // With one item stored, one batch reaches four and the rest are refused.
    assert_eq!(contend(1).await, (1, 7, 4));
}

#[tokio::test]
async fn an_expired_stream_reads_as_not_found() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let run = start(&app, "stream-expiry").await;
    let (workflow, run) = (&run["workflow_id"], &run["run_id"]);
    for name in ["tokens", "kept"] {
        let (status, _) = request(&app, "POST", &route(workflow, run, name, "/items"), json!({"items":items(1)})).await;
        assert_eq!(status, StatusCode::OK);
    }
    let (status, _) = request(&app, "POST", &route(workflow, run, "tokens", "/close"), json!({"retention_seconds":1})).await;
    assert_eq!(status, StatusCode::OK);
    tokio::time::sleep(std::time::Duration::from_millis(2100)).await;

    let (status, body) = request(&app, "GET", &route(workflow, run, "tokens", ""), Value::Null).await;
    assert_eq!((status, &body["reason"]), (StatusCode::NOT_FOUND, &json!("stream_not_found")), "{body}");
    let (status, body) = request(&app, "GET", &route(workflow, run, "tokens", "/items"), Value::Null).await;
    assert_eq!((status, &body["reason"]), (StatusCode::NOT_FOUND, &json!("stream_not_found")), "{body}");
    let (status, body) = request(&app, "POST", &route(workflow, run, "tokens", "/close"), json!({})).await;
    assert_eq!((status, &body["reason"]), (StatusCode::NOT_FOUND, &json!("stream_not_found")), "{body}");
    let list = format!("/api/workflows/{}/runs/{}/streams", workflow.as_str().unwrap(), run.as_str().unwrap());
    let (_, listed) = request(&app, "GET", &list, Value::Null).await;
    let names: Vec<_> = listed["streams"].as_array().unwrap().iter().map(|stream| stream["stream_name"].clone()).collect();
    assert_eq!(names, vec![json!("kept")], "{listed}");
    let (status, body) = request(&app, "POST", &route(workflow, run, "tokens", "/items"), json!({"items":items(1)})).await;
    assert_eq!((status, &body["reason"]), (StatusCode::CONFLICT, &json!("stream_closed")), "{body}");
}

#[tokio::test]
async fn the_retention_pass_reclaims_expired_streams_in_bounded_resumable_steps() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let run = start(&app, "stream-sweep").await;
    let (workflow, run) = (&run["workflow_id"], &run["run_id"]);
    let fill = |name: &'static str, count: usize| {
        let app = app.clone();
        let path = route(workflow, run, name, "/items");
        async move {
            let mut left = count;
            while left > 0 {
                let batch = left.min(500);
                let (status, body) = request(&app, "POST", &path, json!({"items":items(batch)})).await;
                assert_eq!(status, StatusCode::OK, "{body}");
                left -= batch;
            }
        }
    };
    fill("large", 150).await;
    fill("small", 1).await;
    fill("long", 1).await;
    fill("open", 1).await;
    for (name, retention) in [("large", 1), ("small", 1), ("long", 3600)] {
        let (status, _) = request(&app, "POST", &route(workflow, run, name, "/close"), json!({"retention_seconds":retention})).await;
        assert_eq!(status, StatusCode::OK);
    }
    tokio::time::sleep(std::time::Duration::from_millis(2100)).await;
    let pass = |limit: i64| {
        let app = app.clone();
        async move {
            let (status, body) = request(&app, "POST", "/api/system/retention/pass", json!({"limit":limit})).await;
            assert!(status == StatusCode::OK, "{status}: {body}");
        }
    };
    // limit 1: one stream and at most 100 items per pass; the next pass
    // resumes where it stopped.
    pass(1).await;
    assert_eq!((usage(&app, "workflow_streams").await, usage(&app, "workflow_stream_items").await), (4, 53));
    pass(1).await;
    assert_eq!((usage(&app, "workflow_streams").await, usage(&app, "workflow_stream_items").await), (3, 3));
    pass(1).await;
    assert_eq!((usage(&app, "workflow_streams").await, usage(&app, "workflow_stream_items").await), (2, 2));
    // The long-retention and open streams stay.
    pass(100).await;
    assert_eq!((usage(&app, "workflow_streams").await, usage(&app, "workflow_stream_items").await), (2, 2));
}
