//! A live query lease belongs to the poll request that claimed it. As in
//! published PHP, a retry of that same request receives it again; any other
//! poll, from the same worker or another, is an independent slot.
use super::*;

async fn poll_with(app: &Router, worker: &str, request_id: Option<&str>) -> Value {
    let mut body = json!({"worker_id":worker,"task_queue":"test","timeout_seconds":0});
    if let Some(id) = request_id {
        body["poll_request_id"] = json!(id);
    }
    let (status, response) = request(app, "POST", "/api/worker/query-tasks/poll", body).await;
    assert_eq!(status, StatusCode::OK, "{response}");
    response["task"].clone()
}

fn assert_replay(first: &Value, repeated: &Value) {
    let initial =
        chrono::DateTime::parse_from_rfc3339(first["server_time"].as_str().unwrap()).unwrap();
    let current =
        chrono::DateTime::parse_from_rfc3339(repeated["server_time"].as_str().unwrap()).unwrap();
    assert!(current >= initial);
    let mut replay = repeated.clone();
    // Only the observation clock may differ. The claim, its attempt, lease,
    // timing and every payload field stay exactly as first delivered.
    replay["server_time"] = first["server_time"].clone();
    assert_eq!(replay, *first);
}

#[tokio::test]
async fn query_lease_replays_only_to_the_poll_request_that_claimed_it() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    query_worker(&app, "request-worker").await;
    query_worker(&app, "other-worker").await;
    let started = start(&app, "poll-request-query").await;
    let workflow_task = poll(&app, "request-worker", "workflow").await;
    let completed = finish_task(
        &app,
        &workflow_task,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}]),
    )
    .await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let query_path = format!(
        "/api/workflows/poll-request-query/runs/{}/query/state",
        started["run_id"].as_str().unwrap()
    );
    let waiting = {
        let app = app.clone();
        tokio::spawn(async move {
            request(&app, "POST", &query_path,
                json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))})).await
        })
    };
    // One waiting poll claims it. Repeating an ID that already got an empty
    // answer would only get that answer again.
    let (status, claimed) = request(&app, "POST", "/api/worker/query-tasks/poll",
        json!({"worker_id":"request-worker","task_queue":"test","timeout_seconds":5,
            "poll_request_id":"first"})).await;
    assert_eq!(status, StatusCode::OK, "{claimed}");
    let claim = claimed["task"].clone();
    assert!(claim.is_object(), "query is admitted: {claimed}");
    assert!(claim.get("poll_request_id").is_none(), "{claim}");

    assert_replay(&claim, &poll_with(&app, "request-worker", Some("first")).await);
    assert!(poll_with(&app, "request-worker", Some("second")).await.is_null());
    assert!(poll_with(&app, "request-worker", None).await.is_null());
    assert!(poll_with(&app, "other-worker", Some("first")).await.is_null());
    // A newer request does not take the lease away from the original one.
    assert_replay(&claim, &poll_with(&app, "request-worker", Some("first")).await);

    let invalid = request(&app, "POST", "/api/worker/query-tasks/poll",
        json!({"worker_id":"request-worker","task_queue":"test","timeout_seconds":0,"poll_request_id":7})).await;
    assert_eq!(invalid.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", invalid.1);

    let finish = request(
        &app,
        "POST",
        &format!("/api/worker/query-tasks/{}/complete", claim["query_task_id"].as_str().unwrap()),
        json!({"lease_owner":"request-worker","query_task_attempt":claim["query_task_attempt"],
            "result_envelope":envelope(Payload::Long(7))}),
    )
    .await;
    assert_eq!(finish.0, StatusCode::OK, "{}", finish.1);
    assert_eq!(waiting.await.unwrap().0, StatusCode::OK);
    assert!(poll_with(&app, "request-worker", Some("first")).await.is_null());
    runtime.close().await;
    database.remove().await;
}

async fn poll_waiting(app: &Router, request_id: &str, timeout: u64) -> (Value, std::time::Duration) {
    let started = std::time::Instant::now();
    let (status, response) = request(app, "POST", "/api/worker/query-tasks/poll",
        json!({"worker_id":"request-worker","task_queue":"test","timeout_seconds":timeout,
            "poll_request_id":request_id})).await;
    assert_eq!(status, StatusCode::OK, "{response}");
    (response, started.elapsed())
}

