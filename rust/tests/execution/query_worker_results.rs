//! A query worker's plain `result`, which published PHP stores beside the
//! result envelope and returns to the caller (`completeQueryTask`).
use super::*;

async fn ask(app: &Router, workflow_id: &str) -> tokio::task::JoinHandle<(StatusCode, Value)> {
    let app = app.clone();
    let path = format!("/api/workflows/{workflow_id}/query/state");
    tokio::spawn(async move {
        request(&app, "POST", &path, json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))})).await
    })
}

#[tokio::test]
async fn query_answers_carry_the_worker_s_plain_result() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    query_worker(&app, "plain-query-worker").await;
    let plain = json!({"received":0,"last":null});
    for (workflow_id, with_envelope) in [("plain-and-envelope", true), ("plain-only", false)] {
        start(&app, workflow_id).await;
        let asked = ask(&app, workflow_id).await;
        let claim = await_query(&app, "plain-query-worker").await;
        let mut completion = json!({"lease_owner":"plain-query-worker","query_task_attempt":claim["query_task_attempt"],
            "result":plain});
        if with_envelope {
            completion["result_envelope"] = envelope(Payload::Long(7));
        }
        let (status, body) = request(&app, "POST",
            &format!("/api/worker/query-tasks/{}/complete", claim["query_task_id"].as_str().unwrap()), completion).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        let (status, answer) = asked.await.unwrap();
        assert_eq!(status, StatusCode::OK, "{answer}");
        assert_eq!(answer["result"], plain, "{answer}");
        assert_eq!(answer["control_plane"]["result"], plain, "{answer}");
        assert_eq!(answer["result_envelope"].is_object(), with_envelope, "{answer}");
    }
    runtime.close().await;
    database.remove().await;
}
