use super::*;

// server#1016: JSON doubles come back bit-exact on every backend, MySQL
// included, through the run's memo, its double search attributes (describe,
// list and WorkflowStarted) and its activity heartbeat details. MySQL's json
// type used to store its own binary double, one ULP off for some values;
// schema 13 stores each document as exact utf8mb4_bin text.
const DOUBLES: [&str; 6] = ["1.4820142857142857", "124435.71428571429", "33.333333333333336",
    "0.7071067811865476", "1.0000000000000002", "2.2250738585072014e-308"];

fn exact(found: &Value, key: &str, text: &str, surface: &str) {
    let wanted: f64 = text.parse().unwrap();
    let actual = found[key].as_f64().unwrap_or_else(|| panic!("{surface} {key} missing: {found}"));
    assert_eq!(actual.to_bits(), wanted.to_bits(), "{surface} {key}: {actual} is not {text}");
}

#[tokio::test]
async fn doubles_come_back_bit_exact_through_history_memo_search_attributes_and_heartbeats() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    if matches!(database, TestDatabase::MySql { .. }) {
        let types = text_rows(&database, "", "", "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workflow_history_events' AND COLUMN_NAME='payload'").await;
        assert_eq!(types, [("payload".to_owned(), "longtext".to_owned())]);
    }
    let mut memo = serde_json::Map::new();
    let mut attributes = serde_json::Map::new();
    for (index, text) in DOUBLES.iter().enumerate() {
        let value: f64 = text.parse().unwrap();
        memo.insert(format!("d{index}"), json!(value));
        let name = format!("Dbl{index}");
        let (status, body) = request(&app, "POST", "/api/search-attributes", json!({"name":name,"type":"double"})).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
        attributes.insert(name, json!(value));
    }
    let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"doubles-worker","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["measure"]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let (status, started) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"doubles","workflow_type":"echo",
        "task_queue":"test","input":envelope(Payload::Array(vec![])),"memo":{"doubles":memo},"search_attributes":attributes})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let task = poll(&app, "doubles-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"measure",
        "arguments":envelope(Payload::Array(vec![])),"start_to_close_timeout":300}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let activity = poll(&app, "doubles-worker", "activity").await;
    let (status, body) = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/heartbeat", activity["task_id"].as_str().unwrap()),
        json!({"activity_attempt_id":activity["activity_attempt_id"],"lease_owner":activity["lease_owner"],"details":memo})).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    // Read everything back through a fresh runtime, from storage alone.
    runtime.close().await;
    drop(app);
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let run = format!("/api/workflows/doubles/runs/{}", started["run_id"].as_str().unwrap());
    let described = request(&app, "GET", &run, Value::Null).await.1;
    let history = run_history(&app, &json!("doubles"), &started["run_id"]).await;
    let workflow_started = history.as_array().unwrap().iter().find(|event| event["event_type"] == "WorkflowStarted").unwrap()["payload"].clone();
    let listed = request(&app, "GET", &format!("/api/workflows?query={}", "WorkflowId%20%3D%20%22doubles%22"), Value::Null).await.1;
    let activities = request(&app, "GET", &format!("{run}/activities"), Value::Null).await.1;
    let heartbeat = &activities["activities"][0]["last_heartbeat_progress"]["details"];
    for (index, text) in DOUBLES.iter().enumerate() {
        let (key, name) = (format!("d{index}"), format!("Dbl{index}"));
        exact(&described["memo"]["doubles"], &key, text, "described memo");
        exact(&workflow_started["memo"]["doubles"], &key, text, "WorkflowStarted memo");
        exact(&described["search_attributes"], &name, text, "described search attribute");
        exact(&listed["workflows"][0]["search_attributes"], &name, text, "listed search attribute");
        exact(&workflow_started["search_attributes"], &name, text, "WorkflowStarted search attribute");
        exact(heartbeat, &key, text, "heartbeat details");
    }
    runtime.close().await;
    database.remove().await;
}