// One workflow that a "request-worker" can be queried about, plus a helper
// that admits one query and returns its pending caller.
async fn queryable(name: &str) -> (TestDatabase, Runtime, Router, String) {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    query_worker(&app, "request-worker").await;
    let started = start(&app, name).await;
    let workflow_task = poll(&app, "request-worker", "workflow").await;
    let completed = finish_task(
        &app,
        &workflow_task,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}]),
    )
    .await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let path = format!("/api/workflows/{name}/runs/{}/query/state", started["run_id"].as_str().unwrap());
    (database, runtime, app, path)
}

fn admit_query(app: &Router, path: &str) -> tokio::task::JoinHandle<(StatusCode, Value)> {
    let app = app.clone();
    let path = path.to_owned();
    tokio::spawn(async move {
        request(&app, "POST", &path, json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))})).await
    })
}

async fn finish_query(app: &Router, task: &Value) {
    let finish = request(
        app,
        "POST",
        &format!("/api/worker/query-tasks/{}/complete", task["query_task_id"].as_str().unwrap()),
        json!({"lease_owner":"request-worker","query_task_attempt":task["query_task_attempt"],
            "result_envelope":envelope(Payload::Long(7))}),
    )
    .await;
    assert_eq!(finish.0, StatusCode::OK, "{}", finish.1);
}

