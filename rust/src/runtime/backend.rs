//! Typed storage differences for one shared native execution state machine.
use chrono::{DateTime, NaiveDateTime, SecondsFormat, Utc};
use serde_json::Value;
use sqlx::{Database, Encode, MySql, Pool, Postgres, Row, Sqlite, Transaction, Type, types::Json};
use std::borrow::Cow;

use super::{Result, mysql, postgres, schema};

pub(super) trait Backend: Database {
    type EncodedTime: for<'q> Encode<'q, Self> + Type<Self> + Send + Sync + 'static;
    type EncodedDocument: for<'q> Encode<'q, Self> + Type<Self> + Send + Sync + 'static;
    const HISTORY_PAGE: &'static str;
    const WORKER_REGISTRATION_SQL: &'static str = super::store::WORKER_REGISTRATION_SQL;
    fn statement(sql: &'static str) -> Cow<'static, str> {
        Cow::Borrowed(sql)
    }

    fn bind_time(time: DateTime<Utc>) -> Self::EncodedTime;
    fn document(value: &Value) -> Self::EncodedDocument;
    fn optional_document(value: &Value) -> Option<Self::EncodedDocument> {
        (!value.is_null()).then(|| Self::document(value))
    }
    fn string(row: &Self::Row, field: &str) -> Result<String>;
    fn optional_string(row: &Self::Row, field: &str) -> Result<Option<String>>;
    fn number(row: &Self::Row, field: &str) -> Result<i64>;
    fn affected(result: Self::QueryResult) -> u64;
    fn instant(row: &Self::Row, field: &str) -> Result<DateTime<Utc>>;
    fn optional_instant(row: &Self::Row, field: &str) -> Result<Option<DateTime<Utc>>>;
    fn document_row(row: &Self::Row, field: &str) -> Result<Value>;
    fn begin(pool: &Pool<Self>) -> impl Future<Output = Result<Transaction<'static, Self>>> + Send;
    fn history_ready(pool: &Pool<Self>) -> impl Future<Output = bool> + Send;
}

fn sqlite_instant(value: String) -> Result<DateTime<Utc>> {
    DateTime::parse_from_rfc3339(&value)
        .map(|time| time.with_timezone(&Utc))
        .map_err(|_| {
            super::refuse(
                axum::http::StatusCode::CONFLICT,
                "unsupported_stored_timestamp",
            )
        })
}

impl Backend for Sqlite {
    type EncodedTime = String;
    type EncodedDocument = String;
    const HISTORY_PAGE: &'static str = "SELECT * FROM (SELECT *,SUM(length(CAST(payload AS BLOB))) OVER (ORDER BY sequence) AS page_bytes FROM workflow_history_events WHERE workflow_run_id=$1 AND sequence>$2 ORDER BY sequence LIMIT $3) AS page WHERE page_bytes<=8388608 ORDER BY sequence";
    fn bind_time(time: DateTime<Utc>) -> String {
        time.to_rfc3339_opts(SecondsFormat::Millis, true)
    }
    fn document(value: &Value) -> String {
        value.to_string()
    }
    fn string(row: &Self::Row, field: &str) -> Result<String> {
        Ok(row.try_get(field)?)
    }
    fn optional_string(row: &Self::Row, field: &str) -> Result<Option<String>> {
        Ok(row.try_get(field)?)
    }
    fn number(row: &Self::Row, field: &str) -> Result<i64> {
        Ok(row.try_get(field)?)
    }
    fn affected(result: Self::QueryResult) -> u64 {
        result.rows_affected()
    }
    fn instant(row: &Self::Row, field: &str) -> Result<DateTime<Utc>> {
        sqlite_instant(row.try_get(field)?)
    }
    fn optional_instant(row: &Self::Row, field: &str) -> Result<Option<DateTime<Utc>>> {
        row.try_get::<Option<String>, _>(field)?
            .map(sqlite_instant)
            .transpose()
    }
    fn document_row(row: &Self::Row, field: &str) -> Result<Value> {
        Ok(serde_json::from_str(&row.try_get::<String, _>(field)?)?)
    }
    async fn begin(pool: &Pool<Self>) -> Result<Transaction<'static, Self>> {
        Ok(pool.begin_with("BEGIN IMMEDIATE").await?)
    }
    async fn history_ready(pool: &Pool<Self>) -> bool {
        let markers: std::result::Result<Vec<(String, i64)>, _> =
            sqlx::query_as("SELECT engine,version FROM dw_server_schema")
                .fetch_all(pool)
                .await;
        if !markers.is_ok_and(|rows| rows == [("rust-development".into(), schema::VERSION)]) {
            return false;
        }
        let Ok(mut connection) = pool.acquire().await else {
            return false;
        };
        schema::verify_history(&mut connection).await.is_ok()
    }
}

