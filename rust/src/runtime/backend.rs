//! Typed storage differences for one shared native execution state machine.
use chrono::{DateTime, NaiveDateTime, SecondsFormat, Utc};
use serde_json::Value;
use sqlx::{Database, Encode, Pool, Postgres, Row, Sqlite, Transaction, Type, types::Json};

use super::{Result, postgres, schema};

pub(super) trait Backend: Database {
    type EncodedTime: for<'q> Encode<'q, Self> + Type<Self> + Send + Sync + 'static;
    type EncodedDocument: for<'q> Encode<'q, Self> + Type<Self> + Send + Sync + 'static;
    const HISTORY_PAGE: &'static str;

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
