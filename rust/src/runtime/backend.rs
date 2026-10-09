//! Typed storage differences for one shared native execution state machine.
use chrono::{DateTime, NaiveDateTime, Utc};
use serde_json::Value;
use sqlx::{Database, Encode, MySql, Pool, Postgres, Row, Sqlite, Transaction, Type, types::Json};
use std::borrow::Cow;

use super::{Result, mysql, postgres, schema};

pub(super) trait Backend: Database {
    type EncodedTime: for<'q> Encode<'q, Self> + Type<Self> + Send + Sync + 'static;
    type EncodedDocument: for<'q> Encode<'q, Self> + Type<Self> + Send + Sync + 'static;
    const HISTORY_PAGE: &'static str;
    const WORKER_REGISTRATION_SQL: &'static str = super::store::WORKER_REGISTRATION_SQL;
    const TASK_CANDIDATES_SQL: &'static str = super::store::TASK_CANDIDATES_SQL;
    const POLL_RECEIPT_CLEANUP_SQL: &'static str = super::store::POLL_RECEIPT_CLEANUP_SQL;
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
    // PHP's Workflow models store UTC SQL timestamps, including microseconds.
    // Earlier native development data used UTC RFC3339. Keep both readable;
    // non-UTC stored offsets require explicit conversion before takeover.
    if let Ok(time) = DateTime::parse_from_rfc3339(&value)
        && time.offset().local_minus_utc() == 0
    {
        return Ok(time.with_timezone(&Utc));
    }
    NaiveDateTime::parse_from_str(&value, "%Y-%m-%d %H:%M:%S%.f")
        .map(|time| time.and_utc())
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
    // Normalize only the two supported UTC storage shapes. Removing trailing
    // fractional zeros preserves exact decimal ordering, including earlier
    // native precision; SQLite's floating-point date functions are avoided.
    const TASK_CANDIDATES_SQL: &'static str = r#"
WITH utc_tasks AS (
    SELECT t.*,
        replace(replace(replace(t.available_at,'T',' '),'Z',''),'+00:00','') AS _dw_available_utc,
        replace(replace(replace(t.lease_expires_at,'T',' '),'Z',''),'+00:00','') AS _dw_lease_utc
    FROM workflow_tasks t WHERE t.namespace='default' AND t.queue=$1 AND t.task_type=$2
), ordered_tasks AS (
    SELECT *,
        rtrim(rtrim(CASE WHEN length(_dw_available_utc)=19 THEN _dw_available_utc||'.0' ELSE _dw_available_utc END,'0'),'.') AS _dw_available_order,
        rtrim(rtrim(CASE WHEN length(_dw_lease_utc)=19 THEN _dw_lease_utc||'.0' ELSE _dw_lease_utc END,'0'),'.') AS _dw_lease_order
    FROM utc_tasks
)
SELECT t.*,r.workflow_type FROM ordered_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id
WHERE r.status IN ('running','waiting')
    AND (t.status='ready' OR (t.status='leased' AND t._dw_lease_order<=rtrim(rtrim($3,'0'),'.')))
    AND (t.available_at IS NULL OR t._dw_available_order<=rtrim(rtrim($4,'0'),'.'))
ORDER BY t._dw_available_order,t.id LIMIT 100
"#;
    const POLL_RECEIPT_CLEANUP_SQL: &'static str = r#"
WITH utc_receipts AS (
    SELECT p.namespace,p.worker_id,p.kind,p.request_id,t.status,
        replace(replace(replace(p.expires_at,'T',' '),'Z',''),'+00:00','') AS receipt_utc,
        replace(replace(replace(t.lease_expires_at,'T',' '),'Z',''),'+00:00','') AS lease_utc
    FROM dw_poll_receipts p JOIN workflow_tasks t ON t.id=p.task_id
)
DELETE FROM dw_poll_receipts WHERE (namespace,worker_id,kind,request_id) IN (
    SELECT namespace,worker_id,kind,request_id FROM utc_receipts
    WHERE rtrim(rtrim(CASE WHEN length(receipt_utc)=19 THEN receipt_utc||'.0' ELSE receipt_utc END,'0'),'.')<=rtrim(rtrim($1,'0'),'.')
        AND (status!='leased' OR rtrim(rtrim(CASE WHEN length(lease_utc)=19 THEN lease_utc||'.0' ELSE lease_utc END,'0'),'.')<=rtrim(rtrim($2,'0'),'.'))
)
"#;
    fn bind_time(time: DateTime<Utc>) -> String {
        time.format("%Y-%m-%d %H:%M:%S%.6f").to_string()
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

#[cfg(test)]
mod timestamp_tests {
    use super::*;
    use chrono::SecondsFormat;
    use serde_json::json;

    #[tokio::test]
    async fn sqlite_php_and_native_deadlines_keep_microsecond_claim_and_receipt_fences() {
        let directory = tempfile::tempdir().unwrap();
        let runtime = super::super::Runtime::open(
            directory.path().join("time.sqlite").to_str().unwrap(),
            "test-token".into(),
        )
        .await
        .unwrap();
        runtime.start(json!({"workflow_id":"timestamp-fences", "workflow_type":"echo", "task_queue":"test", "input":{"codec":"avro","blob":"wwHioz3/VYAiNwA="}})).await.unwrap();
        let pool = runtime.sqlite_pool();
        let clock = DateTime::parse_from_rfc3339("2026-01-02T03:04:05.123456Z")
            .unwrap()
            .with_timezone(&Utc);
        // Values and decisions come from UTC deadline ordering. Execute the
        // actual compiled claim/cleanup queries with a fixed bound clock; no
        // float-based SQLite date conversion may round a microsecond fence.
        for (value, ready) in [
            ("2026-01-02 03:04:05.123455", true),
            ("2026-01-02 03:04:05.123456", true),
            ("2026-01-02 03:04:05.123457", false),
            ("2026-01-02T03:04:05.123455Z", true),
            ("2026-01-02T03:04:05.123456Z", true),
            ("2026-01-02T03:04:05.123457Z", false),
            ("2026-01-02T03:04:05.123456+00:00", true),
            ("2026-01-02T03:04:05.123457+00:00", false),
            ("2026-01-02T03:04:05.123Z", true),
            ("2026-01-02T03:04:05.124Z", false),
            ("2026-01-02 03:04:05", true),
            ("2026-01-02T03:04:06Z", false),
        ] {
            sqlx::query(
                "UPDATE workflow_tasks SET status='ready',available_at=$1,lease_expires_at=NULL",
            )
            .bind(value)
            .execute(pool)
            .await
            .unwrap();
            let available = sqlx::query(Sqlite::TASK_CANDIDATES_SQL)
                .bind("test")
                .bind("workflow")
                .bind(Sqlite::bind_time(clock))
                .bind(Sqlite::bind_time(clock))
                .fetch_all(pool)
                .await
                .unwrap();
            assert_eq!(available.len(), usize::from(ready), "availability {value}");
            sqlx::query(
                "UPDATE workflow_tasks SET status='leased',available_at=NULL,lease_expires_at=$1",
            )
            .bind(value)
            .execute(pool)
            .await
            .unwrap();
            let expired = sqlx::query(Sqlite::TASK_CANDIDATES_SQL)
                .bind("test")
                .bind("workflow")
                .bind(Sqlite::bind_time(clock))
                .bind(Sqlite::bind_time(clock))
                .fetch_all(pool)
                .await
                .unwrap();
            assert_eq!(expired.len(), usize::from(ready), "lease {value}");
            sqlx::query("INSERT INTO dw_poll_receipts(namespace,worker_id,kind,request_id,task_id,attempt,response,expires_at) SELECT 'default','worker','workflow','receipt',id,1,'{}',$1 FROM workflow_tasks")
                .bind(value).execute(pool).await.unwrap();
            sqlx::query(Sqlite::POLL_RECEIPT_CLEANUP_SQL)
                .bind(Sqlite::bind_time(clock))
                .bind(Sqlite::bind_time(clock))
                .execute(pool)
                .await
                .unwrap();
            let retained: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM dw_poll_receipts")
                .fetch_one(pool)
                .await
                .unwrap();
            assert_eq!(retained, i64::from(!ready), "receipt {value}");
            sqlx::query("DELETE FROM dw_poll_receipts")
                .execute(pool)
                .await
                .unwrap();
        }
        // Expiry and lease are independent fences. An old receipt for a live
        // lease stays bound; an expired lease does not erase a future receipt.
        for (expiry, lease, retained) in [
            (
                "2026-01-02 03:04:05.123455",
                "2026-01-02T03:04:05.123457Z",
                1,
            ),
            (
                "2026-01-02T03:04:05.123457Z",
                "2026-01-02 03:04:05.123455",
                1,
            ),
            (
                "2026-01-02T03:04:05.123456Z",
                "2026-01-02 03:04:05.123456",
                0,
            ),
        ] {
            sqlx::query("UPDATE workflow_tasks SET status='leased',lease_expires_at=$1")
                .bind(lease)
                .execute(pool)
                .await
                .unwrap();
            sqlx::query("INSERT INTO dw_poll_receipts(namespace,worker_id,kind,request_id,task_id,attempt,response,expires_at) SELECT 'default','worker','workflow','receipt',id,1,'{}',$1 FROM workflow_tasks")
                .bind(expiry).execute(pool).await.unwrap();
            sqlx::query(Sqlite::POLL_RECEIPT_CLEANUP_SQL)
                .bind(Sqlite::bind_time(clock))
                .bind(Sqlite::bind_time(clock))
                .execute(pool)
                .await
                .unwrap();
            let actual: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM dw_poll_receipts")
                .fetch_one(pool)
                .await
                .unwrap();
            assert_eq!(actual, retained, "expiry={expiry}, lease={lease}");
            sqlx::query("DELETE FROM dw_poll_receipts")
                .execute(pool)
                .await
                .unwrap();
        }
        runtime.close().await;
    }

    #[test]
    fn sqlite_timestamp_reads_preserve_php_microseconds_and_native_utc_instants() {
        for value in [
            "2026-01-02 03:04:05.123456",
            "2026-01-02T03:04:05.123456Z",
            "2026-01-02T03:04:05.123456+00:00",
        ] {
            let instant = sqlite_instant(value.into()).unwrap();
            assert_eq!(
                instant.to_rfc3339_opts(SecondsFormat::Micros, true),
                "2026-01-02T03:04:05.123456Z"
            );
        }
        for value in [
            "not-a-date",
            "2026-02-30 03:04:05.123456",
            "2026-01-02 03:04:05.123456 trailing",
            "2026-01-02T03:04:05.123456+02:00",
            "2026-01-02T03:04:05.123456-02:00",
        ] {
            assert!(sqlite_instant(value.into()).is_err(), "invalid {value}");
        }
    }
}
