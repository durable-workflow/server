use super::*;

// The durable stream producer contract of Server #774, natively: bounded
// producer controls, whole-batch capacity under contention, expiry for every
// operation that keeps the stream's identity, and a bounded, resumable
// retention pass that reads due streams from an index.

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
    // Append is not found too, and writes nothing.
    let (status, body) = request(&app, "POST", &route(workflow, run, "tokens", "/items"), json!({"items":items(1)})).await;
    assert_eq!((status, &body["reason"]), (StatusCode::NOT_FOUND, &json!("stream_not_found")), "{body}");
    assert_eq!((usage(&app, "workflow_streams").await, usage(&app, "workflow_stream_items").await), (2, 2));
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
    let deadlines = || task_retry::rows(&database, "SELECT COUNT(*) FROM dw_stream_reclaims");
    assert_eq!(deadlines().await, 3);
    // limit 1: one stream and at most 100 items per pass; the next pass
    // resumes where it stopped. Reclaimed streams keep their rows.
    pass(1).await;
    assert_eq!((usage(&app, "workflow_streams").await, usage(&app, "workflow_stream_items").await), (4, 53));
    pass(1).await;
    assert_eq!((usage(&app, "workflow_streams").await, usage(&app, "workflow_stream_items").await), (4, 3));
    pass(1).await;
    assert_eq!((usage(&app, "workflow_streams").await, usage(&app, "workflow_stream_items").await), (4, 2));
    // The long-retention and open streams stay; only the long one still has
    // a deadline.
    pass(100).await;
    assert_eq!((usage(&app, "workflow_streams").await, usage(&app, "workflow_stream_items").await), (4, 2));
    assert_eq!(deadlines().await, 1);
    // A reclaimed stream's name is never opened again.
    let (status, body) = request(&app, "POST", &route(workflow, run, "large", "/items"), json!({"items":items(1)})).await;
    assert_eq!((status, &body["reason"]), (StatusCode::NOT_FOUND, &json!("stream_not_found")), "{body}");
    assert_eq!(usage(&app, "workflow_stream_items").await, 2);
}

#[tokio::test]
async fn a_stream_whose_items_hold_external_references_is_left_with_its_items() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let run = start(&app, "stream-references").await;
    let (workflow, run) = (&run["workflow_id"], &run["run_id"]);
    let (status, _) = request(&app, "POST", &route(workflow, run, "tokens", "/items"), json!({"items":items(2)})).await;
    assert_eq!(status, StatusCode::OK);
    let (status, _) = request(&app, "POST", &route(workflow, run, "tokens", "/close"), json!({"retention_seconds":1})).await;
    assert_eq!(status, StatusCode::OK);
    // Native admits no external reference and settles none, so a row
    // holding one, written here directly, keeps its stream unreclaimed.
    database.execute("UPDATE workflow_durable_stream_items SET payload_reference='file:///php/stream-item.bin'").await;
    tokio::time::sleep(std::time::Duration::from_millis(2100)).await;
    let (status, body) = request(&app, "POST", "/api/system/retention/pass", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(usage(&app, "workflow_stream_items").await, 2);
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM dw_stream_reclaims").await, 1);
}

async fn completed_run(app: &Router, workflow: &str, stream_items: usize) -> String {
    let started = start(app, workflow).await;
    let (status, _) = request(app, "POST", &route(&started["workflow_id"], &started["run_id"], "tokens", "/items"), json!({"items":items(stream_items)})).await;
    assert_eq!(status, StatusCode::OK);
    let (status, _) = request(app, "POST", &route(&started["workflow_id"], &started["run_id"], "tokens", "/close"), json!({})).await;
    assert_eq!(status, StatusCode::OK);
    let task = poll(app, "worker", "workflow").await;
    let complete = json!({"type":"complete_workflow","result":envelope(Payload::Long(1))});
    assert_eq!(finish_task(app, &task, json!([complete])).await.0, StatusCode::OK);
    started["run_id"].as_str().unwrap().to_owned()
}

