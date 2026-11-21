use super::*;
use base64::{Engine, engine::general_purpose::STANDARD};
use durable_workflow_server::runtime::QueryTaskConfig;
use std::time::Duration;
use tokio::time::timeout;

fn with_history_timeout(runtime: Runtime, seconds: i64) -> Router {
    router(runtime.with_query_task_config(QueryTaskConfig {
        worker_poll_timeout_seconds: seconds,
        ..Default::default()
    }))
}

async fn history_paths(app: &Router, workflow: &str) -> (String, String, i64) {
    let started = start(app, workflow).await;
    let run = started["run_id"].as_str().unwrap();
    let path = format!("/api/workflows/{workflow}/runs/{run}/history");
    let (status, page) = request(app, "GET", &path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{page}");
    let last = page["events"].as_array().unwrap().last().unwrap()["sequence"]
        .as_i64()
        .unwrap();
    let tail = format!(
        "{path}?next_page_token={}",
        STANDARD.encode(last.to_string())
    );
    (path, tail, last)
}

#[tokio::test]
async fn history_wait_times_out_with_an_empty_page_and_skips_wait_for_ready_events() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = with_history_timeout(runtime.clone(), 1);
    let (path, tail, _) = history_paths(&app, "history-timeout").await;
    let wait = format!("{tail}&wait_new_event=1");
    let response = request(&app, "GET", &wait, Value::Null);
    tokio::pin!(response);
    assert!(
        timeout(Duration::from_millis(750), &mut response)
            .await
            .is_err()
    );
    let (status, page) = timeout(Duration::from_secs(3), response).await.unwrap();
    assert_eq!(status, StatusCode::OK, "{page}");
    assert_eq!(page["events"], json!([]), "{page}");
    assert!(page["next_page_token"].is_null());

    for flag in [
        "",
        "&wait_new_event=0",
        "&wait_new_event=",
        "&wait_new_event=%20",
        "&unrelated=yes",
    ] {
        let (status, page) = timeout(
            Duration::from_millis(750),
            request(&app, "GET", &format!("{tail}{flag}"), Value::Null),
        )
        .await
        .unwrap();
        assert_eq!(status, StatusCode::OK, "{page}");
        assert_eq!(page["events"], json!([]), "{page}");
    }

    let (status, page) = timeout(
        Duration::from_millis(750),
        request(
            &app,
            "GET",
            &format!("{path}?page_size=1&wait_new_event=1"),
            Value::Null,
        ),
    )
    .await
    .unwrap();
    assert_eq!(status, StatusCode::OK, "{page}");
    assert_eq!(page["events"][0]["event_type"], "StartAccepted", "{page}");
    assert!(page["next_page_token"].is_string(), "{page}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn history_wait_observes_commits_on_the_same_node_and_another_node() {
    for same_node in [true, false] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = with_history_timeout(runtime.clone(), 8);
        let writer_runtime = if same_node {
            runtime.clone()
        } else {
            database.open().await.unwrap()
        };
        let writer = router(writer_runtime.clone());
        let (_, tail, last) = history_paths(&app, "history-signal").await;
        let wait = format!("{tail}&wait_new_event=1");
        let response = request(&app, "GET", &wait, Value::Null);
        tokio::pin!(response);
        assert!(
            timeout(Duration::from_millis(100), &mut response)
                .await
                .is_err()
        );
        let (status, receipt) = request(
            &writer,
            "POST",
            "/api/workflows/history-signal/signal/wake",
            json!([]),
        )
        .await;
        assert_eq!(status, StatusCode::ACCEPTED, "{receipt}");
        let (status, page) = timeout(Duration::from_secs(3), response).await.unwrap();
        assert_eq!(status, StatusCode::OK, "{page}");
        let events = page["events"].as_array().unwrap();
        assert_eq!(events[0]["sequence"], last + 1, "{page}");
        assert_eq!(events[0]["event_type"], "SignalReceived", "{page}");
        if !same_node {
            writer_runtime.close().await;
        }
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn history_wait_validates_nullable_boolean_and_matches_the_requested_run_scope() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = with_history_timeout(runtime.clone(), 8);
    let (path, _, _) = history_paths(&app, "scoped-history").await;
    for query in [
        "wait_new_event=true",
        "wait_new_event=false",
        "wait_new_event=2",
        "wait_new_event%5B%5D=1",
    ] {
        let (status, page) = request(&app, "GET", &format!("{path}?{query}"), Value::Null).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{query}: {page}");
        assert_eq!(
            page["errors"]["wait_new_event"],
            json!(["The wait new event field must be true or false."]),
            "{page}"
        );
    }
    let other_workflow = path.replace("/scoped-history/", "/unrelated/");
    let (status, page) = timeout(
        Duration::from_secs(2),
        request(
            &app,
            "GET",
            &format!("{other_workflow}?wait_new_event=1"),
            Value::Null,
        ),
    )
    .await
    .unwrap();
    assert_eq!(status, StatusCode::NOT_FOUND, "{page}");
    assert_eq!(page["reason"], "run_not_found");
    runtime.close().await;
    database.remove().await;
}
