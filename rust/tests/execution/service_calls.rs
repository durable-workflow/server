use super::*;

// Nexus service-call execution, show and cancel for the handler kinds that
// stay accepted (`activity_execution`, `invocable_http`), with the boundary's
// refusals, retries, deadlines and idempotent replays. The shared
// `service-calls` fixture records the same requests against published 2.11.1.

const OPERATIONS: &str = "/api/service-endpoints/pay/services/billing/operations";

async fn catalog(app: &Router, operations: &[(&str, Value)]) {
    let (status, body) = request(app, "POST", "/api/service-endpoints", json!({"endpoint_name": "pay"})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let (status, body) = request(app, "POST", "/api/service-endpoints/pay/services", json!({"service_name": "billing"})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    for (name, definition) in operations {
        let mut definition = definition.clone();
        definition["operation_name"] = json!(name);
        if definition.get("operation_mode").is_none() {
            definition["operation_mode"] = json!("async");
        }
        let (status, body) = request(app, "POST", OPERATIONS, definition).await;
        assert_eq!(status, StatusCode::CREATED, "{name}: {body}");
    }
}

async fn execute(app: &Router, operation: &str, body: Value) -> (StatusCode, Value) {
    request(app, "POST", &format!("{OPERATIONS}/{operation}/execute"), body).await
}

fn activity(target: &str) -> Value {
    json!({"handler_binding_kind": "activity_execution", "handler_target_reference": target})
}

fn is_upper_ulid(value: &Value) -> bool {
    value.as_str().is_some_and(|id| id.len() == 26 && id.bytes().all(|byte| byte.is_ascii_digit() || byte.is_ascii_uppercase()))
}

#[tokio::test]
async fn executed_calls_stay_accepted_with_their_handler_stamped() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    catalog(&app, &[
        ("charge", activity("charge-activity")),
        ("charge-bound", json!({"handler_binding_kind": "activity_execution", "handler_binding": {"activity_class": "C",
            "activity_type": "t", "activity_execution_id": "act-fixed-1", "owning_workflow_instance_id": "wf-own-1", "queue": "bq"}})),
        ("notify", json!({"handler_binding_kind": "invocable_http", "handler_target_reference": "https://carrier.example/notify"})),
        ("timed", json!({"handler_binding_kind": "activity_execution", "handler_target_reference": "t",
            "deadline_policy": {"timeout_seconds": 3600}})),
    ]).await;

    let (status, call) = execute(&app, "charge", json!({"connection": "cx", "queue": "qx", "caller_namespace": "Partner-NS",
        "arguments": {"codec": "avro", "blob": "wwHioz3/VYAiNwwA"}})).await;
    assert_eq!(status, StatusCode::OK, "{call}");
    assert_eq!((call["status"].clone(), call["outcome"].clone(), call["accepted"].clone(), call["idempotent_replay"].clone(),
        call["reason"].clone(), call["payload_codec"].clone()),
        (json!("accepted"), json!("accepted"), json!(true), json!(false), Value::Null, Value::Null));
    assert_eq!(call["id"], call["service_call_id"]);
    assert!(is_upper_ulid(&call["resolved_target_reference"]), "{call}");
    assert_eq!(call["handler"], json!({"accepted": true, "kind": "activity_execution",
        "activity_execution_id": call["resolved_target_reference"], "activity_class": "charge-activity", "activity_type": "charge-activity"}));
    assert_eq!((call["metadata"]["boundary_key"].clone(), call["metadata"]["connection"].clone(), call["metadata"]["queue"].clone()),
        (json!("Partner-NS|default|pay|billing|charge"), json!("cx"), json!("qx")));
    assert_eq!((call["caller_principal_subject"].clone(), call["caller_principal_roles"].clone(), call["caller_principal_claims"].clone()),
        (json!("legacy-token"), json!(["admin"]), json!({"legacy_credential": true})));
    assert_eq!(call["policy_name"], "server.shared-service-boundary");
    let attempts = call["service_call_attempts"].as_array().unwrap();
    assert_eq!((attempts.len(), call["retry_attempt_count"].clone(), attempts[0]["retry_scheduled"].clone()), (1, json!(1), json!(false)));
    assert!(call["accepted_at"].as_str().unwrap().ends_with('Z') && call["created_at"].as_str().unwrap().ends_with("+00:00"), "{call}");

    let (status, bound) = execute(&app, "charge-bound", json!({})).await;
    assert_eq!((status, bound["resolved_target_reference"].clone(), bound["linked_workflow_instance_id"].clone(),
        bound["metadata"]["queue"].clone()), (StatusCode::OK, json!("act-fixed-1"), json!("wf-own-1"), json!("bq")));

    let (status, notify) = execute(&app, "notify", json!({"mode_override": "sync"})).await;
    assert_eq!((status, notify["resolved_binding_kind"].clone(), notify["operation_mode"].clone()),
        (StatusCode::OK, json!("invocable_carrier_request"), json!("sync")));
    assert_eq!((notify["handler"]["carrier_endpoint"].clone(), notify["handler"]["carrier_handler"].clone()),
        (json!("https://carrier.example/notify"), Value::Null));

    let (status, timed) = execute(&app, "timed", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{timed}");
    let accepted = chrono::DateTime::parse_from_rfc3339(timed["accepted_at"].as_str().unwrap()).unwrap();
    let deadline = chrono::DateTime::parse_from_rfc3339(timed["call_deadline_at"].as_str().unwrap()).unwrap();
    assert_eq!(deadline - accepted, chrono::Duration::seconds(3600), "{timed}");

    // Show reads the call by id, trimmed, under its own operation only.
    let id = call["id"].as_str().unwrap();
    let (status, shown) = request(&app, "GET", &format!("{OPERATIONS}/charge/service-calls/%20{id}%20"), Value::Null).await;
    assert_eq!((status, shown["found"].clone(), shown["service_call_id"].clone(), shown["status"].clone()),
        (StatusCode::OK, json!(true), json!(id), json!("accepted")));
    let (status, missing) = request(&app, "GET", &format!("{OPERATIONS}/notify/service-calls/{id}"), Value::Null).await;
    assert_eq!((status, missing), (StatusCode::NOT_FOUND, json!({
        "message": format!("Service call [{id}] not found under operation [notify] for service [billing] at endpoint [pay] in namespace [default]."),
        "reason": "service_call_not_found", "namespace": "default", "endpoint_name": "pay", "service_name": "billing",
        "operation_name": "notify", "service_call_id": id})));
}

#[tokio::test]
async fn handler_failures_retry_and_deadlines_expire() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    catalog(&app, &[
        ("broken", json!({"handler_binding_kind": "activity_execution", "handler_binding": {"queue": "q"}})),
        ("broken-retry", json!({"handler_binding_kind": "activity_execution", "handler_binding": {"queue": "q"},
            "retry_policy": {"max_attempts": 3}})),
        ("broken-noretry", json!({"handler_binding_kind": "activity_execution", "handler_binding": {"queue": "q"},
            "retry_policy": {"max_attempts": 3, "non_retryable_error_types": ["handler_target_missing"]}})),
        ("notify-broken", json!({"handler_binding_kind": "invocable_http", "handler_binding": {"carrier": "c"}})),
        ("expired", json!({"handler_binding_kind": "activity_execution", "handler_target_reference": "t",
            "deadline_policy": {"deadline_at": "2001-02-03T04:05:06.5+01:00"}})),
    ]).await;

    let (status, broken) = execute(&app, "broken", json!({})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{broken}");
    assert_eq!((broken["status"].clone(), broken["outcome"].clone(), broken["outcome_category"].clone(), broken["reason"].clone(),
        broken["message"].clone(), broken["error_type"].clone()),
        (json!("failed"), json!("handler_failed"), json!("handler"), json!("handler_target_missing"),
            json!("Activity binding has no activity class or type."), json!("handler_target_missing")));
    assert_eq!(broken["outcome_metadata"]["failure_reason"], "handler_failure");
    assert_eq!(broken["handler"]["retry_scheduled"], false);
    assert_eq!(broken["service_call_attempts"].as_array().unwrap().len(), 1);

    let (status, retried) = execute(&app, "broken-retry", json!({})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{retried}");
    let attempts = retried["service_call_attempts"].as_array().unwrap();
    assert_eq!(attempts.iter().map(|attempt| attempt["retry_scheduled"].clone()).collect::<Vec<_>>(),
        [json!(true), json!(true), json!(false)], "{retried}");
    assert_eq!((attempts[0]["status"].clone(), attempts[0]["scheduled_backoff_seconds"].clone(), attempts[2]["status"].clone()),
        (json!("started"), json!(0), json!("failed")));
    assert!(retried["started_at"].is_string() && retried["metadata"]["last_retry_failure"].is_object(), "{retried}");

    let (_, noretry) = execute(&app, "broken-noretry", json!({})).await;
    assert_eq!(noretry["service_call_attempts"].as_array().unwrap().len(), 1, "{noretry}");
    let (status, carrier) = execute(&app, "notify-broken", json!({})).await;
    assert_eq!((status, carrier["handler"]["kind"].clone(), carrier["message"].clone()), (StatusCode::CONFLICT,
        json!("invocable_carrier_request"), json!("Invocable carrier binding has no endpoint or carrier reference.")));

    // A deadline already past is admitted and timed out in the same request.
    let (status, expired) = execute(&app, "expired", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{expired}");
    assert_eq!((expired["accepted"].clone(), expired["status"].clone(), expired["outcome"].clone(), expired["reason"].clone(),
        expired["call_deadline_at"].clone(), expired["message"].clone(), expired.get("handler").is_some()),
        (json!(true), json!("failed"), json!("timed_out"), json!("timed_out"), json!("2001-02-03T03:05:06.500000Z"),
            json!("Service call deadline expired."), false));
    let id = expired["id"].as_str().unwrap();
    let (status, cancelled) = request(&app, "POST", &format!("{OPERATIONS}/expired/service-calls/{id}/cancel"), json!({})).await;
    assert_eq!((status, cancelled["reason"].clone()), (StatusCode::CONFLICT, json!("service_call_terminal")));
}

#[tokio::test]
async fn the_boundary_refuses_and_records_every_decision() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let with_policy = |policy: Value| {
        let mut definition = activity("a");
        definition["boundary_policy"] = policy;
        definition
    };
    catalog(&app, &[
        ("need-role", with_policy(json!({"required_roles": ["nexus-caller"]}))),
        ("deny-caller", with_policy(json!({"deny_callers": ["blocked"]}))),
        ("allow-list", with_policy(json!({"allow_callers": ["partner"]}))),
        ("circuit", with_policy(json!({"circuit_break": {"open": true}}))),
        ("misconfigured", with_policy(json!({"rate_limit": {"requests_per_minute": "lots"}}))),
        ("limited", with_policy(json!({"concurrency_limit": {"max_in_flight": 1, "sync_only": false}}))),
        ("start", json!({"handler_binding_kind": "start_workflow", "handler_target_reference": "wf"})),
    ]).await;

    let (status, forbidden) = execute(&app, "need-role", json!({})).await;
    assert_eq!(status, StatusCode::FORBIDDEN, "{forbidden}");
    assert_eq!((forbidden["reason"].clone(), forbidden["message"].clone(), forbidden["outcome_metadata"].clone(),
        forbidden.get("endpoint_id").is_some()), (json!("missing_required_role"),
        json!("Nexus operation is not permitted for this caller."), json!({"failure_reason": "policy_rejection"}), false));
    // The audit row keeps what the response redacts.
    let id = forbidden["service_call_id"].as_str().unwrap();
    let (status, shown) = request(&app, "GET", &format!("{OPERATIONS}/need-role/service-calls/{id}"), Value::Null).await;
    assert_eq!((status, shown["outcome_message"].clone(), shown["outcome_metadata"]["forbidden_axis"].clone(), shown["status"].clone()),
        (StatusCode::OK, json!("Caller [legacy-token] is missing one of the required roles for service-boundary admission."),
            json!("operation"), json!("failed")));

    let (status, denied) = execute(&app, "deny-caller", json!({"caller_namespace": "blocked"})).await;
    assert_eq!((status, denied["reason"].clone()), (StatusCode::FORBIDDEN, json!("caller_namespace_denied")));
    let (status, listed) = execute(&app, "allow-list", json!({})).await;
    assert_eq!((status, listed["reason"].clone()), (StatusCode::FORBIDDEN, json!("caller_namespace_not_allowed")));
    let (status, open) = execute(&app, "circuit", json!({})).await;
    assert_eq!(status, StatusCode::SERVICE_UNAVAILABLE, "{open}");
    assert_eq!((open["reason"].clone(), open["retry_after_seconds"].clone(), open["outcome_metadata"]["target_key"].clone(),
        open["endpoint_name"].clone()), (json!("circuit_open"), json!(30), json!("default|pay|billing|circuit"), json!("pay")));
    let (status, unavailable) = execute(&app, "misconfigured", json!({})).await;
    assert_eq!((status, unavailable["outcome"].clone(), unavailable["reason"].clone(), unavailable["retry_after_seconds"].clone()),
        (StatusCode::SERVICE_UNAVAILABLE, json!("rejected_throttled"), json!("service_boundary_admission_unavailable"), json!(1)));

    // An async call keeps its concurrency lease, so the next one is refused.
    let (status, admitted) = execute(&app, "limited", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{admitted}");
    let (status, limited) = execute(&app, "limited", json!({})).await;
    assert_eq!(status, StatusCode::TOO_MANY_REQUESTS, "{limited}");
    assert_eq!((limited["outcome"].clone(), limited["reason"].clone(), limited["retry_after_seconds"].clone(),
        limited["outcome_metadata"].clone()), (json!("rejected_concurrency_limited"), json!("concurrency_limit_exceeded"), json!(1),
        json!({"failure_reason": "policy_rejection", "admission_scope": "boundary", "observed_in_flight": 1, "max_in_flight": 1})));

    // A later slice: workflow-bound handlers.
    let (status, start) = execute(&app, "start", json!({})).await;
    assert_eq!((status, start["reason"].clone()), (StatusCode::NOT_IMPLEMENTED, json!("rust_capability_not_implemented")));
    let rows = text_rows(&database, "SELECT id, status FROM workflow_service_calls ORDER BY id",
        "SELECT id, status FROM workflow_service_calls ORDER BY id", "SELECT id, status FROM workflow_service_calls ORDER BY id").await;
    assert_eq!(rows.len(), 7, "one audit row per decision, none for a 501: {rows:?}");
}

#[tokio::test]
async fn shared_admission_limits_count_per_boundary() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let with_policy = |mode: &str, policy: Value| {
        let mut definition = activity("a");
        definition["operation_mode"] = json!(mode);
        definition["boundary_policy"] = policy;
        definition
    };
    catalog(&app, &[
        ("rated", with_policy("async", json!({"rate_limit": {"requests_per_minute": 2, "retry_after_seconds": "7"}}))),
        ("held-sync", with_policy("sync", json!({"concurrency_limit": {"max_in_flight": 1}}))),
    ]).await;

    // The rate window is one minute; start early enough in one to stay in it.
    let second = chrono::Timelike::second(&chrono::Utc::now());
    if second >= 50 {
        tokio::time::sleep(std::time::Duration::from_secs(u64::from(61 - second))).await;
    }
    let (status, first) = execute(&app, "rated", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{first}");
    assert_eq!(first["outcome_metadata"], json!({"boundary_key": "default|default|pay|billing|rated", "admission_scope": "shared_cache",
        "boundary_rate_limit": 2, "namespace_rate_limit": null, "boundary_concurrency_limit": null, "namespace_concurrency_limit": null}));
    let (status, second_call) = execute(&app, "rated", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{second_call}");
    let (status, throttled) = execute(&app, "rated", json!({})).await;
    assert_eq!(status, StatusCode::TOO_MANY_REQUESTS, "{throttled}");
    assert_eq!((throttled["outcome"].clone(), throttled["reason"].clone(), throttled["message"].clone(),
        throttled["retry_after_seconds"].clone(), throttled["policy_name"].clone(), throttled["outcome_metadata"].clone()),
        (json!("rejected_throttled"), json!("rate_limit_exceeded"), json!("Shared service-call rate capacity is exhausted."), json!(7),
            json!("server.shared-service-boundary"),
            json!({"failure_reason": "policy_rejection", "admission_scope": "boundary", "observed_window_count": 2, "requests_per_minute": 2})));
    // Another caller namespace is another boundary with its own window.
    let (status, other) = execute(&app, "rated", json!({"caller_namespace": "other"})).await;
    assert_eq!(status, StatusCode::OK, "{other}");

    // A sync call returns its lease when the request ends.
    for _ in 0..3 {
        let (status, call) = execute(&app, "held-sync", json!({})).await;
        assert_eq!(status, StatusCode::OK, "{call}");
        assert_eq!(call["outcome_metadata"]["boundary_concurrency_limit"], json!(1), "{call}");
    }
    let leases = text_rows(&database, "SELECT cache_key, cache_key FROM dw_service_admission_counters WHERE cache_key LIKE '%concurrency%'",
        "SELECT cache_key, cache_key FROM dw_service_admission_counters WHERE cache_key LIKE '%concurrency%'",
        "SELECT cache_key, cache_key FROM dw_service_admission_counters WHERE cache_key LIKE '%concurrency%'").await;
    assert!(leases.is_empty(), "a returned lease leaves no counter: {leases:?}");
}

#[tokio::test]
async fn cancellation_and_idempotent_replays_follow_the_control_plane() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let mut nocancel = activity("a");
    nocancel["cancellation_policy"] = json!({"mode": "none"});
    catalog(&app, &[("charge", activity("a")), ("nocancel", nocancel)]).await;

    let (_, call) = execute(&app, "charge", json!({"idempotency_key": "k1"})).await;
    let id = call["id"].as_str().unwrap().to_owned();
    let (status, replay) = execute(&app, "charge", json!({"idempotency_key": " k1 ", "arguments": "wwHioz3/VYAiNwoIZG9uZQ=="})).await;
    assert_eq!(status, StatusCode::OK, "{replay}");
    assert_eq!((replay["accepted"].clone(), replay["idempotent_replay"].clone(), replay["reason"].clone(), replay["found"].clone(),
        replay["service_call_id"].clone(), replay.get("id").is_some()),
        (json!(true), json!(true), Value::Null, json!(true), json!(id), false));
    // The key belongs to the operation (Workflow #931): the catalog names in
    // any case replay the call, and anyone but its owner is refused without
    // a new call, an audit row or a change to the first call.
    let (status, upper) = request(&app, "POST", &format!("{}/CHARGE/execute", OPERATIONS.replace("pay", "PAY").replace("billing", "BILLING")),
        json!({"idempotency_key": "k1"})).await;
    assert_eq!((status, upper["service_call_id"].clone(), upper["idempotent_replay"].clone()), (StatusCode::OK, json!(id), json!(true)), "{upper}");
    let (_, before) = request(&app, "GET", &format!("{OPERATIONS}/charge/service-calls/{id}"), Value::Null).await;
    const CALLS: &str = "SELECT id, id FROM workflow_service_calls";
    let count = text_rows(&database, CALLS, CALLS, CALLS).await.len();
    for foreign in [json!({"caller_namespace": "other"}), json!({"caller_workflow_instance_id": "caller-wf"}),
        json!({"caller_workflow_instance_id": "caller-wf", "caller_workflow_run_id": "01hcallerrun00000000000000"})] {
        let mut body = foreign.clone();
        body["idempotency_key"] = json!("k1");
        let (status, refused) = execute(&app, "charge", body).await;
        assert_eq!((status, refused), (StatusCode::FORBIDDEN, json!({"reason": "service_operation_forbidden",
            "message": "The service operation is not authorized for this caller."})), "{foreign}");
    }
    assert_eq!(text_rows(&database, CALLS, CALLS, CALLS).await.len(), count, "a refused replay writes no call or audit row");
    let (_, after) = request(&app, "GET", &format!("{OPERATIONS}/charge/service-calls/{id}"), Value::Null).await;
    assert_eq!(after, before, "a refused replay leaves the first call as it was");

    let cancel = format!("{OPERATIONS}/charge/service-calls/{id}/cancel");
    let (status, cancelled) = request(&app, "POST", &cancel, json!({"reason": "operator stop"})).await;
    assert_eq!(status, StatusCode::OK, "{cancelled}");
    assert_eq!((cancelled["status"].clone(), cancelled["outcome_category"].clone(), cancelled["outcome_reason"].clone(),
        cancelled["outcome_message"].clone(), cancelled["failure_message"].clone(), cancelled["outcome_metadata"].clone(),
        cancelled["accepted"].clone(), cancelled["reason"].clone()),
        (json!("cancelled"), json!("handler"), json!("cancelled_by_request"), json!("operator stop"), json!("operator stop"),
            json!({"failure_reason": "cancellation"}), json!(true), Value::Null));
    let (status, again) = request(&app, "POST", &cancel, json!({})).await;
    assert_eq!((status, again["accepted"].clone(), again["reason"].clone()), (StatusCode::CONFLICT, json!(false), json!("service_call_terminal")));
    let (status, refused) = request(&app, "POST", &cancel, json!({"reason": "x".repeat(1001)})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
    let (status, unknown) = request(&app, "POST", &format!("{OPERATIONS}/charge/service-calls/nope/cancel"),
        json!({"reason": "x".repeat(1001)})).await;
    assert_eq!((status, unknown["reason"].clone()), (StatusCode::NOT_FOUND, json!("service_call_not_found")), "the call before the input");
    let (status, replayed) = execute(&app, "charge", json!({"idempotency_key": "k1"})).await;
    assert_eq!((status, replayed["accepted"].clone(), replayed["status"].clone()), (StatusCode::CONFLICT, json!(false), json!("cancelled")));

    let (_, kept) = execute(&app, "nocancel", json!({})).await;
    let (status, not_allowed) = request(&app, "POST", &format!("{OPERATIONS}/nocancel/service-calls/{}/cancel", kept["id"].as_str().unwrap()),
        json!({})).await;
    assert_eq!((status, not_allowed["reason"].clone(), not_allowed["status"].clone()),
        (StatusCode::CONFLICT, json!("cancellation_not_allowed"), json!("accepted")));
}

#[tokio::test]
async fn execute_requests_are_checked_like_the_controller() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    catalog(&app, &[("charge", activity("a"))]).await;
    for (body, field, message) in [
        (json!({"payload_codec": "json"}), "payload_codec", "unsupported_payload_codec"),
        (json!({"arguments": "not-avro"}), "arguments", "The payload must contain one complete Avro Value"),
        (json!({"arguments": {"codec": "avro", "blob": "%%%"}}), "arguments.blob", "The payload must contain one complete Avro Value"),
        (json!({"arguments": {"codec": "avro"}}), "arguments", "The arguments envelope must contain only codec and blob"),
        (json!({"arguments": {"codec": "avro", "blob": 5}}), "arguments.blob", "The payload envelope blob must be a string."),
        (json!({"search_attributes": {"1bad": "x"}}), "search_attributes", "Search attribute key [1bad] must start with a letter"),
        (json!({"mode_override": "fast"}), "mode_override", "The selected mode override is invalid."),
        (json!({"wait_timeout_seconds": 61}), "wait_timeout_seconds", "The wait timeout seconds field must not be greater than 60."),
    ] {
        let (status, refused) = execute(&app, "charge", body.clone()).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}: {refused}");
        assert!(refused["errors"][field][0].as_str().unwrap().starts_with(message), "{body}: {refused}");
    }
    // The runtime transport refuses a provider reference before routing.
    let (status, transport) = request(&app, "POST", &format!("{OPERATIONS}/nope/execute"),
        json!({"arguments": {"codec": "avro", "external_storage": {}}})).await;
    assert_eq!((status, transport["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("external_payload_unsupported")));
    let (status, missing) = execute(&app, "NOPE", json!({"mode_override": "fast"})).await;
    assert_eq!((status, missing["reason"].clone(), missing["operation_name"].clone()),
        (StatusCode::NOT_FOUND, json!("operation_not_found"), json!("nope")), "the catalog before the input");
}

#[tokio::test]
async fn catalog_reads_list_the_recorded_calls() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let mut guarded = activity("a");
    guarded["boundary_policy"] = json!({"required_roles": ["nexus-caller"]});
    catalog(&app, &[("charge", activity("a")), ("guarded", guarded)]).await;
    let (_, first) = execute(&app, "charge", json!({})).await;
    let (_, second) = execute(&app, "charge", json!({"caller_namespace": "partner", "caller_workflow_instance_id": "wf-caller"})).await;
    let (status, refused) = execute(&app, "guarded", json!({})).await;
    assert_eq!(status, StatusCode::FORBIDDEN, "{refused}");
    let operation = first["operation_id"].as_str().unwrap();

    // Newest accepted first; the list item is `ServiceCallView::listItem`.
    let (status, page) = request(&app, "GET", &format!("/api/service-catalog/calls?operation_id={operation}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{page}");
    let ids = |page: &Value| page["data"].as_array().unwrap().iter().map(|item| item["id"].clone()).collect::<Vec<_>>();
    assert_eq!((page["total"].clone(), ids(&page)), (json!(2), vec![second["id"].clone(), first["id"].clone()]));
    let item = &page["data"][1];
    assert_eq!((item["status_bucket"].clone(), item["outcome_bucket"].clone(), item["is_terminal"].clone(),
        item["is_policy_outcome"].clone(), item["caller_principal_roles"].clone(), item["retry_attempt_count"].clone()),
        (json!("open"), json!("open"), json!(false), json!(false), json!(["admin"]), json!(1)));
    assert!(item["created_at"].as_str().unwrap().ends_with('Z') && item["created_at"].as_str().unwrap().contains('.'), "{item}");
    assert!(item.get("metadata").is_none(), "the detail fields stay out of a list item: {item}");

    // Buckets and scopes.
    let (_, policy) = request(&app, "GET", "/api/service-catalog/calls?outcome_bucket=policy", Value::Null).await;
    assert_eq!((policy["total"].clone(), ids(&policy)), (json!(1), vec![refused["service_call_id"].clone()]));
    assert_eq!((policy["data"][0]["is_policy_outcome"].clone(), policy["data"][0]["accepted_at"].clone(), policy["outcome_bucket"].clone()),
        (json!(true), Value::Null, json!("policy")));
    let (_, caller) = request(&app, "GET", &format!("/api/service-catalog/calls?scope=caller&operation_id={operation}"), Value::Null).await;
    assert_eq!(ids(&caller), vec![first["id"].clone()], "another caller's call is not this namespace's as caller");
    let (_, paged) = request(&app, "GET", "/api/service-catalog/calls?per_page=1&page=2&status=accepted", Value::Null).await;
    assert_eq!((paged["total"].clone(), paged["last_page"].clone(), ids(&paged)), (json!(2), json!(2), vec![first["id"].clone()]));

    // A call by identity: the detail, with the caller link and the payload view.
    let (status, shown) = request(&app, "GET", &format!("/api/service-catalog/calls/{}", second["id"].as_str().unwrap()), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{shown}");
    assert_eq!((shown["caller_link"].clone(), shown["payloads_in_observer_namespace"].clone(), shown["result_envelope"].clone(),
        shown["failure_type"].clone(), shown["linked_run_ref"].clone(), shown["deadline_policy"].clone()),
        (json!({"namespace": "partner", "workflow_instance_id": "wf-caller", "workflow_run_id": null, "in_observer_namespace": false}),
            json!(true), Value::Null, Value::Null, Value::Null, json!([])));
    let (status, refused_shown) = request(&app, "GET", &format!("/api/service-catalog/calls/{}", refused["service_call_id"].as_str().unwrap()),
        Value::Null).await;
    assert_eq!((status, refused_shown["outcome"].clone()), (StatusCode::OK, json!("rejected_forbidden")));
    let (status, missing) = request(&app, "GET", "/api/service-catalog/calls/01nosuchcall00000000000000", Value::Null).await;
    assert_eq!((status, missing["reason"].clone()), (StatusCode::NOT_FOUND, json!("catalog_object_not_found")));
}

#[tokio::test]
async fn the_repair_pass_times_out_calls_past_their_deadline() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let mut timed = activity("a");
    timed["deadline_policy"] = json!({"timeout_seconds": 1});
    catalog(&app, &[("timed", timed), ("charge", activity("a"))]).await;
    let (_, due) = execute(&app, "timed", json!({})).await;
    let (_, open) = execute(&app, "charge", json!({})).await;
    tokio::time::sleep(std::time::Duration::from_millis(1200)).await;

    // The sweep expires the call without anyone reading it first.
    let (status, report) = request(&app, "POST", "/api/system/repair/pass", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{report}");
    assert_eq!((report["service_call_timeout_candidates"].clone(), report["service_call_timeouts_enforced"].clone(),
        report["service_call_timeout_failures"].clone()), (json!(1), json!(1), json!([])), "{report}");
    let (_, page) = request(&app, "GET", &format!("/api/service-catalog/calls?operation_id={}", due["operation_id"].as_str().unwrap()),
        Value::Null).await;
    assert_eq!((page["data"][0]["status"].clone(), page["data"][0]["outcome"].clone()), (json!("failed"), json!("timed_out")), "{page}");
    let (_, other) = request(&app, "GET", &format!("/api/service-catalog/calls/{}", open["id"].as_str().unwrap()), Value::Null).await;
    assert_eq!(other["status"], "accepted", "a call without a deadline stays open");

    // A second pass finds nothing left to time out.
    let (_, again) = request(&app, "POST", "/api/system/repair/pass", json!({})).await;
    assert_eq!((again["service_call_timeout_candidates"].clone(), again["service_call_timeouts_enforced"].clone()), (json!(0), json!(0)));
}

/// Core's continue-as-new touches no service call (continue-transfers
/// fixture): a run that is the caller of an accepted call continues as new,
/// and the call keeps the predecessor as its caller and stays accepted.
#[tokio::test]
async fn a_caller_run_continues_as_new_and_its_call_stays_with_the_predecessor() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    catalog(&app, &[("charge", activity("charge-activity"))]).await;
    register(&app, "caller-worker", json!(["echo"]), json!([])).await;
    let started = start(&app, "service-caller").await;
    let task = poll(&app, "caller-worker", "workflow").await;
    let (status, call) = execute(&app, "charge", json!({"arguments": {"codec": "avro", "blob": "wwHioz3/VYAiNwwA"},
        "caller_workflow_instance_id": "service-caller", "caller_workflow_run_id": started["run_id"]})).await;
    assert_eq!(status, StatusCode::OK, "{call}");
    let id = call["service_call_id"].as_str().unwrap().to_owned();
    let path = format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap());
    let (status, continued) = request(&app, "POST", &path, completion(&task, json!([{"type":"continue_as_new"}]))).await;
    assert_eq!(status, StatusCode::OK, "{continued}");
    assert_eq!(continued["outcome"], "completed");
    let runs = request(&app, "GET", "/api/workflows/service-caller/runs", Value::Null).await.1;
    assert_eq!(runs["runs"].as_array().unwrap().len(), 2);
    assert_eq!(runs["runs"][1]["is_current_run"], true);
    let (status, shown) = request(&app, "GET", &format!("{OPERATIONS}/charge/service-calls/{id}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{shown}");
    assert_eq!((shown["status"].clone(), shown["outcome"].clone(), shown["caller_workflow_run_id"].clone()),
        (json!("accepted"), json!("accepted"), started["run_id"].clone()));
    let successor = poll(&app, "caller-worker", "workflow").await;
    assert_eq!(successor["run_id"], runs["runs"][1]["run_id"]);
    let (status, terminated) = request(&app, "POST", "/api/workflows/service-caller/terminate",
        json!({"reason": "continue transfers"})).await;
    assert_eq!(status, StatusCode::OK, "{terminated}");
    runtime.close().await;
    database.remove().await;
}

// A call linked to a run through an `activity_execution` binding stays with
// the predecessor when that run continues as new: Core moves only open calls
// with the `workflow_run` binding (`LinkedServiceCallCloser`).
#[tokio::test]
async fn a_call_linked_through_an_activity_binding_stays_with_the_predecessor_run() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    catalog(&app, &[]).await;
    register(&app, "linked-worker", json!(["echo"]), json!([])).await;
    let started = start(&app, "service-linked").await;
    let run = started["run_id"].clone();
    let (status, body) = request(&app, "POST", OPERATIONS, json!({"operation_name": "charge-linked", "operation_mode": "async",
        "handler_binding_kind": "activity_execution", "handler_binding": {"activity_type": "t",
            "owning_workflow_instance_id": "service-linked", "owning_workflow_run_id": run}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let (status, call) = execute(&app, "charge-linked", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{call}");
    assert_eq!((call["resolved_binding_kind"].clone(), call["linked_workflow_run_id"].clone()),
        (json!("activity_execution"), run.clone()), "{call}");
    let id = call["service_call_id"].as_str().unwrap().to_owned();

    let task = poll(&app, "linked-worker", "workflow").await;
    let path = format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap());
    let (status, continued) = request(&app, "POST", &path, completion(&task, json!([{"type":"continue_as_new"}]))).await;
    assert_eq!(status, StatusCode::OK, "{continued}");
    let (status, shown) = request(&app, "GET", &format!("{OPERATIONS}/charge-linked/service-calls/{id}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{shown}");
    assert_eq!((shown["status"].clone(), shown["linked_workflow_run_id"].clone(), shown["resolved_target_reference"].clone()),
        (json!("accepted"), run, call["resolved_target_reference"].clone()), "{shown}");
    runtime.close().await;
    database.remove().await;
}
