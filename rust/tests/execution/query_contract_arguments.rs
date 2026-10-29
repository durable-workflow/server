use super::*;

// Published Server 2.11.5 with Workflow 2.15.1
// (`WorkflowQueryContract::validatedArgumentsForRun`): a declared query's
// arguments, positional or named, are matched to its durable parameter
// contract. The worker receives the normalized positional list, encoded
// again; a violation is refused with every message. The
// query-contract-arguments fixture records the same cases.

async fn contract_worker(app: &Router) {
    let parameter = |required: bool, default: Option<&str>| {
        let mut parameter = json!({"name":"prefix","position":0,"type":"string","required":required,"variadic":false,
            "default_available":default.is_some(),"allows_null":false});
        if let Some(default) = default {
            parameter["default"] = json!(default);
        }
        parameter
    };
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":"contract-worker","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":["query_tasks"],
        "workflow_command_contracts":{"echo":{"signals":[],"updates":[],"queries":["state","greet","plain","listdef","gapdef"],"query_contracts":[
            {"name":"state","parameters":[parameter(true, None)]},{"name":"greet","parameters":[parameter(false, Some("dflt"))]},
            {"name":"listdef","parameters":[json_default(json!({"0":"x","1":"y"}))]},
            {"name":"gapdef","parameters":[json_default(json!({"1":"x"}))]}]}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

/// An optional `mixed` parameter whose registered default is raw JSON.
fn json_default(default: Value) -> Value {
    json!({"name":"value","position":0,"type":"mixed","required":false,"variadic":false,"default_available":true,
        "allows_null":false,"default":default})
}

fn named(entries: &[(&str, Payload)]) -> Value {
    envelope(Payload::Map(entries.iter().map(|(key, value)| ((*key).to_owned(), value.clone())).collect()))
}

/// Sends a query the contract accepts and returns the arguments its task carries.
async fn routed(app: &Router, query: &str, input: Value) -> Value {
    let waiting = {
        let app = app.clone();
        let path = format!("/api/workflows/contract-query/query/{query}");
        tokio::spawn(async move { request(&app, "POST", &path, json!({"input": input})).await })
    };
    let task = await_query(app, "contract-worker").await;
    let (status, body) = request(app, "POST", &format!("/api/worker/query-tasks/{}/complete", task["query_task_id"].as_str().unwrap()),
        json!({"lease_owner":"contract-worker","query_task_attempt":task["query_task_attempt"],
            "result_envelope":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, body) = waiting.await.unwrap();
    assert_eq!(status, StatusCode::OK, "{body}");
    task["query_arguments"].clone()
}

#[tokio::test]
async fn named_and_defaulted_query_arguments_reach_the_worker_as_positional_lists() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    contract_worker(&app).await;
    start(&app, "contract-query").await;
    let ok = envelope(Payload::Array(vec![Payload::String("ok".into())]));
    assert_eq!(routed(&app, "state", named(&[("prefix", Payload::String("ok".into()))])).await, ok);
    assert_eq!(routed(&app, "state", ok.clone()).await, ok);
    assert_eq!(routed(&app, "greet", envelope(Payload::Array(vec![]))).await,
        envelope(Payload::Array(vec![Payload::String("dflt".into())])));
    // A declared query without a parameter contract takes a list as given.
    let two = envelope(Payload::Array(vec![Payload::String("a".into()), Payload::String("b".into())]));
    assert_eq!(routed(&app, "plain", two.clone()).await, two);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn query_contract_violations_are_refused_with_phps_messages() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    contract_worker(&app).await;
    start(&app, "contract-query").await;
    for (query, input, errors) in [
        ("state", named(&[("prefix", Payload::String("ok".into())), ("other", Payload::String("x".into()))]),
            json!({"other":["Unknown argument [other]."]})),
        ("state", named(&[("other", Payload::String("x".into()))]),
            json!({"prefix":["The prefix argument is required."],"other":["Unknown argument [other]."]})),
        ("state", envelope(Payload::Array(vec![Payload::Long(5)])), json!({"prefix":["The prefix argument must be of type string."]})),
        ("state", envelope(Payload::Array(vec![Payload::String("a".into()), Payload::String("b".into())])),
            json!({"arguments":["Too many arguments were provided for query [state]."]})),
        ("state", envelope(Payload::Array(vec![Payload::Null])), json!({"prefix":["The prefix argument cannot be null."]})),
        ("state", envelope(Payload::Array(vec![])), json!({"prefix":["The prefix argument is required."]})),
        ("plain", named(&[("prefix", Payload::String("ok".into()))]),
            json!({"arguments":["Named arguments require a durable or loadable workflow query contract."]})),
    ] {
        let (status, body) = request(&app, "POST", &format!("/api/workflows/contract-query/query/{query}"), json!({"input": input})).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
        assert_eq!((body["reason"].clone(), body["message"].clone(), body["validation_errors"].clone()),
            (json!("invalid_query_arguments"), json!(format!("Workflow query [{query}] argument validation failed.")), errors), "{body}");
    }
    assert!(poll(&app, "contract-worker", "query").await.is_null(), "no query task was created");
    runtime.close().await;
    database.remove().await;
}

// A registered JSON default takes PHP's array shape: a list is an Avro array.
// An integer key outside a list fails PHP's encoder with an uncaught 500;
// native keeps the status and names the default.
#[tokio::test]
async fn a_registered_json_default_takes_phps_array_shape() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    contract_worker(&app).await;
    start(&app, "contract-query").await;
    let list = Payload::Array(vec![Payload::String("x".into()), Payload::String("y".into())]);
    assert_eq!(routed(&app, "listdef", envelope(Payload::Array(vec![]))).await, envelope(Payload::Array(vec![list])));
    let (status, body) = request(&app, "POST", "/api/workflows/contract-query/query/gapdef",
        json!({"input": envelope(Payload::Array(vec![]))})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::INTERNAL_SERVER_ERROR, json!("query_default_unencodable")), "{body}");
    runtime.close().await;
    database.remove().await;
}
