//! Known root-column Avro frames and external-reference metadata only.
//! Nested history, provider bytes and registry ownership remain unqualified.
use super::external_reference::{ReferenceDecodeError, inspect_php_reference};
use crate::runtime::{Result, backend::Backend, refuse};
use axum::http::StatusCode;
use serde::Serialize;
use serde_json::{Value, json};
use sqlx::{AssertSqlSafe, Encode, Executor, IntoArguments, Type};
use std::collections::BTreeMap;

const MAX_DIAGNOSTICS: usize = 16;

struct Column {
    table: &'static str,
    name: &'static str,
    sqlite_sql: &'static str,
    network_sql: &'static str,
}

macro_rules! column {
    ($table:literal, $name:literal, $codec:literal) => {
        Column {
            table: $table,
            name: $name,
            sqlite_sql: concat!(
                "SELECT CASE WHEN length(CAST(id AS BLOB))<=256 THEN id ELSE NULL END AS id,",
                "length(CAST(",
                $name,
                " AS BLOB)) AS stored_bytes,substr(",
                $name,
                ",1,32) AS prefix,",
                "CASE WHEN length(CAST(",
                $name,
                " AS BLOB))<=65536 THEN ",
                $name,
                " ELSE NULL END AS stored_value,",
                "CASE WHEN length(CAST(",
                $codec,
                " AS BLOB))<=64 THEN ",
                $codec,
                " WHEN ",
                $codec,
                " IS NULL THEN NULL ELSE 'unsupported-codec-size' END AS codec ",
                "FROM ",
                $table,
                " WHERE id>$1 ORDER BY id LIMIT 32"
            ),
            network_sql: concat!(
                "SELECT CASE WHEN octet_length(id)<=256 THEN id ELSE NULL END AS id,",
                "octet_length(",
                $name,
                ") AS stored_bytes,substr(",
                $name,
                ",1,32) AS prefix,",
                "CASE WHEN octet_length(",
                $name,
                ")<=65536 THEN ",
                $name,
                " ELSE NULL END AS stored_value,",
                "CASE WHEN octet_length(",
                $codec,
                ")<=64 THEN ",
                $codec,
                " WHEN ",
                $codec,
                " IS NULL THEN NULL ELSE 'unsupported-codec-size' END AS codec ",
                "FROM ",
                $table,
                " WHERE id>$1 ORDER BY id LIMIT 32"
            ),
        }
    };
}

const COLUMNS: [Column; 8] = [
    column!("workflow_runs", "arguments", "payload_codec"),
    column!("workflow_runs", "output", "output_payload_codec"),
    column!("activity_executions", "arguments", "payload_codec"),
    column!("activity_executions", "result", "payload_codec"),
    column!("workflow_commands", "payload", "payload_codec"),
    column!("workflow_signal_records", "arguments", "payload_codec"),
    column!("workflow_updates", "arguments", "payload_codec"),
    column!("workflow_updates", "result", "payload_codec"),
];

#[derive(Default, Serialize)]
struct Counts {
    rows: i64,
    absent: i64,
    other_unvalidated: i64,
    avro_inline_values: i64,
    php_v1_references: i64,
    unqualified_references: i64,
}

fn reason(error: ReferenceDecodeError) -> &'static str {
    match error {
        ReferenceDecodeError::TooLarge => "external_reference_too_large",
        ReferenceDecodeError::UnsupportedFormat => "external_reference_format_unsupported",
        ReferenceDecodeError::InvalidBase64 => "external_reference_base64_invalid",
        ReferenceDecodeError::InvalidEnvelope => "external_reference_envelope_invalid",
        ReferenceDecodeError::CodecMismatch => "external_reference_codec_mismatch",
        ReferenceDecodeError::InvalidReference => "external_reference_metadata_invalid",
    }
}

