use super::*;

const ENTRY: &str = "2001-02-03T04:05:06.123456Z";
const FINGERPRINT: &str = "sha256:abababababababababababababababababababababababababababababababab";

fn expected(run: &Value, at: &str, source: &str) -> Value {
    json!({"schema":"durable-workflow.worker-replay-clock/v1",
        "workflow_run_id":run,"started_at":at,"source":source})
}

async fn clock_poll(app: &Router, fields: Value) -> Value {
    let mut body = json!({"worker_id":"worker","task_queue":"test","timeout_seconds":0});
    for (key, value) in fields.as_object().unwrap() {
        body[key] = value.clone();
    }
    let (status, response) = request(app, "POST", "/api/worker/workflow-tasks/poll", body).await;
    assert_eq!(status, StatusCode::OK, "{response}");
    assert!(response["task"].is_object(), "{response}");
    response["task"].clone()
}

fn without_cached_clock(text: &str) -> Option<String> {
    let mut value: Value = serde_json::from_str(text).unwrap();
    value.get_mut("task")?.as_object_mut()?.remove("replay_clock")?;
    Some(value.to_string())
}

impl TestDatabase {
    async fn clock_set_entry(&self, run: &str, value: &str) {
        let at = chrono::DateTime::parse_from_rfc3339(value).unwrap().naive_utc();
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!("sqlite://{}", dir.path().join("runtime.sqlite").display())).await.unwrap();
                sqlx::query("UPDATE workflow_runs SET started_at=$1 WHERE id=$2")
                    .bind(at.format("%Y-%m-%d %H:%M:%S%.6f").to_string()).bind(run).execute(&pool).await.unwrap();
                pool.close().await;
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
                sqlx::query("UPDATE workflow_runs SET started_at=$1 WHERE id=$2").bind(at).bind(run).execute(&pool).await.unwrap();
                pool.close().await;
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
                sqlx::query("UPDATE workflow_runs SET started_at=? WHERE id=?").bind(at).bind(run).execute(&pool).await.unwrap();
                pool.close().await;
            }
        }
    }

    async fn clock_cached_absence(&self) {
        let mut changed = 0;
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!("sqlite://{}", dir.path().join("runtime.sqlite").display())).await.unwrap();
                let rows: Vec<String> = sqlx::query_scalar("SELECT value FROM dw_query_cache").fetch_all(&pool).await.unwrap();
                for old in rows {
                    if let Some(new) = without_cached_clock(&old) {
                        changed += sqlx::query("UPDATE dw_query_cache SET value=$1 WHERE value=$2").bind(new).bind(old).execute(&pool).await.unwrap().rows_affected();
                    }
                }
                pool.close().await;
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
                let rows: Vec<String> = sqlx::query_scalar("SELECT value FROM dw_query_cache").fetch_all(&pool).await.unwrap();
                for old in rows {
                    if let Some(new) = without_cached_clock(&old) {
                        changed += sqlx::query("UPDATE dw_query_cache SET value=$1 WHERE value=$2").bind(new).bind(old).execute(&pool).await.unwrap().rows_affected();
                    }
                }
                pool.close().await;
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
                let rows: Vec<String> = sqlx::query_scalar("SELECT value FROM dw_query_cache").fetch_all(&pool).await.unwrap();
                for old in rows {
                    if let Some(new) = without_cached_clock(&old) {
                        changed += sqlx::query("UPDATE dw_query_cache SET value=? WHERE value=?").bind(new).bind(old).execute(&pool).await.unwrap().rows_affected();
                    }
                }
                pool.close().await;
            }
        }
        assert_eq!(changed, 1);
    }

    async fn clock_replace_start(&self, run: &str, payload: &Value) {
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!("sqlite://{}", dir.path().join("runtime.sqlite").display())).await.unwrap();
                sqlx::query("UPDATE workflow_history_events SET payload=$1 WHERE workflow_run_id=$2 AND event_type='WorkflowStarted'")
                    .bind(payload.to_string()).bind(run).execute(&pool).await.unwrap();
                pool.close().await;
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
                sqlx::query("UPDATE workflow_history_events SET payload=$1 WHERE workflow_run_id=$2 AND event_type='WorkflowStarted'")
                    .bind(sqlx::types::Json(payload)).bind(run).execute(&pool).await.unwrap();
                pool.close().await;
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
                sqlx::query("UPDATE workflow_history_events SET payload=? WHERE workflow_run_id=? AND event_type='WorkflowStarted'")
                    .bind(payload.to_string()).bind(run).execute(&pool).await.unwrap();
                pool.close().await;
            }
        }
    }
}

