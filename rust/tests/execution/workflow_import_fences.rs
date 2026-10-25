//! Server #765: an import into one namespace never reads, links or updates
//! another namespace's rows; the import route admits only admins; and the
//! signing key never travels in the URL. Each test runs on the selected
//! execution backend (SQLite, PostgreSQL or MySQL).
use super::*;
use sha2::{Digest, Sha256};

const FIXTURE: &str = include_str!("../../../tests/Fixtures/ServerParity/workflow-import.json");
const ROUTE: &str = "/api/workflows/import/embedded-v2";
const CREATED_AT: &str = "2026-10-24T14:45:59.849817Z";

/// PHP's canonical bundle JSON (`json-recursive-ksort-v1` under
/// `JSON_UNESCAPED_SLASHES`): maps sorted by key, an empty map as `[]`,
/// and every non-ASCII character as UTF-16 escapes.
fn canonical(value: &Value) -> String {
    fn string(text: &str) -> String {
        let mut out = String::from('"');
        for character in text.chars() {
            match character {
                '"' => out.push_str("\\\""),
                '\\' => out.push_str("\\\\"),
                '\u{8}' => out.push_str("\\b"),
                '\u{c}' => out.push_str("\\f"),
                '\n' => out.push_str("\\n"),
                '\r' => out.push_str("\\r"),
                '\t' => out.push_str("\\t"),
                character if (character as u32) < 0x20 || (character as u32) > 0x7f => {
                    let mut units = [0u16; 2];
                    for unit in character.encode_utf16(&mut units) {
                        out.push_str(&format!("\\u{unit:04x}"));
                    }
                }
                character => out.push(character),
            }
        }
        out.push('"');
        out
    }
    match value {
        Value::Object(map) if map.is_empty() => "[]".into(),
        Value::Object(map) => {
            let mut keys: Vec<&String> = map.keys().collect();
            keys.sort();
            let entries: Vec<String> = keys.iter().map(|key| format!("{}:{}", string(key), canonical(&map[*key]))).collect();
            format!("{{{}}}", entries.join(","))
        }
        Value::Array(items) => format!("[{}]", items.iter().map(canonical).collect::<Vec<_>>().join(",")),
        Value::String(text) => string(text),
        other => other.to_string(),
    }
}

/// A bundle of the fixture's `template` with fresh identities: each `{u:N}`
/// label a new lowercase id and each `{U:N}` an uppercase one (unless
/// `fixed` names it), `{instance}` the given instance, and the checksum
/// recomputed after `mutate`.
fn bundle(template: &str, instance: &str, fixed: &[(&str, &str)], mutate: impl FnOnce(&mut Value)) -> Value {
    let fixture: Value = serde_json::from_str(FIXTURE).unwrap();
    let text = fixture["workflow_import"]["bundles"][template].to_string();
    let salt = ulid::Ulid::new().to_string()[..12].to_owned();
    let label = regex_lite::Regex::new(r"\{([uU]):(\d+)\}|\{instance\}").unwrap();
    let text = label.replace_all(&text, |found: &regex_lite::Captures| {
        let whole = &found[0];
        if whole == "{instance}" {
            return instance.to_owned();
        }
        if let Some((_, to)) = fixed.iter().find(|(from, _)| *from == whole) {
            return (*to).to_owned();
        }
        let id = format!("{salt}{:014}", found[2].parse::<u64>().unwrap());
        if &found[1] == "U" { id.to_uppercase() } else { id.to_lowercase() }
    });
    let mut document: Value = serde_json::from_str(&text).unwrap();
    mutate(&mut document);
    document.as_object_mut().unwrap().remove("integrity");
    let checksum: String = Sha256::digest(canonical(&document).as_bytes()).iter().map(|byte| format!("{byte:02x}")).collect();
    document["integrity"] = json!({"canonicalization":"json-recursive-ksort-v1","checksum_algorithm":"sha256",
        "checksum":checksum,"signature_algorithm":null,"signature":null,"key_id":null});
    document
}

fn fresh_id() -> String {
    ulid::Ulid::new().to_string().to_lowercase()
}

fn run_of(document: &Value) -> String {
    document["workflow"]["run_id"].as_str().unwrap().to_owned()
}

