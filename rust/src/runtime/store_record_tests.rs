//! History recording and described-command boundaries that a public route
//! cannot reach with mismatched identifiers.
use super::Store;
use crate::runtime::backend::Backend;
use crate::runtime::external_resolution::STORED_PREFIX;
use crate::runtime::history_presentation::PHP_EXTERNAL_REFERENCE;
use serde_json::{Value, json};
use std::collections::BTreeMap;

#[test]
fn described_commands_read_only_the_exact_native_stored_prefix() {
    // Each backend compares the first 30 characters, byte for byte, with the
    // whole prefix: a signal's in its signal record, a start's in its command.
    assert_eq!(STORED_PREFIX.len(), 30);
    let quoted = format!("'{STORED_PREFIX}'");
    for (backend, sql, signal, start) in [
        ("sqlite", <sqlx::Sqlite as Backend>::RUN_COMMANDS_SQL,
            "substr(s.arguments,1,30) COLLATE BINARY=", "substr(c.payload,1,30) COLLATE BINARY="),
        ("postgres", <sqlx::Postgres as Backend>::RUN_COMMANDS_SQL,
            "substr(s.arguments,1,30)=", "substr(c.payload,1,30)="),
        ("mysql", <sqlx::MySql as Backend>::RUN_COMMANDS_SQL,
            "CAST(SUBSTRING(s.arguments,1,30) AS BINARY)=", "CAST(SUBSTRING(c.payload,1,30) AS BINARY)="),
    ] {
        let expected = format!("CASE WHEN c.command_type='signal' AND {signal}{quoted} THEN s.arguments \
            WHEN c.command_type='start' AND {start}{quoted} THEN c.payload END");
        assert!(sql.contains(&expected), "{backend}: {sql}");
        // An imported PHP reference is matched by its exact 23-byte prefix,
        // and neither kind of reference is ever read as a command reason.
        let php = format!("{}'{PHP_EXTERNAL_REFERENCE}'", start.replace(",1,30)", ",1,23)"));
        assert!(sql.contains(&format!("CASE WHEN {php} THEN c.payload END AS php_payload_reference")), "{backend}: {sql}");
        assert!(sql.contains(&format!("AND NOT ({start}{quoted}) AND NOT ({php}) THEN c.payload END AS reason_payload")),
            "{backend}: {sql}");
        assert!(!sql.contains("LIKE"), "{backend}: {sql}");
    }
}

async fn recorded_task(store: &Store<sqlx::Sqlite>, run_id: &str) -> Value {
    let row = Store::<sqlx::Sqlite>::query(
        "SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='SideEffectRecorded'",
    )
    .bind(run_id)
    .fetch_one(&store.pool)
    .await
    .unwrap();
    <sqlx::Sqlite as Backend>::document_row(&row, "payload").unwrap()["task"].clone()
}

#[tokio::test]
async fn a_task_snapshot_comes_only_from_the_events_own_run() {
    let directory = tempfile::tempdir().unwrap();
    let store = Store::<sqlx::Sqlite>::new(
        crate::runtime::sqlite::open(directory.path().join("record.sqlite").to_str().unwrap())
            .await
            .unwrap(),
    );
    store
        .register(
            json!({"worker_id":"record-worker","task_queue":"test","runtime":"php",
                "supported_workflow_types":["echo"],"supported_activity_types":[]}),
            &crate::runtime::QueryTaskConfig::default(),
            None,
        )
        .await
        .unwrap();
    let mut runs = Vec::new();
    for workflow_id in ["record-own", "record-other"] {
        let started = store
            .start(
                json!({"workflow_id":workflow_id,"workflow_type":"echo","task_queue":"test",
                    "input":{"codec":"avro","blob":"AA=="}}),
                BTreeMap::new(),
                None,
                None,
            )
            .await
            .unwrap();
        runs.push(started["run_id"].as_str().unwrap().to_owned());
    }
    let task = Store::<sqlx::Sqlite>::query("SELECT id FROM workflow_tasks WHERE workflow_run_id=$1")
        .bind(&runs[0])
        .fetch_one(&store.pool)
        .await
        .unwrap();
    let task_id = <sqlx::Sqlite as Backend>::string(&task, "id").unwrap();

    let mut tx = store.begin().await.unwrap();
    for run_id in &runs {
        Store::<sqlx::Sqlite>::record(&mut tx, run_id, "SideEffectRecorded", json!({"sequence":1}),
            Some(&task_id), None, None, false)
            .await
            .unwrap();
    }
    tx.commit().await.unwrap();

    assert_eq!(recorded_task(&store, &runs[0]).await["id"], json!(task_id), "the run's own task");
    // Recorded on the other run with the first run's task, the event carries
    // no snapshot at all.
    assert!(recorded_task(&store, &runs[1]).await.is_null());
}

