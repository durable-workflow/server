use super::*;

// Server 2.11.1 message streams: ordered, deduplicated appends delivered to
// the current run as the runtime-reserved signal; worker acknowledgements and
// waits on workflow task completions; the hand-over to a continued run.

const STREAM_SIGNAL: &str = "__durable_workflow_message_stream";

fn stream_worker() -> Value {
    json!({"worker_id":"streams","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"capabilities":["message_streams"]})
}

/// An SDK receive: a condition wait keyed by the stream cursor.
fn receive(cursor: i64) -> Value {
    json!([{"type":"open_condition_wait","condition_key":format!("message-stream:orders:{cursor}"),
        "condition_definition_fingerprint":format!("sha256:{}", "a".repeat(64))}])
}

async fn append(app: &Router, workflow: &str, stream: &str, body: Value) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/workflows/{workflow}/message-streams/{stream}/messages"), body).await
}

async fn complete(app: &Router, task: &Value, commands: Value, extra: Value, protocol: &str) -> (StatusCode, Value) {
    let mut body = completion(task, commands);
    body.as_object_mut().unwrap().extend(extra.as_object().unwrap().clone());
    request_protocol(app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()), body, protocol).await
}

async fn streams(app: &Router, workflow: &str) -> Value {
    let (status, body) = request(app, "GET", &format!("/api/workflows/{workflow}/message-streams"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body
}

/// The stream deliveries a history holds: (schema tail, stream, message id or cursor).
fn deliveries(history: &Value) -> Vec<(String, String, String)> {
    history.as_array().unwrap().iter()
        .filter(|event| event["event_type"] == "SignalReceived" && event["payload"]["signal_name"] == STREAM_SIGNAL)
        .map(|event| {
            let blob = event["payload"]["arguments"]["blob"].as_str().unwrap();
            let Payload::Array(values) = durable_workflow_server::codec::ValueCodec::new().unwrap().decode(blob).unwrap() else {
                panic!("one argument list");
            };
            let Payload::Map(delivery) = &values[0] else { panic!("one delivery map") };
            let text = |key: &str| match delivery.get(key) {
                Some(Payload::String(value)) => value.clone(),
                Some(Payload::Long(value)) => value.to_string(),
                other => panic!("{key}: {other:?}"),
            };
            let schema = text("schema");
            let marker = if schema.ends_with(".cursor") { text("through_position") } else { text("message_id") };
            (schema.rsplit('.').next().unwrap().to_owned(), text("stream_name"), marker)
        })
        .collect()
}

fn types(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

#[tokio::test]
async fn appended_messages_reach_the_run_and_follow_it_when_it_continues() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    assert_eq!(request(&app, "POST", "/api/worker/register", stream_worker()).await.0, StatusCode::CREATED);
    let started = start(&app, "streams-flow").await;
    let task = poll(&app, "streams", "workflow").await;
    let waits = json!({"message_stream_waits":[{"stream_name":"orders","after_position":0}]});
    let (status, body) = complete(&app, &task, receive(0), waits, "1.20").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let listed = streams(&app, "streams-flow").await;
    assert_eq!(listed["streams"][0]["pending_wait"]["after_position"], 0, "{listed}");
    assert_eq!(listed["streams"][0]["pending_wait"]["run_id"], started["run_id"]);

    let (status, first) = append(&app, "streams-flow", "orders", json!({"message_id":"m1","input":[{"n":1}]})).await;
    assert_eq!((status, &first["position"], &first["run_id"]), (StatusCode::ACCEPTED, &json!(1), &started["run_id"]), "{first}");
    let (status, again) = append(&app, "streams-flow", "orders", json!({"message_id":"m1","input":[{"n":1}]})).await;
    assert_eq!((status, &again["duplicate"], &again["position"]), (StatusCode::OK, &json!(true), &json!(1)), "{again}");
    assert!(again.get("run_id").is_none(), "a duplicate names no run: {again}");
    let (status, conflict) = append(&app, "streams-flow", "orders", json!({"message_id":"m1","input":[{"n":9}]})).await;
    assert_eq!((status, &conflict["reason"]), (StatusCode::CONFLICT, &json!("message_identity_conflict")), "{conflict}");
    assert_eq!(append(&app, "streams-flow", "orders", json!({"message_id":"m2","input":[{"n":2}]})).await.1["position"], 2);
    let listed = streams(&app, "streams-flow").await;
    let orders = &listed["streams"][0];
    assert_eq!((&orders["last_position"], &orders["pending_count"], &orders["duplicate_count"]), (&json!(2), &json!(2), &json!(2)), "{listed}");
    assert!(orders["pending_wait"].is_null(), "an append clears the wait: {listed}");

    // The condition wait wakes for the first message; the worker reads both.
    let task = poll(&app, "streams", "workflow").await;
    assert_eq!(deliveries(&task["history_events"]), [("message".into(), "orders".into(), "m1".into()), ("message".into(), "orders".into(), "m2".into())]);
    let cursor = json!({"message_stream_cursors":[{"stream_name":"orders","through_position":1}]});
    let (status, body) = complete(&app, &task, receive(1), cursor, "1.20").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["created_task_ids"].as_array().unwrap().len(), 1, "the next message task is reported: {body}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert!(!types(&history).contains(&"SignalApplied".to_owned()), "the condition wait consumes the message: {history}");
    let cursors: Vec<_> = history.as_array().unwrap().iter().filter(|event| event["event_type"] == "MessageCursorAdvanced").collect();
    assert_eq!(cursors.len(), 2, "{history}");
    assert_eq!(cursors[0]["payload"]["stream_key"], "instance:streams-flow");
    assert_eq!(cursors[0]["payload"]["task"]["status"], "leased");
    assert_eq!((&cursors[1]["payload"]["stream_key"], &cursors[1]["payload"]["new_position"]), (&json!("orders"), &json!(1)));
    assert_eq!(cursors[1]["payload"]["task"]["status"], "completed");
    assert_eq!(streams(&app, "streams-flow").await["streams"][0]["cursor_position"], 1);

    // Continue as new acknowledging the second message: the next run gets the
    // cursor and the third message, which was still unconsumed.
    assert_eq!(append(&app, "streams-flow", "orders", json!({"message_id":"m3","input":[{"n":3}]})).await.1["position"], 3);
    let task = poll(&app, "streams", "workflow").await;
    let cursor = json!({"message_stream_cursors":[{"stream_name":"orders","through_position":2}]});
    let (status, body) = complete(&app, &task, json!([{"type":"continue_as_new","arguments":envelope(Payload::Array(vec![]))}]), cursor, "1.20").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let task = poll(&app, "streams", "workflow").await;
    assert_ne!(task["run_id"], started["run_id"], "{task}");
    assert_eq!(deliveries(&task["history_events"]), [("cursor".into(), "orders".into(), "2".into()), ("message".into(), "orders".into(), "m3".into())]);
    let cursor = json!({"message_stream_cursors":[{"stream_name":"orders","through_position":3}]});
    let (status, body) = complete(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::String("done".into()))}]), cursor, "1.20").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let orders = &streams(&app, "streams-flow").await["streams"][0];
    assert_eq!((&orders["cursor_position"], &orders["pending_count"]), (&json!(3), &json!(0)), "{orders}");
    let (status, closed) = append(&app, "streams-flow", "orders", json!({"message_id":"m1","input":[{"n":1}]})).await;
    assert_eq!((status, &closed["reason"]), (StatusCode::CONFLICT, &json!("run_not_active")), "{closed}");
}

