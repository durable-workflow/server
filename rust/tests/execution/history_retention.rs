use super::*;

const STATUS: &str = "/api/system/retention";
const PASS: &str = "/api/system/retention/pass";

async fn completed_run(app: &Router, workflow: &str) -> String {
    let started = start(app, workflow).await;
    let task = poll(app, "worker", "workflow").await;
    let complete = json!({"type":"complete_workflow","result":envelope(Payload::Long(1))});
    assert_eq!(finish_task(app, &task, json!([complete])).await.0, StatusCode::OK);
    started["run_id"].as_str().unwrap().to_owned()
}

fn messages(body: &Value, field: &str) -> Value {
    body["errors"][field].clone()
}

#[test]
fn configured_history_retention_is_persisted_and_used_for_pruning() {
    // Give this fixture its own immutable process configuration.
    let output = std::process::Command::new(std::env::current_exe().unwrap())
        .args(["--ignored", "--exact", "history_retention::configured_retention_child"])
        .env("DW_HISTORY_RETENTION_DAYS", "90")
        .env("WORKFLOW_HISTORY_RETENTION_DAYS", "30")
        .output()
        .unwrap();
    assert!(
        output.status.success() && String::from_utf8_lossy(&output.stdout).contains("1 passed"),
        "{}
{}",
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    );
}