#[tokio::test]
async fn canonical_entry_survives_paging_cached_presence_absence_cold_open_and_a_new_lease() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "clock-ordinary").await;
    database.clock_set_entry(run["run_id"].as_str().unwrap(), ENTRY).await;
    let fields = json!({"poll_request_id":"clock-original","history_page_size":1});
    let task = clock_poll(&app, fields.clone()).await;
    let clock = expected(&run["run_id"], ENTRY, "run_started_at");
    assert_eq!(task["replay_clock"], clock);
    assert_eq!(task["history_events"].as_array().unwrap().len(), 1);
    assert_eq!(task["history_events"][0]["event_type"], "StartAccepted");
    assert!(task["next_history_page_token"].is_string());
    runtime.close().await;
    drop(app);
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let cached = clock_poll(&app, fields.clone()).await;
    assert_eq!(cached["replay_clock"], clock);
    assert_eq!(cached["workflow_task_attempt"], task["workflow_task_attempt"]);
    database.clock_cached_absence().await;
    let old_answer = clock_poll(&app, fields).await;
    assert!(old_answer.get("replay_clock").is_none());
    database.set_time(false, "2000-01-01T00:00:00Z").await;
    let next_lease = clock_poll(&app, json!({"poll_request_id":"clock-next-lease"})).await;
    assert_eq!(next_lease["run_id"], run["run_id"]);
    assert_eq!(next_lease["replay_clock"], clock);
    assert!(next_lease["workflow_task_attempt"].as_u64().unwrap() > task["workflow_task_attempt"].as_u64().unwrap());
    let before = chrono::Utc::now() - chrono::Duration::seconds(1);
    let next_run = start(&app, "clock-new-run").await;
    let after = chrono::Utc::now() + chrono::Duration::seconds(1);
    let next = clock_poll(&app, json!({})).await;
    assert_eq!(next["replay_clock"]["workflow_run_id"], next_run["run_id"]);
    assert_ne!(next["replay_clock"]["workflow_run_id"], run["run_id"]);
    let at = chrono::DateTime::parse_from_rfc3339(next["replay_clock"]["started_at"].as_str().unwrap()).unwrap().with_timezone(&chrono::Utc);
    assert!(at >= before && at <= after);
    runtime.close().await;
}

#[tokio::test]
async fn continuation_and_child_entry_use_their_own_canonical_run() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let parent = start(&app, "clock-parent").await;
    database.clock_set_entry(parent["run_id"].as_str().unwrap(), ENTRY).await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, reply) = finish_task(&app, &task, json!([{"type":"start_child_workflow","workflow_type":"echo","arguments":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(status, StatusCode::OK, "{reply}");
    let child = clock_poll(&app, json!({})).await;
    assert_ne!(child["run_id"], parent["run_id"]);
    assert_eq!(child["replay_clock"]["workflow_run_id"], child["run_id"]);
    assert_eq!(child["replay_clock"]["source"], "run_started_at");
    assert_ne!(child["replay_clock"]["started_at"], ENTRY);
    let (status, reply) = finish_task(&app, &child, json!([{"type":"continue_as_new"}])).await;
    assert_eq!(status, StatusCode::OK, "{reply}");
    let (status, current) = request(&app, "GET", &format!("/api/workflows/{}", child["workflow_id"].as_str().unwrap()), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{current}");
    database.clock_set_entry(current["run_id"].as_str().unwrap(), "2003-02-03T04:05:06.654321Z").await;
    let continued = clock_poll(&app, json!({})).await;
    assert_ne!(continued["run_id"], child["run_id"]);
    assert_eq!(continued["workflow_id"], child["workflow_id"]);
    assert_eq!(continued["replay_clock"]["workflow_run_id"], continued["run_id"]);
    assert_eq!(continued["replay_clock"]["source"], "run_started_at");
    assert_eq!(continued["replay_clock"]["started_at"], "2003-02-03T04:05:06.654321Z");
    runtime.close().await;
}

#[tokio::test]
async fn redrive_preserves_represented_anchor_and_never_substitutes_its_fresh_start() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered = request(&app, "POST", "/api/worker/register", json!({"worker_id":"worker","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["a","b"],
        "workflow_definition_fingerprints":{"echo":FINGERPRINT}})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let source = super::redrive::failed_at_second_activity(&app, "clock-redrive").await;
    database.clock_set_entry(source["run_id"].as_str().unwrap(), ENTRY).await;
    let path = format!("/api/workflows/clock-redrive/runs/{}/redrive", source["run_id"].as_str().unwrap());
    let (status, redriven) = request(&app, "POST", &path, json!({"request_id":"clock-redrive"})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{redriven}");
    let task = clock_poll(&app, json!({"history_page_size":1})).await;
    assert_eq!(task["replay_clock"], expected(&redriven["run_id"], "2001-02-03T04:05:06.000000Z", "redrive_replayed_started_at"));
    let history = run_history(&app, &source["workflow_id"], &redriven["run_id"]).await;
    let original = history.as_array().unwrap().iter().find(|event| event["event_type"]=="WorkflowStarted").unwrap()["payload"].clone();
    let mut cases = Vec::new();
    for (key, value) in [("replayed_started_at",Value::Null),("replayed_started_at",json!("2026-02-30T00:00:00Z")),
        ("resume_step_sequence",json!("2")),("resume_step_sequence",json!(0)),("continued_from_run_id",json!("foreign")),
        ("workflow_link_id",json!("foreign")),("workflow_command_id",json!("foreign")),("workflow_run_id",json!("foreign"))] {
        let mut payload = original.clone();
        payload[key] = value;
        cases.push(payload);
    }
    let mut stripped = original.clone();
    for key in ["recovery_kind","replayed_started_at","resume_step_sequence"] {
        stripped.as_object_mut().unwrap().remove(key);
    }
    cases.push(stripped);
    for payload in cases {
        database.clock_replace_start(redriven["run_id"].as_str().unwrap(), &payload).await;
        database.set_time(false, "2000-01-01T00:00:00Z").await;
        let refused = clock_poll(&app, json!({})).await;
        assert_eq!(refused["run_id"], redriven["run_id"]);
        assert!(refused.get("replay_clock").is_none(), "{payload}: {refused}");
    }
    database.clock_replace_start(redriven["run_id"].as_str().unwrap(), &original).await;
    database.set_time(false, "2000-01-01T00:00:00Z").await;
    let restored = clock_poll(&app, json!({})).await;
    assert_eq!(restored["replay_clock"], task["replay_clock"]);
    runtime.close().await;
}
