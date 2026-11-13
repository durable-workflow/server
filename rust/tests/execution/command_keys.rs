//! Server #934: a caller's request_id makes a signal or update retry safe. A
//! retry with the same command intent gets the original receipt, before any
//! run gate and with nothing written; the same key with a different intent
//! is a typed conflict, also with nothing written.
use super::*;

fn input(value: i64, key: Option<&str>) -> Value {
    let mut body = json!({"input":envelope(Payload::Array(vec![Payload::Long(value)]))});
    if let Some(key) = key {
        body["request_id"] = json!(key);
    }
    body
}

async fn signal(app: &Router, workflow: &str, name: &str, body: Value) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/workflows/{workflow}/signal/{name}"), body).await
}

async fn received(app: &Router, started: &Value) -> usize {
    run_history(app, &started["workflow_id"], &started["run_id"]).await.as_array().unwrap().iter()
        .filter(|event| event["event_type"] == "SignalReceived").count()
}

fn assert_conflict(response: &(StatusCode, Value), retained: &Value, part: &str) {
    assert_eq!(response.0, StatusCode::CONFLICT, "{}", response.1);
    assert_eq!((response.1["reason"].clone(), response.1["outcome"].clone()),
        (json!("request_id_conflict"), json!("rejected_request_id_conflict")), "{}", response.1);
    assert_eq!(response.1["retained_command_id"], retained["command_id"], "{}", response.1);
    assert_eq!(response.1["conflict"], json!([part]), "{}", response.1);
}

