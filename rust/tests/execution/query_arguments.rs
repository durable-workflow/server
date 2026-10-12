use super::*;

async fn query_entries(database: &TestDatabase) -> i64 {
    let sql = "SELECT COUNT(*) FROM dw_query_cache";
    match database {
        TestDatabase::Sqlite(dir) => {
            let pool = sqlx::SqlitePool::connect(&format!(
                "sqlite://{}",
                dir.path().join("runtime.sqlite").display()
            ))
            .await
            .unwrap();
            let count = sqlx::query_scalar(sql).fetch_one(&pool).await.unwrap();
            pool.close().await;
            count
        }
        TestDatabase::Postgres { options, .. } => {
            let pool = sqlx::postgres::PgPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap();
            let count = sqlx::query_scalar(sql).fetch_one(&pool).await.unwrap();
            pool.close().await;
            count
        }
        TestDatabase::MySql { options, .. } => {
            let pool = sqlx::mysql::MySqlPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap();
            let count = sqlx::query_scalar(sql).fetch_one(&pool).await.unwrap();
            pool.close().await;
            count
        }
    }
}

#[tokio::test]
async fn omitted_query_input_uses_official_empty_arguments_without_mutating_the_run() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let worker = "zero-query-worker";
    let registered = request(&app, "POST", "/api/worker/register", json!({
        "worker_id":worker,"task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"capabilities":["query_tasks"],"workflow_command_contracts":{"echo":{
        "queries":["state","required"],"query_contracts":[
            {"name":"state","parameters":[]},
            {"name":"required","parameters":[{"name":"request","position":0,"type":"int","required":true,
                "variadic":false,"default_available":false,"allows_null":false}]}
        ]}}
    })).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let workflow = "zero-query-arguments";
    let started = start(&app, workflow).await;
    let task = poll(&app, worker, "workflow").await;
    let completed = finish_task(
        &app,
        &task,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]),
    )
    .await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let describe = format!("/api/workflows/{workflow}");
    let history_path = format!(
        "{describe}/runs/{}/history",
        started["run_id"].as_str().unwrap()
    );
    let instance_query = format!("{describe}/query/state");
    let run_query = format!(
        "{describe}/runs/{}/query/state",
        started["run_id"].as_str().unwrap()
    );
    let before = request(&app, "GET", &describe, Value::Null).await.1;
    let history_before = request(&app, "GET", &history_path, Value::Null).await.1;
    let empty = envelope(Payload::Array(vec![]));
    assert_eq!(query_entries(&database).await, 0);

    for (path, input, status, reason) in [
        (
            instance_query.clone(),
            Value::Null,
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_request_field",
        ),
        (
            instance_query.clone(),
            json!([]),
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_request_field",
        ),
        (
            instance_query.clone(),
            json!({"foreign":true}),
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_payload_codec",
        ),
        (
            instance_query.clone(),
            json!({"input":empty.clone(),"foreign":true}),
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_request_field",
        ),
        (
            instance_query.clone(),
            json!({"input":null}),
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_payload_codec",
        ),
        (
            instance_query.clone(),
            json!({"input":{"codec":"json","blob":"[]"}}),
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_payload_codec",
        ),
        (
            instance_query.clone(),
            json!({"input":{"codec":"avro"}}),
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_payload_codec",
        ),
        (
            instance_query.clone(),
            json!({"input":{"codec":"avro","blob":""}}),
            StatusCode::UNPROCESSABLE_ENTITY,
            "invalid_payload_envelope",
        ),
        (
            instance_query.clone(),
            json!({"input":{"codec":"avro","blob":"not-avro"}}),
            StatusCode::UNPROCESSABLE_ENTITY,
            "invalid_query_arguments",
        ),
        (
            instance_query.clone(),
            json!({"input":envelope(Payload::Long(7))}),
            StatusCode::UNPROCESSABLE_ENTITY,
            "invalid_query_arguments",
        ),
        (
            instance_query.clone(),
            json!({"input":envelope(Payload::Array(vec![Payload::Long(7)]))}),
            StatusCode::UNPROCESSABLE_ENTITY,
            "invalid_query_arguments",
        ),
        (
            instance_query.clone(),
            json!({"input":{"codec":"avro","blob":"A".repeat(64 * 1024 + 1)}}),
            StatusCode::PAYLOAD_TOO_LARGE,
            "query_arguments_exceed_budget",
        ),
        (
            instance_query.replace("/query/state", "/query/required"),
            json!({}),
            StatusCode::UNPROCESSABLE_ENTITY,
            "invalid_query_arguments",
        ),
        (
            instance_query.replace("/query/state", "/query/required"),
            json!({"input":empty.clone()}),
            StatusCode::UNPROCESSABLE_ENTITY,
            "invalid_query_arguments",
        ),
        (
            instance_query.replace("/query/state", "/query/unknown"),
            json!({}),
            StatusCode::NOT_FOUND,
            "rejected_unknown_query",
        ),
    ] {
        let rejected = request(&app, "POST", &path, input).await;
        assert_eq!(rejected.0, status, "{}", rejected.1);
        assert_eq!(rejected.1["reason"], reason);
        assert_eq!(
            query_entries(&database).await,
            0,
            "refusal must not admit a query"
        );
        assert_eq!(request(&app, "GET", &describe, Value::Null).await.1, before);
        assert_eq!(
            request(&app, "GET", &history_path, Value::Null).await.1,
            history_before
        );
    }

    let result = envelope(Payload::Map(
        [
            ("count".to_owned(), Payload::Long(9007199254740993)),
            ("message".to_owned(), Payload::String("query λ".to_owned())),
        ]
        .into_iter()
        .collect(),
    ));
    let mut task_ids = vec![];
    for (index, (path, input)) in [
        (instance_query, json!({})),
        (run_query, json!({"input":empty.clone()})),
    ]
    .into_iter()
    .enumerate()
    {
        let waiting = {
            let app = app.clone();
            tokio::spawn(async move { request(&app, "POST", &path, input).await })
        };
        let claim = await_query(&app, worker).await;
        assert_eq!(claim["workflow_id"], workflow);
        assert_eq!(claim["run_id"], started["run_id"]);
        assert_eq!(claim["run_status"], "completed");
        assert_eq!(claim["query_name"], "state");
        assert_eq!(claim["query_arguments"], empty);
        assert_eq!(
            ValueCodec::new()
                .unwrap()
                .decode(claim["query_arguments"]["blob"].as_str().unwrap())
                .unwrap(),
            Payload::Array(vec![])
        );
        task_ids.push(claim["query_task_id"].clone());
        let receipt = request(
            &app, "POST",
            &format!("/api/worker/query-tasks/{}/complete", claim["query_task_id"].as_str().unwrap()),
            json!({"lease_owner":claim["lease_owner"],"query_task_attempt":claim["query_task_attempt"],"result_envelope":result}),
        ).await;
        assert_eq!(receipt.0, StatusCode::OK, "{}", receipt.1);
        assert_eq!(receipt.1["query_task_id"], claim["query_task_id"]);
        assert_eq!(receipt.1["outcome"], "completed");
        let response = waiting.await.unwrap();
        assert_eq!(response.0, StatusCode::OK, "{}", response.1);
        assert_eq!(response.1["workflow_id"], workflow);
        assert_eq!(response.1["run_id"], started["run_id"]);
        assert_eq!(response.1["result_envelope"], result);
        assert_eq!(query_entries(&database).await, index as i64 + 1);
        assert_eq!(request(&app, "GET", &describe, Value::Null).await.1, before);
        assert_eq!(
            request(&app, "GET", &history_path, Value::Null).await.1,
            history_before
        );
    }
    assert_ne!(task_ids[0], task_ids[1]);
    runtime.close().await;
    database.remove().await;
}
