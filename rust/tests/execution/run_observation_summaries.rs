use super::*;

async fn observation(app: &Router, namespace: &str, run: &Value) -> Value {
    let response = namespaces::scoped(
        app,
        namespace,
        "GET",
        &format!(
            "/api/workflows/{}/runs/{}/observation",
            run["workflow_id"].as_str().unwrap(),
            run["run_id"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    response.1
}

async fn history(app: &Router, namespace: &str, run: &Value) -> Value {
    let response = namespaces::scoped(
        app,
        namespace,
        "GET",
        &format!(
            "/api/workflows/{}/runs/{}/history",
            run["workflow_id"].as_str().unwrap(),
            run["run_id"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    response.1
}

fn assert_terminal(summary: &Value, history: &Value, status: &str, exceptions: u64, fan_out: u64) {
    assert_eq!(summary["summary_state"], "observed", "{summary}");
    assert_eq!(summary["status"], status);
    assert_eq!(summary["liveness_state"], "closed");
    assert_eq!(
        summary["liveness_reason"],
        format!(
            "Run closed as {}.",
            summary["closed_reason"].as_str().unwrap()
        )
    );
    assert_eq!(
        summary["history_event_count"],
        history["events"].as_array().unwrap().len()
    );
    assert!(summary["history_size_bytes"].as_u64().unwrap() > 0);
    assert_eq!(summary["history_fan_out"], fan_out);
    assert_eq!(summary["exception_count"], exceptions);
    assert!(summary["duration_ms"].as_u64().is_some());
    assert_eq!(summary["summary_observed_at"], summary["closed_at"]);
}

pub(super) async fn check_completed(
    database: &TestDatabase,
    app: &Router,
    namespace: &str,
    run: &Value,
    exceptions: u64,
    fan_out: u64,
) {
    let summary = observation(app, namespace, run).await;
    let events = history(app, namespace, run).await;
    assert_terminal(&summary, &events, "completed", exceptions, fan_out);
    export_reference(database, &summary, &events).await;
}

pub(super) async fn export_reference(database: &TestDatabase, summary: &Value, history: &Value) {
    // Preserve database JSON order for the independent published PHP counter.
    if let Ok(path) = std::env::var("DW_TEST_SUMMARY_REFERENCE") {
        use std::io::Write;
        let run = summary["run_id"].as_str().unwrap();
        let query = "SELECT event_type,CAST(payload AS TEXT),CAST(recorded_at AS TEXT) FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence";
        let task_query = "SELECT id,task_type,status,CAST(available_at AS TEXT),CAST(leased_at AS TEXT),CAST(lease_expires_at AS TEXT),compatibility FROM workflow_tasks WHERE workflow_run_id=$1 AND status IN ('ready','leased') ORDER BY id";
        macro_rules! rows {
            ($pool:expr,$query:expr,$tasks:expr,$problem:expr) => {{
                let pool = $pool;
                let rows: Vec<(String, Option<String>, Option<String>)> = sqlx::query_as($query)
                    .bind(run)
                    .fetch_all(&pool)
                    .await
                    .unwrap();
                let tasks: Vec<(
                    String,
                    String,
                    String,
                    Option<String>,
                    Option<String>,
                    Option<String>,
                    Option<String>,
                )> = sqlx::query_as($tasks)
                    .bind(run)
                    .fetch_all(&pool)
                    .await
                    .unwrap();
                let problem: i64 = sqlx::query_scalar($problem)
                    .bind(run)
                    .fetch_one(&pool)
                    .await
                    .unwrap();
                pool.close().await;
                (rows, tasks, problem != 0)
            }};
        }
        let (rows, tasks, task_problem) = match database {
            TestDatabase::Sqlite(dir) => rows!(
                sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap(),
                query,
                task_query,
                "SELECT COUNT(*) FROM workflow_run_summaries WHERE id=$1 AND task_problem=TRUE"
            ),
            TestDatabase::Postgres { options, .. } => rows!(
                sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap(),
                query,
                task_query,
                "SELECT COUNT(*) FROM workflow_run_summaries WHERE id=$1 AND task_problem=TRUE"
            ),
            TestDatabase::MySql { options, .. } => rows!(
                sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap(),
                "SELECT event_type,CAST(payload AS CHAR),CAST(recorded_at AS CHAR) FROM workflow_history_events WHERE workflow_run_id=? ORDER BY sequence",
                "SELECT id,task_type,status,CAST(available_at AS CHAR),CAST(leased_at AS CHAR),CAST(lease_expires_at AS CHAR),compatibility FROM workflow_tasks WHERE workflow_run_id=? AND status IN ('ready','leased') ORDER BY id",
                "SELECT COUNT(*) FROM workflow_run_summaries WHERE id=? AND task_problem=TRUE"
            ),
        };
        let raw:Vec<Value> = rows.into_iter().map(|(kind,payload,recorded)| json!({"event_type":kind,"payload_json":payload.unwrap_or_else(||"null".into()),"recorded_at":recorded})).collect();
        let tasks: Vec<Value> = tasks
            .into_iter()
            .map(
                |(id, kind, status, available, leased, expires, compatibility)| {
                    json!({
            "id":id,"workflow_run_id":run,"task_type":kind,"status":status,"available_at":available,
            "leased_at":leased,"lease_expires_at":expires,"compatibility":compatibility})
                },
            )
            .collect();
        let mut file = std::fs::OpenOptions::new()
            .create(true)
            .append(true)
            .open(path)
            .unwrap();
        writeln!(
            file,
            "{}",
            json!({"summary":summary,"history":history,"raw_history":raw,"tasks":tasks,"stored_task_problem":task_problem})
        )
        .unwrap();
    }
}

#[tokio::test]
async fn run_observation_terminal_summaries_survive_cold_nodes_and_duplicate_commands() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "summary-observation").await;
    let mut originals = Vec::new();
    for namespace in ["default", "summary-observation"] {
        let result = namespaces::scoped(
            &app,
            namespace,
            "POST",
            "/api/worker/register",
            json!({
            "worker_id":"summary-worker","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":[]}),
        )
        .await;
        assert_eq!(result.0, StatusCode::CREATED);
        for cancelled in [false, true] {
            let created = namespaces::scoped(&app,namespace,"POST","/api/workflows",json!({
                "workflow_id":format!("summary-{namespace}-{cancelled}"),"workflow_type":"echo","task_queue":"test",
                "input":envelope(Payload::Array(vec![])),"memo":{"numeric_keys":{"0":0,"1":1,"2":2,"3":3,"4":4,"5":5,"6":6,"7":7,"8":8,"9":9,"10":10},"fractions":[0.00001,1e17],"text":"日本語 λ\u{2028}"}})).await;
            assert_eq!(created.0, StatusCode::CREATED, "{}", created.1);
            let run = created.1;
            assert_eq!(
                observation(&app, namespace, &run).await["summary_state"],
                "observed"
            );
            let (path, body) = if cancelled {
                (
                    format!(
                        "/api/workflows/{}/runs/{}/cancel",
                        run["workflow_id"].as_str().unwrap(),
                        run["run_id"].as_str().unwrap()
                    ),
                    json!({"reason":"cancel 日本語 λ\u{2028}"}),
                )
            } else {
                let task = namespaces::scoped(
                    &app,
                    namespace,
                    "POST",
                    "/api/worker/workflow-tasks/poll",
                    json!({"worker_id":"summary-worker","task_queue":"test","timeout_seconds":0}),
                )
                .await
                .1["task"]
                    .clone();
                (
                    format!(
                        "/api/worker/workflow-tasks/{}/complete",
                        task["task_id"].as_str().unwrap()
                    ),
                    completion(
                        &task,
                        json!([{"type":"complete_workflow","result":envelope(Payload::String("result 日本語 λ".into()))}]),
                    ),
                )
            };
            let response = namespaces::scoped(&app, namespace, "POST", &path, body.clone()).await;
            assert_eq!(response.0, StatusCode::OK, "{}", response.1);
            let summary = observation(&app, namespace, &run).await;
            let events = history(&app, namespace, &run).await;
            assert_terminal(
                &summary,
                &events,
                if cancelled { "cancelled" } else { "completed" },
                u64::from(cancelled),
                0,
            );
            export_reference(&database, &summary, &events).await;
            // Completion replays its receipt. A fresh cancel request against a
            // closed run is explicitly refused; neither may change counters.
            assert_eq!(
                namespaces::scoped(&app, namespace, "POST", &path, body)
                    .await
                    .0,
                if cancelled {
                    StatusCode::CONFLICT
                } else {
                    StatusCode::OK
                }
            );
            assert_eq!(history(&app, namespace, &run).await, events);
            let repeated = observation(&app, namespace, &run).await;
            for field in [
                "history_event_count",
                "history_size_bytes",
                "exception_count",
                "duration_ms",
                "summary_observed_at",
            ] {
                assert_eq!(repeated[field], summary[field], "{field}");
            }
            let other = if namespace == "default" {
                "summary-observation"
            } else {
                "default"
            };
            assert_eq!(
                namespaces::scoped(
                    &app,
                    other,
                    "GET",
                    &format!(
                        "/api/workflows/{}/runs/{}/observation",
                        run["workflow_id"].as_str().unwrap(),
                        run["run_id"].as_str().unwrap()
                    ),
                    Value::Null
                )
                .await
                .0,
                StatusCode::NOT_FOUND
            );
            originals.push((namespace, run, summary, events));
        }
    }
    runtime.close().await;
    let (a, b) = tokio::join!(database.open(), database.open());
    let (a, b) = (a.unwrap(), b.unwrap());
    for (namespace, run, summary, events) in originals {
        let cold = observation(&router(a.clone()), namespace, &run).await;
        for field in [
            "history_event_count",
            "history_size_bytes",
            "exception_count",
            "duration_ms",
            "summary_observed_at",
        ] {
            assert_eq!(cold[field], summary[field]);
        }
        assert_eq!(history(&router(b.clone()), namespace, &run).await, events);
    }
    a.close().await;
    b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_terminal_summary_continuation_counts_all_history_pages() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    assert_eq!(request(&app,"POST","/api/worker/register",json!({"worker_id":"summary-continuation","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":["memo_upserts"]})).await.0,StatusCode::CREATED);
    let original = start(&app, "summary-continuation").await;
    let task = poll(&app, "summary-continuation", "workflow").await;
    let mut commands:Vec<Value> = (0..20).map(|index| json!({"type":"upsert_memo","entries":envelope(Payload::Map(std::collections::BTreeMap::from([(format!("summary-{index}"),Payload::Long(9007199254740993))])))})).collect();
    commands.push(json!({"type":"continue_as_new"}));
    let response = finish_task(&app, &task, json!(commands)).await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    let summary = observation(&app, "default", &original).await;
    let events = history(&app, "default", &original).await;
    assert_terminal(&summary, &events, "completed", 0, 0);
    export_reference(&database, &summary, &events).await;
    assert_eq!(summary["closed_reason"], "continued");
    assert_eq!(summary["history_event_count"], 23);
    assert_eq!(summary["is_current_run"], false);
    let next = poll(&app, "summary-continuation", "workflow").await;
    assert_ne!(next["run_id"], original["run_id"]);
    let successor = json!({"workflow_id":original["workflow_id"],"run_id":next["run_id"]});
    assert_eq!(
        observation(&app, "default", &successor).await["summary_state"],
        "observed"
    );
    assert_eq!(
        finish_task(
            &app,
            &next,
            json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
        )
        .await
        .0,
        StatusCode::OK
    );
    assert_terminal(
        &observation(&app, "default", &successor).await,
        &history(&app, "default", &successor).await,
        "completed",
        0,
        0,
    );
    export_reference(
        &database,
        &observation(&app, "default", &successor).await,
        &history(&app, "default", &successor).await,
    )
    .await;
    assert_eq!(history(&app, "default", &original).await, events);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_terminal_summary_write_failure_rolls_back_completion() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "summary-rollback", json!(["echo"]), json!([])).await;
    let run = start(&app, "summary-rollback").await;
    let task = poll(&app, "summary-rollback", "workflow").await;
    let before = history(&app, "default", &run).await;
    database.execute("CREATE TRIGGER reject_summary BEFORE INSERT ON workflow_run_summaries BEGIN SELECT RAISE(ABORT, 'summary write failed'); END").await;
    let commands = json!([{"type":"complete_workflow","result":envelope(Payload::Null)}]);
    assert_eq!(
        finish_task(&app, &task, commands.clone()).await.0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    assert_eq!(history(&app, "default", &run).await, before);
    assert_eq!(
        observation(&app, "default", &run).await["summary_state"],
        "observed"
    );
    assert_eq!(
        observation(&app, "default", &run).await["liveness_state"],
        "workflow_task_leased"
    );
    database.execute("DROP TRIGGER reject_summary").await;
    assert_eq!(finish_task(&app, &task, commands).await.0, StatusCode::OK);
    assert_terminal(
        &observation(&app, "default", &run).await,
        &history(&app, "default", &run).await,
        "completed",
        0,
        0,
    );
    database.execute("DELETE FROM workflow_run_summaries").await;
    let before = history(&app, "default", &run).await;
    assert_eq!(
        observation(&app, "default", &run).await["summary_state"],
        "unavailable"
    );
    assert_eq!(history(&app, "default", &run).await, before);
    runtime.close().await;
    database.remove().await;
}