#[tokio::test]
async fn refused_appends_and_acknowledgements_change_nothing_but_the_counts() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    assert_eq!(request(&app, "POST", "/api/worker/register", stream_worker()).await.0, StatusCode::CREATED);
    start(&app, "streams-refused").await;
    let task = poll(&app, "streams", "workflow").await;
    for (extra, field) in [
        (json!({"message_stream_waits":[{"stream_name":"orders","after_position":1}]}), "message_stream_waits.0.after_position"),
        (json!({"message_stream_cursors":[{"stream_name":"orders","through_position":1}]}), "message_stream_cursors.0.through_position"),
        (json!({"message_stream_waits":[{"stream_name":"bad name","after_position":0}]}), "message_stream_waits.0.stream_name"),
        (json!({"message_stream_cursors":[{"stream_name":"orders","through_position":-1}]}), "message_stream_cursors.0.through_position"),
    ] {
        let (status, body) = complete(&app, &task, receive(0), extra, "1.20").await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
        assert!(body["errors"].get(field).is_some(), "{field}: {body}");
    }
    let (status, body) = complete(&app, &task, receive(0), json!({"message_stream_waits":[{"stream_name":"orders","after_position":0}]}), "1.14").await;
    assert_eq!((status, &body["reason"]), (StatusCode::CONFLICT, &json!("message_streams_unavailable")), "{body}");
    assert_eq!(streams(&app, "streams-refused").await["count"], 0, "a refused completion records no stream");

    for (body, field) in [(json!({"input":[1]}), "message_id"), (json!({"message_id":"bad id!"}), "message_id"),
        (json!({"message_id":"x".repeat(192)}), "message_id"), (json!({"message_id":"m1","input":"text"}), "input")] {
        let (status, refused) = append(&app, "streams-refused", "orders", body).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
        assert!(refused["errors"].get(field).is_some(), "{refused}");
    }
    let (status, refused) = append(&app, "streams-refused", "bad%20name", json!({"message_id":"m1"})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
    let (status, refused) = append(&app, "streams-missing", "orders", json!({"message_id":"m1"})).await;
    assert_eq!((status, &refused["reason"]), (StatusCode::NOT_FOUND, &json!("instance_not_found")), "{refused}");
    let orders = &streams(&app, "streams-refused").await["streams"][0];
    assert_eq!((&orders["malformed_count"], &orders["last_position"]), (&json!(4), &json!(0)), "{orders}");
    // The last refused append kept a valid id; the refused stream name and
    // the unknown workflow count nowhere.
    assert_eq!((&orders["last_input_outcome"], &orders["last_input_message_id"]), (&json!("malformed"), &json!("m1")), "{orders}");
    let (status, refused) = request(&app, "POST", &format!("/api/workflows/streams-refused/signal/{STREAM_SIGNAL}"), json!([1])).await;
    assert_eq!((status, &refused["reason"]), (StatusCode::UNPROCESSABLE_ENTITY, &json!("runtime_reserved_signal")), "{refused}");
    let (status, missing) = request(&app, "GET", "/api/workflows/streams-refused/message-streams/other", Value::Null).await;
    assert_eq!((status, &missing["reason"]), (StatusCode::NOT_FOUND, &json!("message_stream_not_found")), "{missing}");
}

#[tokio::test]
async fn cluster_info_and_workers_see_the_contract() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (status, info) = request(&app, "GET", "/api/cluster/info", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{info}");
    assert_eq!(info["message_streams_contract"]["schema"], "durable-workflow.v2.message-streams.contract");
    assert_eq!(info["message_streams_contract"]["transport"]["internal_signal"], STREAM_SIGNAL);
    let capability = &info["worker_protocol"]["server_capabilities"]["message_streams"];
    assert_eq!(capability["supported"], true, "{capability}");
    assert_eq!(capability["minimum_worker_protocol_version"], "1.15");
}
