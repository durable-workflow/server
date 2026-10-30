use super::*;

// Published PHP's json_decode reads request bodies nested up to 511 levels
// (its default depth of 512) and refuses deeper ones with 400
// malformed_json. The deep-json-bodies fixture records the same answers.

/// A start body whose input nests arrays so the whole body has `levels`.
fn start(levels: usize) -> Value {
    let mut input = json!(1);
    for _ in 0..levels - 1 {
        input = json!([input]);
    }
    json!({"workflow_id": format!("deep-{levels}"), "workflow_type": "echo", "task_queue": "test", "input": input})
}

// The server runs on threads with a 64 MiB stack (`THREAD_STACK_BYTES` in
// main.rs); the handlers, and above all the Avro encoder, walk a 511-level
// body recursively, which a debug build cannot do on 2 MiB.
#[test]
fn bodies_are_read_to_php_s_depth() {
    std::thread::Builder::new()
        .stack_size(64 * 1024 * 1024)
        .spawn(|| {
            tokio::runtime::Builder::new_multi_thread()
                .worker_threads(2)
                .thread_stack_size(64 * 1024 * 1024)
                .enable_all()
                .build()
                .unwrap()
                .block_on(read_to_php_s_depth())
        })
        .unwrap()
        .join()
        .unwrap();
}

async fn read_to_php_s_depth() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for levels in [128, 129, 511] {
        let (status, body) = request(&app, "POST", "/api/workflows", start(levels)).await;
        assert_eq!(status, StatusCode::CREATED, "{levels}: {body}");
    }
    let (status, body) = request(&app, "POST", "/api/workflows", start(512)).await;
    assert_eq!(status, StatusCode::BAD_REQUEST, "{body}");
    assert_eq!((body["reason"].clone(), body["json_error"].clone()),
        (json!("malformed_json"), json!("Maximum stack depth exceeded")), "{body}");
    runtime.close().await;
    database.remove().await;
}