/// A lineage entry naming `other` as the related run on `side`.
fn link(side: &str, other_instance: &str, other_run: &str) -> Value {
    let other = if side == "parents" { "parent" } else { "child" };
    let mut entry = json!({"id":fresh_id(),"type":if side == "parents" { "child" } else { "continue_as_new" },
        "sequence":1,"is_primary_parent":true,"created_at":CREATED_AT});
    entry[format!("{other}_workflow_instance_id")] = json!(other_instance);
    entry[format!("{other}_workflow_run_id")] = json!(other_run);
    entry
}

async fn import(app: &Router, namespace: &str, document: &Value) -> (StatusCode, Value) {
    namespaces::scoped(app, namespace, "POST", ROUTE, json!({"bundle": document})).await
}

/// Everything a namespace shows of one run: its exported bundle, without
/// the export time and the checksum over it.
async fn snapshot(app: &Router, namespace: &str, workflow: &str, run: &str) -> Value {
    let path = format!("/api/workflows/{workflow}/runs/{run}/history/export");
    let (status, mut export) = namespaces::scoped(app, namespace, "GET", &path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{export}");
    for key in ["exported_at", "integrity"] {
        export.as_object_mut().unwrap().remove(key);
    }
    export
}

async fn absent(app: &Router, namespace: &str, workflow: &str, run: &str) {
    let path = format!("/api/workflows/{workflow}/runs/{run}");
    let (status, body) = namespaces::scoped(app, namespace, "GET", &path, Value::Null).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "{body}");
}

fn refused(report: &Value, rule: &str, text: &str) {
    assert_eq!(report["status"], "rejected", "{report}");
    assert!(report["rows"].as_object().unwrap().values().all(|count| count == 0), "{report}");
    let errors = report["eligibility"]["errors"].as_array().unwrap();
    assert!(errors.iter().any(|error| error["rule"] == rule && error["message"].as_str().unwrap().contains(text)),
        "{rule} / {text}: {report}");
}

impl TestDatabase {
    /// Rewrites a stored row the way no request can, binding the `$n`
    /// placeholders in order (as `?` on MySQL).
    async fn forge(&self, sql: &'static str, binds: &[&str]) -> u64 {
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!("sqlite://{}", dir.path().join("runtime.sqlite").display()))
                    .await
                    .unwrap();
                let mut query = sqlx::query(sql);
                for bind in binds {
                    query = query.bind(*bind);
                }
                let affected = query.execute(&pool).await.unwrap().rows_affected();
                pool.close().await;
                affected
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
                let mut query = sqlx::query(sql);
                for bind in binds {
                    query = query.bind(*bind);
                }
                let affected = query.execute(&pool).await.unwrap().rows_affected();
                pool.close().await;
                affected
            }
            Self::MySql { options, .. } => {
                let sql = regex_lite::Regex::new(r"\$\d+").unwrap().replace_all(sql, "?").into_owned();
                let pool = sqlx::mysql::MySqlPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
                let mut query = sqlx::query(sqlx::AssertSqlSafe(sql));
                for bind in binds {
                    query = query.bind(*bind);
                }
                let affected = query.execute(&pool).await.unwrap().rows_affected();
                pool.close().await;
                affected
            }
        }
    }
}

#[tokio::test]
async fn lineage_into_another_namespace_is_refused_in_both_directions() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    namespaces::create(&app, "beta").await;
    let foreign = namespaces::start_scoped(&app, "beta", "beta-lineage").await;
    let foreign_run = foreign["run_id"].as_str().unwrap().to_owned();
    let before = snapshot(&app, "beta", "beta-lineage", &foreign_run).await;
    // Beta's run as a parent, as a child, and beta's instance with a run
    // that does not exist yet: each fails the whole import.
    for (index, (side, other_run, message)) in [
        ("parents", foreign_run.as_str(), "names a run of another namespace"),
        ("children", foreign_run.as_str(), "names a run of another namespace"),
        ("parents", "01ksnotyetimported000000000", "names an instance of another namespace"),
    ]
    .into_iter()
    .enumerate()
    {
        let instance = format!("wi-foreign-lineage-{index}");
        let document = bundle("terminated", &instance, &[], |document| {
            document["links"][side] = json!([link(side, "beta-lineage", other_run)]);
        });
        let (status, report) = import(&app, "default", &document).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{report}");
        refused(&report, "target.write_failed", message);
        absent(&app, "default", &instance, &run_of(&document)).await;
    }
    // The imported run itself as its own parent.
    let document = bundle("terminated", "wi-self-lineage", &[], |document| {
        let run = run_of(document);
        document["links"]["parents"] = json!([link("parents", "wi-self-lineage", &run)]);
    });
    let (status, report) = import(&app, "default", &document).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{report}");
    refused(&report, "references.self_link", "names the imported run as its own parent");
    absent(&app, "default", "wi-self-lineage", &run_of(&document)).await;
    assert_eq!(snapshot(&app, "beta", "beta-lineage", &foreign_run).await, before);
}