#[tokio::test]
async fn run_retention_removes_a_pruned_runs_streams_unless_they_hold_references() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let pruned = completed_run(&app, "stream-run-pruned", 2).await;
    let held = completed_run(&app, "stream-run-held", 1).await;
    for run in [&pruned, &held] {
        for table in ["workflow_runs", "workflow_run_summaries"] {
            database
                .execute(sqlx::AssertSqlSafe(format!("UPDATE {table} SET closed_at='2020-01-01 00:00:00.000000' WHERE id='{run}'")))
                .await;
        }
    }
    database
        .execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_durable_stream_items SET payload_reference='file:///php/held.bin' WHERE workflow_run_id='{held}'")))
        .await;

    let (status, body) = request(&app, "POST", "/api/system/retention/pass", json!({"run_ids":[pruned, held]})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let result = &body["results"][0];
    assert_eq!(result["outcome"], "pruned", "{body}");
    assert_eq!(result["deleted"]["durable_stream_items_deleted"], 2, "{body}");
    assert_eq!(result["deleted"]["durable_streams_deleted"], 1, "{body}");
    assert_eq!(body["results"][1]["reason"], "external_payload_cleanup_unavailable", "{body}");
    // A pruned run has no streams to write: a later append opens none.
    let (status, body) = request(&app, "POST", &route(&json!("stream-run-pruned"), &json!(pruned), "late", "/items"), json!({"items":items(1)})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::NOT_FOUND, json!("stream_not_found")), "{body}");
    for (sql, expected) in [
        (format!("SELECT COUNT(*) FROM workflow_durable_streams WHERE workflow_run_id='{pruned}'"), 0),
        (format!("SELECT COUNT(*) FROM dw_stream_reclaims WHERE workflow_run_id='{pruned}'"), 0),
        (format!("SELECT COUNT(*) FROM workflow_durable_stream_items WHERE workflow_run_id='{held}'"), 1),
        (format!("SELECT COUNT(*) FROM dw_stream_reclaims WHERE workflow_run_id='{held}'"), 1),
    ] {
        let sql: &'static str = Box::leak(sql.into_boxed_str());
        assert_eq!(task_retry::rows(&database, sql).await, expected, "{sql}");
    }
}

