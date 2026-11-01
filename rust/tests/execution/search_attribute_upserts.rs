//! `upsert_search_attributes` as published PHP applies it: values checked
//! against the namespace's registered types, typed rows, a
//! `SearchAttributesUpserted` event and the run's typed view.
use super::*;

async fn typed_worker(app: &Router, worker: &str) {
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":worker,
        "task_queue":"test","runtime":"rust","supported_workflow_types":["echo"],"supported_activity_types":[],
        "capabilities":["typed_search_attributes"]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn define(app: &Router, name: &str, kind: &str) {
    let (status, body) =
        request(app, "POST", "/api/search-attributes", json!({"name":name,"type":kind})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

fn upsert(attributes: Value, types: Value) -> Value {
    json!({"type":"upsert_search_attributes","attributes":attributes,"attribute_types":types})
}

fn events<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

async fn described(app: &Router, workflow: &str) -> Value {
    let (status, body) = request(app, "GET", &format!("/api/workflows/{workflow}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["search_attributes"].clone()
}

#[tokio::test]
async fn typed_upserts_store_rows_history_and_the_described_view() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for (name, kind) in [("SearchKeyword", "keyword"), ("SearchTags", "keyword_list"), ("SearchCount", "int"),
        ("SearchRatio", "double"), ("SearchFlag", "bool"), ("SearchTime", "datetime"), ("SearchText", "string"),
        ("SearchRemoved", "keyword")]
    {
        define(&app, name, kind).await;
    }
    typed_worker(&app, "typed-worker").await;
    let started = start(&app, "typed-upserts").await;
    let task = poll(&app, "typed-worker", "workflow").await;
    let initial = json!({"SearchText":"two words","SearchKeyword":"界".repeat(85),
        "SearchTags":[" urgent ","urgent"],"SearchCount":9_007_199_254_740_993_i64,"SearchRatio":1.25,
        "SearchFlag":true,"SearchTime":"2026-10-08T12:34:56.123456Z","SearchRemoved":"erase"});
    let types = json!({"SearchText":"string","SearchKeyword":"keyword","SearchTags":"keyword_list",
        "SearchCount":"int","SearchRatio":"float","SearchFlag":"bool","SearchTime":"datetime","SearchRemoved":"keyword"});
    let first = finish_task(&app, &task, json!([upsert(initial.clone(), types.clone()),
        {"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(first.0, StatusCode::OK, "{}", first.1);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let upserted = events(&history, "SearchAttributesUpserted");
    assert_eq!(upserted.len(), 1, "{history}");
    let payload = &upserted[0]["payload"];
    let mut expected = initial.clone();
    expected["SearchTags"] = json!(["urgent", "urgent"]);
    assert_eq!(payload["attributes"], expected);
    assert_eq!(payload["attribute_types"], types);
    assert_eq!(payload["merged"], expected);
    assert_eq!(payload["sequence"], 1);
    assert_eq!(described(&app, "typed-upserts").await, expected);

    // Change, replace and delete values in a later task.
    assert_eq!(request(&app, "POST", "/api/workflows/typed-upserts/signal/finish",
        json!({"input":envelope(Payload::Array(vec![]))})).await.0, StatusCode::ACCEPTED);
    let resumed = poll(&app, "typed-worker", "workflow").await;
    let mutation = json!({"SearchText":"finished café 界","SearchKeyword":"finished","SearchTags":["done","café"],
        "SearchFlag":false,"SearchRemoved":null});
    let second = finish_task(&app, &resumed, json!([upsert(mutation.clone(), json!({"SearchText":"string",
        "SearchKeyword":"keyword","SearchTags":"keyword_list","SearchFlag":"bool"})),
        {"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(second.0, StatusCode::OK, "{}", second.1);
    let mut finished = expected.clone();
    for (key, value) in mutation.as_object().unwrap() {
        finished[key] = value.clone();
    }
    finished.as_object_mut().unwrap().remove("SearchRemoved");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let upserted = events(&history, "SearchAttributesUpserted");
    assert_eq!(upserted[1]["payload"]["attributes"], mutation);
    assert_eq!(upserted[1]["payload"]["merged"], finished);
    assert_eq!(described(&app, "typed-upserts").await, finished);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn invalid_upserts_are_refused_without_history() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    define(&app, "SearchCount", "int").await;
    typed_worker(&app, "typed-worker").await;
    register(&app, "plain-worker", json!(["echo"]), json!([])).await;
    let started = start(&app, "refused-upserts").await;
    let task = poll(&app, "typed-worker", "workflow").await;
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    // The registered schema refuses an unregistered key and a wrong type.
    let refused = finish_task(&app, &task, json!([
        {"type":"upsert_search_attributes","attributes":{"SearchCount":"3","Other":1}}])).await;
    assert_eq!(refused.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", refused.1);
    assert_eq!(refused.1["errors"]["commands.0.attributes"], json!([
        "Search attribute [Other] is not registered for this namespace.",
        "Search attribute [SearchCount] is registered as int and must be an integer.",
    ]));
    // Declared types need the worker protocol and capability that carry them.
    let path = format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap());
    let typed = completion(&task, json!([upsert(json!({"SearchCount":3}), json!({"SearchCount":"int"}))]));
    let old_protocol = request_protocol(&app, "POST", &path, typed.clone(), "1.15").await;
    assert_eq!(old_protocol.0, StatusCode::CONFLICT, "{}", old_protocol.1);
    assert_eq!(old_protocol.1["reason"], "typed_search_attributes_unavailable");
    assert_eq!(old_protocol.1["minimum_protocol_version"], "1.16");
    assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, before);
    let mut typed = typed;
    typed["commands"].as_array_mut().unwrap().push(json!({"type":"open_signal_wait","signal_name":"finish"}));
    let accepted = request(&app, "POST", &path, typed).await;
    assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
    // Typed history needs a worker that advertises the capability to replay it.
    assert_eq!(request(&app, "POST", "/api/workflows/refused-upserts/signal/finish",
        json!({"input":envelope(Payload::Array(vec![]))})).await.0, StatusCode::ACCEPTED);
    assert!(poll(&app, "plain-worker", "workflow").await.is_null());
    assert!(poll(&app, "typed-worker", "workflow").await.is_object());
    // A worker registering the capability needs protocol 1.16.
    let registered = request_protocol(&app, "POST", "/api/worker/register", json!({"worker_id":"old-worker",
        "task_queue":"test","runtime":"rust","supported_workflow_types":["echo"],"supported_activity_types":[],
        "capabilities":["typed_search_attributes"]}), "1.15").await;
    assert_eq!(registered.0, StatusCode::CONFLICT, "{}", registered.1);
    assert_eq!(registered.1["reason"], "workflow_metadata_capability_protocol_mismatch");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn continued_runs_inherit_typed_attributes() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    typed_worker(&app, "typed-worker").await;
    let started = start(&app, "inherited-upserts").await;
    let task = poll(&app, "typed-worker", "workflow").await;
    // Without registered definitions, untyped values infer their type.
    let continued = finish_task(&app, &task, json!([
        {"type":"upsert_search_attributes","attributes":{"Region":"eu-west","Attempt":2}},
        {"type":"continue_as_new"}])).await;
    assert_eq!(continued.0, StatusCode::OK, "{}", continued.1);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(events(&history, "SearchAttributesUpserted")[0]["payload"]["attribute_types"],
        json!({"Attempt":"int","Region":"keyword"}));
    let current = request(&app, "GET", "/api/workflows/inherited-upserts", Value::Null).await.1;
    assert_ne!(current["run_id"], started["run_id"], "{current}");
    assert_eq!(current["search_attributes"], json!({"Attempt":2,"Region":"eu-west"}));
    runtime.close().await;
    database.remove().await;
}

async fn listed(app: &Router, query: &str) -> (StatusCode, Value) {
    let encoded: String = form_urlencoded::byte_serialize(query.as_bytes()).collect();
    request(app, "GET", &format!("/api/workflows?workflow_type=echo&query={encoded}"), Value::Null).await
}

#[tokio::test]
async fn workflow_lists_filter_and_show_typed_attributes_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for (name, kind) in [("SearchKeyword", "keyword"), ("SearchCount", "int"), ("SearchTags", "keyword_list"),
        ("SearchFlag", "bool")]
    {
        define(&app, name, kind).await;
    }
    typed_worker(&app, "typed-worker").await;
    for (workflow, keyword, count, flag) in [("listed-a", "alpha", 1, true), ("listed-b", "beta", 9_007_199_254_740_993_i64, false)] {
        start(&app, workflow).await;
        let task = poll(&app, "typed-worker", "workflow").await;
        let done = finish_task(&app, &task, json!([upsert(
            json!({"SearchKeyword":keyword,"SearchCount":count,"SearchTags":[keyword,"shared"],"SearchFlag":flag}),
            json!({"SearchKeyword":"keyword","SearchCount":"int","SearchTags":"keyword_list","SearchFlag":"bool"})),
            {"type":"open_signal_wait","signal_name":"finish"}])).await;
        assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    }
    let ids = |body: &Value| -> Vec<String> {
        body["workflows"].as_array().unwrap().iter().map(|row| row["workflow_id"].as_str().unwrap().to_owned()).collect()
    };
    for (query, expected) in [
        ("SearchKeyword = \"alpha\"", vec!["listed-a"]),
        ("SearchCount > 9007199254740992 AND SearchCount <= 9007199254740993", vec!["listed-b"]),
        ("SearchTags = \"shared\"", vec!["listed-b", "listed-a"]),
        ("SearchFlag = false OR SearchKeyword = alpha", vec!["listed-b", "listed-a"]),
        ("NOT SearchFlag AND Status = running", vec!["listed-b"]),
        ("SearchKeyword IN (\"beta\", gamma)", vec!["listed-b"]),
        ("SearchKeyword = \"missing\"", vec![]),
    ] {
        let (status, body) = listed(&app, query).await;
        assert_eq!(status, StatusCode::OK, "{query}: {body}");
        assert_eq!(ids(&body), expected, "{query}");
    }
    let (_, body) = listed(&app, "SearchKeyword = alpha").await;
    assert_eq!(body["workflows"][0]["search_attributes"],
        json!({"SearchCount":1,"SearchFlag":true,"SearchKeyword":"alpha","SearchTags":["alpha","shared"]}));
    // A plain term keeps the free-text workflow ID search.
    assert_eq!(ids(&listed(&app, "listed-a").await.1), ["listed-a"]);
    let refused = listed(&app, "Unknown = 1").await;
    assert_eq!(refused.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", refused.1);
    assert_eq!(refused.1["errors"]["query"], json!(["Search attribute [Unknown] is not defined in namespace [default]."]));
    runtime.close().await;
    database.remove().await;
}

// Published PHP fills in an upsert's registered types before its second
// capability check, so a worker without `typed_search_attributes` cannot
// upsert search attributes even when it sends no `attribute_types`.
#[tokio::test]
async fn an_upsert_needs_the_typed_capability_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "plain-worker", json!(["echo"]), json!([])).await;
    define(&app, "region", "keyword").await;
    let started = start(&app, "untyped-upsert").await;
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let task = poll(&app, "plain-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task,
        json!([{"type":"upsert_search_attributes","attributes":{"region":"eu"}}])).await;
    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!(body["reason"], "workflow_metadata_capability_not_advertised", "{body}");
    assert_eq!(body["capability"], "typed_search_attributes", "{body}");
    assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, before);
}

#[tokio::test]
async fn an_upsert_over_the_run_limits_is_rejected_as_php_rejects_it() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"limit-worker",
        "task_queue":"test","runtime":"rust","supported_workflow_types":["echo"],"supported_activity_types":[],
        "capabilities":["typed_search_attributes","memo_upserts"]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let started = start(&app, "limit-upserts").await;
    let task = poll(&app, "limit-worker", "workflow").await;
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let many = |count: usize| Value::Object((0..count).map(|index| (format!("Attr{index:03}"), json!(index))).collect());
    // `validateAttributeSet`'s exception reaches the completion's catch.
    let refused = finish_task(&app, &task, json!([
        {"type":"upsert_search_attributes","attributes":many(101)}])).await;
    assert_eq!(refused.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", refused.1);
    assert_eq!((&refused.1["reason"], &refused.1["outcome"], &refused.1["recorded"], &refused.1["error"]),
        (&json!("invalid_workflow_stream_command"), &json!("rejected"), &json!(false),
            &json!("Search attributes count exceeds maximum (101 > 100)")));
    assert_eq!(refused.1["task_id"], task["task_id"]);
    let large = Value::Object((0..33).map(|index| (format!("Attr{index:03}"), json!("v".repeat(2048)))).collect());
    let refused = finish_task(&app, &task, json!([{"type":"upsert_search_attributes","attributes":large}])).await;
    assert_eq!(refused.1["error"], "Total search attributes size exceeds maximum (67584 > 65536 bytes)", "{}", refused.1);
    // A completion that also upserts a memo answers the memo failure reason.
    let refused = finish_task(&app, &task, json!([
        {"type":"upsert_memo","entries":envelope(Payload::Map(std::collections::BTreeMap::from([("note".into(), Payload::Long(1))])))},
        {"type":"upsert_search_attributes","attributes":many(101)}])).await;
    assert_eq!(refused.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", refused.1);
    assert_eq!(refused.1["reason"], "workflow_memo_validation_failed", "{}", refused.1);
    assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, before);
    // Exactly at the limit is accepted.
    let accepted = finish_task(&app, &task, json!([
        {"type":"upsert_search_attributes","attributes":many(100)},
        {"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
    assert_eq!(described(&app, "limit-upserts").await.as_object().unwrap().len(), 100);
    runtime.close().await;
    database.remove().await;
}