#[tokio::test]
async fn signal_retries_return_the_original_receipt_and_conflicts_write_nothing() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "keyed-worker").await;
    let started = start(&app, "keyed-signals").await;

    let original = signal(&app, "keyed-signals", "finish", input(1, Some("lost-response"))).await;
    assert_eq!(original.0, StatusCode::ACCEPTED, "{}", original.1);
    let retry = signal(&app, "keyed-signals", "finish", input(1, Some("lost-response"))).await;
    assert_eq!(retry.0, StatusCode::ACCEPTED, "{}", retry.1);
    for field in ["command_id", "run_id", "command_sequence", "outcome", "signal_name"] {
        assert_eq!(retry.1[field], original.1[field], "{field}: {}", retry.1);
    }
    assert_eq!((retry.1["retained"].clone(), retry.1["intent_verified"].clone()), (json!(true), json!(true)), "{}", retry.1);
    assert_eq!(received(&app, &started).await, 1);

    // The same key with other arguments or another name is refused unwritten.
    let before = table_counts(&database).await;
    assert_conflict(&signal(&app, "keyed-signals", "finish", input(2, Some("lost-response"))).await, &original.1, "arguments");
    assert_conflict(&signal(&app, "keyed-signals", "other", input(1, Some("lost-response"))).await, &original.1, "name");
    assert_eq!(table_counts(&database).await, before, "a conflict writes nothing");

    // Without a key every signal is its own command, as before.
    for _ in 0..2 {
        assert_eq!(signal(&app, "keyed-signals", "finish", input(1, None)).await.0, StatusCode::ACCEPTED);
    }
    assert_eq!(received(&app, &started).await, 3);

    // A key at the 255-character request_id limit fits: it is stored as a
    // fixed-width token, not in the 191-character column as given.
    let long = "k".repeat(255);
    let first = signal(&app, "keyed-signals", "finish", input(1, Some(&long))).await;
    assert_eq!(first.0, StatusCode::ACCEPTED, "{}", first.1);
    assert_eq!(signal(&app, "keyed-signals", "finish", input(1, Some(&long))).await.1["command_id"], first.1["command_id"]);

    // Concurrent retries admit one signal and share its receipt.
    let racing: Vec<_> = (0..4).map(|_| signal(&app, "keyed-signals", "finish", input(5, Some("racing")))).collect();
    let answers = futures_util::future::join_all(racing).await;
    for answer in &answers {
        assert_eq!(answer.0, StatusCode::ACCEPTED, "{}", answer.1);
        assert_eq!(answer.1["command_id"], answers[0].1["command_id"], "{}", answer.1);
    }
    assert_eq!(received(&app, &started).await, 5);

    // After the run closes, the retry still gets the original receipt instead
    // of a closed-run refusal, and nothing is recorded.
    // Buffered signals reopen the run's task until each has been seen.
    for _ in 0..10 {
        let task = poll(&app, "keyed-worker", "workflow").await;
        if task.is_null() {
            break;
        }
        let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
    }
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert!(history.as_array().unwrap().iter().any(|event| event["event_type"] == "WorkflowCompleted"), "{history}");
    let before = table_counts(&database).await;
    let closed = signal(&app, "keyed-signals", "finish", input(1, Some("lost-response"))).await;
    assert_eq!((closed.0, closed.1["command_id"].clone()), (StatusCode::ACCEPTED, original.1["command_id"].clone()), "{}", closed.1);
    assert_eq!(table_counts(&database).await, before);
    let fresh = signal(&app, "keyed-signals", "finish", input(1, Some("after-close"))).await;
    assert_eq!((fresh.0, fresh.1["reason"].clone()), (StatusCode::CONFLICT, json!("run_not_active")), "{}", fresh.1);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn keyed_receipts_survive_continue_as_new() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "keyed-worker").await;
    let started = start(&app, "keyed-continued").await;
    let original = signal(&app, "keyed-continued", "finish", input(1, Some("before-continue"))).await;
    assert_eq!(original.0, StatusCode::ACCEPTED, "{}", original.1);
    let task = poll(&app, "keyed-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"continue_as_new"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let retry = signal(&app, "keyed-continued", "finish", input(1, Some("before-continue"))).await;
    assert_eq!(retry.0, StatusCode::ACCEPTED, "{}", retry.1);
    assert_eq!((retry.1["command_id"].clone(), retry.1["run_id"].clone()),
        (original.1["command_id"].clone(), started["run_id"].clone()), "{}", retry.1);
    assert_conflict(&signal(&app, "keyed-continued", "finish", input(3, Some("before-continue"))).await, &original.1, "arguments");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn update_retries_with_different_arguments_are_conflicts() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "keyed-update-worker").await;
    start(&app, "keyed-update").await;
    open_update_wait(&app, "keyed-update-worker").await;
    let path = "/api/workflows/keyed-update/update/set";
    let body = |value: i64, key: &str| {
        let mut body = input(value, Some(key));
        body["wait_for"] = json!("accepted");
        body
    };
    let original = request(&app, "POST", path, body(1, "update-key")).await;
    assert_eq!(original.0, StatusCode::ACCEPTED, "{}", original.1);
    let retry = request(&app, "POST", path, body(1, "update-key")).await;
    assert_eq!((retry.1["update_id"].clone(), retry.1["command_id"].clone()),
        (original.1["update_id"].clone(), original.1["command_id"].clone()), "{}", retry.1);
    // Before #934 this retry silently got the original receipt.
    let before = table_counts(&database).await;
    assert_conflict(&request(&app, "POST", path, body(2, "update-key")).await, &original.1, "arguments");
    assert_eq!(table_counts(&database).await, before, "a conflict writes nothing");
    // A key longer than the 191-character column, as a token, is admitted.
    let long = "u".repeat(200);
    let admitted = request(&app, "POST", path, body(4, &long)).await;
    assert_eq!(admitted.0, StatusCode::ACCEPTED, "{}", admitted.1);
    assert_eq!(request(&app, "POST", path, body(4, &long)).await.1["update_id"], admitted.1["update_id"]);
    runtime.close().await;
    database.remove().await;
}

