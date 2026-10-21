//! A caller's optional trace context never changes a business outcome.
use super::*;

const TRACE_ID: &str = "4bf92f3577b34da6a3ce929d0e0e4736";

fn traced(mut body: Value) -> Value {
    body["observability_context"] = json!({"schema":"durable-workflow.trace-context","version":1,
        "carrier":{"traceparent":format!("00-{TRACE_ID}-00f067aa0ba902b7-01"),"baggage":"tenant.id=t1"}});
    body
}

fn number(value: i64) -> Value {
    json!({"input":envelope(Payload::Array(vec![Payload::Long(value)]))})
}

/// What a caller can observe of an outcome, without generated identifiers.
fn outcome(response: &(StatusCode, Value)) -> (StatusCode, Value, Value, Value) {
    let body = &response.1;
    (
        response.0,
        body["reason"].clone(),
        body["outcome"].clone(),
        body["update_status"].clone(),
    )
}

async fn both(app: &Router, method: &str, path: &str, body: Value) {
    let mut outcomes = Vec::new();
    for (workflow, carried) in [("trace-plain", false), ("trace-carried", true)] {
        let run = request(app, "GET", &format!("/api/workflows/{workflow}"), Value::Null)
            .await
            .1["run_id"]
            .as_str()
            .unwrap()
            .to_owned();
        let path = path.replace("{workflow}", workflow).replace("{run}", &run);
        let body = if carried {
            traced(body.clone())
        } else {
            body.clone()
        };
        let response = request(app, method, &path, body).await;
        assert_ne!(
            response.1["reason"], "unsupported_request_field",
            "{path}: {}",
            response.1
        );
        outcomes.push(outcome(&response));
    }
    assert_eq!(outcomes[0], outcomes[1], "{method} {path}");
}

async fn visible(app: &Router, workflow: &str) -> Value {
    let described = request(app, "GET", &format!("/api/workflows/{workflow}"), Value::Null)
        .await
        .1;
    let observed = request(
        app,
        "GET",
        &format!("/api/workflows/{workflow}/observation"),
        Value::Null,
    )
    .await
    .1;
    let history = run_history(app, &json!(workflow), &described["run_id"]).await;
    json!({"described":described,"observed":observed,"history":history})
}

#[tokio::test]
async fn caller_trace_context_is_ignored_where_php_admits_it_and_nowhere_else() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "trace-worker").await;
    for (workflow, carried) in [("trace-plain", false), ("trace-carried", true)] {
        let body = json!({"workflow_id":workflow,"workflow_type":"echo","task_queue":"test",
            "input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))});
        let started = request(
            &app,
            "POST",
            "/api/workflows",
            if carried { traced(body) } else { body },
        )
        .await;
        assert_eq!(started.0, StatusCode::CREATED, "{}", started.1);
        open_update_wait(&app, "trace-worker").await;
    }
    // A start retried with a different carrier is still the same request.
    let mut retry = traced(json!({"workflow_id":"trace-carried","workflow_type":"echo",
        "task_queue":"test","duplicate_policy":"use-existing",
        "input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))}));
    retry["observability_context"]["carrier"]["traceparent"] =
        json!("00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01");
    let retried = request(&app, "POST", "/api/workflows", retry).await;
    assert_eq!(retried.0, StatusCode::OK, "{}", retried.1);
    assert_eq!(retried.1["outcome"], "returned_existing_active");
    // The returned start is recorded on the run, so the plain run gets the
    // same retry without a carrier.
    let plain_retry = request(&app, "POST", "/api/workflows", json!({"workflow_id":"trace-plain","workflow_type":"echo",
        "task_queue":"test","duplicate_policy":"use-existing",
        "input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))})).await;
    assert_eq!(plain_retry.1["outcome"], "returned_existing_active");

    // Terminate is not an operation that admits the carrier.
    let refused = request(
        &app,
        "POST",
        "/api/workflows/trace-carried/terminate",
        traced(json!({"reason":"not admitted"})),
    )
    .await;
    assert_eq!(refused.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", refused.1);
    assert_eq!(refused.1["reason"], "unsupported_request_field");
    // Beside the carrier, other unknown signal fields are ignored, as PHP's
    // validated input leaves them.
    let mut unknown = number(1);
    unknown["business_key"] = json!("ignored");
    both(&app, "POST", "/api/workflows/{workflow}/signal/note", unknown).await;

    let mut update = terminal_updates::input("trace-current");
    both(&app, "POST", "/api/workflows/{workflow}/update/set", update).await;
    update = terminal_updates::input("trace-run");
    both(
        &app,
        "POST",
        "/api/workflows/{workflow}/runs/{run}/update/set",
        update,
    )
    .await;
    both(
        &app,
        "POST",
        "/api/workflows/{workflow}/signal/finish",
        number(7),
    )
    .await;
    both(
        &app,
        "POST",
        "/api/workflows/{workflow}/runs/{run}/signal/finish",
        number(8),
    )
    .await;
    for path in [
        "/api/workflows/{workflow}/request-cancellation",
        "/api/workflows/{workflow}/runs/{run}/request-cancellation",
    ] {
        both(&app, "POST", path, json!({"reason":"trace λ"})).await;
    }
    both(
        &app,
        "POST",
        "/api/workflows/{workflow}/runs/{run}/cancel",
        json!({"reason":"trace λ"}),
    )
    .await;
    both(
        &app,
        "POST",
        "/api/workflows/{workflow}/cancel",
        json!({"reason":"again"}),
    )
    .await;

    let plain = visible(&app, "trace-plain").await;
    let carried = visible(&app, "trace-carried").await;
    for document in [&plain, &carried] {
        let text = document.to_string();
        assert!(!text.contains(TRACE_ID) && !text.contains("observability_context"));
    }
    assert_eq!(carried["described"]["status"], "cancelled");
    let events = |document: &Value| {
        document["history"]
            .as_array()
            .unwrap()
            .iter()
            .map(|event| event["event_type"].clone())
            .collect::<Vec<_>>()
    };
    assert_eq!(events(&plain), events(&carried));
    runtime.close().await;
    database.remove().await;
}
