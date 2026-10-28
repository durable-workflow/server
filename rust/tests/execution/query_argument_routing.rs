use super::*;

// Published Server 2.11.5 (`WorkflowQueryTaskBroker::query`,
// `validateContractedQueryArguments`) decodes a query's arguments only to
// check them against a declared query contract. A run that declares no
// queries routes the query with its arguments as given, so the worker
// receives even a blob that does not decode. A run that declares the query
// refuses such arguments before routing. The query-arguments fixture
// records the same cases.

const UNDECODABLE: &str = "AAAA";

/// The avro encoding of a single string, which is not a list.
fn not_a_list() -> Value {
    envelope(Payload::String("not a list".into()))
}

async fn uncontracted_worker(app: &Router, worker: &str) {
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":["query_tasks"]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

#[tokio::test]
async fn an_uncontracted_run_routes_arguments_it_cannot_decode_to_the_worker() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    uncontracted_worker(&app, "plain-worker").await;
    start(&app, "plain-query").await;
    for input in [json!({"codec":"avro","blob":UNDECODABLE}), not_a_list()] {
        let waiting = {
            let app = app.clone();
            let input = input.clone();
            tokio::spawn(async move { request(&app, "POST", "/api/workflows/plain-query/query/state", json!({"input": input})).await })
        };
        let task = await_query(&app, "plain-worker").await;
        assert_eq!(task["query_arguments"], input, "the worker receives the arguments as given: {task}");
        let (status, body) = request(&app, "POST", &format!("/api/worker/query-tasks/{}/complete", task["query_task_id"].as_str().unwrap()),
            json!({"lease_owner":"plain-worker","query_task_attempt":task["query_task_attempt"],
                "result_envelope":envelope(Payload::Array(vec![]))})).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        let (status, body) = waiting.await.unwrap();
        assert_eq!(status, StatusCode::OK, "{body}");
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_declared_query_refuses_arguments_that_do_not_decode_to_a_list() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    query_worker(&app, "contract-worker").await;
    let started = start(&app, "declared-query").await;
    for (input, message, error) in [
        (json!({"codec":"avro","blob":UNDECODABLE}), "Workflow query [state] arguments could not be decoded.", "Query arguments could not be decoded."),
        (not_a_list(), "Workflow query [state] arguments must decode to an array.", "Query arguments must decode to an array."),
    ] {
        let (status, body) = request(&app, "POST", "/api/workflows/declared-query/query/state", json!({"input": input})).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
        assert_eq!((body["reason"].clone(), body["message"].clone(), body["validation_errors"].clone()),
            (json!("invalid_query_arguments"), json!(message), json!({"arguments": [error]})), "{body}");
        assert_eq!(body["run_id"], started["run_id"], "{body}");
    }
    assert!(poll(&app, "contract-worker", "query").await.is_null(), "no query task was created");
    runtime.close().await;
    database.remove().await;
}
