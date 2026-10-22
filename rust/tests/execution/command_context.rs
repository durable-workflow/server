//! Command contexts and history principals, as published PHP Server records
//! them (`WorkflowCommandContextFactory`, `WorkerTerminalEventAttribution`
//! and `HistoryController::eventPrincipal`). The recorded identity comes only
//! from the server's authentication, never from the request body or headers.
use super::*;

const OPERATOR_TOKEN: &str = "dwr_context_operator_default_0123456789";
const WORKER_TOKEN: &str = "dwr_context_worker_default_0123456789ab";

/// The principal contract's forged identity headers.
const FORGED_HEADERS: [(&str, &str); 6] = [
    ("x-workflow-principal-id", "mallory"),
    ("x-workflow-principal-type", "attacker"),
    ("x-workflow-caller-label", "Mallory Gateway"),
    ("x-workflow-auth-status", "trusted_elsewhere"),
    ("x-forwarded-user", "mallory"),
    ("x-remote-user", "mallory"),
];

async fn call(
    app: &Router,
    token: Option<&str>,
    method: &str,
    path: &str,
    body: Value,
    headers: &[(&str, &str)],
) -> (StatusCode, Value) {
    let worker = path.starts_with("/api/worker/");
    let mut request = Request::builder()
        .method(method)
        .uri(path)
        .header("content-type", "application/json")
        .header("x-namespace", "default")
        .header(
            if worker { "x-durable-workflow-protocol-version" } else { "x-durable-workflow-control-plane-version" },
            if worker { "1.19" } else { "2" },
        );
    if let Some(token) = token {
        request = request.header("authorization", format!("Bearer {token}"));
    }
    for (name, value) in headers {
        request = request.header(*name, *value);
    }
    let body = if body.is_null() { Body::empty() } else { Body::from(body.to_string()) };
    let response = app.clone().oneshot(request.body(body).unwrap()).await.unwrap();
    let status = response.status();
    let bytes = to_bytes(response.into_body(), 4 * 1024 * 1024).await.unwrap();
    (status, serde_json::from_slice(&bytes).unwrap_or(Value::Null))
}

/// The start body with the contract's forged identity fields.
fn forged_start(workflow_id: &str) -> Value {
    json!({"workflow_id":workflow_id,"workflow_type":"echo","task_queue":"test",
        "input":envelope(Payload::Array(vec![Payload::Long(1)])),
        "principal":"mallory","principal_id":"mallory","principal_type":"attacker","actor":"mallory","user":"mallory"})
}

async fn history(app: &Router, token: Option<&str>, workflow_id: &str, run_id: &str) -> Vec<Value> {
    let (status, body) = call(app, token, "GET", &format!("/api/workflows/{workflow_id}/runs/{run_id}/history"), Value::Null, &[]).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["events"].as_array().unwrap().clone()
}

fn principal_of(events: &[Value], event_type: &str) -> Value {
    events
        .iter()
        .find(|event| event["event_type"] == event_type)
        .unwrap_or_else(|| panic!("no {event_type} in {events:?}"))["principal"]
        .clone()
}