// The query itself, run on rows whose inputs begin like a stored reference:
// only native's exact prefix in the command type's own input column is read.
#[tokio::test]
async fn described_commands_select_no_inline_payload() {
    let directory = tempfile::tempdir().unwrap();
    let store = Store::<sqlx::Sqlite>::new(
        crate::runtime::sqlite::open(directory.path().join("commands.sqlite").to_str().unwrap())
            .await
            .unwrap(),
    );
    let exact = format!("{STORED_PREFIX}{{}}");
    let large = format!("dw-{}", "A".repeat(4096));
    let near = format!("dw-native-external-payload-v1{}", "A".repeat(4096));
    let upper = exact.to_uppercase();
    // (id, type, command payload, signal arguments, read)
    let rows = [
        ("c1", "signal", "AA==", Some(exact.as_str()), Some(exact.as_str())),
        ("c2", "signal", "AA==", Some(upper.as_str()), None),
        ("c3", "signal", "AA==", Some(near.as_str()), None),
        ("c4", "signal", "AA==", Some(large.as_str()), None),
        // A signal's command row is not its input.
        ("c5", "signal", exact.as_str(), Some("AA=="), None),
        ("c6", "start", exact.as_str(), None, Some(exact.as_str())),
        ("c7", "start", large.as_str(), None, None),
        // Other commands keep no stored reference.
        ("c8", "update", exact.as_str(), None, None),
    ];
    for (sequence, (id, kind, payload, arguments, _)) in rows.iter().enumerate() {
        Store::<sqlx::Sqlite>::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,command_type,status,payload,command_sequence) VALUES ($1,'instance','run',$2,'accepted',$3,$4)")
            .bind(*id)
            .bind(*kind)
            .bind(*payload)
            .bind(sequence as i64 + 1)
            .execute(&store.pool)
            .await
            .unwrap();
        if let Some(arguments) = arguments {
            // A signal record shares its command's instance, as every writer stores it.
            Store::<sqlx::Sqlite>::query("INSERT INTO workflow_signal_records(id,workflow_command_id,workflow_instance_id,signal_name,status,arguments) VALUES ($1,$2,'instance','s','received',$3)")
                .bind(format!("s-{id}"))
                .bind(*id)
                .bind(*arguments)
                .execute(&store.pool)
                .await
                .unwrap();
        }
    }
    let selected = Store::<sqlx::Sqlite>::query(<sqlx::Sqlite as Backend>::RUN_COMMANDS_SQL)
        .bind("run")
        .bind("instance")
        .fetch_all(&store.pool)
        .await
        .unwrap();
    assert_eq!(selected.len(), rows.len());
    for (row, (id, _, _, _, read)) in selected.iter().zip(&rows) {
        assert_eq!(<sqlx::Sqlite as Backend>::string(row, "id").unwrap(), *id);
        let stored = <sqlx::Sqlite as Backend>::optional_string(row, "stored_payload").unwrap();
        assert_eq!(stored.as_deref(), *read, "{id}");
    }
}