#[tokio::test]
async fn lineage_links_only_existing_runs_of_one_namespace() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    namespaces::create(&app, "beta").await;
    // A parent and its child in one namespace: the link is written.
    let parent = bundle("terminated", "wi-parent", &[], |_| {});
    let (status, report) = import(&app, "default", &parent).await;
    assert_eq!(status, StatusCode::CREATED, "{report}");
    let parent_run = run_of(&parent);
    let child = bundle("terminated", "wi-child", &[], |document| {
        document["links"]["parents"] = json!([link("parents", "wi-parent", &parent_run)]);
    });
    let (status, report) = import(&app, "default", &child).await;
    assert_eq!(status, StatusCode::CREATED, "{report}");
    assert_eq!(report["rows"]["workflow_links"], 1, "{report}");
    let exported = snapshot(&app, "default", "wi-child", &run_of(&child)).await;
    assert_eq!(exported["links"]["parents"][0]["parent_workflow_run_id"], json!(parent_run), "{exported}");
    // A parent no namespace holds yet: the child imports without the link,
    // which waits for the parent's own import.
    let pending_run = fresh_id();
    let orphan = bundle("terminated", "wi-orphan", &[], |document| {
        document["links"]["parents"] = json!([link("parents", "wi-later-parent", &pending_run)]);
    });
    let (status, report) = import(&app, "default", &orphan).await;
    assert_eq!(status, StatusCode::CREATED, "{report}");
    assert_eq!(report["rows"]["workflow_links"], 0, "{report}");
    let orphan_before = snapshot(&app, "default", "wi-orphan", &run_of(&orphan)).await;
    assert_eq!(orphan_before["links"]["parents"], json!([]), "{orphan_before}");
    // That run, imported later into another namespace, is never linked.
    let later = bundle("terminated", "wi-later-parent", &[("{u:1}", pending_run.as_str())], |_| {});
    let (status, report) = import(&app, "beta", &later).await;
    assert_eq!(status, StatusCode::CREATED, "{report}");
    assert_eq!(snapshot(&app, "default", "wi-orphan", &run_of(&orphan)).await, orphan_before);
    let foreign = snapshot(&app, "beta", "wi-later-parent", &pending_run).await;
    assert_eq!((foreign["links"]["parents"].clone(), foreign["links"]["children"].clone()), (json!([]), json!([])), "{foreign}");
}

// Two bundles that each carry the same parent/child link, imported at the
// same moment: native serializes durable transactions, so whichever imports
// second sees the first run committed and writes the link exactly once.
#[tokio::test]
async fn reciprocal_imports_at_the_same_moment_write_their_link_once() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let other = database.open().await.unwrap();
    let (first, second) = (router(runtime), router(other));
    let (parent_run, child_run, link_id) = (fresh_id(), fresh_id(), ulid::Ulid::new().to_string());
    let entry = |side: &str, instance: &str, run: &str| {
        let mut entry = link(side, instance, run);
        entry["id"] = json!(link_id);
        entry["type"] = json!("child");
        entry
    };
    let parent = bundle("terminated", "wi-reciprocal-parent", &[("{u:1}", parent_run.as_str())], |document| {
        document["links"]["children"] = json!([entry("children", "wi-reciprocal-child", &child_run)]);
    });
    let child = bundle("terminated", "wi-reciprocal-child", &[("{u:1}", child_run.as_str())], |document| {
        document["links"]["parents"] = json!([entry("parents", "wi-reciprocal-parent", &parent_run)]);
    });
    let ((parent_status, parent_report), (child_status, child_report)) =
        tokio::join!(import(&first, "default", &parent), import(&second, "default", &child));
    assert_eq!(parent_status, StatusCode::CREATED, "{parent_report}");
    assert_eq!(child_status, StatusCode::CREATED, "{child_report}");
    let written = parent_report["rows"]["workflow_links"].as_i64().unwrap() + child_report["rows"]["workflow_links"].as_i64().unwrap();
    assert_eq!(written, 1, "{parent_report} {child_report}");
    let exported = snapshot(&first, "default", "wi-reciprocal-child", &child_run).await;
    let parents = exported["links"]["parents"].as_array().unwrap();
    assert_eq!(parents.len(), 1, "{exported}");
    assert_eq!((parents[0]["id"].clone(), parents[0]["parent_workflow_run_id"].clone()), (json!(link_id), json!(parent_run)));
}