#[tokio::test]
#[ignore = "environment is set by the parent test"]
async fn configured_retention_child() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for (body, expected) in [
        (json!({"name":"inherited"}), json!(90)),
        (json!({"name":"explicit","retention_days":14}), json!(14)),
        (json!({"name":"forever","retention_mode":"forever"}), Value::Null),
    ] {
        let (status, created) = request(&app, "POST", "/api/namespaces", body.clone()).await;
        assert_eq!(status, StatusCode::CREATED, "{created}");
        assert_eq!(created["retention_days"], expected, "{created}");
        let name = body["name"].as_str().unwrap();
        let (status, stored) = request(&app, "GET", &format!("/api/namespaces/{name}"), Value::Null).await;
        assert_eq!(status, StatusCode::OK, "{stored}");
        assert_eq!(stored["retention_days"], expected, "{stored}");
    }

    let (status, initial) = request(&app, "GET", "/api/namespaces/default", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{initial}");
    assert_eq!(initial["retention_days"], 90, "{initial}");
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let kept = completed_run(&app, "configured-kept").await;
    let expired = completed_run(&app, "configured-expired").await;
    for (run, age) in [(&kept, 45), (&expired, 100)] {
        let closed_at = (chrono::Utc::now() - chrono::Duration::days(age))
            .format("%Y-%m-%d %H:%M:%S%.6f");
        for table in ["workflow_runs", "workflow_run_summaries"] {
            database.execute(sqlx::AssertSqlSafe(format!(
                "UPDATE {table} SET closed_at='{closed_at}' WHERE id='{run}'"
            ))).await;
        }
    }
    let (status, retention) = request(&app, "GET", STATUS, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{retention}");
    assert_eq!(retention["retention_days"], 90, "{retention}");
    assert_eq!(retention["expired_run_ids"], json!([expired]), "{retention}");
    let (status, pruned) = request(&app, "POST", PASS, json!({})).await;
    assert_eq!(status, StatusCode::OK, "{pruned}");
    assert_eq!(pruned["pruned"], 1, "{pruned}");
    assert_eq!(pruned["results"][0]["run_id"], expired, "{pruned}");

    let (status, updated) = request(
        &app, "PUT", "/api/namespaces/default", json!({"retention_days":78}),
    ).await;
    assert_eq!(status, StatusCode::OK, "{updated}");
    runtime.close().await;
    drop(app);
    let reopened = database.open().await.unwrap();
    let app = router(reopened.clone());
    let (status, stored) = request(&app, "GET", "/api/namespaces/default", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{stored}");
    assert_eq!(stored["retention_days"], 78, "{stored}");
    reopened.close().await;
}

// Published PHP keeps closed runs for the namespace's retention days, then
// prunes their details: one run per heartbeat, or a batch on request.
#[tokio::test]
async fn retention_prunes_only_expired_closed_runs_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let expired = completed_run(&app, "expired").await;
    let kept = completed_run(&app, "kept").await;
    let open = start(&app, "open").await["run_id"].as_str().unwrap().to_owned();

    let (status, fresh) = request(&app, "GET", STATUS, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{fresh}");
    assert_eq!(
        [&fresh["namespace"], &fresh["retention_mode"], &fresh["retention_days"], &fresh["expired_run_count"]],
        [&json!("default"), &json!("bounded"), &json!(30), &json!(0)]
    );
    assert!(fresh["cutoff"].as_str().unwrap().ends_with("+00:00"), "{fresh}");
    assert_eq!((fresh["scan_limit"].clone(), fresh["scan_pressure"].clone()), (json!(100), json!(false)));

    // The expired run closed long before the 30-day cutoff.
    for table in ["workflow_runs", "workflow_run_summaries"] {
        database
            .execute(sqlx::AssertSqlSafe(format!(
                "UPDATE {table} SET closed_at='2020-01-01 00:00:00.000000' WHERE id='{expired}'"
            )))
            .await;
    }
    let (status, pressured) = request(&app, "GET", &format!("{STATUS}?limit=1"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{pressured}");
    assert_eq!(pressured["expired_run_ids"], json!([expired]));
    assert_eq!((pressured["scan_limit"].clone(), pressured["scan_pressure"].clone()), (json!(1), json!(true)));

    // Laravel's validation messages.
    let (status, invalid) = request(&app, "GET", &format!("{STATUS}?limit=0"), Value::Null).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(messages(&invalid, "limit"), json!(["The limit field must be at least 1."]));
    let (status, invalid) = request(&app, "POST", PASS, json!({"run_ids":[1, "x"]})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(messages(&invalid, "run_ids.0"), json!(["The run_ids.0 field must be a string."]));
    let (status, invalid) = request(&app, "POST", PASS, json!({"run_ids":"x"})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(messages(&invalid, "run_ids"), json!(["The run ids field must be an array."]));

    // Named runs that are missing or still open are skipped with a reason.
    let (status, skipped) = request(&app, "POST", PASS, json!({"run_ids":[" missing ", open]})).await;
    assert_eq!(status, StatusCode::OK, "{skipped}");
    assert_eq!((skipped["processed"].clone(), skipped["skipped"].clone(), skipped["pruned"].clone()), (json!(2), json!(2), json!(0)));
    assert_eq!(skipped["results"][0], json!({"run_id":"missing","outcome":"skipped","reason":"run_not_found"}));
    assert_eq!(skipped["results"][1]["reason"], "run_not_terminal");

    // Without run ids, the pass prunes the expired run in one transaction.
    let (status, pruned) = request(&app, "POST", PASS, json!([])).await;
    assert_eq!(status, StatusCode::OK, "{pruned}");
    assert_eq!((pruned["processed"].clone(), pruned["pruned"].clone(), pruned["failed"].clone()), (json!(1), json!(1), json!(0)));
    let result = &pruned["results"][0];
    assert_eq!((result["run_id"].clone(), result["outcome"].clone()), (json!(expired), json!("pruned")));
    assert!(result["history_events_deleted"].as_i64().unwrap() > 0, "{result}");
    assert_eq!(result["deleted"]["run_summary_deleted"], 1, "{result}");
    assert_eq!(result["deleted"]["run_deleted"], 0, "{result}");
    assert_eq!(result["deleted"]["message_stream_items_compacted"], 0, "{result}");
    assert_eq!(result["external_payloads_deleted"], 0, "{result}");

    let (_, after) = request(&app, "GET", STATUS, Value::Null).await;
    assert_eq!(after["expired_run_count"], 0, "{after}");
    let (status, again) = request(&app, "POST", PASS, json!({"run_ids":[expired]})).await;
    assert_eq!(status, StatusCode::OK, "{again}");
    assert_eq!(again["results"][0]["reason"], "run_not_found", "{again}");
    // The run that has not expired keeps its history.
    let (status, export) = request(&app, "GET", &format!("/api/workflows/kept/runs/{kept}/history/export"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{export}");
    assert!(!export["history_events"].as_array().unwrap().is_empty());

    // A heartbeat reports its namespace's inline pass, at most once a minute.
    let (status, heartbeat) = request(&app, "POST", "/api/worker/heartbeat", json!({"worker_id":"worker"})).await;
    assert_eq!(status, StatusCode::OK, "{heartbeat}");
    let retention = &heartbeat["retention"];
    assert_eq!(retention["failed"], 0, "{heartbeat}");
    for field in ["throttled", "processed", "pruned", "skipped"] {
        assert!(!retention[field].is_null(), "{field}: {heartbeat}");
    }
}

#[tokio::test]
async fn activity_timeout_status_reports_no_expired_executions_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (status, body) = request(&app, "GET", "/api/system/activity-timeouts?limit=5", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body, json!({"expired_count":0,"expired_execution_ids":[],"scan_limit":5,"scan_pressure":false}));
    let (status, invalid) = request(&app, "GET", "/api/system/activity-timeouts?limit=abc", Value::Null).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(messages(&invalid, "limit"), json!(["The limit field must be an integer."]));
}

async fn export(app: &Router, workflow: &str, run: &str) -> Value {
    let (status, bundle) = request(app, "GET", &format!("/api/workflows/{workflow}/runs/{run}/history/export"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{bundle}");
    bundle
}

/// Core 2.17.0's retained-run pruning state (workflow#856, server#976): a run
/// whose details retention cleanup pruned exports as incomplete, with its
/// state and a dedupe key that cannot match the full export taken before,
/// and import refuses it. An unpruned run only gains `details_state`.
#[tokio::test]
async fn a_pruned_run_exports_as_incomplete_and_import_refuses_it() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let completed = completed_run(&app, "pruned-completed").await;
    let failed = start(&app, "pruned-failed").await["run_id"].as_str().unwrap().to_owned();
    let task = poll(&app, "worker", "workflow").await;
    assert_eq!(finish_task(&app, &task, json!([{"type":"fail_workflow","message":"boom"}])).await.0, StatusCode::OK);
    let kept = completed_run(&app, "retained-completed").await;

    let mut before = Vec::new();
    for (workflow, run, status) in [("pruned-completed", &completed, "completed"), ("pruned-failed", &failed, "failed")] {
        let bundle = export(&app, workflow, run).await;
        assert_eq!(bundle["history_complete"], true, "{bundle}");
        assert_eq!([&bundle["workflow"]["status"], &bundle["workflow"]["details_state"], &bundle["workflow"]["details_pruned_at"]],
            [&json!(status), &json!("retained"), &Value::Null]);
        assert!(!bundle["history_events"].as_array().unwrap().is_empty(), "{bundle}");
        before.push(bundle);
    }
    let kept_before = export(&app, "retained-completed", &kept).await;

    let (status, pruned) = request(&app, "POST", PASS, json!({"run_ids":[completed, failed]})).await;
    assert_eq!(status, StatusCode::OK, "{pruned}");
    assert_eq!(pruned["pruned"], 2, "{pruned}");

    for ((workflow, run, status), before) in
        [("pruned-completed", &completed, "completed"), ("pruned-failed", &failed, "failed")].into_iter().zip(&before)
    {
        let bundle = export(&app, workflow, run).await;
        let pruned_at = bundle["workflow"]["details_pruned_at"].as_str().unwrap_or_else(|| panic!("{bundle}")).to_owned();
        assert_eq!(bundle["history_complete"], false, "{bundle}");
        assert_eq!([&bundle["workflow"]["status"], &bundle["workflow"]["details_state"]], [&json!(status), &json!("pruned")]);
        assert_eq!(bundle["history_events"], json!([]), "{bundle}");
        // The full export's key, marked with the pruning time.
        assert_eq!(bundle["dedupe_key"], json!(format!("{}:details-pruned:{pruned_at}", before["dedupe_key"].as_str().unwrap())));
        assert_ne!(bundle["dedupe_key"], before["dedupe_key"]);

        // Import runs the verifier and the terminal-history rule; both refuse.
        let (status, report) = request(&app, "POST", "/api/workflows/import/embedded-v2",
            json!({"bundle": bundle, "dry_run": true})).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{report}");
        fn rules(value: &Value, found: &mut Vec<String>) {
            match value {
                Value::Object(map) => {
                    if let Some(rule) = map.get("rule").and_then(Value::as_str) {
                        found.push(rule.to_owned());
                    }
                    map.values().for_each(|value| rules(value, found));
                }
                Value::Array(items) => items.iter().for_each(|value| rules(value, found)),
                _ => {}
            }
        }
        let mut all = Vec::new();
        rules(&report, &mut all);
        for rule in ["workflow.terminal_history_incomplete", "history.details_pruned"] {
            assert!(all.iter().any(|seen| seen == rule), "{rule} missing from {report}");
        }
    }

    // An unpruned run's export only gains the retained state.
    let mut kept_after = export(&app, "retained-completed", &kept).await;
    let mut kept_before = kept_before;
    for bundle in [&mut kept_after, &mut kept_before] {
        for field in ["exported_at", "integrity"] {
            bundle.as_object_mut().unwrap().remove(field);
        }
    }
    assert_eq!(kept_after, kept_before);
    assert_eq!([&kept_after["workflow"]["details_state"], &kept_after["workflow"]["details_pruned_at"]], [&json!("retained"), &Value::Null]);
}

// Server #633: the native maintenance loop prunes every expired run of a
// namespace without worker heartbeats, past a run it has to skip (#500).
#[tokio::test]
async fn maintenance_retention_prunes_expired_runs_past_held_ones() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let held = completed_run(&app, "held").await;
    let expired: Vec<String> = [completed_run(&app, "expired-a").await, completed_run(&app, "expired-b").await].into();
    let kept = completed_run(&app, "kept").await;
    // The held run is oldest and holds an external reference native cannot
    // settle, so retention skips it.
    for (run, closed) in [(&held, "2020-01-01 00:00:00.000000"), (&expired[0], "2020-01-02 00:00:00.000000"), (&expired[1], "2020-01-03 00:00:00.000000")] {
        for table in ["workflow_runs", "workflow_run_summaries"] {
            database.execute(sqlx::AssertSqlSafe(format!("UPDATE {table} SET closed_at='{closed}' WHERE id='{run}'"))).await;
        }
    }
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE workflow_runs SET arguments='{{\"external_storage\":{{\"uri\":\"held\"}}}}' WHERE id='{held}'"))).await;

    runtime.retention_maintenance().await.unwrap();
    let (status, after) = request(&app, "GET", STATUS, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{after}");
    assert_eq!(after["expired_run_ids"], json!([held]), "{after}");
    for run in &expired {
        let (_, again) = request(&app, "POST", PASS, json!({"run_ids":[run]})).await;
        assert_eq!(again["results"][0]["reason"], "run_not_found", "{again}");
    }
    let (status, export) = request(&app, "GET", &format!("/api/workflows/kept/runs/{kept}/history/export"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{export}");
    assert!(!export["history_events"].as_array().unwrap().is_empty());
}

/// Server #1365: retention deletes a pruned run's events but keeps its
/// `last_history_sequence`. Its history page is empty and final, as published
/// PHP's query finds no events, and says the run is pruned as its export does.
/// It is not refused as an event larger than the page budget.
#[tokio::test]
async fn a_pruned_run_history_page_is_empty_and_final() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let pruned = completed_run(&app, "history-pruned").await;
    let kept = completed_run(&app, "history-kept").await;
    let history = |workflow: &str, run: &str, query: &str| format!("/api/workflows/{workflow}/runs/{run}/history{query}");

    let (status, first) = request(&app, "GET", &history("history-pruned", &pruned, "?page_size=1"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{first}");
    let token = first["next_page_token"].as_str().unwrap_or_else(|| panic!("{first}")).replace('+', "%2B").replace('=', "%3D");
    let (status, retained) = request(&app, "GET", &history("history-kept", &kept, ""), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{retained}");

    let (status, report) = request(&app, "POST", PASS, json!({"run_ids":[pruned]})).await;
    assert_eq!(status, StatusCode::OK, "{report}");
    assert_eq!(report["pruned"], 1, "{report}");

    // The first page, a later page from a token issued before the prune, and
    // a waiting read all answer at once with the same empty, final page.
    for query in [String::new(), "?page_size=1".into(), format!("?next_page_token={token}"), "?wait_new_event=1".into()] {
        let (status, page) = request(&app, "GET", &history("history-pruned", &pruned, &query), Value::Null).await;
        assert_eq!(status, StatusCode::OK, "{query}: {page}");
        assert_eq!(
            [&page["workflow_id"], &page["run_id"], &page["events"], &page["next_page_token"]],
            [&json!("history-pruned"), &json!(pruned), &json!([]), &Value::Null],
            "{query}: {page}"
        );
        assert_eq!([&page["history_complete"], &page["details_state"]], [&json!(false), &json!("pruned")], "{query}: {page}");
    }
    let bundle = export(&app, "history-pruned", &pruned).await;
    assert_eq!([&bundle["history_complete"], &bundle["workflow"]["details_state"]], [&json!(false), &json!("pruned")]);

    // A retained run's page is unchanged and carries no pruning state.
    let (status, after) = request(&app, "GET", &history("history-kept", &kept, ""), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{after}");
    assert_eq!(after, retained);
    for field in ["history_complete", "details_state"] {
        assert!(after.get(field).is_none(), "{field}: {after}");
    }
    runtime.close().await;
    database.remove().await;
}