/// Retention removes a pruned run's request-key digests with its commands, so
/// an argument digest never outlives the command it guards. Another run's
/// digest stays, and the pruned report keeps PHP's keys.
#[tokio::test]
async fn retention_prunes_request_key_digests_with_their_commands() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let pruned = start(&app, "pruned-keys").await;
    start(&app, "kept-keys").await;
    for workflow in ["pruned-keys", "kept-keys"] {
        let sent = signal(&app, workflow, "finish", input(1, Some("retained-key"))).await;
        assert_eq!(sent.0, StatusCode::ACCEPTED, "{}", sent.1);
    }
    const INTENTS: &str = "SELECT COUNT(*) FROM dw_command_intents";
    assert_eq!(task_retry::rows(&database, INTENTS).await, 2);

    let (status, body) = request(&app, "POST", "/api/workflows/pruned-keys/terminate", json!({"reason":"close"})).await;
    assert!(status.is_success(), "{body}");
    let (status, report) = request(&app, "POST", "/api/system/retention/pass",
        json!({"run_ids":[pruned["run_id"]]})).await;
    assert_eq!(status, StatusCode::OK, "{report}");
    assert_eq!(report["pruned"], 1, "{report}");
    assert!(!report.to_string().contains("intent"), "the report keeps PHP's keys: {report}");
    assert_eq!(task_retry::rows(&database, INTENTS).await, 1, "only the pruned run's digest is gone");
    const KEPT: &str = "SELECT COUNT(*) FROM dw_command_intents i JOIN workflow_commands c ON c.id=i.command_id WHERE c.workflow_instance_id='kept-keys'";
    assert_eq!(task_retry::rows(&database, KEPT).await, 1, "the other run keeps its digest");
}

#[tokio::test]
async fn unicode_request_keys_use_the_character_limit_and_keep_retry_identity() {
    for (kind, action) in [("signal", "signal/finish"), ("update", "update/set")] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        update_worker(&app, "unicode-key-worker").await;
        let started = start(&app, "unicode-keys").await;
        if kind == "update" {
            open_update_wait(&app, "unicode-key-worker").await;
        }
        let path = format!("/api/workflows/unicode-keys/{action}");
        for character in ["é", "🦀"] {
            let key = character.repeat(255);
            assert_eq!(key.chars().count(), 255);
            assert!(key.len() > 255);
            let original = request(&app, "POST", &path, input(1, Some(&key))).await;
            assert_eq!(original.0, StatusCode::ACCEPTED, "{kind}: {}", original.1);
            assert_eq!(original.1["request_id"], key);
            assert_eq!((original.1["retained"].clone(), original.1["intent_verified"].clone()),
                (json!(false), json!(true)), "{}", original.1);
            let before = table_counts(&database).await;
            let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
            let replies = futures_util::future::join_all((0..4).map(|_| {
                request(&app, "POST", &path, input(1, Some(&key)))
            })).await;
            for (status, reply) in replies {
                assert_eq!(status, StatusCode::ACCEPTED, "{reply}");
                assert_eq!(reply["command_id"], original.1["command_id"]);
                assert_eq!(reply["run_id"], original.1["run_id"]);
                assert_eq!(reply["request_id"], key);
                assert_eq!((reply["retained"].clone(), reply["intent_verified"].clone()),
                    (json!(true), json!(true)), "{reply}");
                if kind == "update" {
                    assert_eq!(reply["update_id"], original.1["update_id"]);
                }
            }
            assert_conflict(&request(&app, "POST", &path, input(2, Some(&key))).await,
                &original.1, "arguments");
            let too_long = character.repeat(256);
            let refused = request(&app, "POST", &path, input(1, Some(&too_long))).await;
            assert_eq!(refused.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", refused.1);
            assert_eq!(refused.1["reason"], "validation_failed", "{}", refused.1);
            assert_eq!(table_counts(&database).await, before);
            assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, history);
        }
        runtime.close().await;
        database.remove().await;
    }
}

/// The pre-3.9 writer stored a raw request_id and no intent row. Preserve
/// that exact shape without rewriting the original command or its history.
async fn legacy_key(database: &TestDatabase, command: &Value, key: &str) {
    let id = command["command_id"].as_str().unwrap();
    assert!(id.bytes().all(|byte| byte.is_ascii_alphanumeric()));
    assert!(key.bytes().all(|byte| byte.is_ascii_alphanumeric() || b"-:_".contains(&byte)));
    database.execute(sqlx::AssertSqlSafe(format!(
        "DELETE FROM dw_command_intents WHERE command_id='{id}'"))).await;
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE workflow_commands SET request_id='{key}' WHERE id='{id}'"))).await;
}