#[tokio::test]
async fn foreign_row_references_are_refused_before_anything_is_written() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    namespaces::create(&app, "beta").await;
    let foreign = namespaces::start_scoped(&app, "beta", "beta-rows").await;
    let foreign_run = foreign["run_id"].as_str().unwrap().to_owned();
    let terminate = format!("/api/workflows/beta-rows/runs/{foreign_run}/terminate");
    let (status, body) = namespaces::scoped(&app, "beta", "POST", &terminate, json!({"reason":"fence"})).await;
    assert!(status.is_success(), "{body}");
    let before = snapshot(&app, "beta", "beta-rows", &foreign_run).await;
    let foreign_command = before["commands"][0]["id"].as_str().unwrap().to_owned();
    let foreign_failure = before["failures"][0]["id"].as_str().expect("termination records a failure").to_owned();
    type Mutation = Box<dyn Fn(&mut Value)>;
    let id = |value: &str| json!(value);
    let command = foreign_command.clone();
    let update = move |command_id: Value, failure_id: Value| {
        json!([{"id":fresh_id(),"command_id":command_id,"status":"failed","name":"set","command_sequence":3,
            "failure_id":failure_id,"accepted_at":CREATED_AT,"closed_at":CREATED_AT}])
    };
    let cases: Vec<(&str, Mutation, &str, &str)> = vec![
        ("signal command", Box::new({ let command = command.clone(); move |d: &mut Value| d["signals"][0]["command_id"] = id(&command) }),
            "references.unresolved", "signals.0.command_id must name a signal command"),
        ("update command", Box::new({ let command = command.clone(); move |d: &mut Value| d["updates"] = update(id(&command), Value::Null) }),
            "references.unresolved", "updates.0.command_id must name an update command"),
        ("activity task", Box::new({ let command = command.clone(); move |d: &mut Value| d["tasks"][1]["payload"]["activity_execution_id"] = id(&command) }),
            "references.unresolved", "tasks.1.payload.activity_execution_id must name an activity"),
        ("current attempt", Box::new({ let command = command.clone(); move |d: &mut Value| d["activities"][0]["current_attempt_id"] = id(&command) }),
            "references.unresolved", "activities.0.current_attempt_id must name one of the activity's own attempts"),
        ("event command", Box::new({ let command = command.clone(); move |d: &mut Value| d["history_events"][0]["workflow_command_id"] = id(&command) }),
            "references.unresolved", "history_events.0.workflow_command_id must name a command"),
        ("resolved run", Box::new({ let run = foreign_run.clone(); move |d: &mut Value| d["commands"][1]["resolved_run_id"] = id(&run) }),
            "references.foreign_run", "commands.1.resolved_run_id must name the imported run"),
        ("requested run", Box::new({ let run = foreign_run.clone(); move |d: &mut Value| d["commands"][1]["requested_run_id"] = id(&run) }),
            "target.write_failed", "commands.1.requested_run_id is not a run of this workflow in this namespace"),
        // The bundle's own failed update, naming beta's termination failure.
        ("update failure", Box::new({ let failure = foreign_failure.clone(); move |d: &mut Value| {
            let command_id = fresh_id();
            d["commands"].as_array_mut().unwrap().push(json!({"id":command_id,"sequence":3,"type":"update",
                "target_scope":"instance","status":"accepted","accepted_at":CREATED_AT}));
            d["updates"] = update(json!(command_id), id(&failure));
        } }), "target.write_failed", "updates.0.failure_id names a failure of another run"),
    ];
    for (index, (case, mutation, rule, text)) in cases.into_iter().enumerate() {
        let instance = format!("wi-foreign-rows-{index}");
        let document = bundle("rich", &instance, &[], |document| mutation(document));
        let (status, report) = import(&app, "default", &document).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{case}: {report}");
        refused(&report, rule, text);
        absent(&app, "default", &instance, &run_of(&document)).await;
    }
    // The untouched template imports, so each refusal came from its reference.
    let document = bundle("rich", "wi-foreign-rows-control", &[], |_| {});
    let (status, report) = import(&app, "default", &document).await;
    assert_eq!(status, StatusCode::CREATED, "{report}");
    assert_eq!(snapshot(&app, "beta", "beta-rows", &foreign_run).await, before);
}