#[tokio::test]
async fn commands_and_history_record_the_authenticated_principal_not_forged_identity() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone().with_auth_options(false, true));
    for (id, token, subject, roles, claims) in [
        ("operator-a", OPERATOR_TOKEN, "svc:operator", json!(["operator"]), json!({"display_name":"Ops"})),
        ("worker-a", WORKER_TOKEN, "svc:worker", json!(["worker"]), json!({})),
    ] {
        let (status, body) = call(&app, Some("test-token"), "PUT", &format!("/api/runtime-credentials/{id}"),
            json!({"token":token,"subject":subject,"roles":roles,"tenant":"default","claims":claims}), &[]).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
    }
    let (status, body) = call(&app, Some(WORKER_TOKEN), "POST", "/api/worker/register",
        json!({"worker_id":"ctx-worker","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
            "supported_activity_types":[]}), &[]).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let mut headers = FORGED_HEADERS.to_vec();
    headers.push(("x-request-id", "req-context"));
    let (status, started) = call(&app, Some(OPERATOR_TOKEN), "POST", "/api/workflows", forged_start("ctx-run"), &headers).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let (status, body) = call(&app, Some(OPERATOR_TOKEN), "POST", "/api/workflows/ctx-run/signal/go",
        json!({"input":envelope(Payload::Array(vec![])),"principal":"mallory","user":"mallory"}), &FORGED_HEADERS).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let (status, polled) = call(&app, Some(WORKER_TOKEN), "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"ctx-worker","task_queue":"test","timeout_seconds":0}), &[]).await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    let task = &polled["task"];
    let (status, body) = call(&app, Some(WORKER_TOKEN), "POST",
        &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        {
            let mut body = completion(task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(9))}]));
            for field in ["principal", "principal_id", "actor", "user"] {
                body[field] = json!("mallory");
            }
            body["principal_type"] = json!("attacker");
            body
        }, &FORGED_HEADERS).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let events = history(&app, Some("test-token"), "ctx-run", started["run_id"].as_str().unwrap()).await;
    let operator = json!({"type":"auth:runtime-token","id":"svc:operator","label":"Ops"});
    for event_type in ["StartAccepted", "WorkflowStarted", "SignalReceived"] {
        assert_eq!(principal_of(&events, event_type), operator, "{event_type}");
    }
    assert_eq!(principal_of(&events, "WorkflowCompleted"), json!({"type":"auth:runtime-token","id":"svc:worker","label":"Worker"}));
    let completed = events.iter().find(|event| event["event_type"] == "WorkflowCompleted").unwrap();
    assert_eq!((completed["payload"]["command"]["type"].clone(), completed["payload"]["command"]["source"].clone()),
        (json!("complete_workflow"), json!("worker_protocol")));
    let start = &events.iter().find(|event| event["event_type"] == "StartAccepted").unwrap()["payload"]["command"];
    assert_eq!(start["context"], json!({"principal": operator}));
    for (field, value) in [("type", "start"), ("source", "control_plane"), ("caller_label", "Standalone Server"),
        ("auth_status", "authorized"), ("auth_method", "runtime-token"), ("request_method", "POST"),
        ("request_path", "/api/workflows"), ("request_id", "req-context"), ("status", "accepted"), ("outcome", "started_new")] {
        assert_eq!(start[field], value, "{field}: {start}");
    }
    assert!(start["request_fingerprint"].as_str().is_some_and(|value| value.starts_with("sha256:") && value.len() == 71), "{start}");
    let signal = &events.iter().find(|event| event["event_type"] == "SignalReceived").unwrap()["payload"]["command"];
    assert_eq!((signal["type"].clone(), signal["target_name"].clone()), (json!("signal"), json!("go")));
    // Forged values remain ordinary request data and never become identity.
    for event in &events {
        assert!(!event["principal"].to_string().contains("mallory") && !event["payload"]["command"].to_string().contains("allory"),
            "{event}");
    }

    // The run description shows each command's actor, as PHP's does.
    let (status, described) = call(&app, Some("test-token"), "GET", "/api/workflows/ctx-run", Value::Null, &[]).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    let commands = described["commands"].as_array().unwrap();
    let start = commands.iter().find(|command| command["type"] == "start").unwrap();
    assert_eq!(start["principal"], operator, "{start}");
    assert_eq!(start["context"], json!({"caller":{"type":"server","label":"Standalone Server"},
        "auth":{"status":"authorized","method":"runtime-token","role":"operator"},"principal":operator}));
    assert_eq!((start["request_id"].clone(), start["auth_method"].clone()), (json!("req-context"), json!("runtime-token")));

    // A cancellation and a termination record the operator who sent them.
    for (workflow_id, action, event_type) in [("ctx-cancel", "cancel", "CancelRequested"), ("ctx-terminate", "terminate", "TerminateRequested")] {
        let (status, run) = call(&app, Some(OPERATOR_TOKEN), "POST", "/api/workflows", forged_start(workflow_id), &[]).await;
        assert_eq!(status, StatusCode::CREATED, "{run}");
        let (status, body) = call(&app, Some(OPERATOR_TOKEN), "POST", &format!("/api/workflows/{workflow_id}/{action}"),
            json!({"reason":"stop","principal":"mallory"}), &FORGED_HEADERS).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        let events = history(&app, Some("test-token"), workflow_id, run["run_id"].as_str().unwrap()).await;
        assert_eq!(principal_of(&events, event_type), operator, "{action}");
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn the_none_driver_records_the_anonymous_server_principal() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone().with_auth_options(true, false));
    let (status, started) = call(&app, None, "POST", "/api/workflows", forged_start("ctx-anonymous"), &FORGED_HEADERS).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let events = history(&app, None, "ctx-anonymous", started["run_id"].as_str().unwrap()).await;
    assert_eq!(principal_of(&events, "StartAccepted"), json!({"type":"server","id":"anonymous","label":"Admin"}));
    let command = &events[0]["payload"]["command"];
    assert_eq!((command["auth_status"].clone(), command["auth_method"].clone()), (json!("not_configured"), json!("none")));
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn query_tasks_and_answers_carry_the_requester_principal() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone().with_auth_options(false, true));
    for (id, token, subject, roles) in [
        ("operator-q", OPERATOR_TOKEN, "svc:operator", json!(["operator"])),
        ("worker-q", WORKER_TOKEN, "svc:worker", json!(["worker"])),
    ] {
        let (status, body) = call(&app, Some("test-token"), "PUT", &format!("/api/runtime-credentials/{id}"),
            json!({"token":token,"subject":subject,"roles":roles,"tenant":"default"}), &[]).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
    }
    let (status, body) = call(&app, Some(WORKER_TOKEN), "POST", "/api/worker/register", json!({
        "worker_id":"ctx-query-worker","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"capabilities":["query_tasks"],"workflow_command_contracts":{"echo":{
        "queries":["state"],"query_contracts":[{"name":"state","parameters":[]}]}}}), &[]).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let (status, started) = call(&app, Some(OPERATOR_TOKEN), "POST", "/api/workflows", forged_start("ctx-query"), &[]).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let path = format!("/api/workflows/ctx-query/runs/{}/query/state", started["run_id"].as_str().unwrap());
    let asked = {
        let app = app.clone();
        tokio::spawn(async move {
            call(&app, Some(OPERATOR_TOKEN), "POST", &path,
                json!({"input":envelope(Payload::Array(vec![])),"principal":"mallory"}), &FORGED_HEADERS).await
        })
    };
    let claim = tokio::time::timeout(std::time::Duration::from_secs(5), async {
        loop {
            let (status, body) = call(&app, Some(WORKER_TOKEN), "POST", "/api/worker/query-tasks/poll",
                json!({"worker_id":"ctx-query-worker","task_queue":"test","timeout_seconds":0}), &[]).await;
            assert_eq!(status, StatusCode::OK, "{body}");
            if !body["task"].is_null() {
                break body["task"].clone();
            }
            tokio::time::sleep(std::time::Duration::from_millis(10)).await;
        }
    })
    .await
    .expect("query task");
    let operator = json!({"type":"auth:runtime-token","id":"svc:operator","label":"Operator"});
    assert_eq!(claim["principal"], operator, "{claim}");
    assert_eq!(claim["command_context"]["source"], "control_plane");
    assert_eq!(claim["command_context"]["context"]["server"]["command"], "query");
    assert_eq!(claim["command_context"]["context"]["server"]["metadata"], json!({"query_name":"state"}));
    let (status, body) = call(&app, Some(WORKER_TOKEN), "POST",
        &format!("/api/worker/query-tasks/{}/complete", claim["query_task_id"].as_str().unwrap()),
        json!({"lease_owner":"ctx-query-worker","query_task_attempt":claim["query_task_attempt"],
            "result_envelope":envelope(Payload::Long(3))}), &[]).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, answer) = asked.await.unwrap();
    assert_eq!(status, StatusCode::OK, "{answer}");
    // The audit principal is the authenticated operator, apart from any
    // application value the query returns.
    assert_eq!(answer["principal"], operator, "{answer}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn cluster_info_publishes_php_s_principal_attribution_contract() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, cluster) = call(&app, Some("test-token"), "GET", "/api/cluster/info", Value::Null, &[]).await;
    assert_eq!(status, StatusCode::OK, "{cluster}");
    let contract = &cluster["principal_attribution_contract"];
    assert_eq!((contract["schema"].clone(), contract["version"].clone()),
        (json!("durable-workflow.v2.principal-attribution.contract"), json!(2)));
    assert_eq!(contract["principal_shape"]["anonymous_value"], json!({"type":"server","id":"anonymous"}));
    // The forged values a gateway injects, as published PHP lists them.
    let guards = &contract["spoofing_guards"];
    assert_eq!(guards["request_body_field_values"], json!({"principal":"mallory","principal_id":"mallory",
        "principal_type":"attacker","actor":"mallory","user":"mallory"}));
    assert_eq!(guards["request_header_values"]["X-Workflow-Principal-Id"], "mallory");
    assert_eq!(guards["request_headers"].as_array().unwrap().len(), 11);
    runtime.close().await;
    database.remove().await;
}
