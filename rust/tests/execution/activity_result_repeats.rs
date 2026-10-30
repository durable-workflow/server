use super::*;

// Published PHP answers every repeat of the result that finished an activity
// attempt with its stale-attempt receipt (409), identical or not, before and
// after the workflow consumed it. The published PHP, Python and Rust SDKs read
// a repeated completion's receipt as their own committed completion. The
// activity-result-repeats fixture records the same answers.

async fn finished_activity(app: &Router, id: &str) -> (Value, Value) {
    register(app, "worker", json!(["echo"]), json!(["echo-activity"])).await;
    let started = start(app, id).await;
    let task = poll(app, "worker", "workflow").await;
    let (status, body) = finish_task(app, &task, json!([{"type":"schedule_activity","activity_type":"echo-activity",
        "arguments":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    (started, poll(app, "worker", "activity").await)
}

fn route(activity: &Value, kind: &str) -> String {
    format!("/api/worker/activity-tasks/{}/{kind}", activity["task_id"].as_str().unwrap())
}

#[tokio::test]
async fn a_repeated_activity_result_is_stale_whether_it_completed_or_failed() {
    for kind in ["complete", "fail"] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let id = format!("repeat-{kind}");
        let (started, activity) = finished_activity(&app, &id).await;
        let lease = json!({"activity_attempt_id":activity["activity_attempt_id"],"lease_owner":activity["lease_owner"]});
        let mut body = lease.clone();
        if kind == "complete" {
            body["result"] = envelope(Payload::Array(vec![]));
        } else {
            body["failure"] = json!({"message":"attempt failed","type":"Boom","non_retryable":true});
        }
        let (status, first) = request(&app, "POST", &route(&activity, kind), body.clone()).await;
        assert_eq!((status, &first["recorded"]), (StatusCode::OK, &json!(true)), "{kind}: {first}");
        let before = run_history(&app, &json!(id), &started["run_id"]).await;
        let (status, again) = request(&app, "POST", &route(&activity, kind), body.clone()).await;
        assert_eq!(status, StatusCode::CONFLICT, "{kind}: {again}");
        let mut again = again;
        for field in ["server_capabilities", "protocol_version"] {
            again.as_object_mut().unwrap().remove(field);
        }
        let mut expected = json!({"task_id":activity["task_id"],"activity_attempt_id":activity["activity_attempt_id"],
            "outcome":if kind == "complete" { "completed" } else { "failed" },"recorded":false,"reason":"stale_attempt","next_task_id":null});
        if kind == "complete" {
            for (field, value) in [("lease_owner", activity["lease_owner"].clone()), ("activity_status", json!("completed")),
                ("attempt_status", json!("completed")), ("task_status", json!("completed"))] {
                expected[field] = value;
            }
        }
        assert_eq!(again, expected, "{kind}");
        assert_eq!(run_history(&app, &json!(id), &started["run_id"]).await, before, "{kind}: nothing is recorded");
        runtime.close().await;
        database.remove().await;
    }
}
