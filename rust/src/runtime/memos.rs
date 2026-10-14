//! Initial memo storage and lossless reads. Authored upserts remain unqualified.
use super::{Result, refuse};
use crate::codec::{Value as Payload, ValueCodec};
use axum::http::StatusCode;
use base64::{Engine, engine::general_purpose::STANDARD};
use serde_json::{Value, json};
use std::{collections::BTreeMap, sync::Arc};
use tokio::sync::Semaphore;

pub(super) const MAX_ENTRIES: usize = 100;
const MAX_VALUE_BYTES: usize = 10_240;
const MAX_TOTAL_BYTES: usize = 65_536;
const INVALID: &str = "workflow_memo_validation_failed";

pub(super) struct StoredMemo {
    pub(super) envelope: Value,
    pub(super) projection: Value,
}
pub(super) type PreparedMemo = BTreeMap<String, StoredMemo>;
pub(super) const UPDATES_SUPPORTED: bool = true;
type StoredRows = Vec<(String, Value, Option<Value>)>;

pub(super) struct PreparedUpdate {
    pub(super) changes: BTreeMap<String, Option<StoredMemo>>,
    pub(super) entries: Value,
    pub(super) merged: Value,
}

fn invalid() -> super::RuntimeError {
    refuse(StatusCode::UNPROCESSABLE_ENTITY, INVALID)
}

fn valid_key(key: &str) -> bool {
    let numeric = key.strip_prefix('-').unwrap_or(key);
    !key.is_empty()
        && key.len() <= 64
        && key
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || b"_.:-".contains(&byte))
        && !(!numeric.is_empty() && numeric.bytes().all(|byte| byte.is_ascii_digit()))
}

// Initial JSON follows the published PHP associative-array boundary. Typed
// portable envelopes bypass this conversion. Envelope-shaped JSON is literal.
fn from_json(value: Value) -> Result<Payload> {
    Ok(match value {
        Value::Null => Payload::Null,
        Value::Bool(value) => Payload::Boolean(value),
        Value::String(value) => Payload::String(value),
        Value::Number(value) if value.is_i64() => {
            Payload::Long(value.as_i64().ok_or_else(invalid)?)
        }
        Value::Number(value) => Payload::Double(value.as_f64().ok_or_else(invalid)?),
        Value::Array(values) => {
            Payload::Array(values.into_iter().map(from_json).collect::<Result<_>>()?)
        }
        Value::Object(mut values) => {
            if (0..values.len()).all(|index| values.contains_key(&index.to_string())) {
                Payload::Array(
                    (0..values.len())
                        .map(|index| {
                            from_json(
                                values
                                    .remove(&index.to_string())
                                    .expect("contiguous JSON keys"),
                            )
                        })
                        .collect::<Result<_>>()?,
                )
            } else {
                if values.keys().any(|key| {
                    key.parse::<i64>()
                        .is_ok_and(|number| number.to_string() == *key)
                }) {
                    return Err(invalid());
                }
                Payload::Map(
                    values
                        .into_iter()
                        .map(|(key, value)| Ok((key, from_json(value)?)))
                        .collect::<Result<_>>()?,
                )
            }
        }
    })
}

fn project(value: Payload) -> Value {
    match value {
        Payload::Null => Value::Null,
        Payload::Boolean(value) => json!(value),
        Payload::Long(value) => json!(value),
        Payload::Double(value) => json!(value),
        Payload::String(value) => json!(value),
        Payload::Bytes(value) => json!({"$type":"bytes", "base64":STANDARD.encode(value)}),
        Payload::Array(values) => Value::Array(values.into_iter().map(project).collect()),
        Payload::Map(values) => {
            // PHP uses the public map adapter when arrays would erase an empty
            // map or coerce an exact string key to a signed 64-bit integer.
            let wrapped = values.is_empty()
                || values.keys().any(|key| {
                    key.parse::<i64>()
                        .is_ok_and(|value| value.to_string() == *key)
                });
            if wrapped {
                json!({"$type":"map", "entries":values.into_iter().map(|(key, value)|
                    json!({"key":key, "value":project(value)})).collect::<Vec<_>>()})
            } else {
                Value::Object(
                    values
                        .into_iter()
                        .map(|(key, value)| (key, project(value)))
                        .collect(),
                )
            }
        }
    }
}