/// Beta's run and its start command, which the forged rows below name.
/// Beta's run and its accepted, unapplied command of `kind`: the first
/// message of its run, as the forged row's own command is of its run, so
/// only the command's workflow tells them apart.
async fn foreign_target(app: &Router, workflow: &str, kind: &str) -> (String, String, Value) {
    namespaces::create(app, "beta").await;
    let int = json!({"name":"value","type":"int","required":true,"variadic":false,"default_available":false,"allows_null":false});
    let (status, body) = namespaces::scoped(app, "beta", "POST", "/api/worker/register", json!({
        "worker_id":"beta-worker","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"capabilities":["workflow_updates"],"workflow_command_contracts":{"echo":{
            "signals":["finish"],"signal_contracts":[{"name":"finish","parameters":[int]}],
            "updates":["set"],"update_validators":[],"update_contracts":[{"name":"set","parameters":[int]}]}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let foreign = namespaces::start_scoped(app, "beta", workflow).await;
    let run = foreign["run_id"].as_str().unwrap().to_owned();
    let (status, polled) = namespaces::scoped(app, "beta", "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"beta-worker","task_queue":"test","timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    let task = &polled["task"];
    let complete = format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap());
    let (status, body) = namespaces::scoped(app, "beta", "POST", &complete,
        completion(task, json!([{"type":"open_signal_wait","signal_name":"finish"}]))).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let input = envelope(Payload::Array(vec![Payload::Long(5)]));
    let (path, body) = match kind {
        "update" => (format!("/api/workflows/{workflow}/runs/{run}/update/set"), json!({"input":input,"wait_for":"accepted"})),
        _ => (format!("/api/workflows/{workflow}/runs/{run}/signal/finish"), json!({"input":input})),
    };
    let (status, accepted) = namespaces::scoped(app, "beta", "POST", &path, body).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{accepted}");
    let before = snapshot(app, "beta", workflow, &run).await;
    let stored = before["commands"].as_array().unwrap().iter().find(|row| row["type"] == json!(kind)).unwrap().clone();
    assert_eq!((stored["message_sequence"].clone(), stored["applied_at"].clone()), (json!(1), Value::Null), "{before}");
    (run, stored["id"].as_str().unwrap().to_owned(), before)
}

// Defense in depth for rows no import can now write: a signal record that
// names another namespace's command is refused when it applies, and the
// foreign command stays as it was.
#[tokio::test]
async fn a_signal_never_applies_to_another_namespaces_command() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (foreign_run, foreign_command, before) = foreign_target(&app, "beta-signal-target", "update").await;
    update_worker(&app, "forged-signal-worker").await;
    let started = start(&app, "forged-signal").await;
    let run = started["run_id"].as_str().unwrap().to_owned();
    open_update_wait(&app, "forged-signal-worker").await;
    let path = format!("/api/workflows/forged-signal/runs/{run}/signal/finish");
    let (status, signal) = request(&app, "POST", &path, json!({"input":envelope(Payload::Array(vec![Payload::Long(7)]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{signal}");
    let forged = database.forge("UPDATE workflow_signal_records SET workflow_command_id=$1 WHERE workflow_run_id=$2",
        &[&foreign_command, &run]).await;
    assert_eq!(forged, 1);
    let task = poll(&app, "forged-signal-worker", "workflow").await;
    assert!(task["workflow_signal_id"].is_string(), "{task}");
    let (status, refusal) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!(status, StatusCode::CONFLICT, "{refusal}");
    assert_eq!(refusal["reason"], "signal_command_unavailable", "{refusal}");
    assert_eq!(snapshot(&app, "beta", "beta-signal-target", &foreign_run).await, before);
}

#[tokio::test]
async fn an_update_never_applies_to_another_namespaces_command() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (foreign_run, foreign_command, before) = foreign_target(&app, "beta-update-target", "signal").await;
    update_worker(&app, "forged-update-worker").await;
    let started = start(&app, "forged-update").await;
    let run = started["run_id"].as_str().unwrap().to_owned();
    open_update_wait(&app, "forged-update-worker").await;
    let path = format!("/api/workflows/forged-update/runs/{run}/update/set");
    let (status, admitted) = request(&app, "POST", &path,
        json!({"input":envelope(Payload::Array(vec![Payload::Long(7)])),"wait_for":"accepted"})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{admitted}");
    let update_id = admitted["update_id"].as_str().unwrap().to_owned();
    let forged = database.forge("UPDATE workflow_updates SET workflow_command_id=$1 WHERE id=$2", &[&foreign_command, &update_id]).await;
    assert_eq!(forged, 1);
    let task = poll(&app, "forged-update-worker", "workflow").await;
    assert_eq!(task["workflow_update_id"], json!(update_id), "{task}");
    let commands = json!([{"type":"complete_update","update_id":update_id,"result":envelope(Payload::Long(1))}]);
    let (status, refusal) = finish_task(&app, &task, commands).await;
    assert_eq!(status, StatusCode::CONFLICT, "{refusal}");
    assert_eq!(refusal["reason"], "update_command_unavailable", "{refusal}");
    assert_eq!(snapshot(&app, "beta", "beta-update-target", &foreign_run).await, before);
}

#[tokio::test]
async fn only_admin_tokens_reach_the_import_controller() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.with_role_tokens(
        Some("worker-token".into()),
        Some("operator-token".into()),
        Some("admin-token".into()),
    ));
    let document = bundle("terminated", "wi-roles", &[], |_| {});
    let run = run_of(&document);
    let describe = format!("/api/workflows/wi-roles/runs/{run}");
    for token in ["worker-token", "operator-token"] {
        let (status, _, body) = admission::admission_http(&app, "POST", ROUTE, false, Some(token), Some("2"),
            Some("default"), json!({"bundle": document})).await;
        assert_eq!(status, StatusCode::FORBIDDEN, "{token}: {body}");
        // The guard answered: no import report, nothing written.
        assert!(body.get("schema").is_none() && body.get("rows").is_none(), "{token}: {body}");
        let (status, _, body) = admission::admission_http(&app, "GET", &describe, false, Some("admin-token"), Some("2"),
            Some("default"), Value::Null).await;
        assert_eq!(status, StatusCode::NOT_FOUND, "{token}: {body}");
    }
    let (status, _, report) = admission::admission_http(&app, "POST", ROUTE, false, Some("admin-token"), Some("2"),
        Some("default"), json!({"bundle": document})).await;
    assert_eq!(status, StatusCode::CREATED, "{report}");
    assert_eq!(report["status"], "imported", "{report}");
}

#[tokio::test]
async fn the_signing_key_is_refused_in_the_url_and_never_repeated() {
    const SENTINEL: &str = "sentinel-signing-key-f3a9c1";
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let document = bundle("terminated", "wi-signing-key", &[], |_| {});
    let run = run_of(&document);
    for query in [format!("?signing_key={SENTINEL}"), format!("?dry_run=1&signing_key%5B%5D={SENTINEL}")] {
        let (status, body) = namespaces::scoped(&app, "default", "POST", &format!("{ROUTE}{query}"),
            json!({"bundle": document})).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
        assert_eq!(body["reason"], "signing_key_in_query", "{body}");
        assert!(!body.to_string().contains(SENTINEL), "{body}");
        absent(&app, "default", "wi-signing-key", &run).await;
    }
    // From the body the key is read, and the report never repeats it.
    let (status, report) = namespaces::scoped(&app, "default", "POST", ROUTE,
        json!({"bundle": document, "signing_key": SENTINEL})).await;
    assert_eq!(status, StatusCode::CREATED, "{report}");
    assert!(!report.to_string().contains(SENTINEL), "{report}");
}