#[tokio::test]
async fn legacy_updates_read_back_unverified_intent_after_restart_close_and_continuation() {
    for close in [None, Some("complete_workflow"), Some("continue_as_new")] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        update_worker(&app, "legacy-worker").await;
        let started = start(&app, "legacy-update").await;
        open_update_wait(&app, "legacy-worker").await;
        let path = "/api/workflows/legacy-update/update/set";
        let original = request(&app, "POST", path, input(1, Some("legacy-update-key"))).await;
        assert_eq!(original.0, StatusCode::ACCEPTED, "{}", original.1);
        legacy_key(&database, &original.1, "legacy-update-key").await;
        if let Some(close) = close {
            let task = poll(&app, "legacy-worker", "workflow").await;
            let done = finish_task(&app, &task, json!([
                {"type":"complete_update","update_id":original.1["update_id"],"result":envelope(Payload::Null)},
                {"type":close,"result":envelope(Payload::Null)}])).await;
            assert_eq!(done.0, StatusCode::OK, "{}", done.1);
        }
        runtime.close().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let before = table_counts(&database).await;
        let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        // Unknown old arguments cannot establish equality. Read back the
        // original with that fact explicit, even for concurrent retries.
        let replies = futures_util::future::join_all((0..4).map(|_| {
            request(&app, "POST", path, input(999, Some("legacy-update-key")))
        })).await;
        for (status, reply) in replies {
            assert!(status.is_success(), "{reply}");
            for field in ["command_id", "update_id", "run_id", "accepted_at"] {
                assert_eq!(reply[field], original.1[field], "{field}: {reply}");
            }
            assert_eq!((reply["retained"].clone(), reply["intent_verified"].clone()),
                (json!(true), json!(false)), "{reply}");
            assert_eq!(reply["request_id"], "legacy-update-key");
            if close.is_some() {
                assert_eq!(reply["update_status"], "completed", "{reply}");
                assert_eq!(reply["result"], Value::Null, "completed null remains an outcome");
            }
        }
        let original_target = format!("/api/workflows/legacy-update/runs/{}/update/set", started["run_id"].as_str().unwrap());
        let selected = request(&app, "POST", &original_target, input(999, Some("legacy-update-key"))).await;
        assert!(selected.0.is_success(), "{}", selected.1);
        assert_eq!(selected.1["update_id"], original.1["update_id"]);
        assert_eq!(selected.1["intent_verified"], false);
        let changed_target = "/api/workflows/legacy-update/runs/00000000000000000000000000/update/set";
        assert_conflict(&request(&app, "POST", changed_target, input(1, Some("legacy-update-key"))).await,
            &original.1, "target");
        let changed_name = path.replace("/set", "/other");
        assert_conflict(&request(&app, "POST", &changed_name, input(1, Some("legacy-update-key"))).await,
            &original.1, "name");
        assert_eq!(table_counts(&database).await, before, "all retries and refusals write nothing");
        assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, history);
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn legacy_signals_do_not_honor_a_changed_target_or_fabricate_intent_equality() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "legacy-worker").await;
    let started = start(&app, "legacy-signal").await;
    let original = signal(&app, "legacy-signal", "finish", input(1, Some("legacy-signal-key"))).await;
    assert_eq!(original.0, StatusCode::ACCEPTED, "{}", original.1);
    legacy_key(&database, &original.1, "legacy-signal-key").await;
    let task = poll(&app, "legacy-worker", "workflow").await;
    let done = finish_task(&app, &task, json!([{"type":"continue_as_new"}])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let before = table_counts(&database).await;
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let retry = signal(&app, "legacy-signal", "finish", input(999, Some("legacy-signal-key"))).await;
    assert_eq!(retry.0, StatusCode::ACCEPTED, "{}", retry.1);
    assert_eq!(retry.1["command_id"], original.1["command_id"]);
    assert_eq!(retry.1["run_id"], original.1["run_id"]);
    assert_eq!((retry.1["retained"].clone(), retry.1["intent_verified"].clone()), (json!(true), json!(false)));
    assert_eq!(retry.1["request_id"], "legacy-signal-key");
    let target = "/api/workflows/legacy-signal/runs/00000000000000000000000000/signal/finish";
    assert_conflict(&request(&app, "POST", target, input(1, Some("legacy-signal-key"))).await, &original.1, "target");
    assert_eq!(table_counts(&database).await, before);
    assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, history);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn raw_and_token_identity_collisions_are_refused_without_selecting_a_receipt() {
    use sha2::{Digest, Sha256};
    let hash: String = Sha256::digest(b"token-key").iter().map(|byte| format!("{byte:02x}")).collect();
    for (kind, action) in [("signal", "signal/finish"), ("update", "update/set")] {
        let token = format!("{kind}-v1:{hash}");
        for shape in ["two-rows", "legacy-token", "modern-raw"] {
            let database = TestDatabase::new().await;
            let runtime = database.open().await.unwrap();
            let app = router(runtime.clone());
            update_worker(&app, "collision-worker").await;
            let started = start(&app, "key-collision").await;
            if kind == "update" {
                open_update_wait(&app, "collision-worker").await;
            }
            let path = format!("/api/workflows/key-collision/{action}");
            let original = request(&app, "POST", &path, input(1, Some("token-key"))).await;
            assert_eq!(original.0, StatusCode::ACCEPTED, "{}", original.1);
            let key = if shape == "modern-raw" {
                token.as_str()
            } else if shape == "legacy-token" {
                // A legacy caller deliberately used the bytes of another key's
                // modern token; those bytes are not proof of the modern key.
                legacy_key(&database, &original.1, &token).await;
                "token-key"
            } else {
                let legacy = request(&app, "POST", &path, input(2, Some("second-key"))).await;
                assert_eq!(legacy.0, StatusCode::ACCEPTED, "{}", legacy.1);
                legacy_key(&database, &legacy.1, "token-key").await;
                "token-key"
            };
            let before = table_counts(&database).await;
            let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
            let refused = request(&app, "POST", &path, input(1, Some(key))).await;
            assert_eq!(refused.0, StatusCode::CONFLICT, "{shape}: {}", refused.1);
            assert_eq!(refused.1["reason"], "request_id_ambiguous", "{shape}: {}", refused.1);
            assert_eq!(refused.1["intent_verified"], false);
            assert_eq!(refused.1["retryable"], false, "{shape}: {}", refused.1);
            assert!(refused.1["command_id"].is_null(), "no candidate is selected: {}", refused.1);
            assert_eq!(table_counts(&database).await, before);
            assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, history);
            runtime.close().await;
            database.remove().await;
        }
    }
}