fn encode(values: BTreeMap<String, Payload>) -> Result<PreparedMemo> {
    if values.len() > MAX_ENTRIES || values.keys().any(|key| !valid_key(key)) {
        return Err(invalid());
    }
    let codec = ValueCodec::new().map_err(|_| invalid())?;
    let mut total = 0;
    let mut prepared = BTreeMap::new();
    for (key, value) in values {
        let blob = codec.encode_ordered(&value).map_err(|_| invalid())?;
        let size = STANDARD.decode(&blob).map_err(|_| invalid())?.len();
        total += size;
        if size > MAX_VALUE_BYTES || total > MAX_TOTAL_BYTES {
            return Err(invalid());
        }
        prepared.insert(
            key,
            StoredMemo {
                envelope: json!({"codec":"avro", "blob":blob}),
                projection: project(value),
            },
        );
    }
    Ok(prepared)
}

fn initial(value: Value) -> Result<PreparedMemo> {
    let entries = match value {
        Value::Null => return Ok(BTreeMap::new()),
        Value::Array(values) if values.is_empty() => return Ok(BTreeMap::new()),
        Value::Object(values) => values,
        _ => return Err(invalid()),
    };
    if entries.keys().any(|key| !valid_key(key)) {
        return Err(invalid());
    }
    encode(
        entries
            .into_iter()
            .filter(|(_, value)| !value.is_null())
            .map(|(key, value)| Ok((key, from_json(value)?)))
            .collect::<Result<_>>()?,
    )
}

fn decode_rows(rows: StoredRows, codec: &ValueCodec) -> Result<BTreeMap<String, Payload>> {
    if rows.len() > MAX_ENTRIES {
        return Err(invalid());
    }
    let mut values = BTreeMap::new();
    for (key, projection, portable) in rows {
        if !valid_key(&key) || values.contains_key(&key) {
            return Err(invalid());
        }
        let value = if let Some(portable) = portable {
            let blob = super::envelope(&json!({"value":portable}), "value")?;
            codec.decode(&blob).map_err(|_| invalid())?
        } else {
            from_json(projection)?
        };
        values.insert(key, value);
    }
    Ok(values)
}

fn update(
    rows: StoredRows,
    blob: String,
    configured_limit: Option<usize>,
) -> Result<PreparedUpdate> {
    let codec = ValueCodec::new().map_err(|_| invalid())?;
    let Payload::Map(entries) = codec.decode(&blob).map_err(|_| invalid())? else {
        return Err(invalid());
    };
    if entries.is_empty() || entries.keys().any(|key| !valid_key(key)) {
        return Err(invalid());
    }
    let mut merged = decode_rows(rows, &codec)
        .map_err(|_| refuse(StatusCode::CONFLICT, "unsupported_stored_memo"))?;
    for (key, value) in &entries {
        if matches!(value, Payload::Null) {
            merged.remove(key);
        } else {
            merged.insert(key.clone(), value.clone());
        }
    }
    let mut prepared = encode(merged.clone())?;
    let canonical_entries = super::wire(
        &codec
            .encode_ordered(&Payload::Map(entries.clone()))
            .map_err(|_| invalid())?,
    );
    let canonical_merged = super::wire(
        &codec
            .encode_ordered(&Payload::Map(merged))
            .map_err(|_| invalid())?,
    );
    // The fixed total limit sums individual envelopes. The configured
    // structural limit measures the whole canonical map, including its keys.
    let merged_bytes = STANDARD
        .decode(canonical_merged["blob"].as_str().expect("prepared blob"))
        .expect("prepared base64")
        .len();
    if let Some(limit) = configured_limit.filter(|limit| merged_bytes > *limit) {
        return Err(super::RuntimeError::Protocol {
            status: StatusCode::UNPROCESSABLE_ENTITY,
            response: json!({"reason":"structural_limit_exceeded", "limit_kind":"memo_size",
                "outcome":"rejected", "recorded":false, "current_value":merged_bytes,
                "configured_limit":limit,
                "error":format!("Structural limit exceeded: memo size {merged_bytes} bytes (limit {limit} bytes).")}),
        });
    }
    let changes = entries
        .into_iter()
        .map(|(key, value)| {
            let change = if matches!(value, Payload::Null) {
                None
            } else {
                Some(prepared.remove(&key).ok_or_else(invalid)?)
            };
            Ok((key, change))
        })
        .collect::<Result<_>>()?;
    Ok(PreparedUpdate {
        changes,
        entries: canonical_entries,
        merged: canonical_merged,
    })
}