impl Backend for Postgres {
    type EncodedTime = NaiveDateTime;
    type EncodedDocument = Json<Value>;
    const HISTORY_PAGE: &'static str = "SELECT * FROM (SELECT *,SUM(octet_length(payload::text)) OVER (ORDER BY sequence) AS page_bytes FROM workflow_history_events WHERE workflow_run_id=$1 AND sequence>$2 ORDER BY sequence LIMIT $3) AS page WHERE page_bytes<=8388608 ORDER BY sequence";
    fn bind_time(time: DateTime<Utc>) -> NaiveDateTime {
        time.naive_utc()
    }
    fn document(value: &Value) -> Json<Value> {
        Json(value.clone())
    }
    fn string(row: &Self::Row, field: &str) -> Result<String> {
        Ok(row.try_get(field)?)
    }
    fn affected(result: Self::QueryResult) -> u64 {
        result.rows_affected()
    }
    fn optional_string(row: &Self::Row, field: &str) -> Result<Option<String>> {
        Ok(row.try_get(field)?)
    }
    fn number(row: &Self::Row, field: &str) -> Result<i64> {
        // PostgreSQL keeps PHP's smallint/integer/bigint physical columns.
        // Decode the actual width rather than changing the schema to fit Rust.
        if let Ok(number) = row.try_get::<i64, _>(field) {
            return Ok(number);
        }
        if let Ok(number) = row.try_get::<i32, _>(field) {
            return Ok(i64::from(number));
        }
        Ok(i64::from(row.try_get::<i16, _>(field)?))
    }
    fn instant(row: &Self::Row, field: &str) -> Result<DateTime<Utc>> {
        Ok(row.try_get::<NaiveDateTime, _>(field)?.and_utc())
    }
    fn optional_instant(row: &Self::Row, field: &str) -> Result<Option<DateTime<Utc>>> {
        Ok(row
            .try_get::<Option<NaiveDateTime>, _>(field)?
            .map(|time| time.and_utc()))
    }
    fn document_row(row: &Self::Row, field: &str) -> Result<Value> {
        Ok(row.try_get::<Json<Value>, _>(field)?.0)
    }
    async fn begin(pool: &Pool<Self>) -> Result<Transaction<'static, Self>> {
        let mut tx = pool.begin().await?;
        // The first single-namespace slice serializes its durable transitions
        // across independent pools/processes. Empty long polls release this
        // transaction before waiting. Throughput/idle cost remain unqualified.
        sqlx::query("SELECT pg_advisory_xact_lock(4924501473315676163)")
            .execute(&mut *tx)
            .await?;
        Ok(tx)
    }
    async fn history_ready(pool: &Pool<Self>) -> bool {
        let Ok(mut connection) = pool.acquire().await else {
            return false;
        };
        postgres::verify_history(&mut connection).await.is_ok()
    }
}