#[tokio::test]
async fn a_retried_empty_poll_request_returns_its_remembered_answer() {
    let (database, runtime, app, path) = queryable("remembered-empty-query").await;
    let (first, _) = poll_waiting(&app, "empty-first", 0).await;
    assert!(first["task"].is_null(), "{first}");
    let waiting = admit_query(&app, &path);
    tokio::time::sleep(std::time::Duration::from_millis(300)).await;
    // The retry gets the original's answer, even though a query now waits.
    let (retry, _) = poll_waiting(&app, "empty-first", 0).await;
    assert!(retry["task"].is_null(), "{retry}");
    assert_eq!(retry["poll_status"], first["poll_status"]);
    let (fresh, _) = poll_waiting(&app, "empty-fresh", 5).await;
    assert!(fresh["task"].is_object(), "{fresh}");
    finish_query(&app, &fresh["task"]).await;
    assert_eq!(waiting.await.unwrap().0, StatusCode::OK);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_newer_poll_request_ends_an_older_waiting_poll() {
    let (database, runtime, app, _) = queryable("superseded-query-poll").await;
    let older = {
        let app = app.clone();
        tokio::spawn(async move { poll_waiting(&app, "older", 10).await })
    };
    tokio::time::sleep(std::time::Duration::from_millis(500)).await;
    let (newer, _) = poll_waiting(&app, "newer", 0).await;
    assert!(newer["task"].is_null(), "{newer}");
    let (ended, elapsed) = older.await.unwrap();
    assert!(ended["task"].is_null(), "{ended}");
    assert!(elapsed < std::time::Duration::from_secs(3), "the older poll ended early: {elapsed:?}");
    // Its retry keeps the same answer instead of waiting again.
    let (retry, elapsed) = poll_waiting(&app, "older", 10).await;
    assert!(retry["task"].is_null(), "{retry}");
    assert!(elapsed < std::time::Duration::from_secs(2), "{elapsed:?}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn duplicate_polls_with_one_request_id_share_one_claim() {
    let (database, runtime, app, path) = queryable("shared-query-claim").await;
    let polls: Vec<_> = (0..2)
        .map(|_| {
            let app = app.clone();
            tokio::spawn(async move { poll_waiting(&app, "shared", 5).await })
        })
        .collect();
    tokio::time::sleep(std::time::Duration::from_millis(300)).await;
    let waiting = admit_query(&app, &path);
    let mut claims = vec![];
    for poll in polls {
        claims.push(poll.await.unwrap().0["task"].clone());
    }
    assert!(claims[0].is_object(), "{}", claims[0]);
    assert_eq!(claims[0]["query_task_id"], claims[1]["query_task_id"]);
    assert_eq!(claims[0]["query_task_attempt"], claims[1]["query_task_attempt"]);
    assert_eq!(claims[0]["query_task_attempt"], 1);
    finish_query(&app, &claims[0]).await;
    assert_eq!(waiting.await.unwrap().0, StatusCode::OK);
    runtime.close().await;
    database.remove().await;
}

// An answered query keeps its receipt but holds no queue capacity (Server
// #964): more sequential queries than the cache's open-task bound (64) all
// succeed against one run, as against published PHP.
#[tokio::test]
async fn answered_queries_release_queue_capacity() {
    let (database, runtime, app, path) = queryable("sequential-queries").await;
    for index in 0..80 {
        let caller = admit_query(&app, &path);
        let (claimed, _) = poll_waiting(&app, &format!("sequential-{index}"), 5).await;
        let task = claimed["task"].clone();
        assert!(task.is_object(), "query {index} was not claimed: {claimed}");
        finish_query(&app, &task).await;
        let (status, answer) = caller.await.unwrap();
        assert_eq!(status, StatusCode::OK, "query {index}: {answer}");
    }
    runtime.close().await;
    database.remove().await;
}

// Retained receipts hold no capacity and cost no scan (Server #964): with
// thousands of answered tasks already in the cache, as 3.0.1 left them,
// later queries are admitted, claimed and answered as usual, while the
// one-time cutover files the old rows a bounded page at a time.
#[tokio::test]
async fn thousands_of_retained_receipts_leave_queries_unaffected() {
    let (database, runtime, app, path) = queryable("many-receipts").await;
    let expires = chrono::Utc::now().timestamp() + 3600;
    let rows: Vec<String> = (0..3000)
        .map(|_| {
            let id = ulid::Ulid::new().to_string();
            format!("('dw-native-query:{id}','{{\"query_task_id\":\"{id}\",\"namespace\":\"default\",\"status\":\"completed\"}}',{expires})")
        })
        .collect();
    for chunk in rows.chunks(500) {
        // Column order is the cache table's: key, value, expiration.
        database.execute(sqlx::AssertSqlSafe(format!("INSERT INTO dw_query_cache VALUES {}", chunk.join(",")))).await;
    }
    for index in 0..20 {
        let caller = admit_query(&app, &path);
        let (claimed, _) = poll_waiting(&app, &format!("receipts-{index}"), 5).await;
        let task = claimed["task"].clone();
        assert!(task.is_object(), "query {index} was not claimed: {claimed}");
        finish_query(&app, &task).await;
        let (status, answer) = caller.await.unwrap();
        assert_eq!(status, StatusCode::OK, "query {index}: {answer}");
    }
    runtime.close().await;
    database.remove().await;
}

// The cutover never lists a 3.0.1 row that can no longer be answered (Server
// #964): an expired row is deleted, and a pending row past its query
// deadline closes first. Otherwise 128 such rows would fill the namespace's
// open set and refuse the live queries below as query_task_queue_full.
#[tokio::test]
async fn the_cutover_lists_only_answerable_rows() {
    let (database, runtime, app, path) = queryable("stale-cutover").await;
    let now = chrono::Utc::now();
    let wire = |time: chrono::DateTime<chrono::Utc>| time.format("%Y-%m-%dT%H:%M:%S%.6fZ").to_string();
    let row = |expiration: i64| {
        let id = ulid::Ulid::new().to_string();
        let task = format!(
            "{{\"query_task_id\":\"{id}\",\"namespace\":\"default\",\"task_queue\":\"test\",\"status\":\"pending\",\"query_admitted_at\":\"{}\",\"query_deadline_at\":\"{}\",\"receipt_expires_at\":\"{}\"}}",
            wire(now - chrono::Duration::seconds(600)), wire(now - chrono::Duration::seconds(300)), wire(now + chrono::Duration::seconds(3600)));
        format!("('dw-native-query:{id}','{task}',{expiration})")
    };
    let mut rows: Vec<String> = (0..64).map(|_| row(now.timestamp() - 30)).collect();
    rows.extend((0..64).map(|_| row(now.timestamp() + 3600)));
    database.execute(sqlx::AssertSqlSafe(format!("INSERT INTO dw_query_cache VALUES {}", rows.join(",")))).await;
    for index in 0..3 {
        let caller = admit_query(&app, &path);
        let (claimed, _) = poll_waiting(&app, &format!("stale-{index}"), 5).await;
        let task = claimed["task"].clone();
        assert!(task.is_object(), "query {index} was not claimed: {claimed}");
        finish_query(&app, &task).await;
        let (status, answer) = caller.await.unwrap();
        assert_eq!(status, StatusCode::OK, "query {index}: {answer}");
    }
    runtime.close().await;
    database.remove().await;
}