pub(super) async fn prepare_update(
    codec: Arc<Semaphore>,
    rows: StoredRows,
    blob: String,
    configured_limit: Option<usize>,
) -> Result<PreparedUpdate> {
    let permit = codec
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "memo_codec_unavailable"))?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        update(rows, blob, configured_limit)
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "memo_codec_unavailable"))?
}

pub(super) async fn prepare_initial(codec: Arc<Semaphore>, value: Value) -> Result<PreparedMemo> {
    if value.is_null() {
        return Ok(BTreeMap::new());
    }
    let permit = codec
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "memo_codec_unavailable"))?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        initial(value)
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "memo_codec_unavailable"))?
}

// The Server host retains a predecessor-readable projection and an Avro
// envelope. Only a portable envelope at the SAME command sequence is authority.
// Otherwise the published compatibility cast treats value as literal JSON.
pub(super) async fn read_stored(
    codec: Arc<Semaphore>,
    rows: Vec<(String, Value, Option<Value>)>,
) -> Result<Value> {
    if rows.is_empty() {
        return Ok(json!({}));
    }
    let permit = codec
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "memo_codec_unavailable"))?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        let decode = || -> Result<Value> {
            let codec = ValueCodec::new().map_err(|_| invalid())?;
            let values = decode_rows(rows, &codec)?;
            let prepared = encode(values)?;
            Ok(Value::Object(
                prepared
                    .into_iter()
                    .map(|(key, value)| (key, value.projection))
                    .collect(),
            ))
        };
        decode().map_err(|_| refuse(StatusCode::CONFLICT, "unsupported_stored_memo"))
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "memo_codec_unavailable"))?
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn update_replaces_deletes_and_preserves_the_original_typed_neighbors() {
        let codec = ValueCodec::new().unwrap();
        let rows = vec![
            (
                "keep".into(),
                Value::Null,
                Some(super::super::wire(
                    &codec
                        .encode_ordered(&Payload::Long(9007199254740993))
                        .unwrap(),
                )),
            ),
            ("delete".into(), json!("present"), None),
            ("replace".into(), json!("old"), None),
        ];
        let entries = Payload::Map(BTreeMap::from([
            ("delete".into(), Payload::Null),
            ("replace".into(), Payload::Boolean(false)),
            (
                "new".into(),
                Payload::Array(vec![Payload::Null, Payload::String("memo λ".into())]),
            ),
        ]));
        let prepared = update(rows, codec.encode_ordered(&entries).unwrap(), None).unwrap();
        assert_eq!(prepared.changes.len(), 3);
        assert!(!prepared.changes.contains_key("keep"));
        assert!(prepared.changes["delete"].is_none());
        assert_eq!(
            codec
                .decode(prepared.entries["blob"].as_str().unwrap())
                .unwrap(),
            entries
        );
        let Payload::Map(merged) = codec
            .decode(prepared.merged["blob"].as_str().unwrap())
            .unwrap()
        else {
            panic!("merged memo must remain a map");
        };
        assert_eq!(merged["keep"], Payload::Long(9007199254740993));
        assert_eq!(merged["replace"], Payload::Boolean(false));
        assert!(!merged.contains_key("delete"));
    }

    #[test]
    fn update_rejects_invalid_shape_keys_and_limits_before_exposing_changes() {
        let codec = ValueCodec::new().unwrap();
        for entries in [
            Payload::Null,
            Payload::Array(vec![]),
            Payload::Map(BTreeMap::new()),
            Payload::Map(BTreeMap::from([("0".into(), Payload::Long(1))])),
            Payload::Map(BTreeMap::from([(
                "large".into(),
                Payload::String("x".repeat(10_240)),
            )])),
        ] {
            assert!(update(vec![], codec.encode_ordered(&entries).unwrap(), None).is_err());
        }
        let entries = Payload::Map(BTreeMap::from([("valid".into(), Payload::Long(1))]));
        assert!(matches!(
            update(vec![], codec.encode_ordered(&entries).unwrap(), Some(1)),
            Err(super::super::RuntimeError::Protocol { response, .. })
                if response["reason"] == "structural_limit_exceeded"
        ));
        let corrupt = vec![(
            "bad".into(),
            Value::Null,
            Some(json!({"codec":"avro", "blob":"broken"})),
        )];
        assert!(matches!(
            update(corrupt, codec.encode_ordered(&entries).unwrap(), None),
            Err(super::super::RuntimeError::Refused {
                reason: "unsupported_stored_memo",
                ..
            })
        ));
    }

    #[test]
    fn configured_limit_measures_the_complete_map_at_the_inclusive_boundary() {
        let codec = ValueCodec::new().unwrap();
        let entries = Payload::Map(BTreeMap::from([("k".repeat(64), Payload::Long(1))]));
        let blob = codec.encode_ordered(&entries).unwrap();
        let size = STANDARD.decode(&blob).unwrap().len();
        assert!(
            size > STANDARD
                .decode(codec.encode_ordered(&Payload::Long(1)).unwrap())
                .unwrap()
                .len()
        );
        assert!(update(vec![], blob.clone(), Some(size)).is_ok());
        assert!(matches!(
            update(vec![], blob, Some(size - 1)),
            Err(super::super::RuntimeError::Protocol { response, .. })
                if response["reason"] == "structural_limit_exceeded"
        ));
    }

    #[tokio::test]
    async fn sqlite_transition_preserves_neighbor_metadata_and_rolls_back_failed_history() {
        use super::super::{sqlite, store::Store};
        let directory = tempfile::tempdir().unwrap();
        let mut store = Store::<sqlx::Sqlite>::new(
            sqlite::open(directory.path().join("memo.sqlite").to_str().unwrap())
                .await
                .unwrap(),
        );
        store.register(json!({"worker_id":"memo-unit-worker", "task_queue":"test", "runtime":"php",
            "supported_workflow_types":["echo"], "supported_activity_types":[], "capabilities":["memo_upserts"]})).await.unwrap();
        let original = store
            .start(
                json!({"workflow_id":"memo-unit", "workflow_type":"echo", "task_queue":"test",
            "input":{"codec":"avro","blob":"wwHioz3/VYAiNwA="}}),
                initial(json!({"keep":9007199254740993_i64,"replace":"old"})).unwrap(),
            )
            .await
            .unwrap();
        let run_id = original["run_id"].as_str().unwrap();
        let claimed = store
            .poll(
                json!({"worker_id":"memo-unit-worker","task_queue":"test","timeout_seconds":0}),
                "workflow",
                "1.20",
            )
            .await
            .unwrap();
        let task_id = claimed["task"]["task_id"].as_str().unwrap();
        let codec = ValueCodec::new().unwrap();
        let command = json!({"type":"upsert_memo","entries":super::super::wire(&codec.encode_ordered(
            &Payload::Map(BTreeMap::from([("replace".into(), Payload::Boolean(false))]))).unwrap())});
        let before_limit = store.describe("memo-unit", Some(run_id)).await.unwrap();
        store.memo_size_limit = Some(1);
        let mut refused = store.begin().await.unwrap();
        let run = Store::<sqlx::Sqlite>::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut *refused)
            .await
            .unwrap();
        let task = Store::<sqlx::Sqlite>::query("SELECT * FROM workflow_tasks WHERE id=$1")
            .bind(task_id)
            .fetch_one(&mut *refused)
            .await
            .unwrap();
        let rejected = store
            .upsert_memo(&mut refused, &run, &task, &command, 1)
            .await
            .unwrap_err();
        let super::super::RuntimeError::Protocol { status, response } = rejected else {
            panic!("configured limit must retain its published diagnostic");
        };
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
        assert_eq!(response["reason"], "structural_limit_exceeded");
        assert_eq!(response["limit_kind"], "memo_size");
        assert_eq!(response["task_id"], task_id);
        assert_eq!(response["workflow_task_attempt"], 1);
        assert_eq!(response["outcome"], "rejected");
        assert_eq!(response["recorded"], false);
        assert_eq!(response["configured_limit"], 1);
        refused.rollback().await.unwrap();
        assert_eq!(
            store.describe("memo-unit", Some(run_id)).await.unwrap(),
            before_limit,
            "configured-limit refusal preserves the original history and memo"
        );
        let merged = Payload::Map(BTreeMap::from([
            ("keep".into(), Payload::Long(9007199254740993)),
            ("replace".into(), Payload::Boolean(false)),
        ]));
        assert_eq!(
            response["current_value"],
            STANDARD
                .decode(codec.encode_ordered(&merged).unwrap())
                .unwrap()
                .len()
        );
        store.memo_size_limit = Some(
            STANDARD
                .decode(codec.encode_ordered(&merged).unwrap())
                .unwrap()
                .len(),
        );
        let mut tx = store.begin().await.unwrap();
        let run = Store::<sqlx::Sqlite>::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut *tx)
            .await
            .unwrap();
        let task = Store::<sqlx::Sqlite>::query("SELECT * FROM workflow_tasks WHERE id=$1")
            .bind(task_id)
            .fetch_one(&mut *tx)
            .await
            .unwrap();
        store
            .upsert_memo(&mut tx, &run, &task, &command, 1)
            .await
            .unwrap();
        tx.commit().await.unwrap();
        let before = store.describe("memo-unit", Some(run_id)).await.unwrap();
        assert_eq!(
            before["memo"],
            json!({"keep":9007199254740993_i64,"replace":false})
        );
        let sequence: i64 =
            sqlx::query_scalar("SELECT upserted_at_sequence FROM workflow_memos WHERE key='keep'")
                .fetch_one(&store.pool)
                .await
                .unwrap();
        assert_eq!(
            sequence, 0,
            "untouched neighbor retains its original sequence"
        );
        store.memo_size_limit = None;
        sqlx::query("CREATE TRIGGER refuse_memo_history BEFORE INSERT ON workflow_history_events WHEN NEW.event_type='MemoUpserted' BEGIN SELECT RAISE(ABORT,'synthetic memo history refusal'); END")
            .execute(&store.pool).await.unwrap();
        let mut tx = store.begin().await.unwrap();
        let changed = json!({"type":"upsert_memo","entries":super::super::wire(&codec.encode_ordered(
            &Payload::Map(BTreeMap::from([("replace".into(), Payload::String("must roll back".into()))]))).unwrap())});
        assert!(matches!(
            store.upsert_memo(&mut tx, &run, &task, &changed, 2).await,
            Err(super::super::RuntimeError::Database(_))
        ));
        tx.rollback().await.unwrap();
        assert_eq!(
            store.describe("memo-unit", Some(run_id)).await.unwrap(),
            before
        );
        store.close().await;
    }

    #[test]
    fn initial_json_boundary_matches_the_actual_frozen_php_admissions() {
        let fixture: Value = serde_json::from_str(include_str!(
            "../../tests/fixtures/memo-initial-json-v1.json"
        ))
        .unwrap();
        for case in fixture["cases"].as_array().unwrap() {
            let value: Value = serde_json::from_str(case["json"].as_str().unwrap()).unwrap();
            let prepared = initial(json!({"metadata":value.clone()}));
            if case["accepted"] == false {
                assert!(prepared.is_err(), "{} must refuse", case["id"]);
            } else {
                assert_eq!(
                    prepared.unwrap()["metadata"].projection,
                    case["expected"],
                    "{}",
                    case["id"]
                );
            }
            if let Some(bits) = case["expected_avro_double_bits"].as_str() {
                let prepared = initial(json!({"metadata":value})).unwrap();
                let decoded = ValueCodec::new()
                    .unwrap()
                    .decode(prepared["metadata"].envelope["blob"].as_str().unwrap())
                    .unwrap();
                let Payload::Double(number) = decoded else {
                    panic!("{} must retain the published double branch", case["id"]);
                };
                assert_eq!(format!("{:016x}", number.to_bits().swap_bytes()), bits);
            }
        }
    }

    #[test]
    fn initial_values_retain_int64_null_false_lists_and_literal_envelopes() {
        let values = initial(
            json!({"integer":9007199254740993_i64, "optional":null, "false":false,
            "list":[], "map":{}, "literal":{"codec":"avro", "blob":"literal"}}),
        )
        .unwrap();
        let codec = ValueCodec::new().unwrap();
        assert_eq!(
            codec
                .decode(values["integer"].envelope["blob"].as_str().unwrap())
                .unwrap(),
            Payload::Long(9007199254740993)
        );
        assert_eq!(
            codec
                .decode(values["map"].envelope["blob"].as_str().unwrap())
                .unwrap(),
            Payload::Array(vec![])
        );
        assert_eq!(
            codec
                .decode(values["list"].envelope["blob"].as_str().unwrap())
                .unwrap(),
            Payload::Array(vec![])
        );
        assert_eq!(
            values["literal"].projection,
            json!({"codec":"avro", "blob":"literal"})
        );
        assert!(!values.contains_key("optional"));
        assert_eq!(values["false"].projection, json!(false));
    }

    #[test]
    fn structural_guards_measure_avro_bytes_and_reject_integer_like_keys() {
        for key in ["", "0", "-12", "01", "bad key", "λ"] {
            assert!(initial(json!({(key):1})).is_err(), "{key}");
        }
        assert!(initial(json!({"-":1})).is_ok());
        assert!(initial(json!({"value":"x".repeat(MAX_VALUE_BYTES)})).is_err());
        let many: serde_json::Map<_, _> =
            (0..101).map(|n| (format!("key-{n}"), json!(1))).collect();
        assert!(initial(Value::Object(many)).is_err());
        let deletions: serde_json::Map<_, _> = (0..101)
            .map(|n| (format!("key-{n}"), Value::Null))
            .collect();
        assert!(initial(Value::Object(deletions)).unwrap().is_empty());
        let large: serde_json::Map<_, _> = (0..7)
            .map(|n| (format!("key-{n}"), json!("x".repeat(10_000))))
            .collect();
        assert!(initial(Value::Object(large)).is_err());
        assert_eq!(
            initial(json!({"outside_int64":u64::MAX})).unwrap()["outside_int64"].projection,
            json!(u64::MAX as f64)
        );
    }

    #[tokio::test]
    async fn stored_reads_use_exact_authority_and_preserve_byte_and_double_branches() {
        let codec = ValueCodec::new().unwrap();
        let portable = |value| json!({"codec":"avro", "blob":codec.encode(&value).unwrap()});
        let rows = vec![
            (
                "double".into(),
                json!(7),
                Some(portable(Payload::Double(7.0))),
            ),
            (
                "bytes".into(),
                json!("wrong"),
                Some(portable(Payload::Bytes(vec![255, 0]))),
            ),
            (
                "literal".into(),
                json!({"codec":"avro", "blob":"literal"}),
                None,
            ),
            (
                "empty_map".into(),
                json!([]),
                Some(portable(Payload::Map(BTreeMap::new()))),
            ),
            (
                "numeric_map".into(),
                Value::Null,
                Some(portable(Payload::Map(BTreeMap::from([(
                    "0".into(),
                    Payload::String("literal key".into()),
                )])))),
            ),
        ];
        let result = read_stored(Arc::new(Semaphore::new(1)), rows)
            .await
            .unwrap();
        assert_eq!(result["double"].as_f64(), Some(7.0));
        assert!(result["double"].as_i64().is_none());
        assert_eq!(result["bytes"], json!({"$type":"bytes", "base64":"/wA="}));
        assert_eq!(result["literal"], json!({"codec":"avro", "blob":"literal"}));
        assert_eq!(result["empty_map"], json!({"$type":"map", "entries":[]}));
        assert_eq!(
            result["numeric_map"],
            json!({"$type":"map", "entries":[{"key":"0","value":"literal key"}]})
        );
        assert!(
            read_stored(
                Arc::new(Semaphore::new(1)),
                vec![(
                    "bad".into(),
                    Value::Null,
                    Some(json!({"codec":"avro", "blob":"broken"}))
                )]
            )
            .await
            .is_err()
        );
    }
}
