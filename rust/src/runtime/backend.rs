//! Typed storage differences for one shared native execution state machine.
use chrono::{DateTime, NaiveDateTime, Utc};
use serde_json::Value;
use sqlx::{Database, Encode, MySql, Pool, Postgres, Row, Sqlite, Transaction, Type, types::Json};
use std::borrow::Cow;

use super::{Result, mysql, postgres, schema};

/// Counts every durable stream item, durable stream and inbound message that
/// holds an external payload reference. The arguments name the JSON payload
/// and metadata columns as the backend reads them as text.
macro_rules! stream_references {
    ($payload:literal, $metadata:literal) => {
        concat!(
            "SELECT (SELECT COUNT(*) FROM workflow_durable_stream_items WHERE payload_reference IS NOT NULL OR ",
            super::retention::reference!($payload),
            ")+(SELECT COUNT(*) FROM workflow_durable_streams WHERE ",
            super::retention::reference!($metadata),
            ")+(SELECT COUNT(*) FROM workflow_inbound_stream_items WHERE ",
            super::retention::reference!("payload_blob"),
            ") AS n"
        )
    };
}

/// `STREAM_REFERENCES` for backends that store JSON as text.
const PLAIN_STREAM_REFERENCES: &str = stream_references!("payload", "metadata");