/// The retention pass reads a namespace's due streams from
/// `dw_stream_reclaims_due`: an index range in deadline order that stops at
/// its limit, whatever else the namespaces hold.
#[tokio::test]
async fn due_stream_discovery_reads_the_deadline_index() {
    use sqlx::{Connection, Row};
    let database = TestDatabase::new().await;
    let _app = router(database.open().await.unwrap());
    // 2,000 deadlines in two namespaces; 30 of `default`'s are due.
    let mut values = Vec::new();
    for (n, namespace) in ["default", "elsewhere"].into_iter().enumerate() {
        for i in 0..1000 {
            let id = n * 1000 + i + 1;
            let at = if namespace == "default" && i < 30 { "2020-01-01 00:00:00" } else { "2099-01-01 00:00:00" };
            values.push(format!("({id},'{namespace}','run-{id}','{at}')"));
        }
    }
    for chunk in values.chunks(500) {
        database
            .execute(sqlx::AssertSqlSafe(format!(
                "INSERT INTO dw_stream_reclaims(stream_id,namespace,workflow_run_id,reclaim_at) VALUES {}",
                chunk.join(",")
            )))
            .await;
    }
    let sql = "SELECT stream_id FROM dw_stream_reclaims WHERE namespace='default' AND reclaim_at<='2026-01-01 00:00:00' ORDER BY reclaim_at,stream_id LIMIT 10";
    let plan = match &database {
        TestDatabase::Sqlite(dir) => {
            let pool = sqlx::SqlitePool::connect(&format!("sqlite://{}", dir.path().join("runtime.sqlite").display())).await.unwrap();
            let rows: Vec<(i64, i64, i64, String)> =
                sqlx::query_as(sqlx::AssertSqlSafe(format!("EXPLAIN QUERY PLAN {sql}"))).fetch_all(&pool).await.unwrap();
            pool.close().await;
            let plan = rows.into_iter().map(|row| row.3).collect::<Vec<_>>().join("\n");
            assert!(plan.contains("USING COVERING INDEX dw_stream_reclaims_due (namespace=? AND reclaim_at<?)"), "{plan}");
            assert!(!plan.contains("TEMP B-TREE"), "{plan}");
            plan
        }
        TestDatabase::Postgres { options, .. } => {
            let mut connection = sqlx::PgConnection::connect_with(options).await.unwrap();
            sqlx::query("ANALYZE dw_stream_reclaims").execute(&mut connection).await.unwrap();
            // A small table plans as a sequential scan; without one, the plan
            // shows the index serves both the predicate and the order.
            let mut tx = connection.begin().await.unwrap();
            sqlx::query("SET LOCAL enable_seqscan=off").execute(&mut *tx).await.unwrap();
            let rows: Vec<(String,)> = sqlx::query_as(sqlx::AssertSqlSafe(format!(
                "EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF) {sql}"
            )))
            .fetch_all(&mut *tx)
            .await
            .unwrap();
            tx.rollback().await.unwrap();
            let plan = rows.into_iter().map(|row| row.0).collect::<Vec<_>>().join("\n");
            assert!(plan.contains("Index Only Scan using dw_stream_reclaims_due"), "{plan}");
            assert!(plan.contains("actual rows=10 loops=1"), "{plan}");
            assert!(!plan.contains("Sort"), "{plan}");
            plan
        }
        TestDatabase::MySql { options, .. } => {
            let mut connection = sqlx::MySqlConnection::connect_with(options).await.unwrap();
            sqlx::raw_sql("ANALYZE TABLE dw_stream_reclaims").execute(&mut connection).await.unwrap();
            let explained = sqlx::raw_sql(sqlx::AssertSqlSafe(format!("EXPLAIN {sql}"))).fetch_all(&mut connection).await.unwrap();
            assert_eq!(explained.len(), 1);
            let field = |name: &str| explained[0].try_get::<Option<String>, _>(name).unwrap().unwrap_or_default();
            let plan = format!("type={} key={} extra={}", field("type"), field("key"), field("Extra"));
            assert_eq!((field("type"), field("key")), ("range".into(), "dw_stream_reclaims_due".into()), "{plan}");
            assert!(!plan.contains("filesort"), "{plan}");
            // The first use of a table or status view reads the data
            // dictionary, which MySQL counts too; measure a second read.
            sqlx::raw_sql(sql).fetch_all(&mut connection).await.unwrap();
            sqlx::raw_sql("SHOW SESSION STATUS LIKE 'Handler_read%'").fetch_all(&mut connection).await.unwrap();
            sqlx::raw_sql("FLUSH STATUS").execute(&mut connection).await.unwrap();
            assert_eq!(sqlx::raw_sql(sql).fetch_all(&mut connection).await.unwrap().len(), 10);
            let reads: std::collections::BTreeMap<String, i64> = sqlx::raw_sql("SHOW SESSION STATUS LIKE 'Handler_read%'")
                .fetch_all(&mut connection)
                .await
                .unwrap()
                .iter()
                .map(|row| (row.try_get::<String, _>(0).unwrap(), row.try_get::<String, _>(1).unwrap().parse().unwrap()))
                .collect();
            // One seek and nine index steps: the limit, not the namespace.
            assert!(reads["Handler_read_key"] + reads["Handler_read_next"] <= 11, "{reads:?}");
            assert_eq!(reads["Handler_read_rnd_next"], 0, "{reads:?}");
            format!("{plan} reads={reads:?}")
        }
    };
    eprintln!("[due-stream plan]\n{plan}");
}