pub(super) async fn inspect<DB: Backend>(
    connection: &mut DB::Connection,
    expected_rows: &BTreeMap<String, i64>,
) -> Result<Value>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
{
    let mut columns = Vec::new();
    let mut diagnostics = Vec::new();
    let mut omitted = 0_i64;
    let mut references_supported = true;
    let mut inline_supported = true;
    let codec = crate::codec::ValueCodec::new().map_err(|_| {
        refuse(
            StatusCode::SERVICE_UNAVAILABLE,
            "upgrade_source_inline_codec_unavailable",
        )
    })?;
    for column in COLUMNS {
        let sql = if DB::NAME == "SQLite" {
            column.sqlite_sql
        } else {
            column.network_sql
        };
        let mut cursor = String::new();
        let mut counts = Counts::default();
        loop {
            // Each page transfers at most 32 * 64 KiB of values plus bounded
            // IDs/codecs/prefixes. Large inline values never reach the driver.
            let page = sqlx::query(AssertSqlSafe(DB::statement(sql)))
                .bind(cursor.as_str())
                .fetch_all(&mut *connection)
                .await?;
            if page.is_empty() {
                break;
            }
            for row in page {
                cursor = DB::optional_string(&row, "id")?.ok_or_else(|| {
                    refuse(
                        StatusCode::CONFLICT,
                        "upgrade_source_payload_identity_unqualified",
                    )
                })?;
                counts.rows += 1;
                let Some(bytes) = DB::optional_number(&row, "stored_bytes")? else {
                    counts.absent += 1;
                    continue;
                };
                let prefix = DB::string(&row, "prefix")?;
                if !prefix.starts_with("dw-external-payload:")
                    && !prefix.starts_with("dw-native-external-payload-")
                {
                    let invalid = if bytes > 65536 {
                        Some("stored_inline_payload_too_large")
                    } else if DB::optional_string(&row, "codec")?.as_deref() != Some("avro") {
                        Some("stored_inline_codec_unqualified")
                    } else if codec
                        .validate_stored_inline(&DB::string(&row, "stored_value")?)
                        .is_err()
                    {
                        Some("stored_inline_frame_unqualified")
                    } else {
                        None
                    };
                    if let Some(reason) = invalid {
                        inline_supported = false;
                        counts.other_unvalidated += 1;
                        if diagnostics.len() < MAX_DIAGNOSTICS {
                            diagnostics.push(json!({"table":column.table,"column":column.name,
                                "record_id":cursor,"reason":reason}));
                        } else {
                            omitted += 1;
                        }
                    } else {
                        counts.avro_inline_values += 1;
                    }
                    continue;
                }
                let decoded = if bytes > 65536 {
                    Err(ReferenceDecodeError::TooLarge)
                } else {
                    let stored = DB::string(&row, "stored_value")?;
                    let codec = DB::optional_string(&row, "codec")?;
                    inspect_php_reference(&stored, codec.as_deref())
                };
                match decoded {
                    Ok(Some(_)) => counts.php_v1_references += 1,
                    Ok(None) => unreachable!("reference prefixes were checked before decoding"),
                    Err(error) => {
                        references_supported = false;
                        counts.unqualified_references += 1;
                        if diagnostics.len() < MAX_DIAGNOSTICS {
                            diagnostics.push(json!({"table":column.table,"column":column.name,
                                "record_id":cursor,"reason":reason(error)}));
                        } else {
                            omitted += 1;
                        }
                    }
                }
            }
        }
        // Empty IDs or collation-equivalent cursor values cannot disappear
        // behind pagination and produce a complete-looking report.
        if expected_rows.get(column.table) != Some(&counts.rows) {
            return Err(refuse(
                StatusCode::CONFLICT,
                "upgrade_source_payload_rows_unqualified",
            ));
        }
        columns.push(json!({"table":column.table,"column":column.name,"counts":counts}));
    }
    Ok(json!({
        "status":"known_columns_inspected", "columns":columns,
        "references_metadata_supported":references_supported,
        "diagnostics":diagnostics, "diagnostics_omitted":omitted,
        "inline_values_verified":inline_supported, "nested_payloads_verified":false,
        // These tables also hold codec-tagged payloads. Takeover carries
        // their rows over unchanged without validating each inline value;
        // a stream row holding an external reference is refused through
        // `stream_reference_inspection`.
        "other_root_payload_columns_verified":false,
        "unscanned_payload_codec_tables":["workflow_durable_stream_items","workflow_inbound_stream_items","workflow_update_validation_tasks"],
        "inline_validation_limits":{"encoded_frame_bytes":65536,"traversal_depth":64,
            "traversal_visits":16384,"scalar_allocation_bytes":apache_avro::util::max_allocation_bytes(apache_avro::util::DEFAULT_MAX_ALLOCATION_BYTES)},
        "provider_bytes_verified":false, "registry_binding_verified":false,
        "source_mutated":false, "takeover_allowed":false
    }))
}