#[tokio::test]
async fn legacy_key_case_collisions_never_return_another_keys_receipt() {
    for action in ["signal/finish", "update/set"] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        update_worker(&app, "case-worker").await;
        let started = start(&app, "case-keys").await;
        if action == "update/set" {
            open_update_wait(&app, "case-worker").await;
        }
        let path = format!("/api/workflows/case-keys/{action}");
        let original = request(&app, "POST", &path, input(1, Some("CaseSensitive"))).await;
        assert_eq!(original.0, StatusCode::ACCEPTED, "{}", original.1);
        legacy_key(&database, &original.1, "CaseSensitive").await;
        let before = table_counts(&database).await;
        let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        let exact = request(&app, "POST", &path, input(99, Some("CaseSensitive"))).await;
        assert_eq!(exact.1["command_id"], original.1["command_id"]);
        assert_eq!(exact.1["intent_verified"], false);
        let other = request(&app, "POST", &path, input(1, Some("casesensitive"))).await;
        if matches!(&database, TestDatabase::MySql { .. }) {
            // The default MySQL/MariaDB collation sees a raw-key match.
            // Refuse safely instead of reporting equality for other bytes.
            assert_eq!(other.0, StatusCode::CONFLICT, "{}", other.1);
            assert_eq!(other.1["reason"], "request_id_ambiguous");
            assert_eq!(other.1["retryable"], false);
            assert_eq!(table_counts(&database).await, before);
            assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, history);
        } else {
            assert_eq!(other.0, StatusCode::ACCEPTED, "{}", other.1);
            assert_ne!(other.1["command_id"], original.1["command_id"]);
        }
        runtime.close().await;
        database.remove().await;
    }
}