impl Backend for MySql {
    type EncodedTime = DateTime<Utc>;
    type EncodedDocument = Json<Value>;
    // First use the preserved run/sequence index to materialize only IDs and
    // byte counts for this bounded page. Sorting JSON payloads in the window
    // exhausts MySQL's default sort buffer before the size rejection can run.
    const HISTORY_PAGE: &'static str = "SELECT h.* FROM (SELECT id,sequence,SUM(payload_bytes) OVER (ORDER BY sequence) AS page_bytes FROM (SELECT id,sequence,LENGTH(CAST(payload AS CHAR CHARACTER SET utf8mb4)) AS payload_bytes FROM workflow_history_events FORCE INDEX (workflow_history_events_workflow_run_id_sequence_unique) WHERE workflow_run_id=? AND sequence>? ORDER BY sequence LIMIT ?) AS sizes) AS page JOIN workflow_history_events h ON h.id=page.id WHERE page.page_bytes<=8388608 ORDER BY page.sequence";
    const WORKER_REGISTRATION_SQL: &'static str = "INSERT INTO workflow_worker_registrations(namespace,worker_id,task_queue,runtime,sdk_version,build_id,supported_workflow_types,supported_activity_types,capabilities,capability_manifest,last_heartbeat_at,created_at,updated_at) VALUES ('default',?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE task_queue=VALUES(task_queue),runtime=VALUES(runtime),sdk_version=VALUES(sdk_version),build_id=VALUES(build_id),supported_workflow_types=VALUES(supported_workflow_types),supported_activity_types=VALUES(supported_activity_types),capabilities=VALUES(capabilities),capability_manifest=VALUES(capability_manifest),last_heartbeat_at=VALUES(last_heartbeat_at),updated_at=VALUES(updated_at)";
    fn statement(sql: &'static str) -> Cow<'static, str> {
        if !sql.contains('$') {
            return Cow::Borrowed(sql);
        }
        // Shared templates use ordered, unique $N positions. Convert only
        // compiled static SQL; request data never enters this string and stays
        // in SQLx bind parameters. The adapter accepts no arbitrary SQL input.
        let mut parts = sql.split('$');
        let mut result = parts.next().unwrap().to_owned();
        for (index, part) in parts.enumerate() {
            let digits = part.bytes().take_while(u8::is_ascii_digit).count();
            assert_eq!(
                part[..digits].parse::<usize>().ok(),
                Some(index + 1),
                "native SQL templates require ordered unique positions"
            );
            result.push('?');
            result.push_str(&part[digits..]);
        }
        Cow::Owned(result)
    }
    fn bind_time(time: DateTime<Utc>) -> DateTime<Utc> {
        time
    }
    fn document(value: &Value) -> Json<Value> {
        Json(value.clone())
    }
    fn string(row: &Self::Row, field: &str) -> Result<String> {
        Ok(row.try_get(field)?)
    }
    fn optional_string(row: &Self::Row, field: &str) -> Result<Option<String>> {
        Ok(row.try_get(field)?)
    }
    fn number(row: &Self::Row, field: &str) -> Result<i64> {
        if let Ok(number) = row.try_get::<i64, _>(field) {
            return Ok(number);
        }
        i64::try_from(row.try_get::<u64, _>(field)?).map_err(|_| {
            super::refuse(
                axum::http::StatusCode::CONFLICT,
                "unsupported_stored_integer",
            )
        })
    }
    fn affected(result: Self::QueryResult) -> u64 {
        result.rows_affected()
    }
    fn instant(row: &Self::Row, field: &str) -> Result<DateTime<Utc>> {
        Ok(row.try_get(field)?)
    }
    fn optional_instant(row: &Self::Row, field: &str) -> Result<Option<DateTime<Utc>>> {
        Ok(row.try_get(field)?)
    }
    fn document_row(row: &Self::Row, field: &str) -> Result<Value> {
        Ok(row.try_get::<Json<Value>, _>(field)?.0)
    }
    async fn begin(pool: &Pool<Self>) -> Result<Transaction<'static, Self>> {
        let mut tx = pool.begin().await?;
        // InnoDB row locking releases on commit/rollback/process loss. This
        // first slice serializes transitions on its single ownership row.
        sqlx::query("SELECT engine FROM dw_server_schema WHERE engine='rust-development' AND version=2 FOR UPDATE").fetch_one(&mut *tx).await?;
        Ok(tx)
    }
    async fn history_ready(pool: &Pool<Self>) -> bool {
        let Ok(mut connection) = pool.acquire().await else {
            return false;
        };
        mysql::verify_history(&mut connection).await.is_ok()
    }
}