pub(super) trait Backend: Database {
    const PHP_CONNECTION: &str;
    type EncodedTime: for<'q> Encode<'q, Self> + Type<Self> + Send + Sync + 'static;
    type EncodedOptionalTime: for<'q> Encode<'q, Self> + Type<Self> + Send + Sync + 'static;
    type EncodedDocument: for<'q> Encode<'q, Self> + Type<Self> + Send + Sync + 'static;
    type EncodedInteger: for<'q> Encode<'q, Self> + Type<Self> + Send + Sync + 'static;
    type EncodedFloat: for<'q> Encode<'q, Self> + Type<Self> + Send + Sync + 'static;
    type EncodedFlag: for<'q> Encode<'q, Self> + Type<Self> + Send + Sync + 'static;
    const HISTORY_PAGE: &str;
    // Multi-statement reads that must observe one committed state. SQLite's
    // deferred read transaction already keeps one WAL snapshot.
    const READ_SNAPSHOT_BEGIN: &str = "BEGIN";
    const ACTIVITY_METADATA_HISTORY: &str = "SELECT sequence,event_type,CASE WHEN projection_bytes<=8388608 THEN payload ELSE NULL END AS payload,workflow_task_id,recorded_at,projection_count FROM (SELECT sequence,event_type,payload,workflow_task_id,recorded_at,COUNT(*) OVER () AS projection_count,SUM(octet_length(CAST(payload AS TEXT))) OVER (ORDER BY sequence) AS projection_bytes FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('ActivityScheduled','ActivityStarted','ActivityHeartbeatRecorded','ActivityRetryScheduled','ActivityCompleted','ActivityFailed','ActivityTimedOut','ActivityCancelled') ORDER BY sequence LIMIT 10001) AS page ORDER BY sequence";
    // Waits whose stored position differs from their chronological ordinal
    // over every retained wait of the run, as Workflow #941's projector stores.
    const WAIT_POSITION_MOVES: &str = "WITH clocks AS (SELECT id,wait_id,position,opened_at,EXTRACT(EPOCH FROM opened_at) AS seconds FROM workflow_run_waits WHERE workflow_run_id=$1), ordinals AS (SELECT id,position,ROW_NUMBER() OVER (ORDER BY CASE WHEN opened_at IS NULL THEN 1 ELSE 0 END,ROUND((CASE WHEN seconds<0 THEN 2*FLOOR(seconds)-seconds ELSE seconds END)*1000),wait_id)-1 AS ordinal FROM clocks) SELECT id,ordinal FROM ordinals WHERE position IS DISTINCT FROM ordinal ORDER BY ordinal LIMIT 100";
    const SUMMARY_HISTORY_SQL: &str = "SELECT id,sequence,event_type,CAST(payload AS TEXT) AS payload_json FROM workflow_history_events WHERE workflow_run_id=$1 AND sequence>$2 ORDER BY sequence LIMIT 16";
    const OBSERVATION_FAILURE_EVENT: &str = "SELECT id,sequence,event_type,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 AND payload->>'failure_id'=$2 AND event_type IN ('ActivityFailed','ActivityTimedOut','ChildRunFailed','ChildRunCancelled','ChildRunTerminated','WorkflowFailed','WorkflowTimedOut','WorkflowCancelled','WorkflowTerminated','UpdateCompleted') ORDER BY sequence DESC LIMIT 1";
    const OBSERVATION_ATTRIBUTES: &str = "SELECT \"key\" AS attribute_key,type,value_string,value_keyword,value_int,value_float,CASE WHEN value_bool IS NULL THEN NULL WHEN value_bool THEN 1 ELSE 0 END AS value_bool,value_datetime,value_keyword_list FROM workflow_search_attributes WHERE workflow_run_id=$1 AND workflow_instance_id=$2 AND \"key\" IN ($3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21,$22) ORDER BY \"key\" LIMIT 20";
    const ACTIVITY_RESULT_EVENT_SQL: &str = "SELECT sequence,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('ActivityScheduled','ActivityStarted','ActivityHeartbeatRecorded','ActivityRetryScheduled','ActivityCompleted','ActivityFailed','ActivityTimedOut','ActivityCancelled') AND (payload->>'activity_execution_id'=$2 OR payload->'activity'->>'id'=$3) ORDER BY sequence DESC LIMIT 1";
    const EXTERNAL_PAYLOAD_BYTES: &str = "SELECT CAST(COALESCE(SUM(size_bytes),0) AS BIGINT) FROM runtime_external_payloads WHERE namespace=$1";
    const VISIBILITY_PAGE: &str = super::visibility::VISIBILITY_PAGE;
    const WORKER_REGISTRATION_SQL: &str = super::store::WORKER_REGISTRATION_SQL;
    const TASK_CANDIDATES_SQL: &str = super::store::TASK_CANDIDATES_SQL;
    const POLL_WAKE_TASKS_SQL: &str = super::cross_kind_poll::TASKS_SQL;
    const POLL_WAKE_QUERIES_SQL: &str = "SELECT \"key\" AS id FROM dw_query_cache WHERE \"key\" LIKE 'dw-native-query:%' AND expiration>$1 AND CAST(value AS jsonb)->>'namespace'=$2 AND CAST(value AS jsonb)->>'task_queue'=$3 AND CAST(value AS jsonb)->>'query_deadline_at'>$4 AND (CAST(value AS jsonb)->>'status'='pending' OR (CAST(value AS jsonb)->>'status'='leased' AND CAST(value AS jsonb)->>'lease_expires_at'<=$5)) ORDER BY \"key\" LIMIT 65";
    const POLL_RECEIPT_CLEANUP_SQL: &str = super::store::POLL_RECEIPT_CLEANUP_SQL;
    const TIMER_TASK_SQL: &str;
    const TIMER_SCHEDULE_SQL: &str = "SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='TimerScheduled' AND payload->>'timer_id'=$2 ORDER BY sequence DESC LIMIT 1";
    /// The history sequence of a signal's `SignalReceived` event.
    const SIGNAL_RECEIVED_SEQUENCE_SQL: &str = "SELECT sequence FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='SignalReceived' AND payload->>'signal_id'=$2 ORDER BY sequence LIMIT 1";
    // PostgreSQL compares text case-sensitively.
    const RUN_COMMANDS_SQL: &str =
        super::store::run_commands_sql!(
        "substr(s.arguments,1,30)='dw-native-external-payload-v1:'",
        "substr(c.payload,1,30)='dw-native-external-payload-v1:'",
        "substr(c.payload,1,23)='dw-external-payload:v1:'"
    );
    const SELECTION_RESOLUTION_SQL: &str = "SELECT id,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='SelectionResolved' AND payload->>'selection_group_id'=$2 ORDER BY sequence LIMIT 1";
    // Frozen run command/task repair counters use each backend's actual width.
    const REPAIR_COUNTER_MAX: i64 = i32::MAX as i64;
    const RANGE_REPAIR_TASK_SQL: &str = "SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND namespace=$2 AND task_type='workflow' AND status='failed' AND payload->>'replay_blocked'='true' ORDER BY CASE WHEN available_at IS NULL THEN 1 ELSE 0 END,available_at,created_at,id LIMIT 1";
    const ACTIVITY_OPEN_TASK_SQL: &str = "SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='activity' AND status IN ('ready','leased') AND payload->>'activity_execution_id'=$2 ORDER BY id LIMIT 1";
    // Local activities in the namespace, as PHP's operator metrics count them.
    const LOCAL_ACTIVITY_EXECUTIONS: &str = "SELECT status FROM activity_executions WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace=$1) AND activity_options->>'execution_mode'='local'";
    const LOCAL_ACTIVITY_ATTEMPTS: &str = "SELECT p.status FROM activity_attempts p JOIN activity_executions a ON a.id=p.activity_execution_id WHERE p.workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace=$1) AND a.activity_options->>'execution_mode'='local'";
    const QUERY_CACHE_SCAN: &str = "SELECT \"key\",value,expiration FROM dw_query_cache WHERE \"key\" LIKE 'dw-native-query:%' AND value::jsonb->>'namespace'=$1 ORDER BY \"key\" LIMIT 65";
    const QUERY_CACHE_GET: &str =
        "SELECT value,expiration FROM dw_query_cache WHERE \"key\"=$1";
    const QUERY_CACHE_INSERT: &str =
        "INSERT INTO dw_query_cache(\"key\",value,expiration) VALUES ($1,$2,$3)";
    const QUERY_CACHE_UPDATE: &str =
        "UPDATE dw_query_cache SET value=$1,expiration=$2 WHERE \"key\"=$3";
    const QUERY_CACHE_DELETE: &str = "DELETE FROM dw_query_cache WHERE \"key\"=$1";
    // A bounded batch of expired per-request query poll rows.
    const QUERY_POLL_EXPIRED: &str = "SELECT \"key\" FROM dw_query_cache WHERE \"key\" LIKE 'dw-native-query-poll:%' AND expiration<=$1 ORDER BY \"key\" LIMIT 32";
    const WORKFLOW_POLL_EXPIRED: &str = "SELECT \"key\" FROM dw_query_cache WHERE \"key\" LIKE 'dw-native-workflow-poll:%' AND expiration<=$1 ORDER BY \"key\" LIMIT 32";
    /// Add a cache row, or lock the existing one with a no-op update, so
    /// transactions over one poll request ID run one at a time.
    /// A worker session's running activity attempts held by its owner,
    /// other than one task (`activeActivityCount`).
    const SESSION_RUNNING_ATTEMPTS: &str = "SELECT COUNT(*) FROM activity_attempts p JOIN activity_executions e ON e.id=p.activity_execution_id JOIN workflow_tasks t ON t.id=p.workflow_task_id WHERE t.namespace=$1 AND p.status='running' AND p.lease_expires_at>$2 AND p.lease_owner=$3 AND e.activity_options->'worker_session'->>'session_id'=$4 AND p.workflow_task_id<>$5";
    const QUERY_CACHE_LOCK: &str = "INSERT INTO dw_query_cache(\"key\",value,expiration) VALUES ($1,$2,$3) ON CONFLICT (\"key\") DO UPDATE SET expiration=dw_query_cache.expiration";
    /// `WorkflowTaskKindSelector`: create the queue's cursor at
    /// `update_validation` if it is missing, and lock it.
    const TASK_KIND_CURSOR_LOCK: &str = "INSERT INTO workflow_task_poll_cursors(namespace,task_queue,next_task_kind,created_at,updated_at) VALUES ($1,$2,'update_validation',$3,$4) ON CONFLICT (namespace,task_queue) DO UPDATE SET next_task_kind=workflow_task_poll_cursors.next_task_kind";
    const MEMO_INITIAL_INSERT: &str = "INSERT INTO workflow_memos(workflow_run_id,workflow_instance_id,\"key\",value,upserted_at_sequence,inherited_from_parent,created_at,updated_at,portable_value,portable_value_sequence) VALUES ($1,$2,$3,$4,0,false,$5,$6,$7,0)";
    const MEMO_INHERIT_INSERT: &str = "INSERT INTO workflow_memos(workflow_run_id,workflow_instance_id,\"key\",value,upserted_at_sequence,inherited_from_parent,created_at,updated_at,portable_value,portable_value_sequence) VALUES ($1,$2,$3,$4,1,true,$5,$6,$7,1)";
    const MEMO_READ: &str = "SELECT \"key\",value,portable_value,portable_value_sequence,upserted_at_sequence FROM workflow_memos WHERE workflow_run_id=$1 ORDER BY \"key\",id LIMIT 101";
    const MEMO_UPSERT: &str = "INSERT INTO workflow_memos(workflow_run_id,workflow_instance_id,\"key\",value,upserted_at_sequence,inherited_from_parent,created_at,updated_at,portable_value,portable_value_sequence) VALUES ($1,$2,$3,$4,$5,false,$6,$7,$8,$9) ON CONFLICT(workflow_run_id,\"key\") DO UPDATE SET value=excluded.value,upserted_at_sequence=excluded.upserted_at_sequence,inherited_from_parent=false,updated_at=excluded.updated_at,portable_value=excluded.portable_value,portable_value_sequence=excluded.portable_value_sequence";
    const MEMO_DELETE: &str =
        "DELETE FROM workflow_memos WHERE workflow_run_id=$1 AND \"key\"=$2";
    const SEARCH_ATTRIBUTE_ROWS: &str = "SELECT \"key\" AS attribute_key,type,value_string,value_keyword,value_int,value_float,CASE WHEN value_bool IS NULL THEN NULL WHEN value_bool THEN 1 ELSE 0 END AS value_bool,value_datetime,value_keyword_list FROM workflow_search_attributes WHERE workflow_run_id=$1 ORDER BY \"key\" LIMIT 1001";
    const SEARCH_ATTRIBUTE_UPSERT: &str = "INSERT INTO workflow_search_attributes(workflow_run_id,workflow_instance_id,\"key\",type,value_string,value_keyword,value_keyword_list,value_int,value_float,value_bool,value_datetime,upserted_at_sequence,inherited_from_parent,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,false,$13,$14) ON CONFLICT(workflow_run_id,\"key\") DO UPDATE SET workflow_instance_id=excluded.workflow_instance_id,type=excluded.type,value_string=excluded.value_string,value_keyword=excluded.value_keyword,value_keyword_list=excluded.value_keyword_list,value_int=excluded.value_int,value_float=excluded.value_float,value_bool=excluded.value_bool,value_datetime=excluded.value_datetime,upserted_at_sequence=excluded.upserted_at_sequence,inherited_from_parent=false,updated_at=excluded.updated_at";
    const SEARCH_ATTRIBUTE_DELETE: &str = "DELETE FROM workflow_search_attributes WHERE workflow_run_id=$1 AND \"key\"=$2";
    const SEARCH_ATTRIBUTE_KEY_ROWS: &str = "SELECT a.workflow_run_id,a.\"key\" AS attribute_key,a.type,a.value_string,a.value_keyword,a.value_int,a.value_float,CASE WHEN a.value_bool IS NULL THEN NULL WHEN a.value_bool THEN 1 ELSE 0 END AS value_bool,a.value_datetime,a.value_keyword_list FROM workflow_search_attributes a JOIN workflow_runs r ON r.id=a.workflow_run_id WHERE r.namespace=$1 AND a.\"key\"=$2";
    const SEARCH_ATTRIBUTE_INHERIT: &str = "INSERT INTO workflow_search_attributes(workflow_run_id,workflow_instance_id,\"key\",type,value_string,value_keyword,value_keyword_list,value_int,value_float,value_bool,value_datetime,upserted_at_sequence,inherited_from_parent,created_at,updated_at) SELECT $1,$2,\"key\",type,value_string,value_keyword,value_keyword_list,value_int,value_float,value_bool,value_datetime,1,true,$3,$4 FROM workflow_search_attributes WHERE workflow_run_id=$5";
    // Durable workflow stream items; `offset` is a reserved word.
    const STREAM_ITEM_BY_KEY: &str = "SELECT \"offset\" FROM workflow_durable_stream_items WHERE stream_id=$1 AND idempotency_key=$2 LIMIT 1";
    const STREAM_ITEM_INSERT: &str = "INSERT INTO workflow_durable_stream_items(stream_id,namespace,workflow_run_id,stream_name,\"offset\",idempotency_key,origin,origin_reference,payload,payload_reference,payload_codec,item_type,content_type,emitted_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16)";
    const STREAM_ITEMS_WINDOW: &str = "SELECT * FROM workflow_durable_stream_items WHERE stream_id=$1 AND \"offset\">=$2 ORDER BY \"offset\" LIMIT $3";
    const STREAM_SWEEP_BOUNDARY: &str = "SELECT \"offset\" FROM workflow_durable_stream_items WHERE stream_id=$1 ORDER BY \"offset\" LIMIT 1 OFFSET $2";
    const STREAM_SWEEP_THROUGH: &str = "DELETE FROM workflow_durable_stream_items WHERE stream_id=$1 AND \"offset\"<=$2";
    // Durable stream items, and streams, holding an external payload
    // reference, which native does not settle (Server #774). PostgreSQL
    // stores these payloads as `json`, matched through its text.
    const STREAM_ITEM_REFERENCES: &str = concat!("SELECT COUNT(*) FROM workflow_durable_stream_items WHERE stream_id=$1 AND (payload_reference IS NOT NULL OR ", super::retention::reference!("payload::text"), ")");
    // Every stream item, durable stream and inbound message that holds an
    // external payload reference, which takeover refuses as it refuses any
    // other external payload.
    const STREAM_REFERENCES: &str = stream_references!("payload::text", "metadata::text");
    const RUN_STREAM_REFERENCES: &str = concat!(
        "SELECT (SELECT COUNT(*) FROM workflow_durable_stream_items WHERE workflow_run_id=$1 AND (payload_reference IS NOT NULL OR ", super::retention::reference!("payload::text"), "))",
        "+(SELECT COUNT(*) FROM workflow_durable_streams WHERE workflow_run_id=$2 AND ", super::retention::reference!("metadata::text"), ")"
    );
    // The namespace's stream rows holding an external reference, which
    // native's namespace delete does not settle ($1-$3 bind the namespace).
    const NAMESPACE_STREAM_REFERENCES: &str = concat!(
        "SELECT (SELECT COUNT(*) FROM workflow_durable_stream_items WHERE namespace=$1 AND (payload_reference IS NOT NULL OR ", super::retention::reference!("payload::text"), "))",
        "+(SELECT COUNT(*) FROM workflow_durable_streams WHERE namespace=$2 AND ", super::retention::reference!("metadata::text"), ")",
        "+(SELECT COUNT(*) FROM workflow_inbound_stream_items WHERE namespace=$3 AND ", super::retention::reference!("payload_blob"), ")"
    );
    // `duplicateBridgeCommand`: the latest accepted command PHP recorded for
    // this adapter, action, key and target name ($6 names the target field),
    // filtered in the database as PHP's JSON-path query is. PHP's bridge
    // commands carry no identity: no request id for a signal, the raw key
    // ($8) for an update.
    const BRIDGE_COMMAND: &str = "SELECT workflow_run_id,resolved_workflow_run_id FROM workflow_commands WHERE workflow_instance_id=$1 AND command_type=$2 AND status='accepted' AND context->'server'->'metadata'->>'adapter'=$3 AND context->'server'->'metadata'->>'action'=$4 AND context->'server'->'metadata'->>'idempotency_key'=$5 AND context->'server'->'metadata'->>$6=$7 AND (request_id IS NULL OR request_id=$8) ORDER BY accepted_at DESC,command_sequence DESC LIMIT 1";
    fn statement(sql: &'static str) -> Cow<'static, str> {
        Cow::Borrowed(sql)
    }

    fn bind_time(time: DateTime<Utc>) -> Self::EncodedTime;
    fn optional_time(time: Option<DateTime<Utc>>) -> Self::EncodedOptionalTime;
    fn document(value: &Value) -> Self::EncodedDocument;
    fn optional_integer(value: Option<i64>) -> Self::EncodedInteger;
    fn optional_float_value(value: Option<f64>) -> Self::EncodedFloat;
    fn optional_flag(value: Option<bool>) -> Self::EncodedFlag;
    fn optional_document(value: &Value) -> Option<Self::EncodedDocument> {
        (!value.is_null()).then(|| Self::document(value))
    }
    fn string(row: &Self::Row, field: &str) -> Result<String>;
    fn optional_string(row: &Self::Row, field: &str) -> Result<Option<String>>;
    fn number(row: &Self::Row, field: &str) -> Result<i64>;
    fn optional_number(row: &Self::Row, field: &str) -> Result<Option<i64>>;
    fn optional_float(row: &Self::Row, field: &str) -> Result<Option<f64>>;
    fn affected(result: Self::QueryResult) -> u64;
    fn instant(row: &Self::Row, field: &str) -> Result<DateTime<Utc>>;
    fn optional_instant(row: &Self::Row, field: &str) -> Result<Option<DateTime<Utc>>>;
    fn document_row(row: &Self::Row, field: &str) -> Result<Value>;
    fn optional_document_row(row: &Self::Row, field: &str) -> Result<Option<Value>>;
    fn begin(pool: &Pool<Self>) -> impl Future<Output = Result<Transaction<'static, Self>>> + Send;
    fn history_ready(pool: &Pool<Self>) -> impl Future<Output = bool> + Send;
}

fn sqlite_instant(value: String) -> Result<DateTime<Utc>> {
    // PHP's Workflow models store UTC SQL timestamps, including microseconds.
    // Earlier native development data used UTC RFC3339. Keep both readable;
    // other shapes/offsets require explicit conversion before takeover. The
    // accepted date prefix must match the SQL ordering expression exactly.
    if let Ok(time) = DateTime::parse_from_rfc3339(&value)
        && time.offset().local_minus_utc() == 0
        && (value.ends_with('Z') || value.ends_with("+00:00"))
        && value.get(..19) == Some(time.format("%Y-%m-%dT%H:%M:%S").to_string().as_str())
    {
        return Ok(time.with_timezone(&Utc));
    }
    if let Ok(time) = NaiveDateTime::parse_from_str(&value, "%Y-%m-%d %H:%M:%S%.f")
        && value.get(..19) == Some(time.format("%Y-%m-%d %H:%M:%S").to_string().as_str())
    {
        return Ok(time.and_utc());
    }
    Err(super::refuse(
        axum::http::StatusCode::CONFLICT,
        "unsupported_stored_timestamp",
    ))
}

impl Backend for Sqlite {
    type EncodedInteger = Option<i64>;
    type EncodedFloat = Option<f64>;
    type EncodedFlag = Option<bool>;
    fn optional_integer(value: Option<i64>) -> Option<i64> {
        value
    }
    fn optional_float_value(value: Option<f64>) -> Option<f64> {
        value
    }
    fn optional_flag(value: Option<bool>) -> Option<bool> {
        value
    }
    const ACTIVITY_METADATA_HISTORY: &str = "SELECT sequence,event_type,CASE WHEN projection_bytes<=8388608 THEN payload ELSE NULL END AS payload,workflow_task_id,recorded_at,projection_count FROM (SELECT sequence,event_type,payload,workflow_task_id,recorded_at,COUNT(*) OVER () AS projection_count,SUM(length(CAST(payload AS BLOB))) OVER (ORDER BY sequence) AS projection_bytes FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('ActivityScheduled','ActivityStarted','ActivityHeartbeatRecorded','ActivityRetryScheduled','ActivityCompleted','ActivityFailed','ActivityTimedOut','ActivityCancelled') ORDER BY sequence LIMIT 10001) AS page ORDER BY sequence";
    const PHP_CONNECTION: &str = "sqlite";
    // Carbon's millisecond comparison rounds at half a millisecond. Parse only
    // whole UTC seconds with SQLite's clock functions and round integer
    // microseconds explicitly; SQLite's fractional-date rounding differs at ties.
    // Preserve Carbon's signed U.u decimal key for pre-epoch dates as well.
    const WAIT_POSITION_MOVES: &str = r#"
WITH utc_waits AS (
    SELECT id,wait_id,position,opened_at,
        replace(replace(replace(opened_at,'T',' '),'Z',''),'+00:00','') AS utc_opened
    FROM workflow_run_waits WHERE workflow_run_id=$1
), clocks AS (
    SELECT *,unixepoch(substr(utc_opened,1,19))*1000000
        +(CASE WHEN unixepoch(substr(utc_opened,1,19))<0 THEN -1 ELSE 1 END)
        *CAST(CASE WHEN substr(utc_opened,20,1)='.'
            THEN substr(substr(utc_opened,21)||'000000',1,6) ELSE '000000' END AS INTEGER) AS micros
    FROM utc_waits
), ordinals AS (
    SELECT id,position,ROW_NUMBER() OVER (ORDER BY CASE WHEN opened_at IS NULL THEN 1 ELSE 0 END,
        CASE WHEN micros<0 THEN (micros-500)/1000 ELSE (micros+500)/1000 END,wait_id)-1 AS ordinal
    FROM clocks
)
SELECT id,ordinal FROM ordinals WHERE position IS NOT ordinal ORDER BY ordinal LIMIT 100
"#;
    const OBSERVATION_FAILURE_EVENT: &str = "SELECT id,sequence,event_type,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 AND json_extract(payload,'$.failure_id') COLLATE BINARY=$2 AND event_type IN ('ActivityFailed','ActivityTimedOut','ChildRunFailed','ChildRunCancelled','ChildRunTerminated','WorkflowFailed','WorkflowTimedOut','WorkflowCancelled','WorkflowTerminated','UpdateCompleted') ORDER BY sequence DESC LIMIT 1";
    const ACTIVITY_RESULT_EVENT_SQL: &str = "SELECT sequence,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('ActivityScheduled','ActivityStarted','ActivityHeartbeatRecorded','ActivityRetryScheduled','ActivityCompleted','ActivityFailed','ActivityTimedOut','ActivityCancelled') AND (json_extract(payload,'$.activity_execution_id') COLLATE BINARY=$2 OR json_extract(payload,'$.activity.id') COLLATE BINARY=$3) ORDER BY sequence DESC LIMIT 1";
    const QUERY_CACHE_SCAN: &str = "SELECT \"key\",value,expiration FROM dw_query_cache WHERE \"key\" LIKE 'dw-native-query:%' AND json_extract(value,'$.namespace')=$1 ORDER BY \"key\" LIMIT 65";
    const SESSION_RUNNING_ATTEMPTS: &str = "SELECT COUNT(*) FROM activity_attempts p JOIN activity_executions e ON e.id=p.activity_execution_id JOIN workflow_tasks t ON t.id=p.workflow_task_id WHERE t.namespace=$1 AND p.status='running' AND p.lease_expires_at>$2 AND p.lease_owner=$3 AND json_extract(e.activity_options,'$.worker_session.session_id')=$4 AND p.workflow_task_id<>$5";
    const POLL_WAKE_QUERIES_SQL: &str = "SELECT \"key\" AS id FROM dw_query_cache WHERE \"key\" LIKE 'dw-native-query:%' AND expiration>$1 AND json_extract(value,'$.namespace')=$2 AND json_extract(value,'$.task_queue')=$3 AND json_extract(value,'$.query_deadline_at')>$4 AND (json_extract(value,'$.status')='pending' OR (json_extract(value,'$.status')='leased' AND json_extract(value,'$.lease_expires_at')<=$5)) ORDER BY \"key\" LIMIT 65";
    const POLL_WAKE_TASKS_SQL: &str = r#"
WITH utc_tasks AS (
    SELECT t.id,t.namespace,t.queue,t.workflow_run_id,t.status,t.available_at,
        replace(replace(replace(t.available_at,'T',' '),'Z',''),'+00:00','') AS available_utc,
        replace(replace(replace(t.lease_expires_at,'T',' '),'Z',''),'+00:00','') AS lease_utc
    FROM workflow_tasks t WHERE t.namespace=$1 AND t.queue=$2 AND t.task_type!=$3 AND t.task_type IN ('workflow','activity')
), ordered_tasks AS (
    SELECT *,
        rtrim(rtrim(CASE WHEN length(available_utc)=19 THEN available_utc||'.0' ELSE available_utc END,'0'),'.') AS available_order,
        rtrim(rtrim(CASE WHEN length(lease_utc)=19 THEN lease_utc||'.0' ELSE lease_utc END,'0'),'.') AS lease_order
    FROM utc_tasks
)
SELECT t.id,t.namespace,t.queue FROM ordered_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id
WHERE r.status IN ('pending','running','waiting')
    AND (t.status='ready' OR (t.status='leased' AND t.lease_order<=rtrim(rtrim($4,'0'),'.')))
    AND (t.available_at IS NULL OR t.available_order<=rtrim(rtrim($5,'0'),'.'))
ORDER BY t.available_order,t.id LIMIT 100
"#;
    const SELECTION_RESOLUTION_SQL: &str = "SELECT id,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='SelectionResolved' AND json_extract(payload,'$.selection_group_id')=$2 ORDER BY sequence LIMIT 1";
    const STREAM_ITEM_REFERENCES: &str = concat!("SELECT COUNT(*) FROM workflow_durable_stream_items WHERE stream_id=$1 AND (payload_reference IS NOT NULL OR ", super::retention::reference!("payload"), ")");
    const STREAM_REFERENCES: &str = PLAIN_STREAM_REFERENCES;
    const RUN_STREAM_REFERENCES: &str = concat!(
        "SELECT (SELECT COUNT(*) FROM workflow_durable_stream_items WHERE workflow_run_id=$1 AND (payload_reference IS NOT NULL OR ", super::retention::reference!("payload"), "))",
        "+(SELECT COUNT(*) FROM workflow_durable_streams WHERE workflow_run_id=$2 AND ", super::retention::reference!("metadata"), ")"
    );
    const NAMESPACE_STREAM_REFERENCES: &str = concat!(
        "SELECT (SELECT COUNT(*) FROM workflow_durable_stream_items WHERE namespace=$1 AND (payload_reference IS NOT NULL OR ", super::retention::reference!("payload"), "))",
        "+(SELECT COUNT(*) FROM workflow_durable_streams WHERE namespace=$2 AND ", super::retention::reference!("metadata"), ")",
        "+(SELECT COUNT(*) FROM workflow_inbound_stream_items WHERE namespace=$3 AND ", super::retention::reference!("payload_blob"), ")"
    );
    const BRIDGE_COMMAND: &str = "SELECT workflow_run_id,resolved_workflow_run_id FROM workflow_commands WHERE workflow_instance_id=$1 AND command_type=$2 AND status='accepted' AND json_extract(context,'$.server.metadata.adapter')=$3 AND json_extract(context,'$.server.metadata.action')=$4 AND json_extract(context,'$.server.metadata.idempotency_key')=$5 AND json_extract(context,'$.server.metadata.'||$6)=$7 AND (request_id IS NULL OR request_id=$8) ORDER BY accepted_at DESC,command_sequence DESC LIMIT 1";
    const REPAIR_COUNTER_MAX: i64 = i64::MAX;
    const RANGE_REPAIR_TASK_SQL: &str = "SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND namespace=$2 AND task_type='workflow' AND status='failed' AND json_extract(payload,'$.replay_blocked')=1 ORDER BY CASE WHEN available_at IS NULL THEN 1 ELSE 0 END,available_at,created_at,id LIMIT 1";
    const ACTIVITY_OPEN_TASK_SQL: &str = "SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='activity' AND status IN ('ready','leased') AND json_extract(payload,'$.activity_execution_id')=$2 ORDER BY id LIMIT 1";
    const LOCAL_ACTIVITY_EXECUTIONS: &str = "SELECT status FROM activity_executions WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace=$1) AND json_extract(activity_options,'$.execution_mode')='local'";
    const LOCAL_ACTIVITY_ATTEMPTS: &str = "SELECT p.status FROM activity_attempts p JOIN activity_executions a ON a.id=p.activity_execution_id WHERE p.workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace=$1) AND json_extract(a.activity_options,'$.execution_mode')='local'";
    const VISIBILITY_PAGE: &str = super::visibility::SQLITE_VISIBILITY_PAGE;
    type EncodedTime = String;
    type EncodedOptionalTime = Option<String>;
    type EncodedDocument = String;
    const TIMER_TASK_SQL: &str = "SELECT id,payload FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='timer' AND status='ready' AND json_extract(payload,'$.timer_id')=$2 AND namespace=$3";
    const TIMER_SCHEDULE_SQL: &str = "SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='TimerScheduled' AND json_extract(payload,'$.timer_id')=$2 ORDER BY sequence DESC LIMIT 1";
    const SIGNAL_RECEIVED_SEQUENCE_SQL: &str = "SELECT sequence FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='SignalReceived' AND json_extract(payload,'$.signal_id')=$2 ORDER BY sequence LIMIT 1";
    const RUN_COMMANDS_SQL: &str =
        super::store::run_commands_sql!(
        "substr(s.arguments,1,30) COLLATE BINARY='dw-native-external-payload-v1:'",
        "substr(c.payload,1,30) COLLATE BINARY='dw-native-external-payload-v1:'",
        "substr(c.payload,1,23) COLLATE BINARY='dw-external-payload:v1:'"
    );
    const HISTORY_PAGE: &str = "SELECT * FROM (SELECT *,SUM(length(CAST(payload AS BLOB))) OVER (ORDER BY sequence) AS page_bytes FROM workflow_history_events WHERE workflow_run_id=$1 AND sequence>$2 ORDER BY sequence LIMIT $3) AS page WHERE page_bytes<=8388608 ORDER BY sequence";
    // Normalize only the two supported UTC storage shapes. Removing trailing
    // fractional zeros preserves exact decimal ordering, including earlier
    // native precision; SQLite's floating-point date functions are avoided.
    const TASK_CANDIDATES_SQL: &str = r#"
WITH utc_tasks AS (
    SELECT t.*,
        replace(replace(replace(t.available_at,'T',' '),'Z',''),'+00:00','') AS _dw_available_utc,
        replace(replace(replace(t.lease_expires_at,'T',' '),'Z',''),'+00:00','') AS _dw_lease_utc
    FROM workflow_tasks t WHERE t.namespace=$1 AND t.queue=$2 AND t.task_type=$3
), ordered_tasks AS (
    SELECT *,
        rtrim(rtrim(CASE WHEN length(_dw_available_utc)=19 THEN _dw_available_utc||'.0' ELSE _dw_available_utc END,'0'),'.') AS _dw_available_order,
        rtrim(rtrim(CASE WHEN length(_dw_lease_utc)=19 THEN _dw_lease_utc||'.0' ELSE _dw_lease_utc END,'0'),'.') AS _dw_lease_order
    FROM utc_tasks
)
SELECT t.*,r.workflow_type FROM ordered_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id
WHERE r.status IN ('pending','running','waiting')
    AND (t.status='ready' OR (t.status='leased' AND t.task_type='workflow' AND t._dw_lease_order<=rtrim(rtrim($4,'0'),'.')))
    AND (t.available_at IS NULL OR t._dw_available_order<=rtrim(rtrim($5,'0'),'.'))
    AND (COALESCE(NULLIF(t.compatibility,''),NULLIF(r.compatibility,'')) IS NULL OR COALESCE(NULLIF(t.compatibility,''),NULLIF(r.compatibility,''))=$6)
ORDER BY t.priority,t._dw_available_order,t.id LIMIT 100
"#;
    const POLL_RECEIPT_CLEANUP_SQL: &str = r#"
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
    fn optional_time(time: Option<DateTime<Utc>>) -> Self::EncodedOptionalTime {
        time.map(Self::bind_time)
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
    fn optional_number(row: &Self::Row, field: &str) -> Result<Option<i64>> {
        Ok(row.try_get(field)?)
    }
    fn optional_float(row: &Self::Row, field: &str) -> Result<Option<f64>> {
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
    fn optional_document_row(row: &Self::Row, field: &str) -> Result<Option<Value>> {
        match row.try_get::<Option<String>, _>(field)? {
            Some(value) => Ok(Some(serde_json::from_str(&value)?)),
            None => Ok(None),
        }
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

// Published PHP document columns are json. SQLx's ordinary Json binding
// advertises jsonb, which rejects escaped NUL before conversion to json.
// Preserve the column contract while reusing SQLx's JSON wire encoder.
pub(super) struct PostgresDocument(Json<Value>);

impl Type<Postgres> for PostgresDocument {
    fn type_info() -> sqlx::postgres::PgTypeInfo {
        sqlx::postgres::PgTypeInfo::with_name("json")
    }
}

impl Encode<'_, Postgres> for PostgresDocument {
    fn encode_by_ref(
        &self,
        buffer: &mut sqlx::postgres::PgArgumentBuffer,
    ) -> std::result::Result<sqlx::encode::IsNull, sqlx::error::BoxDynError> {
        <Json<Value> as Encode<'_, Postgres>>::encode_by_ref(&self.0, buffer)
    }
}

impl Backend for Postgres {
    type EncodedInteger = Option<i64>;
    type EncodedFloat = Option<f64>;
    type EncodedFlag = Option<bool>;
    fn optional_integer(value: Option<i64>) -> Option<i64> {
        value
    }
    fn optional_float_value(value: Option<f64>) -> Option<f64> {
        value
    }
    fn optional_flag(value: Option<bool>) -> Option<bool> {
        value
    }
    const READ_SNAPSHOT_BEGIN: &str = "BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY";
    const PHP_CONNECTION: &str = "pgsql";
    fn optional_float(row: &Self::Row, field: &str) -> Result<Option<f64>> {
        Ok(row.try_get(field)?)
    }
    type EncodedTime = NaiveDateTime;
    type EncodedOptionalTime = Option<NaiveDateTime>;
    type EncodedDocument = PostgresDocument;
    const TIMER_TASK_SQL: &str = "SELECT id,payload FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='timer' AND status='ready' AND payload->>'timer_id'=$2 AND namespace=$3";
    const HISTORY_PAGE: &str = "SELECT * FROM (SELECT *,SUM(octet_length(payload::text)) OVER (ORDER BY sequence) AS page_bytes FROM workflow_history_events WHERE workflow_run_id=$1 AND sequence>$2 ORDER BY sequence LIMIT $3) AS page WHERE page_bytes<=8388608 ORDER BY sequence";
    fn bind_time(time: DateTime<Utc>) -> NaiveDateTime {
        time.naive_utc()
    }
    fn optional_time(time: Option<DateTime<Utc>>) -> Self::EncodedOptionalTime {
        time.map(Self::bind_time)
    }
    fn document(value: &Value) -> PostgresDocument {
        PostgresDocument(Json(value.clone()))
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
    fn optional_number(row: &Self::Row, field: &str) -> Result<Option<i64>> {
        if let Ok(number) = row.try_get::<Option<i64>, _>(field) {
            return Ok(number);
        }
        if let Ok(number) = row.try_get::<Option<i32>, _>(field) {
            return Ok(number.map(i64::from));
        }
        Ok(row.try_get::<Option<i16>, _>(field)?.map(i64::from))
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
    fn optional_document_row(row: &Self::Row, field: &str) -> Result<Option<Value>> {
        Ok(row
            .try_get::<Option<Json<Value>>, _>(field)?
            .map(|value| value.0))
    }
    async fn begin(pool: &Pool<Self>) -> Result<Transaction<'static, Self>> {
        let mut tx = pool.begin().await?;
        // The first single-namespace slice serializes its durable transitions
        // across independent pools/processes. Held long polls wait without it
        // (Store::task_poll_ready). Throughput/idle cost remain unqualified.
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
    type EncodedInteger = Option<i64>;
    type EncodedFloat = Option<f64>;
    type EncodedFlag = Option<bool>;
    fn optional_integer(value: Option<i64>) -> Option<i64> {
        value
    }
    fn optional_float_value(value: Option<f64>) -> Option<f64> {
        value
    }
    fn optional_flag(value: Option<bool>) -> Option<bool> {
        value
    }
    const SEARCH_ATTRIBUTE_ROWS: &str = "SELECT `key` AS attribute_key,type,value_string,value_keyword,value_int,value_float,CASE WHEN value_bool IS NULL THEN NULL WHEN value_bool THEN 1 ELSE 0 END AS value_bool,value_datetime,value_keyword_list FROM workflow_search_attributes WHERE workflow_run_id=$1 ORDER BY `key` LIMIT 1001";
    const SEARCH_ATTRIBUTE_UPSERT: &str = "INSERT INTO workflow_search_attributes(workflow_run_id,workflow_instance_id,`key`,type,value_string,value_keyword,value_keyword_list,value_int,value_float,value_bool,value_datetime,upserted_at_sequence,inherited_from_parent,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,false,$13,$14) ON DUPLICATE KEY UPDATE workflow_instance_id=VALUES(workflow_instance_id),type=VALUES(type),value_string=VALUES(value_string),value_keyword=VALUES(value_keyword),value_keyword_list=VALUES(value_keyword_list),value_int=VALUES(value_int),value_float=VALUES(value_float),value_bool=VALUES(value_bool),value_datetime=VALUES(value_datetime),upserted_at_sequence=VALUES(upserted_at_sequence),inherited_from_parent=false,updated_at=VALUES(updated_at)";
    const SEARCH_ATTRIBUTE_DELETE: &str = "DELETE FROM workflow_search_attributes WHERE workflow_run_id=$1 AND `key`=$2";
    const SEARCH_ATTRIBUTE_KEY_ROWS: &str = "SELECT a.workflow_run_id,a.`key` AS attribute_key,a.type,a.value_string,a.value_keyword,a.value_int,a.value_float,CASE WHEN a.value_bool IS NULL THEN NULL WHEN a.value_bool THEN 1 ELSE 0 END AS value_bool,a.value_datetime,a.value_keyword_list FROM workflow_search_attributes a JOIN workflow_runs r ON r.id=a.workflow_run_id WHERE r.namespace=$1 AND a.`key`=$2";
    const SEARCH_ATTRIBUTE_INHERIT: &str = "INSERT INTO workflow_search_attributes(workflow_run_id,workflow_instance_id,`key`,type,value_string,value_keyword,value_keyword_list,value_int,value_float,value_bool,value_datetime,upserted_at_sequence,inherited_from_parent,created_at,updated_at) SELECT $1,$2,`key`,type,value_string,value_keyword,value_keyword_list,value_int,value_float,value_bool,value_datetime,1,true,$3,$4 FROM workflow_search_attributes WHERE workflow_run_id=$5";
    const STREAM_ITEM_BY_KEY: &str = "SELECT `offset` FROM workflow_durable_stream_items WHERE stream_id=$1 AND idempotency_key=$2 LIMIT 1";
    const STREAM_ITEM_INSERT: &str = "INSERT INTO workflow_durable_stream_items(stream_id,namespace,workflow_run_id,stream_name,`offset`,idempotency_key,origin,origin_reference,payload,payload_reference,payload_codec,item_type,content_type,emitted_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16)";
    const STREAM_ITEMS_WINDOW: &str = "SELECT * FROM workflow_durable_stream_items WHERE stream_id=$1 AND `offset`>=$2 ORDER BY `offset` LIMIT $3";
    const STREAM_SWEEP_BOUNDARY: &str = "SELECT `offset` FROM workflow_durable_stream_items WHERE stream_id=$1 ORDER BY `offset` LIMIT 1 OFFSET $2";
    const STREAM_SWEEP_THROUGH: &str = "DELETE FROM workflow_durable_stream_items WHERE stream_id=$1 AND `offset`<=$2";
    const STREAM_ITEM_REFERENCES: &str = concat!("SELECT COUNT(*) FROM workflow_durable_stream_items WHERE stream_id=$1 AND (payload_reference IS NOT NULL OR ", super::retention::reference!("payload"), ")");
    const STREAM_REFERENCES: &str = PLAIN_STREAM_REFERENCES;
    const RUN_STREAM_REFERENCES: &str = concat!(
        "SELECT (SELECT COUNT(*) FROM workflow_durable_stream_items WHERE workflow_run_id=$1 AND (payload_reference IS NOT NULL OR ", super::retention::reference!("payload"), "))",
        "+(SELECT COUNT(*) FROM workflow_durable_streams WHERE workflow_run_id=$2 AND ", super::retention::reference!("metadata"), ")"
    );
    const NAMESPACE_STREAM_REFERENCES: &str = concat!(
        "SELECT (SELECT COUNT(*) FROM workflow_durable_stream_items WHERE namespace=$1 AND (payload_reference IS NOT NULL OR ", super::retention::reference!("payload"), "))",
        "+(SELECT COUNT(*) FROM workflow_durable_streams WHERE namespace=$2 AND ", super::retention::reference!("metadata"), ")",
        "+(SELECT COUNT(*) FROM workflow_inbound_stream_items WHERE namespace=$3 AND ", super::retention::reference!("payload_blob"), ")"
    );
    const READ_SNAPSHOT_BEGIN: &str = "START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY";
    const ACTIVITY_METADATA_HISTORY: &str = "SELECT h.sequence,h.event_type,CASE WHEN page.projection_bytes<=8388608 THEN h.payload ELSE NULL END AS payload,h.workflow_task_id,h.recorded_at,page.projection_count FROM (SELECT id,sequence,COUNT(*) OVER () AS projection_count,SUM(payload_bytes) OVER (ORDER BY sequence) AS projection_bytes FROM (SELECT id,sequence,octet_length(CAST(payload AS CHAR CHARACTER SET utf8mb4)) AS payload_bytes FROM workflow_history_events FORCE INDEX (workflow_history_events_workflow_run_id_sequence_unique) WHERE workflow_run_id=$1 AND event_type IN ('ActivityScheduled','ActivityStarted','ActivityHeartbeatRecorded','ActivityRetryScheduled','ActivityCompleted','ActivityFailed','ActivityTimedOut','ActivityCancelled')) AS sizes ORDER BY sequence LIMIT 10001) AS page JOIN workflow_history_events h ON h.id=page.id ORDER BY page.sequence";
    const PHP_CONNECTION: &str = "mysql";
    const WAIT_POSITION_MOVES: &str = "WITH clocks AS (SELECT id,wait_id,position,opened_at,TIMESTAMPDIFF(MICROSECOND,'1970-01-01 00:00:00',opened_at) AS micros FROM workflow_run_waits WHERE workflow_run_id=?), ordinals AS (SELECT id,position,CAST(ROW_NUMBER() OVER (ORDER BY CASE WHEN opened_at IS NULL THEN 1 ELSE 0 END,ROUND((CASE WHEN micros<0 THEN 2*FLOOR(micros/1000000)*1000000-micros ELSE micros END)/1000),wait_id) AS SIGNED)-1 AS ordinal FROM clocks) SELECT id,ordinal FROM ordinals WHERE NOT (position <=> ordinal) ORDER BY ordinal LIMIT 100";
    // The run-and-sequence index keeps MySQL from sorting whole rows: a
    // filesort of one large payload exceeds the default sort buffer and
    // fails the transaction ("Out of sort memory").
    const SUMMARY_HISTORY_SQL: &str = "SELECT id,sequence,event_type,CAST(payload AS CHAR) AS payload_json FROM workflow_history_events FORCE INDEX (workflow_history_events_workflow_run_id_sequence_unique) WHERE workflow_run_id=$1 AND sequence>$2 ORDER BY sequence LIMIT 16";
    const OBSERVATION_FAILURE_EVENT: &str = "SELECT id,sequence,event_type,recorded_at FROM workflow_history_events WHERE workflow_run_id=? AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.failure_id')) AS BINARY)=CAST(? AS BINARY) AND event_type IN ('ActivityFailed','ActivityTimedOut','ChildRunFailed','ChildRunCancelled','ChildRunTerminated','WorkflowFailed','WorkflowTimedOut','WorkflowCancelled','WorkflowTerminated','UpdateCompleted') ORDER BY sequence DESC LIMIT 1";
    const OBSERVATION_ATTRIBUTES: &str = "SELECT `key` AS attribute_key,type,value_string,value_keyword,value_int,value_float,CASE WHEN value_bool IS NULL THEN NULL WHEN value_bool THEN 1 ELSE 0 END AS value_bool,value_datetime,value_keyword_list FROM workflow_search_attributes WHERE workflow_run_id=$1 AND workflow_instance_id=$2 AND `key` IN ($3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21,$22) ORDER BY `key` LIMIT 20";
    fn optional_float(row: &Self::Row, field: &str) -> Result<Option<f64>> {
        Ok(row.try_get(field)?)
    }
    const ACTIVITY_RESULT_EVENT_SQL: &str = "SELECT sequence,event_type,payload FROM workflow_history_events WHERE CAST(workflow_run_id AS BINARY)=CAST(? AS BINARY) AND event_type IN ('ActivityScheduled','ActivityStarted','ActivityHeartbeatRecorded','ActivityRetryScheduled','ActivityCompleted','ActivityFailed','ActivityTimedOut','ActivityCancelled') AND (CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.activity_execution_id')) AS BINARY)=CAST(? AS BINARY) OR CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.activity.id')) AS BINARY)=CAST(? AS BINARY)) ORDER BY sequence DESC LIMIT 1";
    const QUERY_CACHE_SCAN: &str = "SELECT `key`,value,expiration FROM dw_query_cache WHERE `key` LIKE 'dw-native-query:%' AND BINARY JSON_UNQUOTE(JSON_EXTRACT(value,'$.namespace'))=BINARY ? ORDER BY `key` LIMIT 65";
    const POLL_WAKE_QUERIES_SQL: &str = "SELECT `key` AS id FROM dw_query_cache WHERE `key` LIKE 'dw-native-query:%' AND expiration>? AND CAST(JSON_UNQUOTE(JSON_EXTRACT(value,'$.namespace')) AS BINARY)=CAST(? AS BINARY) AND CAST(JSON_UNQUOTE(JSON_EXTRACT(value,'$.task_queue')) AS BINARY)=CAST(? AS BINARY) AND CAST(JSON_UNQUOTE(JSON_EXTRACT(value,'$.query_deadline_at')) AS BINARY)>CAST(? AS BINARY) AND (JSON_UNQUOTE(JSON_EXTRACT(value,'$.status'))='pending' OR (JSON_UNQUOTE(JSON_EXTRACT(value,'$.status'))='leased' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(value,'$.lease_expires_at')) AS BINARY)<=CAST(? AS BINARY))) ORDER BY `key` LIMIT 65";
    const EXTERNAL_PAYLOAD_BYTES: &str = "SELECT CAST(COALESCE(SUM(size_bytes),0) AS SIGNED) FROM runtime_external_payloads WHERE namespace=$1";
    const MEMO_UPSERT: &str = "INSERT INTO workflow_memos(workflow_run_id,workflow_instance_id,`key`,value,upserted_at_sequence,inherited_from_parent,created_at,updated_at,portable_value,portable_value_sequence) VALUES ($1,$2,$3,$4,$5,false,$6,$7,$8,$9) ON DUPLICATE KEY UPDATE value=VALUES(value),upserted_at_sequence=VALUES(upserted_at_sequence),inherited_from_parent=false,updated_at=VALUES(updated_at),portable_value=VALUES(portable_value),portable_value_sequence=VALUES(portable_value_sequence)";
    const MEMO_DELETE: &str =
        "DELETE FROM workflow_memos WHERE workflow_run_id=$1 AND `key`=$2";
    const MEMO_INITIAL_INSERT: &str = "INSERT INTO workflow_memos(workflow_run_id,workflow_instance_id,`key`,value,upserted_at_sequence,inherited_from_parent,created_at,updated_at,portable_value,portable_value_sequence) VALUES ($1,$2,$3,$4,0,false,$5,$6,$7,0)";
    const MEMO_INHERIT_INSERT: &str = "INSERT INTO workflow_memos(workflow_run_id,workflow_instance_id,`key`,value,upserted_at_sequence,inherited_from_parent,created_at,updated_at,portable_value,portable_value_sequence) VALUES ($1,$2,$3,$4,1,true,$5,$6,$7,1)";
    const MEMO_READ: &str = "SELECT `key`,value,portable_value,portable_value_sequence,upserted_at_sequence FROM workflow_memos WHERE workflow_run_id=$1 ORDER BY `key`,id LIMIT 101";
    const SELECTION_RESOLUTION_SQL: &str = "SELECT id,payload FROM workflow_history_events WHERE workflow_run_id=? AND event_type='SelectionResolved' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.selection_group_id'))=? ORDER BY sequence LIMIT 1";
    const BRIDGE_COMMAND: &str = "SELECT workflow_run_id,resolved_workflow_run_id FROM workflow_commands WHERE workflow_instance_id=? AND command_type=? AND status='accepted' AND BINARY JSON_UNQUOTE(JSON_EXTRACT(context,'$.server.metadata.adapter'))=BINARY ? AND BINARY JSON_UNQUOTE(JSON_EXTRACT(context,'$.server.metadata.action'))=BINARY ? AND BINARY JSON_UNQUOTE(JSON_EXTRACT(context,'$.server.metadata.idempotency_key'))=BINARY ? AND BINARY JSON_UNQUOTE(JSON_EXTRACT(context,CONCAT('$.server.metadata.',?)))=BINARY ? AND (request_id IS NULL OR BINARY request_id=BINARY ?) ORDER BY accepted_at DESC,command_sequence DESC LIMIT 1";
    const REPAIR_COUNTER_MAX: i64 = u32::MAX as i64;
    const RANGE_REPAIR_TASK_SQL: &str = "SELECT * FROM workflow_tasks WHERE workflow_run_id=? AND namespace=? AND task_type='workflow' AND status='failed' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.replay_blocked'))='true' ORDER BY CASE WHEN available_at IS NULL THEN 1 ELSE 0 END,available_at,created_at,id LIMIT 1";
    const ACTIVITY_OPEN_TASK_SQL: &str = "SELECT * FROM workflow_tasks WHERE workflow_run_id=? AND task_type='activity' AND status IN ('ready','leased') AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.activity_execution_id'))=? ORDER BY id LIMIT 1";
    const LOCAL_ACTIVITY_EXECUTIONS: &str = "SELECT status FROM activity_executions WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace=?) AND JSON_UNQUOTE(JSON_EXTRACT(activity_options,'$.execution_mode'))='local'";
    const LOCAL_ACTIVITY_ATTEMPTS: &str = "SELECT p.status FROM activity_attempts p JOIN activity_executions a ON a.id=p.activity_execution_id WHERE p.workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace=?) AND JSON_UNQUOTE(JSON_EXTRACT(a.activity_options,'$.execution_mode'))='local'";
    type EncodedTime = DateTime<Utc>;
    type EncodedOptionalTime = Option<DateTime<Utc>>;
    type EncodedDocument = Json<Value>;
    const QUERY_CACHE_GET: &str =
        "SELECT value,expiration FROM dw_query_cache WHERE `key`=$1";
    const QUERY_CACHE_INSERT: &str =
        "INSERT INTO dw_query_cache(`key`,value,expiration) VALUES ($1,$2,$3)";
    const QUERY_CACHE_UPDATE: &str =
        "UPDATE dw_query_cache SET value=$1,expiration=$2 WHERE `key`=$3";
    const QUERY_CACHE_DELETE: &str = "DELETE FROM dw_query_cache WHERE `key`=$1";
    const QUERY_POLL_EXPIRED: &str = "SELECT `key` FROM dw_query_cache WHERE `key` LIKE 'dw-native-query-poll:%' AND expiration<=$1 ORDER BY `key` LIMIT 32";
    const WORKFLOW_POLL_EXPIRED: &str = "SELECT `key` FROM dw_query_cache WHERE `key` LIKE 'dw-native-workflow-poll:%' AND expiration<=$1 ORDER BY `key` LIMIT 32";
    const QUERY_CACHE_LOCK: &str = "INSERT INTO dw_query_cache(`key`,value,expiration) VALUES ($1,$2,$3) ON DUPLICATE KEY UPDATE expiration=expiration";
    const SESSION_RUNNING_ATTEMPTS: &str = "SELECT COUNT(*) FROM activity_attempts p JOIN activity_executions e ON e.id=p.activity_execution_id JOIN workflow_tasks t ON t.id=p.workflow_task_id WHERE t.namespace=? AND p.status='running' AND p.lease_expires_at>? AND p.lease_owner=? AND JSON_UNQUOTE(JSON_EXTRACT(e.activity_options,'$.worker_session.session_id'))=? AND p.workflow_task_id<>?";
    const TASK_KIND_CURSOR_LOCK: &str = "INSERT INTO workflow_task_poll_cursors(namespace,task_queue,next_task_kind,created_at,updated_at) VALUES ($1,$2,'update_validation',$3,$4) ON DUPLICATE KEY UPDATE next_task_kind=next_task_kind";
    const TIMER_TASK_SQL: &str = "SELECT id,payload FROM workflow_tasks WHERE workflow_run_id=? AND task_type='timer' AND status='ready' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.timer_id'))=? AND BINARY namespace=BINARY ?";
    const TIMER_SCHEDULE_SQL: &str = "SELECT payload FROM workflow_history_events WHERE workflow_run_id=? AND event_type='TimerScheduled' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.timer_id'))=? ORDER BY sequence DESC LIMIT 1";
    const SIGNAL_RECEIVED_SEQUENCE_SQL: &str = "SELECT sequence FROM workflow_history_events WHERE workflow_run_id=? AND event_type='SignalReceived' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.signal_id'))=? ORDER BY sequence LIMIT 1";
    // The payload column's collation ignores case; compare bytes.
    const RUN_COMMANDS_SQL: &str =
        super::store::run_commands_sql!(
        "CAST(SUBSTRING(s.arguments,1,30) AS BINARY)='dw-native-external-payload-v1:'",
        "CAST(SUBSTRING(c.payload,1,30) AS BINARY)='dw-native-external-payload-v1:'",
        "CAST(SUBSTRING(c.payload,1,23) AS BINARY)='dw-external-payload:v1:'"
    );
    // First use the preserved run/sequence index to materialize only IDs and
    // byte counts for this bounded page. Sorting JSON payloads in the window
    // exhausts MySQL's default sort buffer before the size rejection can run.
    const HISTORY_PAGE: &str = "SELECT h.* FROM (SELECT id,sequence,SUM(payload_bytes) OVER (ORDER BY sequence) AS page_bytes FROM (SELECT id,sequence,LENGTH(CAST(payload AS CHAR CHARACTER SET utf8mb4)) AS payload_bytes FROM workflow_history_events FORCE INDEX (workflow_history_events_workflow_run_id_sequence_unique) WHERE workflow_run_id=? AND sequence>? ORDER BY sequence LIMIT ?) AS sizes) AS page JOIN workflow_history_events h ON h.id=page.id WHERE page.page_bytes<=8388608 ORDER BY page.sequence";
    const WORKER_REGISTRATION_SQL: &str = "INSERT INTO workflow_worker_registrations(namespace,worker_id,task_queue,runtime,sdk_version,build_id,supported_workflow_types,supported_activity_types,capabilities,capability_manifest,last_heartbeat_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE task_queue=VALUES(task_queue),runtime=VALUES(runtime),sdk_version=VALUES(sdk_version),build_id=VALUES(build_id),supported_workflow_types=VALUES(supported_workflow_types),supported_activity_types=VALUES(supported_activity_types),capabilities=VALUES(capabilities),capability_manifest=VALUES(capability_manifest),last_heartbeat_at=VALUES(last_heartbeat_at),updated_at=VALUES(updated_at)";
    fn statement(sql: &'static str) -> Cow<'static, str> {
        // These driver-owned templates already use '?' bindings and contain
        // literal '$.' JSON paths, not PostgreSQL bind placeholders.
        if !sql.contains('$')
            || sql == Self::TIMER_TASK_SQL
            || sql == Self::TIMER_SCHEDULE_SQL
            || sql == Self::SIGNAL_RECEIVED_SEQUENCE_SQL
            || sql == Self::RANGE_REPAIR_TASK_SQL
            || sql == Self::SELECTION_RESOLUTION_SQL
            || sql == Self::BRIDGE_COMMAND
            || sql == Self::ACTIVITY_OPEN_TASK_SQL
            || sql == Self::LOCAL_ACTIVITY_EXECUTIONS
            || sql == Self::LOCAL_ACTIVITY_ATTEMPTS
            || sql == Self::ACTIVITY_RESULT_EVENT_SQL
            || sql == Self::OBSERVATION_FAILURE_EVENT
            || sql == Self::POLL_WAKE_QUERIES_SQL
            || sql == Self::QUERY_CACHE_SCAN
            || sql == Self::SESSION_RUNNING_ATTEMPTS
        {
            return Cow::Borrowed(sql);
        }
        // Shared templates use ordered, unique $N positions. Convert only
        // compiled static SQL; request data never enters this string and stays
        // in SQLx bind parameters. The adapter accepts no arbitrary SQL input.
        // A '$' without digits, such as a JSON path '$[*]', stays literal.
        let mut parts = sql.split('$');
        let mut result = parts.next().unwrap().to_owned();
        let mut position = 0;
        for part in parts {
            let digits = part.bytes().take_while(u8::is_ascii_digit).count();
            if digits == 0 {
                result.push('$');
                result.push_str(part);
                continue;
            }
            position += 1;
            assert_eq!(
                part[..digits].parse::<usize>().ok(),
                Some(position),
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
    fn optional_time(time: Option<DateTime<Utc>>) -> Self::EncodedOptionalTime {
        time.map(Self::bind_time)
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
    fn optional_number(row: &Self::Row, field: &str) -> Result<Option<i64>> {
        if let Ok(number) = row.try_get::<Option<i64>, _>(field) {
            return Ok(number);
        }
        row.try_get::<Option<u64>, _>(field)?
            .map(|number| {
                i64::try_from(number).map_err(|_| {
                    super::refuse(
                        axum::http::StatusCode::CONFLICT,
                        "unsupported_stored_integer",
                    )
                })
            })
            .transpose()
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
    fn optional_document_row(row: &Self::Row, field: &str) -> Result<Option<Value>> {
        Ok(row
            .try_get::<Option<Json<Value>>, _>(field)?
            .map(|value| value.0))
    }
    async fn begin(pool: &Pool<Self>) -> Result<Transaction<'static, Self>> {
        let mut tx = pool.begin().await?;
        // InnoDB row locking releases on commit/rollback/process loss. This
        // first slice serializes transitions on its single ownership row.
        sqlx::query("SELECT engine FROM dw_server_schema WHERE engine='rust-development' AND version=? FOR UPDATE")
            .bind(mysql::VERSION).fetch_one(&mut *tx).await?;
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

    #[test]
    fn mysql_statement_converts_numbered_positions_and_keeps_json_paths() {
        let sql = "SELECT COUNT(*) FROM s WHERE namespace=$1 AND at>=$2 AND t IN (SELECT jt.t FROM JSON_TABLE($3,'$[*]' COLUMNS(t VARCHAR(255) PATH '$')) jt)";
        assert_eq!(
            MySql::statement(sql),
            "SELECT COUNT(*) FROM s WHERE namespace=? AND at>=? AND t IN (SELECT jt.t FROM JSON_TABLE(?,'$[*]' COLUMNS(t VARCHAR(255) PATH '$')) jt)"
        );
    }

    #[test]
    #[should_panic(expected = "ordered unique positions")]
    fn mysql_statement_refuses_out_of_order_positions_after_a_json_path() {
        let _ = MySql::statement("SELECT '$[*]' WHERE a=$2");
    }

    #[tokio::test]
    async fn sqlite_php_and_native_deadlines_keep_microsecond_claim_and_receipt_fences() {
        let directory = tempfile::tempdir().unwrap();
        let runtime = super::super::Runtime::open(
            directory.path().join("time.sqlite").to_str().unwrap(),
            "test-token".into(),
        )
        .await
        .unwrap();
        runtime.start(json!({"workflow_id":"timestamp-fences", "workflow_type":"echo", "task_queue":"test", "input":{"codec":"avro","blob":"wwHioz3/VYAiNwA="}}), None).await.unwrap();
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
                .bind("default")
                .bind("test")
                .bind("workflow")
                .bind(Sqlite::bind_time(clock))
                .bind(Sqlite::bind_time(clock))
                .bind(None::<&str>)
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
                .bind("default")
                .bind("test")
                .bind("workflow")
                .bind(Sqlite::bind_time(clock))
                .bind(Sqlite::bind_time(clock))
                .bind(None::<&str>)
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
        for value in ["2026-01-02 03:04:05", "2026-01-02T03:04:05Z"] {
            assert_eq!(
                sqlite_instant(value.into())
                    .unwrap()
                    .timestamp_subsec_nanos(),
                0
            );
        }
        for value in [
            "not-a-date",
            "2026-02-30 03:04:05.123456",
            "2026-01-02 03:04:05.123456 trailing",
            "2026-01-02T03:04:05.123456+02:00",
            "2026-01-02T03:04:05.123456-02:00",
            "2026-01-02T03:04:05.123456-00:00",
            "2026-01-02t03:04:05.123456z",
            "2026-1-2 03:04:05.123456",
        ] {
            assert!(sqlite_instant(value.into()).is_err(), "invalid {value}");
        }
    }
}
