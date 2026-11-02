//! One durable execution state machine for typed database adapters.
use super::transaction::Transaction;
use super::{Result, backend::Backend, envelope, refuse, side_effect_result, text, wire};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Map, Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Pool, Type};
use std::{sync::Arc, time::Duration};
use tokio::sync::{Notify, Semaphore};
use ulid::Ulid;

pub(super) struct PreparedControlArguments {
    pub(super) arguments: String,
    pub(super) values: Vec<crate::codec::Value>,
    pub(super) value: String,
    pub(super) command: String,
}
/// The events PHP's Workflow package records with the task model in hand,
/// which therefore carry the task's snapshot.
const TASK_SNAPSHOT_EVENTS: &[&str] = &[
    "ActivityCompleted",
    "ActivityFailed",
    "ActivityHeartbeatRecorded",
    "ActivityRetryScheduled",
    "ActivityScheduled",
    "ActivityStarted",
    "ActivityTimedOut",
    "ChildRunStarted",
    "ChildWorkflowScheduled",
    "ConditionWaitOpened",
    "ConditionWaitSatisfied",
    "ConditionWaitTimedOut",
    "FailureHandled",
    "MemoUpserted",
    "MessageCursorAdvanced",
    "SearchAttributesUpserted",
    "SelectionOperationCancelled",
    "SideEffectRecorded",
    "SignalApplied",
    "SignalWaitOpened",
    "TimerFired",
    "TimerScheduled",
    "UpdateApplied",
    "UpdateCompleted",
    "VersionMarkerRecorded",
    "WorkflowCompleted",
    "WorkflowContinuedAsNew",
    "WorkflowFailed",
    "WorkflowTimedOut",
];
/// Describe's command journal. Each backend passes its exact, case-sensitive
/// test for native's stored-reference prefix
/// (`external_resolution::STORED_PREFIX`) over the column that holds a
/// command's input: a signal's in its signal record (`s.arguments`), a start's
/// in the command row (`c.payload`). Other commands keep no stored reference.
/// Only a stored reference is read; inline payloads are not.
macro_rules! run_commands_sql {
    ($signal:literal, $start:literal, $php:literal) => {
        concat!(
            "SELECT c.id,c.command_sequence,c.command_type,c.target_scope,c.requested_workflow_run_id,c.resolved_workflow_run_id,c.source,c.status,c.outcome,c.rejection_reason,c.workflow_type,c.workflow_class,c.accepted_at,c.applied_at,c.rejected_at,c.context,s.signal_name,CASE WHEN c.command_type='signal' AND ",
            $signal,
            " THEN s.arguments WHEN c.command_type='start' AND ",
            $start,
            " THEN c.payload END AS stored_payload,CASE WHEN ",
            $php,
            // An imported PHP history keeps PHP's own external references.
            " THEN c.payload END AS php_payload_reference,",
            // `commandReason()` reads only small inline payloads of commands
            // that were not rejected; references are never read for a reason.
            "CASE WHEN c.status<>'rejected' AND (c.payload_codec IS NULL OR c.payload_codec='avro') AND LENGTH(c.payload)<=8192 AND NOT (",
            $start,
            ") AND NOT (",
            $php,
            ") THEN c.payload END AS reason_payload FROM workflow_commands c LEFT JOIN workflow_signal_records s ON s.workflow_command_id=c.id AND s.workflow_instance_id=c.workflow_instance_id WHERE c.workflow_run_id=$1 AND c.workflow_instance_id=$2 ORDER BY c.command_sequence,c.created_at,c.id"
        )
    };
}
pub(super) use run_commands_sql;
/// One signal command's identity and sequences in its run, as both the
/// public signal route and the runtime's message-stream deliveries write it.
pub(super) struct SignalWrite<'a> {
    pub(super) workflow_id: &'a str,
    pub(super) run_id: &'a str,
    pub(super) workflow_type: &'a str,
    pub(super) name: &'a str,
    pub(super) command_id: &'a str,
    pub(super) signal_id: &'a str,
    pub(super) command_sequence: i64,
    pub(super) message_sequence: i64,
    pub(super) context: &'a Value,
    /// A bridge command's identity, as its `request_id`.
    pub(super) request_id: Option<&'a str>,
}
/// The runtime-owned message-stream delivery signal (`WorkflowStub::MESSAGE_STREAM_RUNTIME_SIGNAL`).
const MESSAGE_STREAM_SIGNAL: &str = super::message_streams::INTERNAL_SIGNAL;
pub(super) const TASK_CANDIDATES_SQL: &str = "SELECT t.*,r.workflow_type FROM workflow_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id WHERE t.namespace=$1 AND t.queue=$2 AND t.task_type=$3 AND r.status IN ('pending','running','waiting') AND (t.status='ready' OR (t.status='leased' AND t.task_type='workflow' AND t.lease_expires_at<=$4)) AND (t.available_at IS NULL OR t.available_at<=$5) AND (COALESCE(NULLIF(t.compatibility,''),NULLIF(r.compatibility,'')) IS NULL OR COALESCE(NULLIF(t.compatibility,''),NULLIF(r.compatibility,''))=$6) ORDER BY t.priority,t.available_at,t.id LIMIT 100";
/// A signal record's place in `PendingMessageTask::signalFollows`: its
/// command sequence (unsequenced last), receipt and creation times, id.
struct SignalOrder {
    sequence: Option<i64>,
    received_at: Option<DateTime<Utc>>,
    created_at: Option<DateTime<Utc>>,
    id: String,
}

impl SignalOrder {
    fn read<DB: Backend>(row: &DB::Row) -> Result<Self> {
        Ok(Self {
            sequence: DB::optional_number(row, "command_sequence")?,
            received_at: DB::optional_instant(row, "received_at")?,
            created_at: DB::optional_instant(row, "created_at")?,
            id: DB::string(row, "id")?,
        })
    }

    fn follows(&self, attempted: &Self) -> bool {
        if self.sequence != attempted.sequence {
            return match (self.sequence, attempted.sequence) {
                (None, _) => true,
                (_, None) => false,
                (Some(candidate), Some(attempted)) => candidate > attempted,
            };
        }
        for (candidate, attempted) in [(self.received_at, attempted.received_at), (self.created_at, attempted.created_at)] {
            if candidate != attempted {
                return candidate.is_some_and(|candidate| attempted.is_none_or(|attempted| candidate > attempted));
            }
        }
        self.id > attempted.id
    }
}

/// `nextEligibleSignal`: a run's received signals in PHP's order: command
/// sequence (unsequenced last), receipt time, then id.
const RECEIVED_SIGNALS: &str = "SELECT id,signal_name,signal_wait_id,command_sequence,workflow_command_id,received_at,created_at FROM workflow_signal_records WHERE workflow_run_id=$1 AND status='received' AND closed_at IS NULL ORDER BY CASE WHEN command_sequence IS NULL THEN 1 ELSE 0 END,command_sequence,received_at,id LIMIT 100";

pub(super) const POLL_RECEIPT_CLEANUP_SQL: &str = "DELETE FROM dw_poll_receipts WHERE expires_at<=$1 AND task_id IN (SELECT id FROM workflow_tasks WHERE status!='leased' OR lease_expires_at<=$2)";
pub(super) const WORKER_REGISTRATION_SQL: &str = "INSERT INTO workflow_worker_registrations(namespace,worker_id,task_queue,runtime,sdk_version,build_id,supported_workflow_types,supported_activity_types,capabilities,capability_manifest,last_heartbeat_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13) ON CONFLICT(worker_id,namespace) DO UPDATE SET task_queue=excluded.task_queue,runtime=excluded.runtime,sdk_version=excluded.sdk_version,build_id=excluded.build_id,supported_workflow_types=excluded.supported_workflow_types,supported_activity_types=excluded.supported_activity_types,capabilities=excluded.capabilities,capability_manifest=excluded.capability_manifest,last_heartbeat_at=excluded.last_heartbeat_at,updated_at=excluded.updated_at";
/// A registration's build id as the pollers compare it: trimmed, and none
/// when blank. A task pinned to a build goes only to that build's workers.
pub(super) fn worker_build<DB: Backend>(row: &DB::Row) -> Result<Option<String>> {
    Ok(DB::optional_string(row, "build_id")?
        .map(|build| build.trim().to_owned())
        .filter(|build| !build.is_empty()))
}
pub(super) struct Store<DB: Backend> {
    pub(super) pool: Pool<DB>,
    pub(super) wake: Arc<Notify>,
    pub(super) namespace: Arc<str>,
    pub(super) memo_codec: Arc<Semaphore>,
    pub(super) memo_size_limit: Option<usize>,
    pub(super) history_budget: super::history_budget::Config,
    /// How long a claimed workflow task stays leased (DW_WORKFLOW_TASK_TIMEOUT).
    pub(super) workflow_task_lease_seconds: i64,
    /// How long a claimed activity task stays leased (DW_ACTIVITY_TASK_TIMEOUT).
    pub(super) activity_task_lease_seconds: i64,
    /// How long a completion wait for an update lasts by default.
    pub(super) update_wait_seconds: i64,
    pub(super) search_attribute_limit: i64,
    /// When a silent registration stops counting (`server.workers.stale_after_seconds`).
    pub(super) worker_stale_after_seconds: i64,
    /// How many abandoned workflow task leases a probe recovers at most
    /// (`server.polling.expired_workflow_task_recovery_scan_limit`).
    pub(super) workflow_task_recovery_scan_limit: i64,
    /// Bounds the repair payload encoding of recovered workflow tasks.
    pub(super) repair_codec: Arc<Semaphore>,
    /// When this server last ran a task repair pass, shared by its namespaces
    /// as PHP shares its loop throttle key.
    pub(super) repair_pass_at: Arc<std::sync::Mutex<Option<std::time::Instant>>>,
}
/// The `closed_reason` a terminal history event gives its run, or `None` for
/// an event that does not close the run.
fn closed_reason(event_type: &str) -> Option<&'static str> {
    Some(match event_type {
        "WorkflowCompleted" => "completed",
        "WorkflowContinuedAsNew" => "continued",
        "WorkflowFailed" => "failed",
        "WorkflowCancelled" => "cancelled",
        "WorkflowTerminated" => "terminated",
        "WorkflowTimedOut" => "timed_out",
        _ => return None,
    })
}
/// A ULID that sorts after every earlier one from this process, as Laravel's
/// (Symfony's) generator does. Rows created in the same millisecond, such as
/// the activity tasks of one completion, keep their creation order in
/// `ORDER BY available_at,id`.
pub(super) fn id() -> String {
    static GENERATOR: std::sync::Mutex<ulid::Generator> =
        std::sync::Mutex::new(ulid::Generator::new());
    let mut generator = GENERATOR
        .lock()
        .unwrap_or_else(std::sync::PoisonError::into_inner);
    // Overflow needs 2^80 identifiers in one millisecond.
    generator
        .generate()
        .unwrap_or_else(|_| Ulid::new())
        .to_string()
}

/// The distinct, non-empty signal IDs a history page's `SignalReceived`
/// events name: the only signal records its hydration reads.
fn page_signal_ids(events: &[Value]) -> std::collections::BTreeSet<String> {
    events
        .iter()
        .filter(|event| event["event_type"] == "SignalReceived")
        .filter_map(|event| event["payload"]["signal_id"].as_str())
        .filter(|id| !id.is_empty())
        .map(str::to_owned)
        .collect()
}

#[cfg(test)]
#[test]
fn a_history_page_reads_only_its_own_signal_records() {
    let signal = |id: &str| json!({"event_type":"SignalReceived","payload":{"signal_id":id}});
    let page = [signal("b"), signal("a"), signal("a"), signal(""), json!({"event_type":"TimerFired","payload":{"signal_id":"c"}}),
        json!({"event_type":"SignalReceived","payload":{}})];
    assert_eq!(page_signal_ids(&page).into_iter().collect::<Vec<_>>(), ["a", "b"]);
    // One event, one record, however many signals the run holds.
    assert_eq!(page_signal_ids(&[signal("only")]).len(), 1);
    assert!(page_signal_ids(&[]).is_empty());
}

// Hydration never reads a record the page does not name. The run's other
// record holds a sequence that cannot be decoded (SQLite keeps the text in
// an integer column), so a run-wide read fails where a page-scoped one
// succeeds; a same-ID record of another run stays fenced out.
#[cfg(test)]
#[tokio::test]
async fn signal_hydration_reads_no_record_outside_the_page() {
    use sqlx::Connection;
    let mut connection = sqlx::SqliteConnection::connect("sqlite::memory:").await.unwrap();
    sqlx::query("CREATE TABLE workflow_signal_records (id TEXT, workflow_run_id TEXT, workflow_sequence INTEGER)")
        .execute(&mut connection)
        .await
        .unwrap();
    for (id, run, sequence) in [("page", "run", "7"), ("unrelated", "run", "not-a-sequence"), ("fenced", "other", "9")] {
        sqlx::query("INSERT INTO workflow_signal_records VALUES ($1, $2, $3)")
            .bind(id)
            .bind(run)
            .bind(sequence)
            .execute(&mut connection)
            .await
            .unwrap();
    }
    let mut events = vec![
        json!({"event_type":"SignalReceived","payload":{"signal_id":"page"}}),
        json!({"event_type":"SignalReceived","payload":{"signal_id":"fenced"}}),
    ];
    Store::<sqlx::Sqlite>::with_signal_sequences(&mut connection, "run", &mut events).await.unwrap();
    assert_eq!(events[0]["payload"]["workflow_sequence"], 7, "{events:?}");
    assert!(events[1]["payload"].get("workflow_sequence").is_none(), "{events:?}");
}

#[cfg(test)]
#[test]
fn identifiers_keep_creation_order_within_a_millisecond() {
    let ids: Vec<String> = (0..10_000).map(|_| id()).collect();
    assert!(ids.windows(2).all(|pair| pair[0] < pair[1]));
}
pub(super) fn now() -> DateTime<Utc> {
    DateTime::from_timestamp_millis(Utc::now().timestamp_millis()).expect("current timestamp")
}
fn after(seconds: i64) -> DateTime<Utc> {
    now() + chrono::Duration::seconds(seconds)
}

/// As PHP's `ActivityTaskController::poll` (since 2.11.0): an activity poll
/// answer carries `server_time`, the clock its lease expiry is measured
/// against. Published SDKs refuse a claimed activity without it.
fn activity_server_time(kind: &str, mut answer: Value) -> Value {
    if kind == "activity" {
        answer["server_time"] = json!(super::activity_lease_renewal::micros(now()));
    }
    answer
}

impl<DB: Backend> Store<DB>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> Option<&'q str>: Encode<'q, DB>,
    for<'q> Option<DB::EncodedDocument>: Encode<'q, DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'r> i64: Decode<'r, DB>,
    usize: ColumnIndex<DB::Row>,
{
    pub(super) fn query(sql: &'static str) -> sqlx::query::Query<'static, DB, DB::Arguments> {
        // Dialect conversion starts from a compiled static template. Runtime
        // values are always encoded by bind(), never interpolated into SQL.
        sqlx::query(sqlx::AssertSqlSafe(DB::statement(sql)))
    }
    pub(super) fn scalar(
        sql: &'static str,
    ) -> sqlx::query::QueryScalar<'static, DB, i64, DB::Arguments> {
        sqlx::query_scalar(sqlx::AssertSqlSafe(DB::statement(sql)))
    }
    pub(super) fn new(pool: Pool<DB>) -> Self {
        Self {
            pool,
            wake: Arc::new(Notify::new()),
            namespace: "default".into(),
            memo_codec: Arc::new(Semaphore::new(1)),
            memo_size_limit: Some(262_144),
            history_budget: super::history_budget::Config::default(),
            workflow_task_lease_seconds: 60,
            activity_task_lease_seconds: 300,
            update_wait_seconds: 10,
            search_attribute_limit: 100,
            worker_stale_after_seconds: 30,
            workflow_task_recovery_scan_limit: 5,
            repair_codec: Arc::new(Semaphore::new(1)),
            repair_pass_at: Arc::default(),
        }
    }
    pub(super) fn in_namespace(&self, namespace: &str) -> Self {
        Self {
            pool: self.pool.clone(),
            wake: self.wake.clone(),
            namespace: namespace.into(),
            memo_codec: self.memo_codec.clone(),
            memo_size_limit: self.memo_size_limit,
            history_budget: self.history_budget,
            workflow_task_lease_seconds: self.workflow_task_lease_seconds,
            activity_task_lease_seconds: self.activity_task_lease_seconds,
            update_wait_seconds: self.update_wait_seconds,
            search_attribute_limit: self.search_attribute_limit,
            worker_stale_after_seconds: self.worker_stale_after_seconds,
            workflow_task_recovery_scan_limit: self.workflow_task_recovery_scan_limit,
            repair_codec: self.repair_codec.clone(),
            repair_pass_at: self.repair_pass_at.clone(),
        }
    }
    pub(super) async fn database_live(&self) -> bool {
        Self::query("SELECT 1").execute(&self.pool).await.is_ok()
    }
    pub(super) async fn schema_ready(&self) -> bool {
        if !DB::history_ready(&self.pool).await {
            return false;
        }
        for query in [
            "SELECT id,namespace,current_run_id FROM workflow_instances LIMIT 0",
            "SELECT id,status,closed_reason,closed_at,last_progress_at,arguments,output,last_history_sequence,last_command_sequence,run_deadline_at FROM workflow_runs LIMIT 0",
            "SELECT id,sequence,payload,recorded_at FROM workflow_history_events LIMIT 0",
            "SELECT id,status,payload,lease_owner,lease_expires_at,attempt_count FROM workflow_tasks LIMIT 0",
            "SELECT id,sequence,activity_type,status,arguments,result,retry_policy,exception,attempt_count,current_attempt_id,parallel_group_path FROM activity_executions LIMIT 0",
            "SELECT id,workflow_task_id,attempt_number,status FROM activity_attempts LIMIT 0",
            "SELECT id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,non_retryable,handled,exception_class,message FROM workflow_failures LIMIT 0",
            "SELECT id,workflow_run_id,sequence,status,delay_seconds,fire_at,fired_at FROM workflow_run_timers LIMIT 0",
            "SELECT id,workflow_command_id,workflow_run_id,signal_name,signal_wait_id,status,arguments FROM workflow_signal_records LIMIT 0",
            "SELECT id,workflow_run_id,command_sequence,message_sequence,status,target_scope,rejection_reason,accepted_at,applied_at,rejected_at,payload FROM workflow_commands LIMIT 0",
            "SELECT namespace,worker_id,supported_workflow_types,supported_activity_types FROM workflow_worker_registrations LIMIT 0",
            "SELECT task_id,receipt FROM dw_task_completions LIMIT 0",
            "SELECT request_id,task_id,attempt,response,expires_at FROM dw_poll_receipts LIMIT 0",
            "SELECT value,expiration FROM dw_query_cache LIMIT 0",
            "SELECT id,workflow_command_id,workflow_run_id,update_name,status,arguments,result FROM workflow_updates LIMIT 0",
            "SELECT id,parent_workflow_run_id,sequence,status,resolved_child_run_id,metadata FROM workflow_child_calls LIMIT 0",
            "SELECT id,parent_workflow_run_id,child_workflow_run_id,sequence FROM workflow_links LIMIT 0",
            "SELECT id,schedule_id,namespace,status,spec,action,next_fire_at,remaining_actions,fires_count FROM workflow_schedules LIMIT 0",
            "SELECT workflow_schedule_id,sequence,event_type,payload,occurrence_at_utc FROM workflow_schedule_history_events LIMIT 0",
        ] {
            if Self::query(query).execute(&self.pool).await.is_err() {
                return false;
            }
        }
        true
    }

    pub(super) async fn close(&self) {
        self.pool.close().await;
    }
    pub(super) async fn begin(&self) -> Result<Transaction<'static, DB>> {
        DB::begin(&self.pool)
            .await
            .map(|inner| Transaction::new(inner, self.history_budget))
    }
    // Reads that change nothing use one consistent read-only snapshot instead
    // of the transition lock, so they neither wait for nor delay transitions.
    pub(super) async fn begin_read(&self) -> Result<Transaction<'static, DB>> {
        Ok(Transaction::new(
            self.pool.begin_with(DB::READ_SNAPSHOT_BEGIN).await?,
            self.history_budget,
        ))
    }
    pub(crate) async fn start(
        &self,
        body: Value,
        memo: super::memos::PreparedMemo,
        external: Option<super::external_resolution::PreparedExternal>,
        context: Option<Value>,
    ) -> Result<Value> {
        self.start_command(body, memo, external, context, false).await
    }

    /// A bridge adapter's start (Server #776). Under the transition lock and
    /// before anything is written, in the bridge's order: a draining queue,
    /// then an instance another namespace holds, then one reserved for
    /// another type. Each is refused as a `Protocol` whose `bridge` field
    /// names the decision, so no state change between a read and the write
    /// can turn into another answer.
    pub(crate) async fn bridge_start(
        &self,
        body: Value,
        memo: super::memos::PreparedMemo,
        external: Option<super::external_resolution::PreparedExternal>,
        context: Option<Value>,
    ) -> Result<Value> {
        self.start_command(body, memo, external, context, true).await
    }

    async fn start_command(
        &self,
        body: Value,
        memo: super::memos::PreparedMemo,
        external: Option<super::external_resolution::PreparedExternal>,
        context: Option<Value>,
        bridge: bool,
    ) -> Result<Value> {
        let mut tx = self.begin().await?;
        let arguments = external.as_ref().map(|prepared| prepared.stored());
        if let Some(prepared) = &external {
            self.claim_external_in(&mut tx, &prepared.reference, &prepared.uri)
                .await?;
        }
        let request = super::start_request::validate(&body)?;
        // The controller validates search attribute values against the
        // namespace's registry before the reservation and routing checks.
        let search_attributes =
            Self::validate_start_search_attributes(&mut tx, &self.namespace, &body["search_attributes"]).await?;
        if bridge {
            let refusal = |decision: Value| super::RuntimeError::Protocol { status: StatusCode::CONFLICT,
                response: json!({"reason":"bridge_start_refused","bridge":decision}) };
            if let Some(block) = Self::routing_block(&mut tx, &self.namespace, &request.task_queue, self.worker_stale_after_seconds).await? {
                return Err(refusal(json!({"refusal":"task_queue_draining","block":block})));
            }
            if let Some(workflow_id) = &request.workflow_id
                && let Some(instance) = Self::query("SELECT workflow_type,namespace FROM workflow_instances WHERE id=$1")
                    .bind(workflow_id.as_str()).fetch_optional(&mut *tx).await?
            {
                if DB::optional_string(&instance, "namespace")?.as_deref() != Some(self.namespace.as_ref()) {
                    return Err(refusal(json!({"refusal":"instance_unavailable"})));
                }
                let reserved_type = DB::string(&instance, "workflow_type")?;
                if reserved_type != request.workflow_type {
                    return Err(refusal(json!({"refusal":"instance_reserved_for_type","reserved_type":reserved_type})));
                }
            }
        }
        // `TaskQueueRoutingGate`: a queue whose live workers all drain takes
        // no new start. A workflow id reserved in another namespace is
        // refused for that first.
        let reserved = match &request.workflow_id {
            Some(workflow_id) if !bridge => Self::query("SELECT namespace FROM workflow_instances WHERE id=$1")
                .bind(workflow_id.as_str()).fetch_optional(&mut *tx).await?
                .map(|instance| DB::optional_string(&instance, "namespace")).transpose()?.flatten()
                .is_some_and(|reserved| reserved != self.namespace.as_ref()),
            _ => false,
        };
        if !bridge && !reserved && let Some(block) = Self::routing_block(&mut tx, &self.namespace, &request.task_queue, self.worker_stale_after_seconds).await? {
            let mut response = json!({"workflow_id":request.workflow_id,"command_status":"rejected","command_source":"control_plane",
                "outcome":"rejected_task_queue_draining","reason":"task_queue_draining","rejection_reason":"task_queue_draining",
                "message":format!("Task queue [{}] is draining and cannot accept new workflow starts until an active worker cohort is available.", request.task_queue),
                "workflow_type":request.workflow_type,"task_queue":request.task_queue});
            response.as_object_mut().expect("object").extend(block);
            response.as_object_mut().expect("object").retain(|_, value| !value.is_null());
            return Err(super::RuntimeError::Protocol { status: StatusCode::CONFLICT, response });
        }
        let started = Self::start_in(
            &mut tx,
            &body,
            &self.namespace,
            StartMetadata { memo: Some(&memo), search_attributes: search_attributes.as_ref() },
            arguments.as_deref(),
            context.as_ref(),
            self.worker_stale_after_seconds,
        )
        .await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(started)
    }

    // Schedule admission and its original run/history must share one commit.
    // Existing explicit starts use the same transition and wake after commit.
    pub(super) async fn start_in(
        tx: &mut Transaction<'_, DB>,
        body: &Value,
        namespace: &str,
        metadata: StartMetadata<'_>,
        external_arguments: Option<&str>,
        context: Option<&Value>,
        stale_after: i64,
    ) -> Result<Value> {
        let request = super::start_request::validate(body)?;
        let StartMetadata { memo, search_attributes } = metadata;
        // PHP names an instance the request leaves unnamed.
        let workflow_id = request.workflow_id.clone().unwrap_or_else(id);
        let workflow_id = workflow_id.as_str();
        let workflow_type = request.workflow_type.as_str();
        let queue = request.task_queue.as_str();
        let arguments = match external_arguments {
            Some(arguments) => arguments.to_owned(),
            None => envelope(&body, "input")?,
        };
        let execution_timeout = request.execution_timeout;
        let run_timeout = request.run_timeout;
        if memo.is_none() && body.get("memo").is_some_and(|value| !value.is_null()) {
            return Err(refuse(
                StatusCode::CONFLICT,
                "workflow_memo_updates_unavailable",
            ));
        }
        if let Some(existing) = Self::query("SELECT r.* FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1")
            .bind(workflow_id).fetch_optional(&mut **tx).await? {
            if DB::optional_string(&existing,"namespace")?.as_deref() != Some(namespace) {
                return Err(super::RuntimeError::Protocol {status:StatusCode::CONFLICT,
                    response:json!({"workflow_id":workflow_id,"command_status":"rejected","command_source":"control_plane","reason":"workflow_id_reserved_in_namespace",
                        "outcome":"rejected_workflow_id_reserved_in_namespace","rejection_reason":"workflow_id_reserved_in_namespace",
                        "message":format!("Workflow [{workflow_id}] is already reserved in another namespace.")})});
            }
            return Self::start_existing(tx, &existing, &request, workflow_id, namespace, &arguments, context).await;
        }
        // Core normalizes the values while seeding the new run, after the
        // reservation, routing and duplicate checks; its refusals name
        // `workflow_type`, as the controller reports Core's LogicException.
        let prepared_attributes = search_attributes.map(|attributes| attributes.prepare()).transpose()?;
        let run_id = id();
        let command_id = id();
        let started_at = now();
        // `WorkflowStartVersionPin::resolveForStart`: the run is pinned to its
        // cohort's build, and records the definition that cohort agrees on.
        let workers = Self::start_workers(tx, namespace, queue, stale_after).await?;
        let cohort = match Self::promoted_cohort(tx, namespace, queue).await? {
            Some(cohort) => Some(cohort),
            None => super::start_definition::resolve(&workers, workflow_type),
        };
        let pin = cohort.as_ref().and_then(|cohort| cohort.pin().map(str::to_owned));
        // DefaultWorkflowControlPlane::start: each deadline follows its own
        // timeout, and a run without timeouts has no deadline.
        let execution_deadline = execution_timeout.map(|seconds| started_at + chrono::Duration::seconds(seconds));
        let run_deadline = run_timeout.map(|seconds| started_at + chrono::Duration::seconds(seconds));
        let authored_memo = body
            .get("memo")
            .filter(|memo| memo.as_object().is_some_and(|entries| !entries.is_empty()))
            .cloned()
            .unwrap_or(Value::Null);
        Self::query("INSERT INTO workflow_instances(id,workflow_type,workflow_class,current_run_id,run_count,execution_timeout_seconds,created_at,updated_at,started_at,namespace,memo,business_key) VALUES ($1,$2,$3,$4,1,NULLIF($5,0),$6,$7,$8,$9,$10,$11)")
            // Timeouts are at least one second; zero binds "no timeout".
            .bind(workflow_id).bind(workflow_type).bind(workflow_type).bind(&run_id).bind(execution_timeout.unwrap_or(0))
            .bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(namespace)
            .bind(DB::optional_document(&authored_memo)).bind(request.business_key.as_deref()).execute(&mut **tx).await?;
        // PHP records the start as the run's first progress.
        Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,workflow_type,workflow_class,run_number,status,payload_codec,arguments,queue,run_timeout_seconds,execution_deadline_at,run_deadline_at,started_at,created_at,updated_at,namespace,last_progress_at,business_key,priority,fairness_key,fairness_weight,compatibility) VALUES ($1,$2,$3,$4,1,'pending','avro',$5,$6,NULLIF($7,0),$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19)")
            .bind(&run_id).bind(workflow_id).bind(workflow_type).bind(workflow_type).bind(&arguments)
            .bind(queue).bind(run_timeout.unwrap_or(0)).bind(DB::optional_time(execution_deadline)).bind(DB::optional_time(run_deadline))
            .bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(namespace)
            .bind(DB::bind_time(started_at)).bind(request.business_key.as_deref()).bind(request.priority)
            .bind(request.fairness_key.as_deref()).bind(request.fairness_weight).bind(pin.as_deref()).execute(&mut **tx).await?;
        if let Some(memo) = memo {
            for (key, value) in memo {
                Self::query(DB::MEMO_INITIAL_INSERT)
                    .bind(&run_id)
                    .bind(workflow_id)
                    .bind(key)
                    .bind(DB::document(&value.projection))
                    .bind(DB::bind_time(started_at))
                    .bind(DB::bind_time(started_at))
                    .bind(DB::document(&value.envelope))
                    .execute(&mut **tx)
                    .await?;
            }
        }
        // `seedTypedVisibilityMetadata`: the start's values are the run's
        // first typed rows, at sequence 0.
        if let Some(prepared) = &prepared_attributes {
            Self::write_typed_rows(tx, &run_id, workflow_id, prepared, 0).await?;
        }
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,source,status,outcome,workflow_type,payload_codec,payload,command_sequence,accepted_at,applied_at,context) VALUES ($1,$2,$3,$4,'start','control_plane','accepted','started_new',$5,'avro',$6,1,$7,$8,$9)")
            .bind(&command_id).bind(workflow_id).bind(&run_id).bind(&run_id).bind(workflow_type).bind(&arguments).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at))
            .bind(DB::optional_document(context.unwrap_or(&Value::Null))).execute(&mut **tx).await?;
        Self::query("UPDATE workflow_runs SET last_command_sequence=1 WHERE id=$1")
            .bind(&run_id)
            .execute(&mut **tx)
            .await?;
        let identity = json!({"workflow_instance_id": workflow_id, "workflow_run_id": run_id,
            "workflow_type": workflow_type, "workflow_class": workflow_type, "workflow_command_id": command_id,
            "memo":authored_memo});
        let mut accepted = identity.clone();
        accepted["business_key"] = json!(request.business_key);
        accepted["visibility_labels"] = Value::Null;
        // The values as the request named them, nulls included.
        let authored_attributes = search_attributes.map(|attributes| Value::Object(attributes.authored.clone())).unwrap_or(Value::Null);
        accepted["search_attributes"] = authored_attributes.clone();
        accepted["outcome"] = json!("started_new");
        Self::append(
            tx,
            &run_id,
            "StartAccepted",
            accepted,
            None,
            Some(&command_id),
        )
        .await?;
        let mut started = identity;
        started["business_key"] = json!(request.business_key);
        started["execution_timeout_seconds"] = json!(execution_timeout);
        started["run_timeout_seconds"] = json!(run_timeout);
        started["execution_deadline_at"] = json!(execution_deadline);
        started["run_deadline_at"] = json!(run_deadline);
        if let Some(fingerprint) = cohort.as_ref().and_then(|cohort| super::start_definition::fingerprint_for(&workers, workflow_type, cohort)) {
            started["workflow_definition_fingerprint"] = json!(fingerprint);
            started["workflow_definition_fingerprint_source"] = json!("worker");
        }
        started["compatibility"] = json!(pin);
        started["search_attributes"] = authored_attributes;
        // PHP filters null fields out of the started payload, then adds the
        // declared contract.
        if let Some(fields) = started.as_object_mut() {
            fields.retain(|_, value| !value.is_null());
        }
        // PHP enriches the start with a contract only from its cohort.
        if let Some(cohort) = &cohort {
            Self::attach_workflow_contract(tx, queue, workflow_type, Some(cohort), &mut started).await?;
        }
        Self::append(
            tx,
            &run_id,
            "WorkflowStarted",
            started,
            None,
            Some(&command_id),
        )
        .await?;
        Self::create_task(tx, &run_id, queue, "workflow", json!({})).await?;
        Ok(
            json!({"workflow_id": workflow_id, "run_id": run_id, "workflow_type": workflow_type,
            "namespace": namespace, "status": "pending", "business_key": request.business_key, "payload_codec": "avro",
            "outcome": "started_new", "command_status": "accepted", "command_source": "control_plane", "reason": null,
            "rejection_reason": null, "message": null}),
        )
    }

    /// `WorkflowStartVersionPin::definitionFingerprintForCohort` over the
    /// queue's active registrations: unexpired or never heartbeated, and
    /// not draining or deregistered.
    pub(super) async fn start_workers(
        tx: &mut Transaction<'_, DB>,
        namespace: &str,
        queue: &str,
        stale_after: i64,
    ) -> Result<Vec<super::start_definition::CohortWorker>> {
        let cutoff = now() - chrono::Duration::seconds(stale_after.max(1));
        let rows = Self::query("SELECT build_id,supported_workflow_types,workflow_definition_fingerprints,last_heartbeat_at FROM workflow_worker_registrations WHERE namespace=$1 AND task_queue=$2 AND (last_heartbeat_at IS NULL OR last_heartbeat_at>=$3) AND (status IS NULL OR status='active')")
            .bind(namespace).bind(queue).bind(DB::bind_time(cutoff)).fetch_all(&mut **tx).await?;
        let mut workers = Vec::with_capacity(rows.len());
        for row in &rows {
            workers.push(super::start_definition::CohortWorker {
                build_id: DB::optional_string(row, "build_id")?,
                workflow_types: DB::optional_document_row(row, "supported_workflow_types")?.unwrap_or(Value::Null),
                fingerprints: DB::optional_document_row(row, "workflow_definition_fingerprints")?.unwrap_or(Value::Null),
                heartbeated: DB::optional_instant(row, "last_heartbeat_at")?.is_some(),
            });
        }
        Ok(workers)
    }

    /// `DefaultWorkflowControlPlane::start` for an instance that already has
    /// a run: an active run is returned under `use-existing`, otherwise the
    /// start is refused. Either way the command and its history event are
    /// committed on that run; the HTTP layer answers a refusal with 409.
    async fn start_existing(
        tx: &mut Transaction<'_, DB>,
        existing: &DB::Row,
        request: &super::start_request::StartRequest,
        workflow_id: &str,
        namespace: &str,
        arguments: &str,
        context: Option<&Value>,
    ) -> Result<Value> {
        let run_id = DB::string(existing, "id")?;
        let status = DB::string(existing, "status")?;
        let returned = request.use_existing && matches!(status.as_str(), "pending" | "running" | "waiting");
        let (command_status, outcome, rejection, event) = if returned {
            ("accepted", "returned_existing_active", None, "StartAccepted")
        } else {
            ("rejected", "rejected_duplicate", Some("instance_already_started"), "StartRejected")
        };
        let command_id = id();
        let at = now();
        let sequence = DB::number(existing, "last_command_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let workflow_type = DB::string(existing, "workflow_type")?;
        let decided = |when: bool| DB::optional_time(when.then_some(at));
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,source,status,outcome,workflow_type,payload_codec,payload,rejection_reason,command_sequence,accepted_at,applied_at,rejected_at,context) VALUES ($1,$2,$3,$4,'start','control_plane',$5,$6,$7,'avro',$8,$9,$10,$11,$12,$13,$14)")
            .bind(&command_id).bind(workflow_id).bind(&run_id).bind(&run_id).bind(command_status).bind(outcome)
            .bind(&workflow_type).bind(arguments).bind(rejection).bind(sequence)
            .bind(decided(returned)).bind(decided(returned)).bind(decided(!returned))
            .bind(DB::optional_document(context.unwrap_or(&Value::Null))).execute(&mut **tx).await?;
        Self::query("UPDATE workflow_runs SET last_command_sequence=$1 WHERE id=$2")
            .bind(sequence)
            .bind(&run_id)
            .execute(&mut **tx)
            .await?;
        let mut memo = serde_json::Map::new();
        for row in Self::query(DB::MEMO_READ).bind(&run_id).fetch_all(&mut **tx).await? {
            memo.insert(DB::string(&row, "key")?, DB::document_row(&row, "value")?);
        }
        let business_key = DB::optional_string(existing, "business_key")?;
        let payload = json!({"workflow_command_id":command_id,"workflow_instance_id":workflow_id,"workflow_run_id":run_id,
            "workflow_class":DB::string(existing, "workflow_class")?,"workflow_type":workflow_type,"business_key":business_key,
            "visibility_labels":DB::optional_document_row(existing, "visibility_labels")?,
            // An empty PHP array projects as a list.
            "memo":if memo.is_empty() { json!([]) } else { Value::Object(memo) },
            "search_attributes":[],"outcome":outcome,"rejection_reason":rejection});
        Self::append(tx, &run_id, event, payload, None, Some(&command_id)).await?;
        tx.summary_changed(&run_id);
        let body = json!({"workflow_id":workflow_id,"run_id":run_id,"workflow_type":workflow_type,"namespace":namespace,
            "status":status,"business_key":business_key,"payload_codec":"avro","outcome":outcome,"command_status":command_status,
            "command_source":"control_plane","reason":rejection,"rejection_reason":rejection,"message":null});
        Ok(body)
    }

    /// A run's decoded memo map as describe and the run list render it.
    pub(super) async fn run_memo(&self, run_id: &str) -> Result<Value> {
        let memo_rows = Self::query(DB::MEMO_READ)
            .bind(run_id)
            .fetch_all(&self.pool)
            .await?;
        let mut stored = Vec::with_capacity(memo_rows.len());
        for memo in memo_rows {
            let portable = if DB::optional_number(&memo, "portable_value_sequence")?
                == Some(DB::number(&memo, "upserted_at_sequence")?)
            {
                DB::optional_document_row(&memo, "portable_value")?
                    .filter(|value| value.is_object() || value.is_array())
            } else {
                None
            };
            stored.push((
                DB::string(&memo, "key")?,
                DB::document_row(&memo, "value")?,
                portable,
            ));
        }
        let memo = super::memos::read_stored(self.memo_codec.clone(), stored).await?;
        // PHP projects an empty memo map as an empty JSON array.
        Ok(if memo.as_object().is_some_and(Map::is_empty) { json!([]) } else { memo })
    }

    pub(crate) async fn describe(&self, workflow_id: &str, run_id: Option<&str>) -> Result<Value> {
        self.describe_with(workflow_id, run_id, None).await
    }

    /// The run description; with a worker stale window it also carries the
    /// fields PHP's `formatRun` adds (actions, fleet, wait and updates).
    pub(crate) async fn describe_with(&self, workflow_id: &str, run_id: Option<&str>, stale_after: Option<i64>) -> Result<Value> {
        let row = Self::query("SELECT r.*,i.execution_timeout_seconds,i.current_run_id,(SELECT parent_workflow_run_id FROM workflow_links l WHERE l.child_workflow_run_id=r.id AND l.link_type='continue_as_new' LIMIT 1) AS continued_from_run_id,(SELECT COUNT(*) FROM workflow_runs counted WHERE counted.workflow_instance_id=i.id) AS run_count FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE i.id=$1 AND r.id=COALESCE($2,i.current_run_id) AND r.namespace=$3")
            .bind(workflow_id).bind(run_id).bind(self.namespace.as_ref()).fetch_optional(&self.pool).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, if run_id.is_some() {"run_not_found"} else {"instance_not_found"}))?;
        let output = DB::optional_string(&row, "output")?;
        let memo = self.run_memo(&DB::string(&row, "id")?).await?;
        let status = DB::string(&row, "status")?;
        let terminal = matches!(
            status.as_str(),
            "completed" | "failed" | "cancelled" | "terminated"
        );
        // Describe's public journal comes from committed rows, including
        // refused signals which intentionally have no workflow history event.
        // Select metadata only: large customer payloads are never decoded or
        // copied merely to display the command's target and admission result.
        // Only a stored external reference is read, to tell whether its preview
        // is omitted; inline payloads stay unread.
        let command_rows = Self::query(DB::RUN_COMMANDS_SQL)
            .bind(DB::string(&row, "id")?).bind(workflow_id).fetch_all(&self.pool).await?;
        let mut commands = Vec::with_capacity(command_rows.len());
        let mut reason_codec = None;
        for command in command_rows {
            // `oversizedPayloadPreview` over the stored reference's envelope;
            // an omitted preview also omits the target name, as PHP does.
            let omitted = DB::optional_string(&command, "stored_payload")?
                .as_deref()
                .and_then(super::external_resolution::stored_envelope)
                .is_some_and(|envelope| super::run_describe::oversized(&envelope))
                || DB::optional_string(&command, "php_payload_reference")?
                    .as_deref()
                    .is_some_and(super::external_resolution::oversized_reference);
            let target_name = DB::optional_string(&command, "signal_name")?.filter(|_| !omitted);
            let mut public = json!({"id":DB::string(&command,"id")?,"sequence":DB::optional_number(&command,"command_sequence")?,
                "type":DB::string(&command,"command_type")?,"target_scope":DB::string(&command,"target_scope")?,
                "requested_run_id":DB::optional_string(&command,"requested_workflow_run_id")?,
                "resolved_run_id":DB::optional_string(&command,"resolved_workflow_run_id")?,
                "target_name":target_name,"source":DB::string(&command,"source")?,
                "status":DB::string(&command,"status")?,"outcome":DB::optional_string(&command,"outcome")?,
                "reason":DB::optional_string(&command,"rejection_reason")?,"rejection_reason":DB::optional_string(&command,"rejection_reason")?,
                // `WorkflowCommand::record` keeps the run's class on each command.
                "workflow_type":DB::optional_string(&command,"workflow_type")?,
                "workflow_class":DB::optional_string(&command,"workflow_class")?.or(DB::optional_string(&row,"workflow_class")?),
                "payload_preview_omitted":omitted,
                "accepted_at":DB::optional_instant(&command,"accepted_at")?,"applied_at":DB::optional_instant(&command,"applied_at")?,
                "rejected_at":DB::optional_instant(&command,"rejected_at")?});
            let fields = public.as_object_mut().expect("command object");
            if let Some(context) = DB::optional_document_row(&command, "context")? {
                fields.extend(super::command_context::described(&context));
            }
            // `commandReason()`: a command that was not rejected shows the
            // reason its payload records. Only small inline payloads are read;
            // every writer caps the reason at 1000 characters.
            if let Some(payload) = DB::optional_string(&command, "reason_payload")? {
                let codec = reason_codec.get_or_insert_with(|| crate::codec::ValueCodec::new().ok());
                if let Some(Ok(crate::codec::Value::Map(map))) = codec.as_ref().map(|codec| codec.decode(&payload))
                    && let Some(crate::codec::Value::String(reason)) = map.get("reason")
                    && !reason.is_empty()
                {
                    fields.insert("reason".into(), json!(reason));
                }
            }
            fields.retain(|_, value| !value.is_null());
            commands.push(public);
        }
        let current = DB::optional_string(&row,"current_run_id")?.as_deref() == Some(DB::string(&row,"id")?.as_str());
        // A standalone activity's host run may have no input.
        let input_envelope = DB::optional_string(&row,"arguments")?.map(|arguments| wire(&arguments)).unwrap_or(Value::Null);
        let output_envelope = output.as_deref().map(wire);
        let mut described =
            json!({"workflow_id": workflow_id, "run_id": DB::string(&row,"id")?, "workflow_type": DB::string(&row,"workflow_type")?,
            "namespace": self.namespace.as_ref(), "task_queue": DB::optional_string(&row,"queue")?, "status": status,
            "commands_scope":"selected_run","commands":commands,
            "status_bucket": super::visibility::status_bucket(&status)?,
            "is_current_run": current,
            "run_count": DB::number(&row,"run_count")?,
            "is_terminal": terminal, "closed_reason": DB::optional_string(&row,"closed_reason")?, "run_number": DB::number(&row,"run_number")?,
            "continued_from_run_id": DB::optional_string(&row,"continued_from_run_id")?,
            "payload_codec": "avro", "input": null, "output": null, "memo": memo,
            "input_envelope": input_envelope, "output_envelope": output_envelope,
            // `toJSON()` for start and close; the run description's
            // `toIso8601String()` for deadlines.
            "started_at": super::task_queues::json_time(DB::optional_instant(&row,"started_at")?),
            "closed_at": super::task_queues::json_time(DB::optional_instant(&row,"closed_at")?),
            "execution_timeout_seconds": DB::optional_number(&row,"execution_timeout_seconds")?,
            "run_timeout_seconds": DB::optional_number(&row,"run_timeout_seconds")?,
            "execution_deadline_at": super::run_describe::iso8601(DB::optional_instant(&row,"execution_deadline_at")?),
            "run_deadline_at": super::run_describe::iso8601(DB::optional_instant(&row,"run_deadline_at")?)});
        if let Some(stale_after) = stale_after {
            let fields = self
                .describe_fields(&row, current, terminal, &described["input_envelope"], &described["output_envelope"], stale_after)
                .await?;
            described.as_object_mut().expect("description object").extend(fields);
        }
        // `formatRun` for a Waterline v1 projection: no control-plane
        // description, every action off, the stored codec and progress time,
        // and the projection's metadata.
        if DB::optional_string(&row, "import_source")?.as_deref() == Some(super::waterline_import::IMPORT_SOURCE) {
            let metadata = self.projection_metadata(&DB::string(&row, "id")?, DB::optional_instant(&row, "imported_at")?,
                DB::optional_string(&row, "import_dedupe_key")?).await?;
            let failure = if stale_after.is_some() && described["status"] == "failed" {
                self.projected_failure(&DB::string(&row, "id")?).await?
            } else {
                Value::Null
            };
            let fields = described.as_object_mut().expect("description object");
            fields.insert("payload_codec".into(), json!(DB::optional_string(&row, "payload_codec")?));
            if stale_after.is_some() {
                fields.insert("definition_routing".into(), Value::Null);
                fields.insert("last_progress_at".into(), super::task_queues::json_time(DB::optional_instant(&row, "last_progress_at")?));
                if let Some(Value::Object(actions)) = fields.get_mut("actions") {
                    actions.values_mut().for_each(|value| *value = json!(false));
                }
                fields.insert("migration_projection".into(), metadata);
                fields.insert("action_blocked_reason".into(), json!("v1_projection_read_only"));
                // `terminalFailurePayload`: a projection keeps no failure
                // snapshots, so a failed one reports its projected activity
                // failures only.
                if !failure.is_null() {
                    fields.insert("error".into(), failure["message"].clone());
                    fields.insert("exception".into(), failure["exception"].clone());
                    fields.insert("failures".into(), json!([]));
                    fields.insert("failure".into(), failure);
                }
            }
        }
        Ok(described)
    }

    pub(crate) async fn history(
        &self,
        workflow_id: &str,
        run_id: &str,
        after_sequence: i64,
        page_size: i64,
    ) -> Result<Value> {
        self.describe(workflow_id, Some(run_id)).await?;
        let run = Self::query("SELECT compatibility,queue,import_source,imported_at,import_dedupe_key FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&self.pool)
            .await?;
        let compatibility = self.compatibility_fields(&run).await?;
        let (events, next) =
            Self::history_page(&self.pool, run_id, after_sequence, page_size).await?;
        let public_events: Vec<Value> = events
            .into_iter()
            .map(|e| {
                json!({"sequence": e["sequence"],
            "event_type": e["event_type"], "timestamp": e["recorded_at"],
            "principal": super::history_presentation::principal(&e["payload"]), "payload": e["payload"]})
            })
            .collect();
        // `HistoryController::show` reports the run's compatibility as
        // describe does.
        let mut body = json!({"workflow_id": workflow_id, "run_id": run_id, "events": public_events, "next_page_token": next});
        body.as_object_mut().expect("history object").extend(compatibility);
        // `LegacyV1Projection::metadataForRun` on a projected run.
        if DB::optional_string(&run, "import_source")?.as_deref() == Some(super::waterline_import::IMPORT_SOURCE) {
            body["migration_projection"] = self.projection_metadata(run_id, DB::optional_instant(&run, "imported_at")?,
                DB::optional_string(&run, "import_dedupe_key")?).await?;
        }
        Ok(body)
    }

    pub(crate) async fn signal(
        &self,
        workflow_id: &str,
        run_id: Option<&str>,
        name: &str,
        body: Value,
        mut prepared: PreparedControlArguments,
        transition_codec: Arc<Semaphore>,
        context: Option<Value>,
        limits: super::memo_limits::StructuralLimits,
        external: Option<&super::external_resolution::PreparedExternal>,
        bridge: Option<&super::bridge_adapters::BridgeKey<'_>>,
    ) -> Result<Value> {
        // The authenticated request's context; a direct call records only
        // the request id and signal name.
        let context = context.unwrap_or_else(|| json!({"request":{"request_id":body["request_id"]},"signal_name":name}));
        // PHP's signal_name column holds 255 bytes, one fewer than its
        // validation allows; native refuses what it cannot store.
        if name.trim().is_empty() || name.len() > 255 {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_signal_name",
            ));
        }
        if body.get("request_id").is_some_and(|value| {
            !value.is_null() && value.as_str().is_none_or(|text| text.len() > 255)
        }) {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_request_id",
            ));
        }
        let mut tx = self.begin().await?;
        // The referenced object now belongs to the signal; a refusal rolls
        // the claim back with everything else.
        if let Some(prepared) = external {
            self.claim_external_in(&mut tx, &prepared.reference, &prepared.uri).await?;
        }
        let instance = Self::query("SELECT current_run_id,last_message_sequence FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(workflow_id).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?.ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        let current_run = DB::string(&instance, "current_run_id")?;
        // A bridge signal already accepted is answered, under the transition
        // lock, before anything else is admitted.
        if let Some(key) = bridge
            && let Some(run) = Self::bridge_receipt(&mut tx, workflow_id, "signal", key).await?
        {
            return Ok(json!({"workflow_id":workflow_id,"run_id":run,"outcome":"deduped_existing_command"}));
        }
        if run_id.is_some_and(|selected| selected != current_run) {
            return Err(refuse(
                StatusCode::CONFLICT,
                "historical_run_command_rejected",
            ));
        }
        // PHP's control plane refuses the runtime's own message-stream signal
        // before admission, whatever the run's state, and records nothing.
        if name == MESSAGE_STREAM_SIGNAL {
            let message = "This signal name is reserved for the durable runtime transport.";
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::UNPROCESSABLE_ENTITY,
                response: json!({"workflow_id":workflow_id,"signal_name":name,"outcome":"rejected",
                    "reason":"runtime_reserved_signal","message":message,"accepted":false}),
            });
        }
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(&current_run)
            .fetch_one(&mut *tx)
            .await?;
        let active = matches!(
            DB::string(&run, "status")?.as_str(),
            "pending" | "running" | "waiting"
        );
        // Retain the existing deadline fences for live runs. Terminal signals
        // instead commit PHP's durable rejection without reopening execution.
        if active {
            Self::active_run(&mut tx, &current_run).await?;
        }
        let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(&current_run).fetch_one(&mut *tx).await?;
        let contract = DB::document_row(&started, "payload")?;
        let declared = contract["declared_signals"]
            .as_array()
            .is_some_and(|names| names.iter().any(|value| value == name));
        // PHP's signalAdmissionForRun: only a complete durable contract can
        // make a signal unknown. A run started before any worker declared its
        // contract admits every signal (external_contract_unavailable), and a
        // declared signal without a parameter contract takes its arguments
        // as given.
        let durable = super::run_observation::command_contract(Some(&contract))["source"] == "durable_history";
        let has_parameters = contract["declared_signal_contracts"]
            .as_array()
            .is_some_and(|contracts| contracts.iter().any(|contract| contract["name"] == name));
        let mut validation_errors = json!([]);
        let mut rejection = if !active {
            Some((
                StatusCode::CONFLICT,
                "run_not_active",
                Some("rejected_not_active"),
            ))
        } else if !declared && durable {
            Some((
                StatusCode::NOT_FOUND,
                "unknown_signal",
                Some("rejected_unknown_signal"),
            ))
        } else if !has_parameters {
            None
        } else {
            match validate_signal_arguments(&contract, name, &prepared.values) {
                Ok(()) => None,
                Err(super::RuntimeError::Refused {
                    reason: "invalid_signal_arguments",
                    ..
                }) => {
                    validation_errors = signal_argument_errors(&contract, name, &prepared.values);
                    Some((
                        StatusCode::UNPROCESSABLE_ENTITY,
                        "invalid_signal_arguments",
                        Some("rejected_invalid_arguments"),
                    ))
                }
                Err(error) => return Err(error),
            }
        };
        // `guardPendingSignals`, then `rejectOversizedSignal`: an admissible
        // signal over a structural ceiling is refused durably, without an
        // outcome or history.
        let mut exceeded = None;
        if rejection.is_none() {
            if limits.pending_signals.is_some() {
                let pending = Self::scalar("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$1 AND status='received'")
                    .bind(&current_run).fetch_one(&mut *tx).await?;
                exceeded = super::memo_limits::Exceeded::pending_signals(limits.pending_signals, pending);
            }
            if exceeded.is_none() {
                exceeded = super::memo_limits::Exceeded::payload(limits.payload_bytes, &prepared.arguments);
            }
            if exceeded.is_some() {
                rejection = Some((StatusCode::CONFLICT, "structural_limit_exceeded", None));
            }
        }
        let command_id = id();
        let signal_id = id();
        let command_sequence = DB::number(&run, "last_command_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let message_sequence = DB::number(&instance, "last_message_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "message_sequence_exhausted"))?;
        if let Some((status, reason, outcome)) = rejection {
            let workflow_type = DB::string(&run, "workflow_type")?;
            let mut diagnostics = json!({});
            if reason == "unknown_signal" {
                let names = contract["declared_signals"]
                    .as_array()
                    .cloned()
                    .unwrap_or_default();
                let labels = names
                    .iter()
                    .filter_map(Value::as_str)
                    .collect::<Vec<_>>()
                    .join(", ");
                diagnostics = json!({"command_contract_source":"durable_history",
                    "command_contract_backfill_needed":false,"command_contract_backfill_available":false,
                    "declared_signals":names,"signal_admission":"handler_not_declared","reason":reason,
                    "message":format!("Workflow signal [{name}] is unknown for workflow type [{workflow_type}]: the durable command contract does not declare that handler. Command contract source [durable_history], declared signals [{labels}].")});
            }
            // An oversized signal keeps no arguments: its command carries the
            // limit instead, and its signal row the limit's message.
            let (mut row_arguments, mut row_errors) = (prepared.arguments.clone(), validation_errors.clone());
            let mut command_values = prepared.values.clone();
            let mut command_errors = validation_errors.clone();
            let mut command_diagnostics = diagnostics.clone();
            if let Some(exceeded) = exceeded.as_ref().filter(|exceeded| exceeded.kind == "payload_size") {
                command_values = vec![];
                command_errors = json!({"arguments":[exceeded.message]});
                command_diagnostics = exceeded.fields();
                row_arguments = super::queries::empty_arguments(transition_codec.clone()).await?;
                row_errors = command_errors.clone();
            }
            prepared.command = prepare_rejected_command(
                transition_codec,
                name.to_owned(),
                command_values,
                command_errors,
                command_diagnostics,
                "signal_codec_unavailable",
            )
            .await?;
            let write = SignalWrite { workflow_id, run_id: &current_run, workflow_type: &workflow_type, name,
                command_id: &command_id, signal_id: &signal_id, command_sequence, message_sequence, context: &context,
                request_id: None };
            Self::record_rejected_signal(&mut tx, &write, outcome, reason, &prepared.command, &row_arguments, &row_errors).await?;
            tx.commit().await?;
            let mut response = json!({"workflow_id":workflow_id,"run_id":current_run,"resolved_run_id":current_run,
                "requested_run_id":null,"signal_name":name,"command_id":command_id,"command_sequence":command_sequence,
                "workflow_type":workflow_type,"command_status":"rejected","command_source":"control_plane",
                "target_scope":"instance","outcome":outcome,"reason":reason,"rejection_reason":reason,
                "validation_errors":validation_errors,"accepted":false});
            response
                .as_object_mut()
                .expect("response object")
                .extend(diagnostics.as_object().expect("diagnostics object").clone());
            // A refusal records admission, not execution: no history, task,
            // signal wait reservation or notification is added here.
            return Err(super::RuntimeError::Protocol { status, response });
        }
        let workflow_type = DB::string(&run, "workflow_type")?;
        let write = SignalWrite { workflow_id, run_id: &current_run, workflow_type: &workflow_type, name,
            command_id: &command_id, signal_id: &signal_id, command_sequence, message_sequence, context: &context,
            request_id: bridge.map(|key| key.identity.as_str()) };
        Self::record_accepted_signal(&mut tx, &write, &prepared.command, &prepared.arguments, &DB::string(&run, "queue")?).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"workflow_id":workflow_id,"run_id":current_run,"resolved_run_id":current_run,"requested_run_id":null,"signal_name":name,"command_id":command_id,"command_sequence":command_sequence,"workflow_type":DB::string(&run, "workflow_type")?,"command_status":"accepted","command_source":"control_plane","target_scope":"instance","outcome":"signal_received","reason":null,"rejection_reason":null,"validation_errors":[]}),
        )
    }

    /// A refused signal's durable admission record: its rejected command,
    /// its rejected signal row and the advanced sequences. No history, task
    /// or wait reservation is added.
    pub(super) async fn record_rejected_signal(
        tx: &mut Transaction<'_, DB>,
        write: &SignalWrite<'_>,
        outcome: Option<&str>,
        reason: &str,
        command: &str,
        arguments: &str,
        errors: &Value,
    ) -> Result<()> {
        let rejected_at = now();
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,source,status,outcome,workflow_type,payload_codec,payload,rejection_reason,command_sequence,message_sequence,rejected_at,context) VALUES ($1,$2,$3,$4,'signal','control_plane','rejected',$5,$6,'avro',$7,$8,$9,$10,$11,$12)")
            .bind(write.command_id).bind(write.workflow_id).bind(write.run_id).bind(write.run_id).bind(outcome).bind(write.workflow_type)
            .bind(command).bind(reason).bind(write.command_sequence).bind(write.message_sequence).bind(DB::bind_time(rejected_at))
            .bind(DB::document(write.context)).execute(&mut **tx).await?;
        Self::query("INSERT INTO workflow_signal_records(id,workflow_command_id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,signal_name,status,outcome,command_sequence,payload_codec,arguments,validation_errors,rejection_reason,rejected_at,closed_at) VALUES ($1,$2,$3,$4,$5,$6,'rejected',$7,$8,'avro',$9,$10,$11,$12,$13)")
            .bind(write.signal_id).bind(write.command_id).bind(write.workflow_id).bind(write.run_id).bind(write.run_id).bind(write.name)
            .bind(outcome).bind(write.command_sequence).bind(arguments).bind(DB::document(errors)).bind(reason)
            .bind(DB::bind_time(rejected_at)).bind(DB::bind_time(rejected_at)).execute(&mut **tx).await?;
        Self::advance_signal_sequences(tx, write).await
    }

    /// `recordAcceptedSignalCommand`, `recordAcceptedSignal` and
    /// `recordSignalReceivedHistory`: the accepted command, the received
    /// signal reserving an open wait for its name, the advanced sequences,
    /// `SignalReceived`, then the run's next message task if none is open.
    pub(super) async fn record_accepted_signal(
        tx: &mut Transaction<'_, DB>,
        write: &SignalWrite<'_>,
        command: &str,
        arguments: &str,
        queue: &str,
    ) -> Result<()> {
        // `signalWaitIdForAcceptedCommand`: behind an earlier pending signal
        // of the same name the signal is buffered; otherwise it reserves the
        // latest wait of its name still open, one no received signal holds
        // (`openWaitIdForName`). Pending is the same predicate the delivery
        // and wait-opening reads use.
        let mut wait_id = format!("signal-command:{}", write.command_id);
        let pending = Self::scalar("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$1 AND signal_name=$2 AND status='received' AND closed_at IS NULL")
            .bind(write.run_id).bind(write.name).fetch_one(&mut **tx).await?;
        if pending == 0
            && let Some(wait) = Self::waits(tx, write.run_id).await?.latest_open_signal(write.name)
        {
            wait_id = wait.id.clone();
        }
        let accepted_at = now();
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,source,status,outcome,workflow_type,payload_codec,payload,command_sequence,message_sequence,accepted_at,context,request_id) VALUES ($1,$2,$3,$4,'signal','control_plane','accepted','signal_received',$5,'avro',$6,$7,$8,$9,$10,$11)")
            .bind(write.command_id).bind(write.workflow_id).bind(write.run_id).bind(write.run_id).bind(write.workflow_type)
            .bind(command).bind(write.command_sequence).bind(write.message_sequence).bind(DB::bind_time(accepted_at))
            .bind(DB::document(write.context)).bind(write.request_id).execute(&mut **tx).await?;
        Self::query("INSERT INTO workflow_signal_records(id,workflow_command_id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,signal_name,signal_wait_id,status,outcome,command_sequence,payload_codec,arguments,received_at) VALUES ($1,$2,$3,$4,$5,$6,$7,'received','signal_received',$8,'avro',$9,$10)")
            .bind(write.signal_id).bind(write.command_id).bind(write.workflow_id).bind(write.run_id).bind(write.run_id).bind(write.name).bind(&wait_id)
            .bind(write.command_sequence).bind(arguments).bind(DB::bind_time(accepted_at)).execute(&mut **tx).await?;
        Self::advance_signal_sequences(tx, write).await?;
        Self::append(tx, write.run_id, "SignalReceived", json!({"workflow_command_id":write.command_id,"signal_id":write.signal_id,
            "workflow_instance_id":write.workflow_id,"workflow_run_id":write.run_id,"signal_name":write.name,"signal_wait_id":wait_id,
            "arguments":wire(arguments),"payload_codec":"avro"}), None, Some(write.command_id)).await?;
        Self::enqueue_next_message(tx, write.run_id, queue, true).await
    }

    /// `StructuralLimits::guardPendingActivities`, `guardPendingChildren` and
    /// `guardPendingTimers` before the bridge writes one more (Workflow #933):
    /// a run already holding the limit refuses the whole completion.
    async fn guard_pending(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        kind: super::memo_limits::Pending,
        limits: &super::memo_limits::StructuralLimits,
        task_id: &str,
        attempt: &Value,
    ) -> Result<()> {
        use super::memo_limits::Pending;
        let (limit, sql) = match kind {
            Pending::Activities => (limits.pending_activities,
                "SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=$1 AND status IN ('pending','running')"),
            Pending::Timers => (limits.pending_timers,
                "SELECT COUNT(*) FROM workflow_run_timers WHERE workflow_run_id=$1 AND status='pending'"),
            Pending::Children => (limits.pending_children,
                "SELECT COUNT(*) FROM workflow_links l JOIN workflow_runs r ON r.id=l.child_workflow_run_id WHERE l.parent_workflow_run_id=$1 AND l.link_type='child_workflow' AND r.status NOT IN ('completed','failed','cancelled','terminated')"),
        };
        if limit.is_none() {
            return Ok(());
        }
        let count = Self::scalar(sql).bind(run_id).fetch_one(&mut **tx).await?;
        match super::memo_limits::Exceeded::pending(kind, limit, count) {
            Some(exceeded) => Err(exceeded.worker_refusal(task_id, attempt)),
            None => Ok(()),
        }
    }

    async fn advance_signal_sequences(tx: &mut Transaction<'_, DB>, write: &SignalWrite<'_>) -> Result<()> {
        Self::query("UPDATE workflow_runs SET last_command_sequence=$1 WHERE id=$2")
            .bind(write.command_sequence)
            .bind(write.run_id)
            .execute(&mut **tx)
            .await?;
        Self::query("UPDATE workflow_instances SET last_message_sequence=$1 WHERE id=$2")
            .bind(write.message_sequence)
            .bind(write.workflow_id)
            .execute(&mut **tx)
            .await?;
        Ok(())
    }

    pub(super) async fn authored_sequence(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
    ) -> Result<i64> {
        // Public control command order and deterministic authored call order
        // are different counters in PHP. Signals must never shift a replayed
        // activity/timer/wait's authored position.
        let activities = Self::query(
            "SELECT sequence FROM activity_executions WHERE workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1",
        )
        .bind(run_id)
        .fetch_optional(&mut **tx)
        .await?
        .map(|row| DB::number(&row, "sequence"))
        .transpose()?
        .unwrap_or(0);
        let timers = Self::query(
            "SELECT sequence FROM workflow_run_timers WHERE workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1",
        )
        .bind(run_id)
        .fetch_optional(&mut **tx)
        .await?
        .map(|row| DB::number(&row, "sequence"))
        .transpose()?
        .unwrap_or(0);
        let wait = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('SignalWaitOpened','ConditionWaitOpened','VersionMarkerRecorded','MemoUpserted','SideEffectRecorded','SearchAttributesUpserted') ORDER BY sequence DESC LIMIT 1")
            .bind(run_id).fetch_optional(&mut **tx).await?;
        let wait_sequence = wait
            .map(|row| DB::document_row(&row, "payload"))
            .transpose()?
            .and_then(|value| value["sequence"].as_i64())
            .unwrap_or(0);
        let children = Self::query("SELECT sequence FROM workflow_child_calls WHERE parent_workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1")
            .bind(run_id).fetch_optional(&mut **tx).await?.map(|row| DB::number(&row,"sequence")).transpose()?.unwrap_or(0);
        // `nextDurableCommandSequence`: a scope opening consumes its sequence,
        // and a delivered scope boundary its whole span, as a delivered run
        // request does.
        let mut scoped = 0;
        for row in Self::query("SELECT event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('CancellationScopeOpened','CancellationScopeDelivered')")
            .bind(run_id).fetch_all(&mut **tx).await?
        {
            let payload = DB::document_row(&row, "payload")?;
            if let Some(sequence) = payload["sequence"].as_i64() {
                let span = payload["sequence_span"].as_i64().filter(|span| *span > 1 && DB::string(&row, "event_type").is_ok_and(|kind| kind == "CancellationScopeDelivered"));
                scoped = scoped.max(span.and_then(|span| sequence.checked_add(span - 1)).unwrap_or(sequence));
            }
        }
        let run =
            Self::query("SELECT cancellation_delivery_sequence,run_number FROM workflow_runs WHERE id=$1")
                .bind(run_id)
                .fetch_one(&mut **tx)
                .await?;
        // A redriven run replays the activities before its resume step from
        // reused completions, which have no execution rows.
        let mut reused = 0;
        if DB::number(&run, "run_number")? > 1 {
            let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
                .bind(run_id).fetch_optional(&mut **tx).await?;
            if let Some(started) = started {
                let payload = DB::document_row(&started, "payload")?;
                if payload["recovery_kind"] == "redrive" {
                    reused = payload["resume_step_sequence"].as_i64().unwrap_or(1) - 1;
                }
            }
        }
        Ok(activities
            .max(timers)
            .max(wait_sequence)
            .max(children)
            .max(scoped)
            .max(reused)
            .max(DB::optional_number(&run, "cancellation_delivery_sequence")?.unwrap_or(0)))
    }

    /// `pendingSignalWaitIdForOpenSignalWait`: a new signal wait takes the
    /// identity of the earliest pending signal of its name whose identity no
    /// wait of that name was opened under yet, so the wait resolves with it;
    /// otherwise it gets a fresh identity. "Opened" reads the same history
    /// view as every other wait decision.
    async fn pending_signal_wait_id(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        name: &str,
    ) -> Result<Option<String>> {
        let pending = Self::query("SELECT signal_wait_id FROM workflow_signal_records WHERE workflow_run_id=$1 AND signal_name=$2 AND status='received' AND closed_at IS NULL ORDER BY CASE WHEN command_sequence IS NULL THEN 1 ELSE 0 END,command_sequence,received_at,id")
            .bind(run_id).bind(name).fetch_all(&mut **tx).await?;
        if pending.is_empty() {
            return Ok(None);
        }
        let waits = Self::waits(tx, run_id).await?;
        for row in pending {
            if let Some(id) = DB::optional_string(&row, "signal_wait_id")?.filter(|id| !id.is_empty() && !waits.taken(name, id)) {
                return Ok(Some(id));
            }
        }
        Ok(None)
    }

    /// `UnprojectedSignalDelivery::allows`: the server cannot load an
    /// external worker's workflow class, so a run delivers a received signal
    /// its durable command contract declares even without an open wait. A
    /// run without a complete durable contract delivers none that way.
    async fn declared_signals(tx: &mut Transaction<'_, DB>, run_id: &str) -> Result<Option<Vec<String>>> {
        let Some(started) = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(run_id).fetch_optional(&mut **tx).await?
        else {
            return Ok(None);
        };
        let started = DB::document_row(&started, "payload")?;
        if super::run_observation::command_contract(Some(&started))["source"] != "durable_history" {
            return Ok(None);
        }
        Ok(Some(
            started["declared_signals"]
                .as_array()
                .into_iter()
                .flatten()
                .filter_map(Value::as_str)
                .map(str::to_owned)
                .collect(),
        ))
    }

    /// `PendingMessageTask::signalCanAdvanceRun`: an open condition wait
    /// whose timeout has not fired takes any signal; a signal wait open or
    /// held under the signal's identity takes its own; a run whose durable
    /// contract declares the name takes it without a wait.
    ///
    /// A wait a received signal already holds counts only when
    /// `include_received` (`includeReceivedProjectedSignalWait`): true when a
    /// signal or update is accepted, false after a workflow task completes,
    /// so a held signal Core cannot apply is not delivered again and again.
    fn signal_can_advance(
        waits: &super::wait_history::Waits,
        declared: Option<&[String]>,
        name: &str,
        signal_wait_id: Option<&str>,
        include_received: bool,
    ) -> bool {
        waits.advanceable_condition()
            || signal_wait_id.is_some_and(|signal_wait_id| waits.signal_advanceable(name, signal_wait_id, include_received))
            || declared.is_some_and(|names| names.iter().any(|declared| declared == name))
    }

    /// The run's waits and its declared signals.
    async fn signal_delivery(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
    ) -> Result<(super::wait_history::Waits, Option<Vec<String>>)> {
        Ok((Self::waits(tx, run_id).await?, Self::declared_signals(tx, run_id).await?))
    }

    /// `PendingMessageTask::hasDeliverableSignal`: a received signal that can
    /// advance the run, after the one this task applied. Server 2.10.0 holds
    /// a worker's close for it, since the worker read history before that
    /// signal committed.
    pub(super) async fn deliverable_signal(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        applied: Option<&str>,
    ) -> Result<bool> {
        Ok(Self::next_eligible_signal(tx, run_id, applied, false).await?.is_some())
    }

    /// `PendingMessageTask::nextEligibleSignal`: the first received signal,
    /// in Core's order, that can advance the run. With the signal a task
    /// just attempted, only one that follows it (`signalFollows`); none
    /// otherwise, so a signal the task left received is not offered again.
    async fn next_eligible_signal(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        attempted: Option<&str>,
        include_received: bool,
    ) -> Result<Option<DB::Row>> {
        let signals = Self::query(RECEIVED_SIGNALS).bind(run_id).fetch_all(&mut **tx).await?;
        if signals.is_empty() {
            return Ok(None);
        }
        let attempted = match attempted {
            Some(signal) => Self::query("SELECT id,command_sequence,received_at,created_at FROM workflow_signal_records WHERE id=$1 AND workflow_run_id=$2")
                .bind(signal).bind(run_id).fetch_optional(&mut **tx).await?
                .map(|row| SignalOrder::read::<DB>(&row)).transpose()?,
            None => None,
        };
        let (waits, declared) = Self::signal_delivery(tx, run_id).await?;
        for signal in signals {
            if !Self::signal_can_advance(
                &waits,
                declared.as_deref(),
                &DB::string(&signal, "signal_name")?,
                DB::optional_string(&signal, "signal_wait_id")?.as_deref(),
                include_received,
            ) {
                continue;
            }
            if attempted.as_ref().is_none_or(|attempted| SignalOrder::read::<DB>(&signal).is_ok_and(|order| order.follows(attempted))) {
                return Ok(Some(signal));
            }
        }
        Ok(None)
    }

    /// `PendingMessageTask::createForRun`: while the run has no open
    /// workflow task, deliver its next message. An accepted update goes
    /// unless an earlier signal is unapplied; a signal when its wait is open.
    pub(super) async fn enqueue_next_message(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        queue: &str,
        include_received: bool,
    ) -> Result<()> {
        Self::next_message_task(tx, run_id, queue, None, include_received).await.map(drop)
    }

    /// `PendingMessageTask::createForRun`: the run's next update or signal
    /// task, when it is open, has no open workflow task and holds a message
    /// that can advance it. The created task's id.
    pub(super) async fn next_message_task(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        queue: &str,
        attempted: Option<&str>,
        include_received: bool,
    ) -> Result<Option<String>> {
        let status = Self::query("SELECT status FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut **tx)
            .await?;
        if !matches!(
            DB::string(&status, "status")?.as_str(),
            "pending" | "running" | "waiting"
        ) {
            return Ok(None);
        }
        let open = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')")
            .bind(run_id).fetch_one(&mut **tx).await?;
        if open != 0 {
            return Ok(None);
        }
        // `nextReadyUpdate`: the earliest accepted update, unless an earlier
        // signal is still unapplied. That signal then goes first.
        let update = Self::query("SELECT id,workflow_command_id,command_sequence FROM workflow_updates WHERE workflow_run_id=$1 AND status='accepted' ORDER BY CASE WHEN command_sequence IS NULL THEN 1 ELSE 0 END,command_sequence,accepted_at,created_at,id LIMIT 1")
            .bind(run_id)
            .fetch_optional(&mut **tx)
            .await?;
        if let Some(update) = update
            && Self::blocking_signal(
                tx,
                run_id,
                DB::optional_number(&update, "command_sequence")?,
                None,
            )
            .await?
            .is_none()
        {
            let task_id = Self::create_update_task(
                tx,
                run_id,
                queue,
                &DB::string(&update, "id")?,
                &DB::string(&update, "workflow_command_id")?,
            )
            .await?;
            return Ok(Some(task_id));
        }
        let Some(signal) = Self::next_eligible_signal(tx, run_id, attempted, include_received).await? else {
            return Ok(None);
        };
        {
            let name = DB::string(&signal, "signal_name")?;
            // A signal recorded without a wait id (an imported one) keeps
            // none, as `WorkflowTaskPayload::forSignal` omits a null field.
            let signal_wait_id = DB::optional_string(&signal, "signal_wait_id")?;
            // `WorkflowTaskPayload::forSignal`.
            let signal_id = DB::string(&signal, "id")?;
            let mut payload = json!({"workflow_wait_kind":"signal",
                "open_wait_id":format!("signal-application:{signal_id}"),"resume_source_kind":"workflow_signal",
                "resume_source_id":signal_id,"workflow_signal_id":signal_id,"signal_name":name,
                "workflow_command_id":DB::string(&signal, "workflow_command_id")?});
            if let Some(signal_wait_id) = signal_wait_id {
                payload["signal_wait_id"] = json!(signal_wait_id);
            }
            let task_id = Self::create_task(tx, run_id, queue, "workflow", payload).await?;
            Ok(Some(task_id))
        }
    }

    /// `recordAppliedSignalForSignalResume`: a signal-resumed task applies
    /// its signal once. A signal already applied (by an earlier prefix
    /// checkpoint on the same claim) is left as it is, as Core leaves it.
    /// For a `checkpoint`, a signal Core would not apply (its command
    /// unavailable, its wait not open or another wait open) is skipped as
    /// Core skips it; a completion keeps native's refusals for those.
    pub(super) async fn apply_task_signal(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        task_id: &str,
        task: &DB::Row,
        transition_codec: Arc<Semaphore>,
        checkpoint: bool,
        commands: &[Value],
    ) -> Result<()> {
        let payload = DB::document_row(task, "payload")?;
        if payload["resume_source_kind"] != "workflow_signal" {
            return Ok(());
        }
        let signal_id = text(&payload, "workflow_signal_id")?;
        // The signal's own command, never one of another workflow (Server #765).
        let Some(signal) = Self::query("SELECT s.*,c.message_sequence FROM workflow_signal_records s JOIN workflow_commands c ON c.id=s.workflow_command_id AND c.workflow_instance_id=s.workflow_instance_id WHERE s.id=$1 AND s.workflow_run_id=$2")
            .bind(signal_id).bind(run_id).fetch_optional(&mut **tx).await?
        else {
            return if checkpoint { Ok(()) } else { Err(refuse(StatusCode::CONFLICT, "signal_command_unavailable")) };
        };
        match DB::string(&signal, "status")?.as_str() {
            "received" => {}
            "applied" => return Ok(()),
            _ => return Err(refuse(StatusCode::CONFLICT, "signal_already_applied")),
        }
        let name = DB::string(&signal, "signal_name")?;
        let mut wait_id = DB::optional_string(&signal, "signal_wait_id")?;
        let waits = Self::waits(tx, run_id).await?;
        // `recordAppliedSignalForSignalResume`: the wait opened under the
        // signal's identity, whatever has happened to it since. A signal
        // buffered (`signal-command:`) before any wait took its identity is
        // delivered to the latest wait of its name still open, and keeps that
        // identity (`openWaitIdForName`).
        let recorded_wait_id = wait_id.clone();
        let mut opening = wait_id.as_deref().and_then(|id| waits.signal_opening(&name, id));
        if opening.is_none()
            && wait_id.as_deref().is_some_and(|id| id.starts_with("signal-command:"))
            && let Some(active) = waits.latest_open_signal(&name)
        {
            opening = waits.signal_opening(&name, &active.id);
            wait_id = Some(active.id.clone());
        }
        let sequence = opening.and_then(|opening| opening.sequence);
        // A declared signal applies without a sequence
        // (`UnprojectedSignalDelivery`); a wait a cancellation scope cancelled
        // keeps its received signal (`ScopedWaitCancellation::cancelled`).
        let declared = Self::declared_signals(tx, run_id)
            .await?
            .is_some_and(|names| names.contains(&name));
        let applies = (sequence.is_some() || declared)
            && !wait_id.as_deref().is_some_and(|id| waits.scope_cancelled(id));
        // `recordSatisfiedConditionWaitForSignalResume` then satisfies the
        // latest open condition wait whose timeout has not fired.
        let condition = waits.latest_open_condition();
        if !applies && condition.is_none() {
            // Core leaves the signal received and completes the task. Native
            // refuses such a completion, unless Core's own history explains
            // it: the signal names a wait the run has had, or a condition wait
            // that could take it timed out after it arrived.
            let known = wait_id.as_deref().is_some_and(|id| waits.knows(id));
            let raced = waits.received_at(signal_id).is_some_and(|received| {
                waits.conditions.iter().any(|wait| wait.is_open() && wait.fired_at.is_some_and(|fired| fired > received))
            });
            if checkpoint || known || raced {
                return Ok(());
            }
            let reason = if waits.any_current() { "signal_wait_mismatch" } else { "signal_wait_not_open" };
            return Err(refuse(StatusCode::CONFLICT, reason));
        }
        // An undeclared signal without a wait of its own is consumed by the
        // condition wait at that wait's sequence, without `SignalApplied`
        // (`markConditionWaitSignalConsumed`).
        let record_sequence = if applies { sequence } else { condition.and_then(|wait| wait.sequence) };
        if applies || record_sequence.is_some() {
            let prepared = prepare_control_arguments(
                transition_codec,
                name.clone(),
                DB::string(&signal, "arguments")?,
                "invalid_signal_arguments",
                false,
            )
            .await?;
            let command_id = DB::string(&signal, "workflow_command_id")?;
            let applied_at = now();
            Self::query("UPDATE workflow_commands SET applied_at=$1 WHERE id=$2 AND workflow_instance_id=$3")
                .bind(DB::bind_time(applied_at))
                .bind(&command_id)
                .bind(DB::string(&signal, "workflow_instance_id")?)
                .execute(&mut **tx)
                .await?;
            // Application keeps the identity it applied under; a condition
            // wait consuming the signal leaves its identity as it was.
            let stored_wait_id = if applies { wait_id.as_deref() } else { recorded_wait_id.as_deref() };
            Self::query("UPDATE workflow_signal_records SET status='applied',signal_wait_id=$1,workflow_sequence=$2,applied_at=$3,closed_at=$4 WHERE id=$5")
                .bind(stored_wait_id).bind(DB::optional_integer(record_sequence)).bind(DB::bind_time(applied_at))
                .bind(DB::bind_time(applied_at)).bind(signal_id).execute(&mut **tx).await?;
            let run = Self::query(
                "SELECT workflow_instance_id,message_cursor_position FROM workflow_runs WHERE id=$1",
            )
            .bind(run_id)
            .fetch_one(&mut **tx)
            .await?;
            let previous = DB::number(&run, "message_cursor_position")?;
            let position = DB::number(&signal, "message_sequence")?;
            if position <= previous {
                return Err(refuse(
                    StatusCode::CONFLICT,
                    "signal_message_order_mismatch",
                ));
            }
            Self::query("UPDATE workflow_runs SET message_cursor_position=$1 WHERE id=$2")
                .bind(position)
                .bind(run_id)
                .execute(&mut **tx)
                .await?;
            Self::append(tx, run_id, "MessageCursorAdvanced", json!({"stream_key":format!("instance:{}",DB::string(&run,"workflow_instance_id")?),"previous_position":previous,"new_position":position,"task":Self::task_snapshot(task)?}), Some(task_id), None).await?;
            if applies {
                if let (Some(sequence), Some(id)) = (sequence, wait_id.as_deref()) {
                    Self::cancel_wait_timeout(tx, run_id, &json!({"sequence":sequence,"signal_wait_id":id}), task_id, applied_at).await?;
                }
                let mut applied = json!({"workflow_command_id":command_id,"signal_id":signal_id,"signal_name":name,"value":wire(&prepared.value)});
                if let Some(wait_id) = &wait_id {
                    applied["signal_wait_id"] = json!(wait_id);
                }
                if let Some(sequence) = sequence {
                    applied["sequence"] = json!(sequence);
                }
                // The opening's parallel group (`payloadForPath`), then the
                // selection winner the application decides.
                let group = opening.map(|opening| super::activity_metadata::payload_for_path(&opening.payload)).unwrap_or_default();
                applied.as_object_mut().unwrap().extend(group.clone());
                Self::append(tx, run_id, "SignalApplied", applied, Some(task_id), Some(&command_id)).await?;
                if let Some(id) = wait_id.as_deref() {
                    Self::claim_wait_selection(tx, run_id, &group, "signal", id, "SignalApplied").await?;
                }
            }
        }
        if let Some(condition) = condition {
            let condition_opening = waits.condition_opening(&condition.id);
            let satisfied = condition.satisfied(condition_opening, &payload);
            Self::append(tx, run_id, "ConditionWaitSatisfied", satisfied.clone(), Some(task_id), None).await?;
            // A task that reopens the same occurrence claims no winner.
            let reopened = condition.occurrence().is_some_and(|occurrence| commands.iter().any(|command| {
                command["type"] == "open_condition_wait" && command["condition_wait_occurrence_id"] == occurrence
            }));
            if !reopened {
                let group = condition_opening.map(|opening| super::activity_metadata::payload_for_path(&opening.payload)).unwrap_or_default();
                Self::claim_wait_selection(tx, run_id, &group, "condition", &condition.id, "ConditionWaitSatisfied").await?;
            }
            if let Some(sequence) = condition.sequence {
                Self::cancel_wait_timeout(tx, run_id, &json!({"sequence":sequence,"condition_wait_id":condition.id}), task_id, now()).await?;
            }
        }
        Ok(())
    }

    /// `ParallelChildGroup::claimSelectionWinner` for a wait member: the
    /// first member of a selection to resolve wins it (`SelectionResolved`).
    /// Native serves a selection directly over its waits; one nested in
    /// another group, or with a member wider than one sequence, stays an
    /// explicit unqualified shape rather than a silent divergence.
    async fn claim_wait_selection(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        group: &serde_json::Map<String, Value>,
        kind: &str,
        identity: &str,
        event_type: &str,
    ) -> Result<()> {
        let path = group.get("parallel_group_path").and_then(Value::as_array).cloned().unwrap_or_default();
        if !path.iter().any(super::selections::is_selection) {
            return Ok(());
        }
        let unqualified = || refuse(StatusCode::UNPROCESSABLE_ENTITY, "development_selection_shape_unqualified");
        let [member] = path.as_slice() else { return Err(unqualified()) };
        let index = member["parallel_group_index"].as_i64().ok_or_else(unqualified)?;
        let base = member["parallel_group_base_sequence"].as_i64().ok_or_else(unqualified)?;
        let mut metadata = member.clone();
        if metadata["selection_member_size"].as_i64().unwrap_or(1) != 1 || metadata["selection_member_kind"] != kind {
            return Err(unqualified());
        }
        if metadata.get("selection_member_base_sequence").is_none() {
            metadata["selection_member_base_sequence"] = json!(base + index);
        }
        if metadata.get("selection_member_index").is_none() {
            metadata["selection_member_index"] = json!(index);
        }
        Self::resolve_selection(tx, run_id, &metadata, kind, identity, event_type).await
    }

    pub(crate) async fn register(
        &self,
        body: Value,
        config: &super::QueryTaskConfig,
        protocol: Option<&str>,
    ) -> Result<Value> {
        let worker_id = text(&body, "worker_id")?;
        let queue = text(&body, "task_queue")?;
        for field in ["supported_workflow_types", "supported_activity_types"] {
            if body[field].as_array().is_none_or(|types| {
                types.len() > 1000
                    || types
                        .iter()
                        .any(|v| v.as_str().is_none_or(|s| s.is_empty() || s.len() > 255))
            }) {
                return Err(refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_worker_definition",
                ));
            }
        }
        let runtime = text(&body, "runtime")?;
        let mut registration = super::worker_management::Registration::parse(&body)?;
        registration.record_protocol(protocol);
        let supplied_contracts = body
            .get("workflow_command_contracts")
            .map(|contracts| {
                super::worker_contracts::normalize(contracts, &body["supported_workflow_types"])
            })
            .transpose()?;
        let token = super::worker_incarnations::token().await?;
        let mut tx = self.begin().await?;
        let previous = Self::query("SELECT worker_id,status,workflow_command_contracts,workflow_definition_fingerprints FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
            .bind(self.namespace.as_ref()).bind(worker_id).fetch_optional(&mut *tx).await?;
        // Only an active registration guards its workflow definitions.
        let current = match &previous {
            Some(worker) if DB::optional_string(worker, "status")?.as_deref() == Some("active") => {
                Some(
                    DB::optional_document_row(worker, "workflow_definition_fingerprints")?
                        .unwrap_or(Value::Null),
                )
            }
            _ => None,
        };
        let supported_types: Vec<String> = body["supported_workflow_types"]
            .as_array()
            .into_iter()
            .flatten()
            .filter_map(|t| t.as_str().map(str::to_owned))
            .collect();
        let fingerprints = registration.reconcile(current.as_ref(), &supported_types)?;
        let contracts = match supplied_contracts {
            Some(contracts) => contracts,
            None => {
                let existing = match &previous {
                    Some(worker) => {
                        DB::optional_document_row(worker, "workflow_command_contracts")?
                            .unwrap_or(Value::Null)
                    }
                    None => Value::Null,
                };
                super::worker_contracts::normalize(&existing, &body["supported_workflow_types"])?
            }
        };
        if previous.is_some() {
            // Match PHP's registration boundary: return original workflow leases
            // to ready without a repair command/history event. Token rotation
            // and lease release commit together; failure restores the old attempt.
            Self::query("UPDATE workflow_tasks SET status='ready',leased_at=NULL,lease_owner=NULL,lease_expires_at=NULL WHERE namespace=$1 AND lease_owner=$2 AND task_type='workflow' AND status='leased'")
                .bind(self.namespace.as_ref()).bind(worker_id).execute(&mut *tx).await?;
            Self::release_replaced_activity_leases(&mut tx, self.namespace.as_ref(), worker_id)
                .await?;
        }
        Self::query(DB::WORKER_REGISTRATION_SQL)
            .bind(self.namespace.as_ref())
            .bind(worker_id)
            .bind(queue)
            .bind(runtime)
            .bind(body["sdk_version"].as_str())
            .bind(body["build_id"].as_str())
            .bind(DB::document(&body["supported_workflow_types"]))
            .bind(DB::document(&body["supported_activity_types"]))
            .bind(DB::optional_document(&body["capabilities"]))
            .bind(DB::optional_document(&body["capability_manifest"]))
            .bind(DB::bind_time(now()))
            .bind(DB::bind_time(now()))
            .bind(DB::bind_time(now()))
            .execute(&mut *tx)
            .await?;
        let status = Self::registration_status(&mut tx, self.namespace.as_ref(), queue, body["build_id"].as_str()).await?;
        Self::query("UPDATE workflow_worker_registrations SET status=$1,workflow_command_contracts=$2 WHERE worker_id=$3 AND namespace=$4")
            .bind(status).bind(DB::document(&contracts)).bind(worker_id).bind(self.namespace.as_ref()).execute(&mut *tx).await?;
        Self::record_registration(
            &mut tx,
            self.namespace.as_ref(),
            worker_id,
            &registration,
            &fingerprints,
        )
        .await?;
        self.rotate_incarnation(&mut tx, worker_id, &token).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"worker_id": worker_id, "registration_token":token, "registered": true, "namespace": self.namespace.as_ref(), "task_queue": queue,
            "runtime": body["runtime"], "build_id": body["build_id"], "heartbeat_interval_seconds": config.worker_heartbeat_interval_seconds,
            "status": status, "capabilities": body["capabilities"], "capability_manifest": body["capability_manifest"]}),
        )
    }

    pub(crate) async fn deregister(&self, worker_id: &str) -> Result<Value> {
        let mut tx = self.begin().await?;
        let deleted = Self::query(
            "DELETE FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2",
        )
        .bind(worker_id)
        .bind(self.namespace.as_ref())
        .execute(&mut *tx)
        .await?;
        if DB::affected(deleted) == 0 {
            return Err(refuse(StatusCode::NOT_FOUND, "worker_not_found"));
        }
        self.retire_incarnation(&mut tx, worker_id).await?;
        Self::revoke_worker_activity_leases(&mut tx, self.namespace.as_ref(), worker_id).await?;
        // Revocation expires the existing fence; later polling creates a new
        // attempt before work can be accepted from another worker.
        let recovered = DB::affected(Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE lease_owner=$2 AND namespace=$3 AND status='leased' AND task_type='workflow'")
            .bind(DB::bind_time(now())).bind(worker_id).bind(self.namespace.as_ref()).execute(&mut *tx).await?);
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"worker_id": worker_id, "outcome": "deregistered", "recovered_workflow_task_count": recovered}),
        )
    }

    /// A poll with the default page sizes and PHP's default 30-second wait.
    #[cfg(test)]
    pub(crate) async fn poll(
        &self,
        body: Value,
        kind: &'static str,
        protocol_version: &str,
    ) -> Result<Value> {
        self.poll_with_pages(body, kind, protocol_version, Default::default(), 30)
            .await
    }

    /// A workflow or activity poll, with the configured history page sizes.
    /// A poll that names no `timeout_seconds` waits `default_wait` seconds,
    /// as PHP's `LongPoller` waits DW_WORKER_POLL_TIMEOUT.
    pub(crate) async fn poll_with_pages(
        &self,
        body: Value,
        kind: &'static str,
        protocol_version: &str,
        pages: super::history_pages::HistoryPages,
        default_wait: u64,
    ) -> Result<Value> {
        self.refuse_draining_worker(text(&body, "worker_id")?, text(&body, "task_queue")?).await?;
        // Laravel's `nullable|integer|min:0|max:60` bounds a requested wait;
        // the configured default is not capped.
        let timeout = match body.get("timeout_seconds") {
            None | Some(Value::Null) => default_wait,
            Some(value) => super::laravel_validation::integer(value)
                .filter(|t| (0..=60).contains(t))
                .and_then(|t| u64::try_from(t).ok())
                .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_poll_timeout"))?,
        };
        let deadline = tokio::time::Instant::now() + Duration::from_secs(timeout);
        let requested = super::history_pages::requested_size(&body)?;
        let encoding = if kind == "workflow" {
            super::history_pages::requested(&body)?
        } else {
            None
        };
        let page = pages.poll(requested, encoding);
        // `WorkerPollFence`: the poll belongs to the registration it is
        // admitted under. One that is not fresh is answered at once,
        // uncached, before any replay.
        let fence = match self.admit_worker_poll(text(&body, "worker_id")?, text(&body, "task_queue")?).await? {
            super::worker_poll_fence::PollAdmission::Stale => return Ok(super::worker_poll_fence::stale_answer()),
            super::worker_poll_fence::PollAdmission::Fenced(fence) => Some(fence),
            super::worker_poll_fence::PollAdmission::Unregistered => None,
        };
        // A workflow poll's request ID replays its last deliverable answer,
        // after the registration checks that come first in PHP.
        let poll_request = match (kind, super::workflow_poll_requests::request_id(&body)) {
            ("workflow", Some(id)) => {
                let worker_id = text(&body, "worker_id")?;
                let queue = text(&body, "task_queue")?;
                let registration = Self::query("SELECT task_queue FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2")
                    .bind(worker_id).bind(self.namespace.as_ref()).fetch_optional(&self.pool).await?
                    .ok_or_else(|| refuse(StatusCode::CONFLICT, "worker_not_registered"))?;
                if DB::string(&registration, "task_queue")? != queue {
                    return Err(refuse(StatusCode::CONFLICT, "task_queue_mismatch"));
                }
                let request = self.workflow_poll_request(worker_id, queue, id, default_wait, &["workflow"]).await?;
                if let Some(replay) = self.replay_workflow_poll(&request, worker_id, queue).await? {
                    return replayed_poll(replay, encoding);
                }
                Some(request)
            }
            _ => None,
        };
        let mut cross_kind = if timeout > 0 {
            self.poll_wake_snapshot(&body, kind).await?
        } else {
            None
        };
        // Only the first probe claims under the lock unconditionally. Later
        // probes, on a same-node wake or a timed read that also observes
        // other server processes, claim only after a lock-free read finds
        // something to claim or refuse.
        let mut first = true;
        let mut pace = super::poll_readiness::ReadPace::new();
        let worker_id = text(&body, "worker_id")?;
        loop {
            // Register the notification before probing.
            let wake = self.wake.notified();
            // A replaced or silent registration ends the held poll, before
            // every probe and inside the claim.
            let current = match &fence {
                Some(fence) => self.worker_poll_fence_holds(fence, worker_id).await?,
                None => true,
            };
            let mut claimed = if !current {
                Err(super::worker_poll_fence::stale_claim())
            } else if std::mem::take(&mut first) || self.task_poll_ready(&body, kind, poll_request.as_ref()).await? {
                self.claim(&body, kind, protocol_version, page, poll_request.as_ref(), fence.as_ref()).await
            } else {
                Ok(None)
            };
            // As `WorkflowTaskPoller::nextTask`: a workflow probe that claimed
            // nothing recovers the queue's abandoned leases, and claims again
            // when it recovered one.
            if current
                && kind == "workflow"
                && matches!(claimed, Ok(None))
                && self.recover_abandoned_workflow_leases(text(&body, "task_queue")?).await?
            {
                claimed = self.claim(&body, kind, protocol_version, page, poll_request.as_ref(), fence.as_ref()).await;
            }
            let claimed = match claimed {
                // A fenced poll records stale_worker_registration like any
                // other no-task answer: a live lease a duplicate committed
                // under the same request ID replays instead.
                Err(error) if super::worker_poll_fence::is_stale_claim(&error) => {
                    return self.no_task_answer(&body, poll_request.as_ref(), super::worker_poll_fence::STALE, encoding).await;
                }
                other => other?,
            };
            if let Some(mut task) = claimed {
                // Compressed after the claim commits; a replayed receipt keeps
                // the plain events and takes this request's encoding.
                super::history_pages::compress(&mut task, encoding)?;
                return Ok(activity_server_time(
                    kind,
                    json!({"task": task, "poll_status": "leased", "server_capabilities": capabilities()}),
                ));
            }
            if tokio::time::Instant::now() >= deadline {
                return self.no_task_answer(&body, poll_request.as_ref(), "empty", encoding).await
                    .map(|answer| activity_server_time(kind, answer));
            }
            if self
                .poll_queue_changed(&body, kind, &mut cross_kind)
                .await?
                && tokio::time::Instant::now() < deadline
            {
                return self.no_task_answer(&body, poll_request.as_ref(), "task_queue_changed", encoding).await
                    .map(|answer| activity_server_time(kind, answer));
            }
            #[cfg(debug_assertions)]
            if let Some(request) = &poll_request {
                super::poll_pauses::stop_at(&request.id, super::poll_pauses::PollPoint::Waiting).await;
            }
            pace.wait(wake, deadline).await;
        }
    }

    /// A poll's answer when it leased nothing. Under a request ID, a
    /// duplicate's lease committed after this poll's last probe replays
    /// instead, as a retry with that ID would see it.
    async fn no_task_answer(
        &self,
        body: &Value,
        poll_request: Option<&super::workflow_poll_requests::WorkflowPollRequest>,
        poll_status: &str,
        encoding: Option<&'static str>,
    ) -> Result<Value> {
        if let Some(request) = poll_request
            && let Some(lease) = self.remember_empty_workflow_poll(request, text(body, "worker_id")?, text(body, "task_queue")?, poll_status).await?
        {
            return replayed_poll(lease, encoding);
        }
        Ok(json!({"task": null, "poll_status": poll_status, "server_capabilities": capabilities()}))
    }

    /// Whether a held workflow or activity poll should claim again.
    async fn task_poll_ready(
        &self,
        body: &Value,
        kind: &str,
        poll_request: Option<&super::workflow_poll_requests::WorkflowPollRequest>,
    ) -> Result<bool> {
        let worker_id = text(body, "worker_id")?;
        let queue = text(body, "task_queue")?;
        // The claim refuses a removed or moved registration.
        let registration = Self::query(
            "SELECT task_queue,build_id FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2",
        )
        .bind(worker_id)
        .bind(self.namespace.as_ref())
        .fetch_optional(&self.pool)
        .await?;
        let build = match registration {
            Some(row) if DB::string(&row, "task_queue")? == queue => worker_build::<DB>(&row)?,
            _ => return Ok(true),
        };
        // A concurrent poll with the same request ID may have answered
        // already; the claim replays that answer.
        if let Some(request) = poll_request
            && self.workflow_poll_answered(&request.keys).await?
        {
            return Ok(true);
        }
        if kind != "workflow"
            && let Some(request_id) = body.get("poll_request_id").and_then(Value::as_str)
            && Self::query("SELECT task_id FROM dw_poll_receipts WHERE worker_id=$1 AND kind=$2 AND request_id=$3 AND namespace=$4")
                .bind(worker_id).bind(kind).bind(request_id).bind(self.namespace.as_ref())
                .fetch_optional(&self.pool).await?.is_some()
        {
            return Ok(true);
        }
        let at = now();
        Ok(Self::query(DB::TASK_CANDIDATES_SQL)
            .bind(self.namespace.as_ref())
            .bind(queue)
            .bind(kind)
            .bind(DB::bind_time(at))
            .bind(DB::bind_time(at))
            .bind(build.as_deref())
            .fetch_optional(&self.pool)
            .await?
            .is_some())
    }

    /// Published PHP's poll order (`claimReadyTask`): its bridge returns the
    /// candidates of supported workflow types a batch at a time, and the
    /// poll tries each batch in `TaskFairnessScheduler` order. Native keeps
    /// the same batches and order. An activity's type is read with its
    /// execution, so its batches keep every candidate.
    fn fairness_batches(candidates: Vec<DB::Row>, kind: &str, types: &Value) -> Result<Vec<DB::Row>> {
        let mut eligible = Vec::with_capacity(candidates.len());
        for task in candidates {
            if kind == "workflow" {
                let workflow_type = DB::string(&task, "workflow_type")?;
                if !types.as_array().is_some_and(|list| list.iter().any(|v| v.as_str() == Some(workflow_type.as_str()))) {
                    continue;
                }
            }
            eligible.push(task);
        }
        let mut ordered = Vec::with_capacity(eligible.len());
        let mut remaining = eligible.into_iter();
        loop {
            let batch: Vec<DB::Row> = remaining.by_ref().take(super::priority_fairness::FAIRNESS_BATCH).collect();
            if batch.is_empty() {
                break;
            }
            let keys = batch.iter()
                .map(|task| Ok((DB::optional_number(task, "priority")?, DB::optional_string(task, "fairness_key")?, DB::optional_number(task, "fairness_weight")?)))
                .collect::<Result<Vec<_>>>()?;
            let mut slots: Vec<Option<DB::Row>> = batch.into_iter().map(Some).collect();
            for index in super::priority_fairness::fairness_order(&keys) {
                ordered.extend(slots[index].take());
            }
        }
        Ok(ordered)
    }

    async fn claim(
        &self,
        body: &Value,
        kind: &str,
        protocol_version: &str,
        page: Option<i64>,
        poll_request: Option<&super::workflow_poll_requests::WorkflowPollRequest>,
        fence: Option<&super::worker_poll_fence::WorkerPollFence>,
    ) -> Result<Option<Value>> {
        let mut tx = self.begin().await?;
        if let Some(fence) = fence
            && !self.worker_poll_fence_holds_in(&mut tx, fence, text(body, "worker_id")?).await?
        {
            return Err(super::worker_poll_fence::stale_claim());
        }
        let claimed = self.claim_in(&mut tx, body, kind, protocol_version, page, poll_request).await?;
        tx.commit().await?;
        Ok(claimed)
    }

    /// One claim inside the caller's transaction, which commits it: a
    /// multiplexed probe claims, turns the queue's cursor and records the
    /// poll request's answer as one unit.
    pub(super) async fn claim_in(
        &self,
        tx: &mut super::transaction::Transaction<'_, DB>,
        body: &Value,
        kind: &str,
        protocol_version: &str,
        page: Option<i64>,
        poll_request: Option<&super::workflow_poll_requests::WorkflowPollRequest>,
    ) -> Result<Option<Value>> {
        let worker_id = text(body, "worker_id")?;
        let queue = text(body, "task_queue")?;
        let worker = Self::query(
            "SELECT * FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2",
        )
        .bind(worker_id)
        .bind(self.namespace.as_ref())
        .fetch_optional(&mut **tx)
        .await?
        .ok_or_else(|| refuse(StatusCode::CONFLICT, "worker_not_registered"))?;
        if DB::string(&worker, "task_queue")? != queue {
            return Err(refuse(StatusCode::CONFLICT, "task_queue_mismatch"));
        }
        Self::query(DB::POLL_RECEIPT_CLEANUP_SQL)
            .bind(DB::bind_time(now()))
            .bind(DB::bind_time(now()))
            .execute(&mut **tx)
            .await?;
        // A concurrent duplicate may have leased since this poll began: the
        // binding row, locked here, makes it wait for that one's answer. Only
        // a workflow answer replays as a workflow claim; a multiplexed probe
        // replays a validation answer itself before claiming.
        if let Some(request) = poll_request {
            Self::bind_workflow_poll(tx, request).await?;
            if let Some(answer) = self.deliverable_workflow_lease(tx, request, worker_id, queue).await?
                && answer["task"]["task_kind"] != "update_validation"
            {
                return Ok(Some(answer["task"].clone()));
            }
        }
        // Activity polls keep their receipts; workflow polls use the answer
        // above.
        let request_id = if kind == "workflow" {
            None
        } else {
            body.get("poll_request_id").map(|_| text(body, "poll_request_id")).transpose()?
        };
        if let Some(request_id) = request_id
            && let Some(receipt) = Self::query("SELECT p.*,t.status,t.attempt_count,t.lease_owner,t.lease_expires_at FROM dw_poll_receipts p JOIN workflow_tasks t ON t.id=p.task_id WHERE p.worker_id=$1 AND p.kind=$2 AND p.request_id=$3 AND p.namespace=$4")
                .bind(worker_id).bind(kind).bind(request_id).bind(self.namespace.as_ref()).fetch_optional(&mut **tx).await? {
                if DB::string(&receipt,"status")? == "leased" && DB::number(&receipt,"attempt")? == DB::number(&receipt,"attempt_count")?
                    && DB::optional_string(&receipt,"lease_owner")?.as_deref() == Some(worker_id)
                    && DB::optional_instant(&receipt,"lease_expires_at")?.is_some_and(|expires| expires > now()) {
                    return Ok(Some(DB::document_row(&receipt,"response")?));
                }
                return Err(refuse(StatusCode::CONFLICT,"poll_cached_task_expired"));
        }
        let types: Value = DB::document_row(
            &worker,
            if kind == "workflow" {
                "supported_workflow_types"
            } else {
                "supported_activity_types"
            },
        )?;
        let build = worker_build::<DB>(&worker)?;
        let candidates = Self::query(DB::TASK_CANDIDATES_SQL)
            .bind(self.namespace.as_ref())
            .bind(queue)
            .bind(kind)
            .bind(DB::bind_time(now()))
            .bind(DB::bind_time(now()))
            .bind(build.as_deref())
            .fetch_all(&mut **tx)
            .await?;
        let candidates = Self::fairness_batches(candidates, kind, &types)?;
        for task in candidates {
            // Unsupported stored timestamp shapes fail before leasing work.
            // Takeover preflight must validate all records, including rows
            // that are not eligible for this poll's queue/type/deadline.
            DB::optional_instant(&task, "available_at")?;
            DB::optional_instant(&task, "lease_expires_at")?;
            let run_id = DB::string(&task, "workflow_run_id")?;
            let mut task_payload = DB::document_row(&task, "payload")?;
            if kind == "workflow"
                && !DB::optional_document_row(&worker, "capabilities")?.is_some_and(|flags| {
                    flags
                        .as_array()
                        .is_some_and(|flags| flags.iter().any(|flag| flag == "memo_upserts"))
                })
            {
                let memo_history = Self::scalar("SELECT COUNT(*) FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='MemoUpserted'")
                    .bind(&run_id).fetch_one(&mut **tx).await?;
                if memo_history > 0 {
                    continue;
                }
            }
            // `WorkflowMetadataCapabilityPolicy::canReplayRun`: typed upsert
            // history needs a worker that advertises typed search attributes.
            if kind == "workflow"
                && !DB::optional_document_row(&worker, "capabilities")?.is_some_and(|flags| {
                    flags.as_array().is_some_and(|flags| {
                        flags.iter().any(|flag| flag == super::search_attribute_upserts::CAPABILITY)
                    })
                })
            {
                let typed_history = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='SearchAttributesUpserted' ORDER BY sequence LIMIT 1001")
                    .bind(&run_id).fetch_all(&mut **tx).await?;
                let mut typed = false;
                for event in &typed_history {
                    typed |= DB::document_row(event, "payload")?.get("attribute_types").is_some();
                }
                if typed {
                    continue;
                }
            }
            let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
                .bind(&run_id)
                .fetch_one(&mut **tx)
                .await?;
            let cancellation = Self::cancellation_request(&run)?;
            if cancellation.is_some()
                && (DB::optional_instant(&run, "cancellation_deadline_at")?
                    .is_none_or(|deadline| deadline <= now())
                    || kind == "workflow"
                        && (!super::cooperative::supports(
                            &DB::optional_document_row(&worker, "capabilities")?
                                .unwrap_or(Value::Null),
                            protocol_version,
                        ) || DB::string(&worker, "status")? != "active"
                            || DB::instant(&worker, "last_heartbeat_at")?
                                <= now() - chrono::Duration::seconds(60)))
            {
                continue;
            }
            // `matchesRecordedWorkflowDefinition`: a run pinned to its recorded
            // definition goes to a worker that has it, while one is live.
            if kind == "workflow" {
                let required = DB::optional_string(&task, "compatibility")?
                    .map(|build| build.trim().to_owned())
                    .filter(|build| !build.is_empty());
                let context = Self::definition_context(tx, &run, Some(queue), required).await?;
                if !self.definition_allows(tx, &run, &worker, &context).await?.0 {
                    continue;
                }
            }
            if kind == "workflow"
                && let Some(update_id) = task_payload["workflow_update_id"].as_str()
            {
                let update = Self::query("SELECT update_name FROM workflow_updates WHERE id=$1 AND workflow_run_id=$2 AND status='accepted'")
                    .bind(update_id).bind(&run_id).fetch_optional(&mut **tx).await?;
                let Some(update) = update else {
                    continue;
                };
                if !Self::update_worker_supported(
                    &worker,
                    &DB::string(&task, "workflow_type")?,
                    &DB::string(&update, "update_name")?,
                )? {
                    continue;
                }
            }
            let activity = if kind == "activity" {
                let payload: Value = DB::document_row(&task, "payload")?;
                Self::query("SELECT * FROM activity_executions WHERE id=$1 AND workflow_run_id=$2")
                    .bind(text(&payload, "activity_execution_id")?)
                    .bind(&run_id)
                    .fetch_optional(&mut **tx)
                    .await?
            } else {
                None
            };
            if let Some(activity) = activity.as_ref() {
                if !matches!(
                    DB::string(activity, "status")?.as_str(),
                    "pending" | "running"
                ) || super::activity_deadlines::expired_kind::<DB>(activity, now())?.is_some()
                {
                    // The timeout sweep owns this original deadline. A claim
                    // cannot erase it by starting another attempt.
                    continue;
                }
            }
            let type_name = activity.as_ref().map_or_else(
                || DB::string(&task, "workflow_type"),
                |a| DB::string(a, "activity_type"),
            )?;
            if !types
                .as_array()
                .is_some_and(|list| list.iter().any(|v| v.as_str() == Some(&type_name)))
            {
                continue;
            }
            // `workerCanSatisfySession`: a session-bound activity goes only to
            // a worker on protocol 1.8 or newer, on the session's queue, with
            // every requirement among its capabilities.
            let session = match activity.as_ref() {
                Some(activity) => DB::optional_document_row(activity, "activity_options")?
                    .and_then(|options| super::worker_sessions::CommandSession::from_snapshot(&options["worker_session"])),
                None => None,
            };
            let activity_queue = match activity.as_ref() {
                Some(activity) => DB::optional_string(activity, "queue")?.unwrap_or_else(|| queue.to_owned()),
                None => queue.to_owned(),
            };
            if let Some(session) = &session {
                let capabilities: Vec<String> = DB::optional_document_row(&worker, "capabilities")?
                    .as_ref()
                    .and_then(Value::as_array)
                    .into_iter()
                    .flatten()
                    .filter_map(|value| value.as_str().map(str::to_owned))
                    .collect();
                if !session.claimable_by(protocol_version, &DB::string(&worker, "task_queue")?, &activity_queue, &capabilities) {
                    continue;
                }
            }
            let task_id = DB::string(&task, "id")?;
            // `admitActivity`: the session admits the claim or the claim is
            // rolled back, so a held or full session leaves the task ready.
            let session_view = match &session {
                Some(session) => {
                    let connection = match activity.as_ref() {
                        Some(activity) => DB::optional_string(activity, "connection")?,
                        None => None,
                    };
                    match self.admit_session_activity(tx, &worker, session, &activity_queue, connection.as_deref(), &task_id).await? {
                        Some(view) => Some(view),
                        None => continue,
                    }
                }
                None => None,
            };
            let attempt = DB::number(&task, "attempt_count")?
                .checked_add(1)
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "task_attempt_exhausted"))?;
            let expires = if kind == "activity" && Self::cancellation_request(&run)?.is_none() {
                now() + chrono::Duration::seconds(self.activity_task_lease_seconds)
            } else {
                self.cancellation_lease_expiry(&run)?
            };
            let leased_at = now();
            // `PollRequestLeaseBinding::bindClaimedTask`: a workflow claim
            // records its poll request ID, or clears an older one.
            if kind == "workflow"
                && let Some(map) = task_payload.as_object_mut()
            {
                match poll_request {
                    Some(request) => {
                        map.insert("_server_poll_request_id".into(), json!(request.id));
                    }
                    None => {
                        map.remove("_server_poll_request_id");
                    }
                }
            }
            // PHP binds the immutable claim on every workflow and activity
            // claim (`PollRequestLeaseBinding::bindClaimedTask`); cooperative
            // delivery and the activity's stop acknowledgement read it back.
            if matches!(kind, "workflow" | "activity") {
                task_payload[super::cooperative::CLAIM_KEY] = json!({"registration_id":DB::number(&worker,"id")?,
                    "worker_id":worker_id,"namespace":self.namespace.as_ref(),"protocol_version":protocol_version,
                    "capabilities":DB::optional_document_row(&worker,"capabilities")?.unwrap_or(json!([])),
                    "updated_at":DB::instant(&worker,"updated_at")?,"last_heartbeat_at":DB::instant(&worker,"last_heartbeat_at")?,
                    "task_id":task_id,"run_id":run_id,"lease_owner":worker_id,"attempt":attempt});
            }
            Self::query("UPDATE workflow_tasks SET status='leased',lease_owner=$1,lease_expires_at=$2,attempt_count=$3,leased_at=$4,payload=$5 WHERE id=$6")
                .bind(worker_id).bind(DB::bind_time(expires)).bind(attempt).bind(DB::bind_time(leased_at))
                .bind(DB::document(&task_payload)).bind(&task_id).execute(&mut **tx).await?;
            // A lease is worker authority, not an authored lifecycle commit.
            if kind == "workflow" {
                Self::query("UPDATE workflow_run_waits SET task_status='leased',updated_at=$1 WHERE workflow_run_id=$2 AND task_id=$3 AND ((kind='child' AND status IN ('resolved','cancelled')) OR (kind='update' AND status='open'))")
                    .bind(DB::bind_time(leased_at)).bind(&run_id).bind(&task_id).execute(&mut **tx).await?;
            }
            tx.summary_changed(&run_id);
            // Published PHP keeps the admitted run pending until commands
            // change its state; the poll and description must agree.
            let mut claim = json!({"task_id": task_id, "workflow_id": DB::string(&run,"workflow_instance_id")?,
                "run_id": run_id, "workflow_instance_id": DB::string(&run,"workflow_instance_id")?, "workflow_run_id": run_id,
                "workflow_type": DB::string(&run,"workflow_type")?, "namespace": self.namespace.as_ref(), "payload_codec": "avro",
                "queue": queue, "task_queue": queue, "lease_owner": worker_id, "lease_expires_at": super::cooperative::timestamp(expires)});
            if let Some(activity) = activity {
                let activity_id = DB::string(&activity, "id")?;
                let policy =
                    DB::optional_document_row(&activity, "retry_policy")?.unwrap_or(Value::Null);
                super::activity_deadlines::validate_policy(&policy)?;
                let attempt_id = id();
                let started_at = now();
                Self::query("UPDATE activity_attempts SET status='expired',lease_expires_at=NULL,closed_at=$1 WHERE activity_execution_id=$2 AND status='running'")
                    .bind(DB::bind_time(started_at)).bind(&activity_id).execute(&mut **tx).await?;
                Self::query("INSERT INTO activity_attempts(id,activity_execution_id,workflow_run_id,workflow_task_id,attempt_number,status,lease_owner,lease_expires_at,started_at) VALUES ($1,$2,$3,$4,$5,'running',$6,$7,$8)")
                    .bind(&attempt_id).bind(&activity_id).bind(&run_id).bind(&task_id).bind(attempt)
                    .bind(worker_id).bind(DB::bind_time(expires)).bind(DB::bind_time(started_at)).execute(&mut **tx).await?;
                Self::query("UPDATE activity_executions SET status='running',attempt_count=$1,current_attempt_id=$2,started_at=$3,last_heartbeat_at=NULL,close_deadline_at=$4,heartbeat_deadline_at=$5 WHERE id=$6")
                    .bind(attempt).bind(&attempt_id).bind(DB::bind_time(started_at))
                    .bind(DB::optional_time(super::activity_deadlines::deadline(&policy, "start_to_close_timeout", started_at)?))
                    .bind(DB::optional_time(super::activity_deadlines::deadline(&policy, "heartbeat_timeout", started_at)?)).bind(&activity_id)
                    .execute(&mut **tx)
                    .await?;
                let activity = Self::query("SELECT * FROM activity_executions WHERE id=$1")
                    .bind(&activity_id)
                    .fetch_one(&mut **tx)
                    .await?;
                let mut payload = Self::activity_payload(&activity)?;
                payload["activity_attempt_id"] = json!(attempt_id);
                payload["attempt_number"] = json!(attempt);
                payload["task"] = json!({"id":task_id,"type":"activity","status":"leased",
                    "repair_count":DB::number(&task,"repair_count")?,
                    "available_at":DB::optional_instant(&task,"available_at")?,"leased_at":leased_at,
                    "lease_owner":worker_id,"lease_expires_at":expires,"attempt_count":attempt,"queue":queue,
                    "connection":DB::PHP_CONNECTION});
                Self::append_at(
                    tx,
                    &run_id,
                    "ActivityStarted",
                    payload,
                    Some(&task_id),
                    None,
                    Some(started_at),
                )
                .await?;
                claim["activity_execution_id"] = json!(activity_id);
                claim["activity_attempt_id"] = json!(attempt_id);
                claim["attempt_number"] = json!(attempt);
                claim["activity_type"] = json!(type_name);
                claim["idempotency_key"] = json!(activity_id);
                if let Some(view) = session_view.clone() {
                    claim["worker_session"] = view;
                }
                // A standalone activity started without input has none.
                if let Some(arguments) = DB::optional_string(&activity, "arguments")? {
                    claim["arguments"] = wire(&arguments);
                }
                claim["retry_policy"] =
                    DB::optional_document_row(&activity, "retry_policy")?.unwrap_or(Value::Null);
                // PHP omits the field when the activity has no deadline.
                let deadlines = super::activity_deadlines::deadlines_view::<DB>(&activity)?;
                if !deadlines.is_null() {
                    claim["deadlines"] = deadlines;
                }
            } else {
                claim["workflow_task_attempt"] = json!(attempt);
                claim["arguments"] = wire(&DB::string(&run, "arguments")?);
                if let Some(update_id) = task_payload["workflow_update_id"].as_str() {
                    claim["workflow_update_id"] = json!(update_id);
                }
                claim["sticky_replay_mode"] = json!("cold_replay");
                claim["compatibility"] = json!(DB::optional_string(&task, "compatibility")?
                    .filter(|build| !build.trim().is_empty())
                    .or(DB::optional_string(&run, "compatibility")?.filter(|build| !build.trim().is_empty())));
                if let Some(cancellation) = cancellation {
                    claim["cancellation_request"] = cancellation;
                }
                for field in [
                    "workflow_wait_kind",
                    "child_call_id",
                    "child_workflow_run_id",
                    "resume_source_kind",
                    "resume_source_id",
                    "workflow_sequence",
                    "workflow_event_type",
                    "open_wait_id",
                    "timer_id",
                    "timer_kind",
                    "signal_wait_id",
                    "signal_name",
                    "condition_wait_id",
                    "condition_wait_occurrence_id",
                    "condition_key",
                    "condition_definition_fingerprint",
                ] {
                    if let Some(value) = task_payload.get(field) {
                        claim[field] = value.clone();
                    }
                }
                // `WorkflowTaskPoller::workflowTaskResumeContext`: a signal task
                // names its signal and command and carries the signal's
                // arguments, which the Rust SDK resumes the wait with.
                for field in ["workflow_signal_id", "workflow_command_id"] {
                    if let Some(value) = task_payload[field].as_str().map(str::trim).filter(|value| !value.is_empty()) {
                        claim[field] = json!(value);
                    }
                }
                if let Some(signal_id) = task_payload["workflow_signal_id"].as_str() {
                    let signal = Self::query("SELECT arguments,workflow_command_id FROM workflow_signal_records WHERE id=$1 AND workflow_run_id=$2")
                        .bind(signal_id)
                        .bind(&run_id)
                        .fetch_optional(&mut **tx)
                        .await?;
                    if let Some(signal) = signal {
                        if let Some(arguments) =
                            DB::optional_string(&signal, "arguments")?.filter(|arguments| !arguments.is_empty())
                        {
                            claim["signal_arguments"] = wire(&arguments);
                        }
                        // PHP's signal task payload names its command; older
                        // native tasks take it from the signal record.
                        if claim.get("workflow_command_id").is_none() {
                            claim["workflow_command_id"] = json!(DB::string(&signal, "workflow_command_id")?);
                        }
                    }
                }
                let (mut events, next) = match page {
                    Some(size) => Self::history_page_connection(&mut **tx, &run_id, 0, size).await?,
                    None => (Self::whole_history(&mut **tx, &run_id).await?, None),
                };
                Self::with_signal_sequences(&mut **tx, &run_id, &mut events).await?;
                let budget = Self::workflow_history_budget(
                    tx,
                    &run_id,
                    DB::number(&run, "last_history_sequence")?,
                )
                .await?;
                budget.write_task(&mut claim);
                claim["history_events"] = json!(events);
                claim["next_history_page_token"] = json!(next);
            }
            if let Some(request_id) = request_id {
                Self::query("INSERT INTO dw_poll_receipts(worker_id,kind,request_id,task_id,attempt,response,expires_at,namespace) VALUES ($1,$2,$3,$4,$5,$6,$7,$8)")
                    .bind(worker_id).bind(kind).bind(request_id).bind(&task_id).bind(attempt).bind(DB::document(&claim)).bind(DB::bind_time(after(120))).bind(self.namespace.as_ref()).execute(&mut **tx).await?;
            }
            if let Some(request) = poll_request {
                Self::remember_workflow_poll(tx, request, &json!({"task": claim, "poll_status": "leased"})).await?;
            }
            return Ok(Some(claim));
        }
        Ok(None)
    }

    pub(crate) async fn heartbeat_task(&self, task_id: &str, body: Value) -> Result<Value> {
        let mut tx = self.begin().await?;
        let task = self.task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "workflow" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        Self::workflow_task_guard(&mut tx, &task, &body, self.worker_stale_after_seconds).await?;
        let run = Self::active_run(&mut tx, &DB::string(&task, "workflow_run_id")?).await?;
        let expires = self.cancellation_lease_expiry(&run)?;
        tx.summary_changed(&DB::string(&run, "id")?);
        Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2")
            .bind(DB::bind_time(expires))
            .bind(task_id)
            .execute(&mut *tx)
            .await?;
        tx.commit().await?;
        // PHP's heartbeat also reports the run's and the task's status.
        let mut response = json!({"task_id": task_id, "workflow_task_attempt": body["workflow_task_attempt"],
            "lease_owner": body["lease_owner"], "lease_expires_at": super::cooperative::timestamp(expires), "renewed": true,
            "run_status": DB::string(&run, "status")?, "task_status": "leased", "reason": null});
        if let Some(cancellation) = Self::cancellation_request(&run)? {
            response["cancellation_request"] = cancellation;
        }
        Ok(response)
    }

    pub(crate) async fn task_history(
        &self,
        task_id: &str,
        body: Value,
        pages: super::history_pages::HistoryPages,
    ) -> Result<Value> {
        let after = super::http::decode_cursor(body["next_history_page_token"].as_str());
        let size = pages.continuation(super::history_pages::requested_size(&body)?);
        let encoding = super::history_pages::requested(&body)?;
        let mut tx = self.begin_read().await?;
        let task = self.task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "workflow" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        Self::workflow_task_guard(&mut tx, &task, &body, self.worker_stale_after_seconds).await?;
        let run_id = DB::string(&task, "workflow_run_id")?;
        let (mut events, next) = Self::history_page_connection(&mut tx, &run_id, after, size).await?;
        Self::with_signal_sequences(&mut tx, &run_id, &mut events).await?;
        let run = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
            .bind(&run_id)
            .fetch_one(&mut *tx)
            .await?;
        let last = DB::number(&run, "last_history_sequence")?;
        let budget = Self::workflow_history_budget(&mut tx, &run_id, last).await?;
        let mut response = json!({"task_id":task_id,"workflow_task_attempt":body["workflow_task_attempt"],"history_events":events,"next_history_page_token":next});
        budget.write_task(&mut response);
        tx.commit().await?;
        super::history_pages::compress(&mut response, encoding)?;
        Ok(response)
    }

    /// `timeoutIfDeadlineExpired`: a workflow task report on a run whose
    /// execution or run deadline has passed closes the run as timed out and
    /// refuses the report with the completion's receipt (`409`, outcome
    /// `completed`, `run_status` `failed`, reason `run_timed_out`). PHP SDK
    /// 3.6.1 recognises that receipt, and does not recognise a timeout refusal
    /// shaped as `waiting_for_history` (Server #794). Completions, failures
    /// and history waits all use it. A run still inside its deadlines gets its
    /// transaction back unchanged.
    async fn close_expired_run(
        &self,
        mut tx: Transaction<'static, DB>,
        run: &DB::Row,
        task: &DB::Row,
        attempt: &Value,
    ) -> Result<Transaction<'static, DB>> {
        let expired = [DB::optional_instant(run, "execution_deadline_at")?, DB::optional_instant(run, "run_deadline_at")?]
            .into_iter()
            .flatten()
            .any(|deadline| deadline <= now());
        if !expired {
            return Ok(tx);
        }
        let response = Self::time_out_run(&mut tx, run, task, attempt).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Err(super::RuntimeError::Protocol { status: StatusCode::CONFLICT, response })
    }

    pub(crate) async fn fail_workflow_task(
        &self,
        task_id: &str,
        body: Value,
        transition_codec: Arc<Semaphore>,
    ) -> Result<Value> {
        // Waiting acknowledgement, ordinary retry and a persisted range block
        // are distinct transitions. Other replay blockers remain explicit gates.
        text(&body, "lease_owner")?;
        if body["workflow_task_attempt"]
            .as_i64()
            .is_none_or(|attempt| attempt < 1)
        {
            return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_request"));
        }
        let failure = body["failure"].as_object().ok_or_else(|| {
            refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_workflow_task_failure",
            )
        })?;
        let message = failure
            .get("message")
            .and_then(Value::as_str)
            .filter(|value| !value.trim().is_empty())
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_workflow_task_failure",
                )
            })?;
        for (field, limit) in [("type", 512), ("reason", 191), ("stack_trace", usize::MAX)] {
            if failure.get(field).is_some_and(|value| {
                !value.is_null()
                    && value
                        .as_str()
                        .is_none_or(|text| text.chars().count() > limit)
            }) {
                return Err(refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_workflow_task_failure",
                ));
            }
        }
        if failure.get("sequence").is_some_and(|value| {
            !value.is_null() && value.as_i64().is_none_or(|sequence| sequence < 1)
        }) {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_workflow_task_failure",
            ));
        }
        let kind = failure
            .get("type")
            .and_then(Value::as_str)
            .unwrap_or("")
            .trim_matches(|c| matches!(c, ' ' | '\t' | '\n' | '\r' | '\0' | '\x0b'));
        let waiting = kind.eq_ignore_ascii_case("WorkflowTaskWaitingForHistory")
            || format!("{kind} {message}")
                .to_ascii_lowercase()
                .contains("workflow task waiting for scheduled history");
        let range_blocked = !waiting
            && (failure
                .get("reason")
                .and_then(Value::as_str)
                .map(super::task_failures::php_trim)
                .is_some_and(|reason| matches!(reason, "version_marker_incompatible_range" | "activity_retry_policy_mismatch"))
                // Published Rust Workers report their replay error in the
                // message, without synthesizing PHP reason/sequence fields.
                || (kind == "RustWorkflowTaskFailure"
                    && super::task_failures::blocks_replay(&body["failure"])));
        // Published PHP records every replay-blocking failure the same way
        // (`workflowTaskFailureBlocksReplay`): the task stays failed with its
        // replay block, without a retry, until an operator repairs the run.
        let range_blocked =
            range_blocked || (!waiting && super::task_failures::blocks_replay(&body["failure"]));
        // The task is read within the request's namespace, and its retry
        // copies the run's namespace.
        let mut tx = self.begin().await?;
        let task = self.task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "workflow" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        Self::workflow_task_guard(&mut tx, &task, &body, self.worker_stale_after_seconds).await?;
        let run_id = DB::string(&task, "workflow_run_id")?;
        let run = Self::active_run(&mut tx, &run_id).await?;
        // Server #794: a failure or history wait reported after the run's
        // deadline closes the run as timed out, with the receipt a completion
        // gets. Published 2.11.8 acknowledged the wait and left the run waiting.
        let mut tx = self.close_expired_run(tx, &run, &task, &body["workflow_task_attempt"]).await?;
        let mut payload = DB::document_row(&task, "payload")?;
        // Published PHP retries a failed task as it is, with any signal or
        // update it carries. A waiting acknowledgement of a signal task
        // applies its signal, as PHP completes that task with no commands; an
        // update task's acknowledgement still needs its own contract. A
        // pending update or deliverable signal gets its own task after the
        // acknowledgement, as in PHP; a signal that cannot advance the run
        // stays received.
        if waiting
            && (payload["workflow_update_id"].is_string()
                || Self::cancellation_request(&run)?.is_some())
        {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_workflow_task_waiting_messages_unqualified",
            ));
        }
        if !waiting {
            tx.summary_changed(&run_id);
            // Do not append history or create a new run. A failed lease and
            // its optional fresh retry commit together under the backend lock.
            for (field, target) in [("type", "failure_type"), ("reason", "failure_reason")] {
                if let Some(value) = failure
                    .get(field)
                    .and_then(Value::as_str)
                    .map(super::task_failures::php_trim)
                    .filter(|value| !value.is_empty())
                {
                    payload[target] = json!(value);
                }
            }
            if let Some(sequence) = failure.get("sequence").and_then(Value::as_i64) {
                payload["failure_sequence"] = json!(sequence);
            }
            if range_blocked {
                payload["replay_blocked"] = json!(true);
                payload["replay_blocked_reason"] = json!("worker_reported_replay_failure");
                if let Some(kind) = payload.get("failure_type").cloned() {
                    payload["replay_blocked_failure_type"] = kind;
                }
            }
            Self::query("UPDATE workflow_tasks SET status='failed',lease_expires_at=NULL,last_error=$1,payload=$2 WHERE id=$3")
                .bind(message).bind(DB::document(&payload)).bind(task_id).execute(&mut *tx).await?;
            if range_blocked {
                // Keep the original run/history and logical task. Only explicit
                // repair may make this lease runnable again; ordinary poll or
                // worker deregistration must not turn the block into a retry.
                Self::query("UPDATE workflow_tasks SET updated_at=$1 WHERE id=$2")
                    .bind(DB::bind_time(now()))
                    .bind(task_id)
                    .execute(&mut *tx)
                    .await?;
                tx.commit().await?;
                self.wake.notify_waiters();
                return Ok(
                    json!({"task_id":task_id,"workflow_task_attempt":body["workflow_task_attempt"],
                    "outcome":"failed","recorded":true,"reason":null,"next_task_id":null}),
                );
            }
            let open = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')")
                .bind(&run_id).fetch_one(&mut *tx).await?;
            let mut next_task_id = None;
            if open == 0 {
                for field in [
                    "failure_reason",
                    "failure_sequence",
                    "failure_type",
                    "replay_blocked",
                    "replay_blocked_reason",
                    "replay_blocked_failure_type",
                ] {
                    payload
                        .as_object_mut()
                        .ok_or_else(|| {
                            refuse(
                                StatusCode::UNPROCESSABLE_ENTITY,
                                "invalid_workflow_task_payload",
                            )
                        })?
                        .remove(field);
                }
                payload["workflow_task_retry_of"] = json!(task_id);
                payload["workflow_task_retry_after_error"] = json!(message);
                let next = id();
                // As PHP in poll mode, a task is dispatched to pollers when it is created.
                let created = now();
                // Copy routing in SQL so JSON/null representations remain
                // native to each backend. A new lease increments this carried
                // attempt count rather than restarting it at one.
                Self::query("INSERT INTO workflow_tasks(id,workflow_run_id,namespace,task_type,status,attempt_count,available_at,created_at,updated_at,last_dispatched_at,last_dispatch_attempt_at,payload,connection,queue,compatibility,priority,fairness_key,fairness_weight) SELECT $1,r.id,r.namespace,'workflow','ready',t.attempt_count,$2,$3,$4,$5,$6,$7,COALESCE(t.connection,r.connection),COALESCE(t.queue,r.queue),COALESCE(t.compatibility,r.compatibility),COALESCE(t.priority,r.priority,5),COALESCE(t.fairness_key,r.fairness_key),COALESCE(t.fairness_weight,r.fairness_weight,1) FROM workflow_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id WHERE t.id=$8")
                    .bind(&next).bind(DB::bind_time(created)).bind(DB::bind_time(created)).bind(DB::bind_time(created)).bind(DB::bind_time(created)).bind(DB::bind_time(created))
                    .bind(DB::document(&payload)).bind(task_id).execute(&mut *tx).await?;
                next_task_id = Some(next);
            }
            tx.commit().await?;
            self.wake.notify_waiters();
            return Ok(
                json!({"task_id":task_id,"workflow_task_attempt":body["workflow_task_attempt"],
                "outcome":"failed","recorded":true,"reason":null,"next_task_id":next_task_id}),
            );
        }
        // `acknowledgeWorkflowTaskWaitingForHistory` completes a signal task
        // through the bridge, which records its signal as applied.
        Self::apply_task_signal(&mut tx, &run_id, task_id, &task, transition_codec, false, &[]).await?;
        payload["waiting_for_history_acknowledged"] = json!(true);
        tx.summary_changed(&run_id);
        payload["waiting_for_history_message"] = json!(message);
        if !kind.is_empty() {
            payload["waiting_for_history_failure_type"] = json!(kind);
        }
        Self::query("UPDATE workflow_tasks SET status='completed',lease_expires_at=NULL,payload=$1 WHERE id=$2")
            .bind(DB::document(&payload)).bind(task_id).execute(&mut *tx).await?;
        Self::query("UPDATE workflow_runs SET status='waiting',last_progress_at=$1 WHERE id=$2")
            .bind(DB::bind_time(now()))
            .bind(&run_id)
            .execute(&mut *tx)
            .await?;
        // PHP releases the run and schedules its next message together.
        Self::enqueue_next_message(&mut tx, &run_id, &DB::string(&run, "queue")?, false).await?;
        let next = Self::query("SELECT id FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status='ready' ORDER BY created_at DESC,id DESC LIMIT 1")
            .bind(&run_id)
            .fetch_optional(&mut *tx)
            .await?
            .map(|row| DB::string(&row, "id"))
            .transpose()?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"task_id":task_id,"workflow_task_attempt":body["workflow_task_attempt"],
            "outcome":"waiting_for_history","recorded":true,"reason":null,"next_task_id":next}),
        )
    }

    /// A workflow task completion, or with `checkpoint` a cancellation scope
    /// prefix checkpoint: the same validation and Server guards, then Core's
    /// checkpoint instead of the completion (`mutateWorkflowTaskCommands`).
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn complete_or_checkpoint(
        &self,
        task_id: &str,
        body: Value,
        transition_codec: Arc<Semaphore>,
        external: super::external_resolution::PreparedCommands,
        worker: Option<super::runtime_credentials::Principal>,
        protocol: Option<&str>,
        limits: super::memo_limits::StructuralLimits,
        checkpoint: Option<&super::scope_checkpoint::Checkpoint>,
    ) -> Result<Value> {
        let transition_codec_for_streams = transition_codec.clone();
        // `commands` is required and non-empty, with no count limit of its
        // own: each command meets the run's structural limits instead.
        let commands = body["commands"]
            .as_array()
            .filter(|c| !c.is_empty())
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_commands"))?;
        super::workflow_failures::validate_batch(commands)?;
        // Validate the entire batch before mutation. Unknown commands and
        // options cannot be silently accepted as unsupported durable effects.
        for (index, command) in commands.iter().enumerate() {
            // `cancellation_scope_id` is checked by the scope admission guard
            // after ownership, before PHP's normalizer reads field scopes.
            // Native records activity and timer membership only, so a scoped
            // child or wait keeps its field refusal.
            let unscoped;
            let scoped = command.get("cancellation_scope_id").is_some();
            let command = if scoped
                && !matches!(command["type"].as_str(), Some("start_child_workflow" | "open_signal_wait" | "open_condition_wait"))
            {
                let mut stripped = command.clone();
                if let Some(fields) = stripped.as_object_mut() {
                    fields.remove("cancellation_scope_id");
                }
                unscoped = stripped;
                &unscoped
            } else {
                command
            };
            // The command normalizer's field scopes and value rules, with
            // PHP's messages, before native's own refusals. A local activity
            // record meets the normalizer after the ownership guards, as in PHP.
            if command["type"] != super::local_activities::COMMAND {
                super::completion_rules::normalizer_checks(index, command)?;
            }
            let command_type = text(command, "type")?;
            // A non-member command naming a scope is refused by the admission
            // guard after ownership, before PHP reads its payload.
            if scoped
                && matches!(command_type, "fail_workflow" | "complete_workflow" | "continue_as_new" | "record_version_marker"
                    | "record_side_effect" | "upsert_search_attributes" | "upsert_memo" | "complete_update" | "fail_update")
            {
                continue;
            }
            match command_type {
                "record_local_activity" => {}
                "fail_workflow" => {
                    super::workflow_failures::validate(command)?;
                }
                "complete_workflow" => {
                    reject_fields(command, &["type", "result"])?;
                    if !external.contains_key(&index) {
                        envelope(command, "result")?;
                    }
                }
                "continue_as_new" => {
                    super::continuations::validate(command)?;
                }
                "schedule_activity" => {
                    super::selections::fields(
                        command,
                        &[
                            "type",
                            "activity_type",
                            "arguments",
                            "connection",
                            "queue",
                            "worker_session",
                            "retry_policy",
                            "start_to_close_timeout",
                            "schedule_to_start_timeout",
                            "schedule_to_close_timeout",
                            "heartbeat_timeout",
                        ],
                    )?;
                    text(command, "activity_type")?;
                    if !external.contains_key(&index) {
                        envelope(command, "arguments")?;
                    }
                    super::activity_failures::retry_policy(command)?;
                }
                "start_child_workflow" => {
                    super::children::validate_child(command)?;
                }
                "start_timer" => {
                    super::selections::fields(command, &["type", "delay_seconds"])?;
                    timer_deadline(command)?;
                }
                "record_version_marker" => {
                    version_marker_payload(command)?;
                }
                "record_side_effect" => {
                    reject_fields(command, &["type", "result", "payload_codec"])?;
                    side_effect_result(command)?;
                }
                "upsert_search_attributes" => {
                    reject_fields(command, &["type", "attributes", "attribute_types"])?;
                    super::search_attribute_upserts::validate_shape(command, index)?;
                }
                "upsert_memo" => {
                    reject_fields(command, &["type", "entries"])?;
                    envelope(command, "entries")?;
                    if !super::memos::UPDATES_SUPPORTED {
                        return Err(super::RuntimeError::Protocol {
                            status: StatusCode::CONFLICT,
                            response: json!({"reason":"workflow_memo_updates_unavailable",
                                "command_type":"upsert_memo","recorded":false}),
                        });
                    }
                }
                "open_condition_wait" => {
                    reject_fields(
                        command,
                        &[
                            "type",
                            "condition_key",
                            "condition_definition_fingerprint",
                            "condition_wait_occurrence_id",
                            "timeout_seconds",
                        ],
                    )?;
                    if command
                        .get("condition_wait_occurrence_id")
                        .is_some_and(|value| !value.is_null() && !value.is_string())
                    {
                        return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_request"));
                    }
                    // `normalizeOpenConditionWaitCommand`: the key and the
                    // definition fingerprint are optional strings, of any form.
                    super::wait_timeouts::timeout_for(command, "invalid_condition_timeout")?;
                }
                "open_signal_wait" => {
                    reject_fields(command, &["type", "signal_name", "timeout_seconds"])?;
                    text(command, "signal_name")?;
                    super::wait_timeouts::timeout(command)?;
                }
                "complete_update" => {
                    reject_fields(command, &["type", "update_id", "result"])?;
                    text(command, "update_id")?;
                    envelope(command, "result")?;
                }
                "fail_update" => {
                    super::updates::update_failure(command)?;
                }
                _ => {
                    return Err(refuse(
                        StatusCode::UNPROCESSABLE_ENTITY,
                        "unsupported_workflow_command",
                    ));
                }
            }
        }
        let mut tx = self.begin().await?;
        let task = self.task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "workflow" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        // A completion that differs from the recorded one, or follows a
        // waiting acknowledgement, comes from a lost claim: the guard refuses
        // it with the fields a worker uses to recognize that.
        // A checkpoint has no completion receipt to replay.
        let replay = checkpoint.is_none() && match Self::duplicate_receipt(&task, &body) {
            Err(super::RuntimeError::Refused {
                reason: "conflicting_completion" | "task_not_leased",
                ..
            }) => false,
            other => other?,
        };
        if replay {
            let run_id = DB::string(&task, "workflow_run_id")?;
            let current = Self::query("SELECT status FROM workflow_runs WHERE id=$1")
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
            let created = DB::optional_document_row(&task, "receipt")?
                .and_then(|receipt| receipt.get(COMPLETION_RECEIPT).map(|receipt| receipt["created_task_ids"].clone()))
                .unwrap_or_else(|| json!([]));
            return Ok(
                json!({"task_id": task_id, "run_id": run_id, "run_status": DB::string(&current,"status")?, "workflow_task_attempt": body["workflow_task_attempt"],
                "outcome": "completed", "recorded": false, "reason": "already_completed", "created_task_ids": created}),
            );
        }
        Self::workflow_task_guard(&mut tx, &task, &body, self.worker_stale_after_seconds).await?;
        // PHP admits cancellation scopes before it guards worker-session
        // commands, and guards both before validating streams.
        Self::cancellation_scope_admission(&mut tx, &task, &body, commands, protocol).await?;
        self.guard_worker_session_commands(&mut tx, &task, task_id, &body, protocol, commands).await?;
        // `MessageStreamService::validateCompletion`, before anything changes.
        let streams = super::message_streams::Completion::of(&body);
        let stream_instance = Self::query("SELECT workflow_instance_id FROM workflow_runs WHERE id=$1")
            .bind(DB::string(&task, "workflow_run_id")?)
            .fetch_one(&mut *tx)
            .await?;
        let stream_instance = DB::string(&stream_instance, "workflow_instance_id")?;
        if !streams.is_empty() {
            self.validate_stream_completion(&mut tx, &stream_instance, &DB::string(&task, "workflow_run_id")?, &streams)
                .await?;
        }
        // `childCancellationPolicyGuard`: a child that requests cancellation
        // on parent close, or propagates the parent's cancellation, needs a
        // cooperative worker's claim and a protocol 1.20 request.
        let mut cooperative_child = false;
        for command in commands.iter().filter(|command| command["type"] == "start_child_workflow") {
            cooperative_child |= super::children::policies(command)?.cooperative();
        }
        if cooperative_child {
            let mut unavailable = Vec::new();
            if !protocol
                .and_then(|version| version.strip_prefix("1."))
                .and_then(|minor| minor.parse::<u32>().ok())
                .is_some_and(|minor| minor >= 20)
            {
                unavailable.push("request_protocol");
            }
            if !Self::claim_supports_cancellation(&task)? {
                unavailable.push("worker_claim_capability");
            }
            if !unavailable.is_empty() {
                return Err(super::RuntimeError::Protocol {
                    status: StatusCode::CONFLICT,
                    response: json!({"task_id": task_id, "workflow_task_attempt": body["workflow_task_attempt"],
                        "worker_id": DB::optional_string(&task, "lease_owner")?, "outcome": "rejected", "recorded": false,
                        "reason": "child_cancellation_policy_not_supported", "required_capability": "cooperative_cancellation",
                        "minimum_protocol_version": "1.20", "requested_version": protocol, "unavailable": unavailable,
                        "remediation": "Use a runtime with child cancellation policies and a claim from a cooperative worker on protocol 1.20 or newer."}),
                });
            }
        }
        // `guardPortableWorkerAffinityCompletion`, then the normalizer's
        // local activity rules inside the transaction.
        let mut local = std::collections::BTreeMap::new();
        let mut exceptions = std::collections::BTreeMap::new();
        if commands.iter().any(|command| command["type"] == super::local_activities::COMMAND) {
            Self::guard_local_activities(&mut tx, self.namespace.as_ref(), &task, &body, protocol).await?;
            local = super::local_activities::normalize(commands, protocol)?;
            exceptions = super::local_activities::exceptions(&local, transition_codec.clone()).await?;
        }
        if let Some(checkpoint) = checkpoint {
            return self
                .checkpoint_prefix(tx, task, &body, commands, checkpoint, transition_codec, &external, &local, &exceptions, limits)
                .await;
        }
        // `parseCommands`: at most one terminal command, anywhere in the
        // batch. It applies after every other command.
        if commands.iter().filter(|command| is_terminal(command)).count() > 1 {
            return Err(refuse(StatusCode::CONFLICT, "invalid_commands"));
        }
        let run_id = DB::string(&task, "workflow_run_id")?;
        let run = Self::active_run(&mut tx, &run_id).await?;
        // `timeoutIfDeadlineExpired`: published PHP applies a run's deadlines
        // when one of its workflow tasks completes, and closes the run.
        let mut tx = self.close_expired_run(tx, &run, &task, &body["workflow_task_attempt"]).await?;
        // Workflow #930: the deadline close above is never metered. Before
        // the worker's first write, its local activities' exact events and
        // rows (other commands at one event) must fit the limits; then the
        // rest of the completion runs under the history meter.
        let (mut events, mut rows) = (0usize, 0usize);
        for index in 0..commands.len() {
            let (command_events, command_rows) = match local.get(&index) {
                Some(record) => record.budget(),
                None => (super::history_meter::MIN_COMMAND_HISTORY_EVENTS, 2 * super::history_meter::MIN_COMMAND_HISTORY_EVENTS),
            };
            events = events.saturating_add(command_events);
            rows = rows.saturating_add(command_rows);
        }
        if limits.history_events.is_some_and(|limit| limit > 0 && events > limit)
            || limits.history_rows.is_some_and(|limit| limit > 0 && rows > limit)
        {
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: json!({"task_id": task_id, "workflow_task_attempt": body["workflow_task_attempt"], "outcome": "completed",
                    "recorded": false, "run_id": run_id, "run_status": null, "created_task_ids": [],
                    "reason": "completion_budget_exceeded"}),
            });
        }
        tx.meter = Some(super::history_meter::Meter::new(&limits, task_id, &body["workflow_task_attempt"]));
        let task_payload = DB::document_row(&task, "payload")?;
        if Self::cancellation_request(&run)?.is_some()
            && commands.iter().any(|command| {
                !matches!(
                    command["type"].as_str(),
                    Some(
                        "schedule_activity" | "start_timer" | "record_side_effect" | "complete_workflow" | "fail_workflow"
                    )
                )
            })
        {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "cancellation_cleanup_command_not_available",
            ));
        }
        if Self::cancellation_request(&run)?.is_some()
            && commands
                .iter()
                .any(|command| command.get("parallel_group_path").is_some())
        {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_selection_cancellation_unqualified",
            ));
        }
        // `validateCancellationScopeMembership` in the bridge's completion: a
        // prepared scope admits no new member, and an undelivered preparation
        // reserves its boundary for every command.
        let non_terminal: Vec<&Value> = commands.iter().filter(|command| !is_terminal(command)).collect();
        if let Some(reason) = Self::cancellation_scope_completion_refusal(&mut tx, &run_id, &non_terminal).await? {
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: super::cancellation_scopes::enveloped(json!({"task_id": task_id,
                    "workflow_task_attempt": body["workflow_task_attempt"], "outcome": "completed", "recorded": false,
                    "run_id": run_id, "run_status": DB::string(&run, "status")?, "created_task_ids": [], "reason": reason})),
            });
        }
        Self::validate_update_commands(&mut tx, &run_id, &task, commands).await?;
        let mut sequence = Self::authored_sequence(&mut tx, &run_id).await?;
        // The tasks this batch's commands create, in order, as PHP reports them.
        let mut created = Vec::new();
        Self::apply_task_signal(&mut tx, &run_id, task_id, &task, transition_codec, false, commands).await?;
        // Published parseCommands applies every other command first, whatever
        // the terminal command's position in the batch.
        let apply = Apply {
            run: &run,
            task: &task,
            task_id,
            run_id: &run_id,
            attempt: &body["workflow_task_attempt"],
            limits: &limits,
            external: &external,
            local: &local,
            exceptions: &exceptions,
        };
        let order = (0..commands.len())
            .filter(|index| !is_terminal(&commands[*index]))
            .chain((0..commands.len()).filter(|index| is_terminal(&commands[*index])));
        for index in order {
            let command = &commands[index];
            if command["type"] == "fail_workflow" {
                // Applied after the loop.
                continue;
            } else if command["type"] == "continue_as_new" {
                sequence += 1;
                if Self::cancellation_request(&run)?.is_none()
                    && Self::deliverable_signal(&mut tx, &run_id, task_payload["workflow_signal_id"].as_str()).await?
                {
                    Self::query("UPDATE workflow_runs SET status='waiting' WHERE id=$1").bind(&run_id).execute(&mut *tx).await?;
                } else {
                    created.push(self.continue_run(&mut tx, &run, &task, command, sequence).await?);
                }
            } else if command["type"] != "complete_workflow" {
                self.apply_non_terminal(&mut tx, &apply, index, command, &mut sequence, &mut created)
                    .await
                    .map_err(|error| super::search_attribute_upserts::with_memo_commands(commands, error))?;
                // The completion leaves the run waiting on what it scheduled
                // or opened. A prefix checkpoint changes no run status:
                // Core's markRunWaiting runs only at completion.
                if matches!(
                    command["type"].as_str(),
                    Some("schedule_activity" | "start_timer" | "open_condition_wait" | "open_signal_wait")
                ) {
                    Self::query("UPDATE workflow_runs SET status='waiting' WHERE id=$1")
                        .bind(&run_id)
                        .execute(&mut *tx)
                        .await?;
                }
            } else {
                if Self::cancellation_request(&run)?.is_some() {
                    Self::finish_root_cancellation(&mut tx, &run, Some(&task)).await?;
                } else if Self::deliverable_signal(&mut tx, &run_id, task_payload["workflow_signal_id"].as_str()).await? {
                    // A signal committed after the worker read history: give
                    // it a turn before the close (Server 2.10.0).
                    Self::query("UPDATE workflow_runs SET status='waiting' WHERE id=$1").bind(&run_id).execute(&mut *tx).await?;
                } else {
                    // Published PHP completes a run whatever is still open. Its
                    // activities, timers and waits stay as they are, and its
                    // open children follow their parent-close policies.
                    let output = match external.get(&index) {
                        Some(prepared) => prepared.stored(),
                        None => envelope(command, "result")?,
                    };
                    let closed_at = now();
                    if let Some(prepared) = external.get(&index) {
                        self.claim_external_in(&mut tx, &prepared.reference, &prepared.uri)
                            .await?;
                    }
                    Self::append(
                        &mut tx,
                        &run_id,
                        "WorkflowCompleted",
                        json!({"output": wire(&output), "payload_codec": "avro", "task":Self::task_snapshot(&task)?}),
                        Some(task_id),
                        None,
                    )
                    .await?;
                    Self::query("UPDATE workflow_runs SET status='completed',closed_reason='completed',output=$1,closed_at=$2 WHERE id=$3")
                    .bind(&output).bind(DB::bind_time(closed_at)).bind(&run_id).execute(&mut *tx).await?;
                    Self::close_pending_updates(&mut tx, &run_id, Some(&task)).await?;
                    Self::enforce_parent_close_policies(&mut tx, &run, "completed", 0).await?;
                    Self::complete_child(&mut tx, &run, &output, closed_at).await?;
                }
            }
        }
        let failed = if let Some(command) = commands
            .iter()
            .find(|command| command["type"] == "fail_workflow")
        {
            Self::apply_workflow_failure(&mut tx, &run, &task, command).await?;
            Self::cancellation_request(&run)?.is_none()
        } else {
            false
        };
        // PHP clears a completed task's lease expiry and keeps its owner.
        Self::query("UPDATE workflow_tasks SET status=$1,lease_expires_at=NULL WHERE id=$2")
            .bind(if failed { "failed" } else { "completed" })
            .bind(task_id)
            .execute(&mut *tx)
            .await?;
        if let Some(successor) = Self::resume_undelivered_cancellation(&mut tx, &run_id, &task, &DB::string(&run, "queue")?).await? {
            created.push(successor);
        }
        // PHP reports the run's next message task with the tasks the
        // commands created.
        // `createForRun($run, workflowSignalIdForTask($task), false)`.
        let attempted = task_payload["workflow_signal_id"].as_str().or_else(|| task_payload["resume_source_id"].as_str())
            .filter(|signal| !signal.is_empty());
        if let Some(next) = Self::next_message_task(&mut tx, &run_id, &DB::string(&run, "queue")?, attempted, false).await? {
            created.push(next);
        }
        Self::record_completion(&mut tx, task_id, &json!({COMPLETION_RECEIPT: {"request": body, "created_task_ids": created}})).await?;
        // PHP's executor leaves every run a completed task did not close
        // waiting, even when the task recorded only markers or side effects.
        Self::query("UPDATE workflow_runs SET status='waiting' WHERE id=$1 AND status IN ('pending','running')")
            .bind(&run_id)
            .execute(&mut *tx)
            .await?;
        let current = Self::query("SELECT status FROM workflow_runs WHERE id=$1")
            .bind(&run_id)
            .fetch_one(&mut *tx)
            .await?;
        let run_status = DB::string(&current, "status")?;
        if let Some(worker) = &worker {
            Self::attribute_worker_terminal(&mut tx, &run_id, task_id, worker).await?;
        }
        // `MessageStreamService::recordCompletion`: only a continued run
        // changes the instance's current run, which then receives the streams.
        if !streams.is_empty() || commands.iter().any(|command| command["type"] == "continue_as_new") {
            self.record_stream_completion(&mut tx, &stream_instance, &run_id, task_id, &streams, transition_codec_for_streams, limits)
                .await?;
        }
        tx.summary_changed(&run_id);
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"task_id": task_id, "run_id": run_id, "run_status": run_status, "workflow_task_attempt": body["workflow_task_attempt"], "outcome": "completed", "recorded": true, "reason": null, "created_task_ids": created}),
        )
    }

    /// One non-terminal command of a workflow task completion or of a
    /// cancellation scope prefix checkpoint (`applyNonTerminalCommand`), at
    /// `sequence`, adding the tasks it creates to `created`.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn apply_non_terminal(
        &self,
        tx: &mut Transaction<'_, DB>,
        apply: &Apply<'_, DB::Row>,
        index: usize,
        command: &Value,
        sequence_ref: &mut i64,
        created: &mut Vec<String>,
    ) -> Result<()> {
        let Apply { run, task, task_id, run_id, attempt, limits, external, local, exceptions } = *apply;
        let mut sequence = *sequence_ref;
        if matches!(
            command["type"].as_str(),
            Some("complete_update" | "fail_update")
        ) {
            // `applyCompleteUpdate` and `applyFailUpdate` record the update at
            // the next sequence and return the one after it, so a later
            // command in the batch takes the following sequence. History
            // does not count update events toward the next turn's sequence.
            sequence += 1;
            Self::apply_update(tx, &run, &task, command, sequence).await?;
        } else if command["type"] == "start_child_workflow" {
            Self::guard_pending(tx, &run_id, super::memo_limits::Pending::Children, &limits, task_id, attempt).await?;
            sequence += 1;
            created.push(Self::start_child(tx, &run, task_id, command, sequence).await?);
        } else if command["type"] == super::local_activities::COMMAND {
            // `applyRecordLocalActivity`: one authored sequence.
            sequence += 1;
            let record = local.get(&index).expect("a normalized local activity record");
            Self::record_local_activity(tx, &run, &task, record, exceptions.get(&index).map(String::as_str), sequence).await?;
        } else if command["type"] == "record_side_effect" {
            // `applyRecordSideEffect`: one authored sequence and its result.
            sequence += 1;
            let result = side_effect_result(command)?;
            let payload = json!({"sequence": sequence, "result": wire(&result), "task": Self::task_snapshot(&task)?});
            Self::append(tx, &run_id, "SideEffectRecorded", payload, Some(task_id), None).await?;
        } else if command["type"] == "record_version_marker" {
            sequence += 1;
            let mut payload = version_marker_payload(command)?;
            payload["sequence"] = json!(sequence);
            payload["task"] = Self::task_snapshot(&task)?;
            Self::append(
                tx,
                &run_id,
                "VersionMarkerRecorded",
                payload,
                Some(task_id),
                None,
            )
            .await?;
        } else if command["type"] == "upsert_memo" {
            sequence += 1;
            self.upsert_memo(tx, &run, &task, command, sequence)
                .await?;
        } else if command["type"] == "upsert_search_attributes" {
            sequence += 1;
            self.upsert_search_attributes(tx, &run, &task, command, index, sequence)
                .await?;
        } else if command["type"] == "schedule_activity" {
            Self::guard_pending(tx, &run_id, super::memo_limits::Pending::Activities, &limits, task_id, attempt).await?;
            sequence += 1;
            let metadata = super::selections::metadata(command, "activity")?;
            super::selections::check_sequence(&metadata, sequence)?;
            let activity_id = id();
            // A session-bound activity without its own queue or
            // connection takes the session's (`applyWorkerSessionRouting`).
            let session = super::worker_sessions::command_session(command);
            let queue = match command.get("queue").filter(|queue| !queue.is_null()) {
                Some(_) => text(command, "queue")?.to_owned(),
                None => session.as_ref().and_then(|session| session.queue.clone()).map_or_else(|| DB::string(&run, "queue"), Ok)?,
            };
            let connection = command["connection"].as_str().map(str::trim).filter(|connection| !connection.is_empty()).map(str::to_owned)
                .or_else(|| session.as_ref().and_then(|session| session.connection.clone()));
            let activity_options = session.as_ref().map(|session| json!({"worker_session": session.snapshot()}));
            let activity_type = text(command, "activity_type")?;
            let arguments = match external.get(&index) {
                Some(prepared) => {
                    self.claim_external_in(tx, &prepared.reference, &prepared.uri)
                        .await?;
                    prepared.stored()
                }
                None => envelope(command, "arguments")?,
            };
            let policy = super::activity_failures::retry_policy(command)?;
            let scheduled_at = now();
            let schedule_deadline = super::activity_deadlines::deadline(
                policy.as_ref().unwrap_or(&Value::Null),
                "schedule_to_start_timeout",
                scheduled_at,
            )?;
            let lifecycle_deadline = super::activity_deadlines::deadline(
                policy.as_ref().unwrap_or(&Value::Null),
                "schedule_to_close_timeout",
                scheduled_at,
            )?;
            // PHP keeps an activity's scope in its options, after the
            // worker session; its snapshot and the scope's members read it
            // from there.
            let mut activity_options = activity_options;
            if let Some(scope) = command.get("cancellation_scope_id") {
                activity_options.get_or_insert_with(|| json!({}))["cancellation_scope_id"] = scope.clone();
            }
            // The preserved SQL schema defaults this counter to one.
            // Published authored scheduling explicitly starts at zero;
            // only the original worker claim consumes its first attempt.
            Self::query("INSERT INTO activity_executions(id,workflow_run_id,sequence,activity_type,activity_class,status,attempt_count,payload_codec,arguments,queue,retry_policy,parallel_group_path,schedule_deadline_at,schedule_to_close_deadline_at,connection,activity_options) VALUES ($1,$2,$3,$4,$5,'pending',0,'avro',$6,$7,$8,$9,$10,$11,$12,$13)")
                .bind(&activity_id).bind(&run_id).bind(sequence).bind(activity_type).bind(activity_type)
                .bind(&arguments).bind(&queue).bind(policy.as_ref().map(DB::document))
                .bind(DB::optional_document(&metadata["parallel_group_path"]))
                .bind(DB::optional_time(schedule_deadline)).bind(DB::optional_time(lifecycle_deadline))
                .bind(connection.as_deref()).bind(activity_options.as_ref().map(DB::document)).execute(&mut **tx).await?;
            let activity = Self::query("SELECT * FROM activity_executions WHERE id=$1")
                .bind(&activity_id)
                .fetch_one(&mut **tx)
                .await?;
            let mut payload = Self::activity_payload(&activity)?;
            payload["task"] = Self::task_snapshot(&task)?;
            let recorded_at = now();
            Self::append_at(
                tx,
                &run_id,
                "ActivityScheduled",
                payload,
                Some(task_id),
                None,
                Some(recorded_at),
            )
            .await?;
            // PHP publishes only timer tasks a completion creates, so this
            // activity task waits for a poller undispatched.
            let activity_task = Self::create_task_dispatched(
                tx,
                &run_id,
                &queue,
                "activity",
                json!({"activity_execution_id": activity_id}),
                false,
            )
            .await?;
            created.push(activity_task.clone());
            Self::create_activity_wait(
                tx,
                &run_id,
                &activity_id,
                &activity_task,
                recorded_at,
            )
            .await?;
        } else if command["type"] == "start_timer" {
            Self::guard_pending(tx, &run_id, super::memo_limits::Pending::Timers, &limits, task_id, attempt).await?;
            sequence += 1;
            let metadata = super::selections::metadata(command, "timer")?;
            super::selections::check_sequence(&metadata, sequence)?;
            let timer_id = id();
            let delay = command["delay_seconds"].as_i64().unwrap();
            let fire_at = timer_deadline(command)?;
            let scheduled_at = now();
            Self::query("INSERT INTO workflow_run_timers(id,workflow_run_id,sequence,status,delay_seconds,fire_at) VALUES ($1,$2,$3,'pending',$4,$5)")
                .bind(&timer_id).bind(&run_id).bind(sequence).bind(delay).bind(DB::bind_time(fire_at)).execute(&mut **tx).await?;
            // PHP records the fire time with `toJSON` (microseconds); a
            // scoped timer's descriptor reads it back exactly.
            let mut timer_payload = json!({"timer_id":timer_id,"sequence":sequence,"delay_seconds":delay,
                "fire_at":super::cancellation_scopes::iso(fire_at),"task":Self::task_snapshot(&task)?});
            if let Some(scope) = command.get("cancellation_scope_id") {
                timer_payload["cancellation_scope_id"] = scope.clone();
            }
            super::selections::merge(&mut timer_payload, &metadata);
            Self::append_at(
                tx,
                &run_id,
                "TimerScheduled",
                timer_payload,
                Some(task_id),
                None,
                Some(scheduled_at),
            )
            .await?;
            let mut timer_task_payload = json!({"timer_id":timer_id});
            if let Some(scope) = command.get("cancellation_scope_id") {
                timer_task_payload["cancellation_scope_id"] = scope.clone();
            }
            super::selections::merge(&mut timer_task_payload, &metadata);
            let timer_task = Self::create_task(
                tx,
                &run_id,
                &DB::string(&run, "queue")?,
                "timer",
                timer_task_payload,
            )
            .await?;
            created.push(timer_task.clone());
            Self::query("UPDATE workflow_tasks SET available_at=$1 WHERE id=$2")
                .bind(DB::bind_time(fire_at))
                .bind(&timer_task)
                .execute(&mut **tx)
                .await?;
            Self::create_timer_wait(
                tx,
                &run_id,
                &timer_id,
                &timer_task,
                sequence,
                scheduled_at,
                fire_at,
            )
            .await?;
        } else if command["type"] == "open_condition_wait"
            || command["type"] == "open_signal_wait"
        {
            // A run may hold any number of open waits
            // (`applyOpenSignalWait`, `applyOpenConditionWait`).
            sequence += 1;
            let direct = command["type"] == "open_signal_wait";
            let mut payload = command.clone();
            payload.as_object_mut().unwrap().remove("type");
            // Match PHP's optional occurrence payload: retain authored
            // nonempty strings and omit null/empty legacy values.
            if !direct
                && payload
                    .get("condition_wait_occurrence_id")
                    .is_some_and(|value| value.is_null() || value.as_str() == Some(""))
            {
                payload
                    .as_object_mut()
                    .unwrap()
                    .remove("condition_wait_occurrence_id");
            }
            // `normalizeOptionalString`: PHP trims the condition's key and
            // definition fingerprint and drops them when empty.
            if !direct {
                for field in ["condition_key", "condition_definition_fingerprint"] {
                    match payload[field].as_str().map(str::trim).filter(|value| !value.is_empty()) {
                        Some(value) => payload[field] = json!(value),
                        None => {
                            payload.as_object_mut().unwrap().remove(field);
                        }
                    }
                }
            }
            let pending_wait_id = if direct {
                Self::pending_signal_wait_id(tx, &run_id, text(command, "signal_name")?).await?
            } else {
                None
            };
            let wait_id = pending_wait_id.clone().unwrap_or_else(id);
            if payload["timeout_seconds"].is_null() {
                payload.as_object_mut().unwrap().remove("timeout_seconds");
            }
            payload[if direct {
                "signal_wait_id"
            } else {
                "condition_wait_id"
            }] = json!(wait_id);
            payload["sequence"] = json!(sequence);
            Self::append(
                tx,
                &run_id,
                if direct {
                    "SignalWaitOpened"
                } else {
                    "ConditionWaitOpened"
                },
                payload.clone(),
                Some(task_id),
                None,
            )
            .await?;
            if (!direct || pending_wait_id.is_none())
                && let Some(timeout_task) = Self::schedule_wait_timeout(tx, &run, &payload, task_id).await?
            {
                created.push(timeout_task);
            }
        } else {
            return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "unsupported_workflow_command"));
        }
        *sequence_ref = sequence;
        Ok(())
    }

    pub(super) async fn upsert_memo(
        &self,
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        task: &DB::Row,
        command: &Value,
        sequence: i64,
    ) -> Result<()> {
        let run_id = DB::string(run, "id")?;
        let worker_id = DB::optional_string(task, "lease_owner")?
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "task_not_leased"))?;
        let worker = Self::query("SELECT capabilities,status FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
            .bind(self.namespace.as_ref()).bind(&worker_id).fetch_optional(&mut **tx).await?;
        let capable = worker
            .as_ref()
            .map(|worker| -> Result<bool> {
                Ok(DB::string(worker, "status")? == "active"
                    && DB::optional_document_row(worker, "capabilities")?.is_some_and(|flags| {
                        flags
                            .as_array()
                            .is_some_and(|flags| flags.iter().any(|flag| flag == "memo_upserts"))
                    }))
            })
            .transpose()?
            .unwrap_or(false);
        if !capable {
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: json!({"reason":"workflow_metadata_capability_not_advertised",
                    "worker_capability":"memo_upserts","worker_id":worker_id,
                    "command_type":"upsert_memo","recorded":false}),
            });
        }
        let rows = Self::query(DB::MEMO_READ)
            .bind(&run_id)
            .fetch_all(&mut **tx)
            .await?;
        let mut stored = Vec::with_capacity(rows.len());
        for row in rows {
            let portable = if DB::optional_number(&row, "portable_value_sequence")?
                == Some(DB::number(&row, "upserted_at_sequence")?)
            {
                DB::optional_document_row(&row, "portable_value")?
                    .filter(|value| value.is_object() || value.is_array())
            } else {
                None
            };
            stored.push((
                DB::string(&row, "key")?,
                DB::document_row(&row, "value")?,
                portable,
            ));
        }
        let task_id = DB::string(task, "id")?;
        let task_attempt = DB::number(task, "attempt_count")?;
        let prepared = super::memos::prepare_update(
            self.memo_codec.clone(),
            stored,
            envelope(command, "entries")?,
            self.memo_size_limit,
        )
        .await
        .map_err(|error| match error {
            super::RuntimeError::Protocol {
                status,
                mut response,
            } if response["reason"] == "structural_limit_exceeded" => {
                response["task_id"] = json!(task_id);
                response["workflow_task_attempt"] = json!(task_attempt);
                super::RuntimeError::Protocol { status, response }
            }
            error => error,
        })?;
        let written_at = now();
        for (key, value) in prepared.changes {
            if let Some(value) = value {
                Self::query(DB::MEMO_UPSERT)
                    .bind(&run_id)
                    .bind(DB::string(run, "workflow_instance_id")?)
                    .bind(key)
                    .bind(DB::document(&value.projection))
                    .bind(sequence)
                    .bind(DB::bind_time(written_at))
                    .bind(DB::bind_time(written_at))
                    .bind(DB::document(&value.envelope))
                    .bind(sequence)
                    .execute(&mut **tx)
                    .await?;
            } else {
                Self::query(DB::MEMO_DELETE)
                    .bind(&run_id)
                    .bind(key)
                    .execute(&mut **tx)
                    .await?;
            }
        }
        Self::append(
            tx,
            &run_id,
            "MemoUpserted",
            json!({"sequence":sequence,
            "entries":prepared.entries,"merged":prepared.merged,"task":Self::task_snapshot(task)?}),
            Some(&DB::string(task, "id")?),
            None,
        )
        .await?;
        Ok(())
    }

    pub(crate) async fn complete_activity(
        &self,
        task_id: &str,
        body: Value,
        external: Option<super::external_resolution::PreparedExternal>,
    ) -> Result<Value> {
        let result = match &external {
            Some(prepared) => prepared.stored(),
            None => envelope(&body, "result")?,
        };
        let attempt_id = text(&body, "activity_attempt_id")?;
        let mut tx = self.begin().await?;
        let task = self.task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "activity" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        Self::cancelled_activity_outcome(&mut tx, &task, &body).await?;
        // A repeat of the result that finished the attempt, identical or not,
        // gets published PHP's stale-attempt receipt below: the attempt is no
        // longer running. The published SDKs read that receipt, with its
        // completed statuses, as their own committed completion.
        let attempt =
            Self::query("SELECT * FROM activity_attempts WHERE id=$1 AND workflow_task_id=$2")
                .bind(attempt_id)
                .bind(task_id)
                .fetch_optional(&mut *tx)
                .await?
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "stale_attempt"))?;
        // PHP judges the attempt before the task's lease: a late result from
        // an attempt that is no longer current is stale even after another
        // attempt finished the task.
        if DB::string(&attempt, "status")? != "running"
            || DB::number(&attempt, "attempt_number")? != DB::number(&task, "attempt_count")?
        {
            // Published PHP's stale completion receipt: nothing is recorded,
            // and the owner of that attempt learns where its work stands.
            let mut receipt = json!({"task_id": task_id, "activity_attempt_id": attempt_id,
                "outcome": "completed", "recorded": false, "reason": "stale_attempt", "next_task_id": null});
            let owner = text(&body, "lease_owner")?;
            if DB::optional_string(&attempt, "lease_owner")?.as_deref() == Some(owner) {
                let activity = Self::query("SELECT status FROM activity_executions WHERE id=$1")
                    .bind(DB::string(&attempt, "activity_execution_id")?)
                    .fetch_optional(&mut *tx)
                    .await?;
                receipt["lease_owner"] = json!(owner);
                receipt["activity_status"] = match activity {
                    Some(activity) => json!(DB::string(&activity, "status")?),
                    None => Value::Null,
                };
                receipt["attempt_status"] = json!(DB::string(&attempt, "status")?);
                receipt["task_status"] = json!(DB::string(&task, "status")?);
            }
            // Every worker answer carries PHP's protocol fields.
            receipt["protocol_version"] = json!(super::WORKER_PROTOCOL_VERSION);
            receipt["server_capabilities"] = capabilities();
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: receipt,
            });
        }
        Self::closed_run_outcome(&mut tx, &task, &body, "completed").await?;
        Self::fence(&task, &body)?;
        let run_id = DB::string(&task, "workflow_run_id")?;
        let run = Self::active_run(&mut tx, &run_id).await?;
        let activity_id = DB::string(&attempt, "activity_execution_id")?;
        let activity =
            Self::query("SELECT * FROM activity_executions WHERE id=$1 AND workflow_run_id=$2")
                .bind(&activity_id)
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
        if DB::optional_string(&activity, "current_attempt_id")?.as_deref() != Some(attempt_id)
            || DB::number(&activity, "attempt_count")? != DB::number(&attempt, "attempt_number")?
            || DB::string(&activity, "status")? != "running"
        {
            return Err(refuse(StatusCode::CONFLICT, "stale_attempt"));
        }
        super::activity_deadlines::fence::<DB>(&activity)?;
        if let Some(prepared) = &external {
            self.claim_external_in(&mut tx, &prepared.reference, &prepared.uri)
                .await?;
        }
        Self::query("UPDATE activity_executions SET status='completed',result=$1,exception=NULL,closed_at=$2 WHERE id=$3")
            .bind(&result).bind(DB::bind_time(now())).bind(&activity_id).execute(&mut *tx).await?;
        let activity = Self::query("SELECT * FROM activity_executions WHERE id=$1")
            .bind(&activity_id)
            .fetch_one(&mut *tx)
            .await?;
        let mut payload = Self::activity_payload(&activity)?;
        let metadata = super::selections::activity_metadata(DB::optional_document_row(
            &activity,
            "parallel_group_path",
        )?)?;
        payload["activity_attempt_id"] = json!(attempt_id);
        payload["attempt_number"] = json!(DB::number(&attempt, "attempt_number")?);
        payload["result"] = wire(&result);
        payload["payload_codec"] = json!("avro");
        payload["task"] = json!({"id":task_id,"type":"activity","status":"leased",
            "repair_count":DB::number(&task,"repair_count")?,
            "available_at":DB::optional_instant(&task,"available_at")?,"leased_at":DB::optional_instant(&task,"leased_at")?,
            "lease_owner":DB::optional_string(&task,"lease_owner")?,"lease_expires_at":DB::optional_instant(&task,"lease_expires_at")?,
            "attempt_count":DB::number(&task,"attempt_count")?,"queue":DB::string(&task,"queue")?,"connection":DB::PHP_CONNECTION});
        Self::append(
            &mut tx,
            &run_id,
            "ActivityCompleted",
            payload,
            Some(task_id),
            None,
        )
        .await?;
        Self::resolve_selection(
            &mut tx,
            &run_id,
            &metadata,
            "activity",
            &activity_id,
            "ActivityCompleted",
        )
        .await?;
        Self::query("UPDATE activity_attempts SET status='completed',lease_expires_at=NULL,closed_at=$1 WHERE id=$2")
            .bind(DB::bind_time(now()))
            .bind(attempt_id)
            .execute(&mut *tx)
            .await?;
        Self::query("UPDATE workflow_tasks SET status='completed',lease_expires_at=NULL WHERE id=$1")
            .bind(task_id)
            .execute(&mut *tx)
            .await?;
        Self::record_completion(&mut tx, task_id, &body).await?;
        // A standalone activity's host run closes with its result.
        let closed = Self::close_standalone_host(&mut tx, &run_id, super::standalone_activities::HostClose::Completed { output: &result }).await?;
        let next_task_id = if closed {
            None
        } else {
            Self::selection_resume(
                &mut tx,
                &run_id,
                &DB::string(&run, "queue")?,
                json!({"activity_execution_id": activity_id}),
                &metadata,
                true,
            )
            .await?
        };
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"task_id": task_id, "activity_attempt_id": attempt_id, "outcome": "completed", "recorded": true, "reason": null, "next_task_id": next_task_id}),
        )
    }
    pub(crate) async fn fire_due_timers(&self) -> Result<()> {
        // Expired root authority wins before any ordinary due timer fires.
        self.expire_cooperative_deadlines().await?;
        self.expire_activity_deadlines().await?;
        // An activity task whose lease expired is settled, not re-claimed.
        self.expire_activity_leases().await?;
        // New timers use the canonical PHP timestamp shape on SQLite and typed
        // UTC values elsewhere. Existing PHP data still cannot be adopted.
        // The read probe avoids acquiring the transition lock when idle.
        // Maintenance visits every namespace; each timer's persisted run
        // supplies its namespace, independently of the store that woke it.
        const DUE: &str = "SELECT t.*,r.queue,r.namespace AS run_namespace FROM workflow_run_timers t JOIN workflow_runs r ON r.id=t.workflow_run_id WHERE r.status IN ('running','waiting') AND t.status='pending' AND t.fire_at<=$1 ORDER BY t.fire_at,t.id LIMIT 50";
        if Self::query(DUE)
            .bind(DB::bind_time(now()))
            .fetch_optional(&self.pool)
            .await?
            .is_none()
        {
            return Ok(());
        }
        let mut tx = self.begin().await?;
        // Re-read under the same durable transition lock as worker completion.
        // Another node may already have fired any candidate from the probe.
        let due = Self::query(DUE)
            .bind(DB::bind_time(now()))
            .fetch_all(&mut *tx)
            .await?;
        for timer in due {
            let fire_at = DB::instant(&timer, "fire_at")?;
            let fired_at = now();
            if fire_at > fired_at {
                continue;
            }
            let run_id = DB::string(&timer, "workflow_run_id")?;
            match Self::active_run(&mut tx, &run_id).await {
                Ok(_) => {}
                Err(super::RuntimeError::Refused {
                    reason: "cancellation_deadline_expired",
                    ..
                }) => continue,
                Err(error) => return Err(error),
            }
            let timer_id = DB::string(&timer, "id")?;
            let task = Self::query(DB::TIMER_TASK_SQL)
                .bind(&run_id)
                .bind(&timer_id)
                .bind(DB::string(&timer, "run_namespace")?)
                .fetch_one(&mut *tx)
                .await?;
            let task_id = DB::string(&task, "id")?;
            let task_payload = DB::document_row(&task, "payload")?;
            let metadata = super::selections::metadata(&task_payload, "timer")?;
            let mut payload = json!({"timer_id":timer_id,"sequence":DB::number(&timer,"sequence")?,"delay_seconds":DB::number(&timer,"delay_seconds")?,"fire_at":super::cancellation_scopes::iso(fire_at),"fired_at":fired_at});
            if task_payload.get("signal_wait_id").is_some() {
                payload["timer_kind"] = json!("signal_timeout");
                payload["signal_wait_id"] = task_payload["signal_wait_id"].clone();
                payload["signal_name"] = task_payload["signal_name"].clone();
            }
            if task_payload.get("condition_wait_id").is_some() {
                payload["timer_kind"] = json!("condition_timeout");
            }
            payload.as_object_mut().unwrap().extend(
                super::wait_timeouts::wait_fields(&task_payload)
                    .as_object()
                    .unwrap()
                    .clone(),
            );
            let resume = if matches!(
                payload["timer_kind"].as_str(),
                Some("signal_timeout" | "condition_timeout")
            ) {
                super::wait_timeouts::resolution(&payload)
            } else {
                // `WorkflowTaskPayload::forTimerResolution` for a plain timer.
                json!({"workflow_wait_kind":"timer","open_wait_id":format!("timer:{timer_id}"),
                    "resume_source_kind":"timer","resume_source_id":timer_id,"timer_id":timer_id,
                    "workflow_sequence":payload["sequence"],"workflow_event_type":"TimerFired"})
            };
            super::selections::merge(&mut payload, &metadata);
            // `RunTimerTask`: the timer task is leased to itself while its
            // timer fires, so `TimerFired` carries the leased task.
            Self::query("UPDATE workflow_tasks SET status='leased',leased_at=$1,lease_owner=$2,lease_expires_at=$3,attempt_count=attempt_count+1 WHERE id=$4")
                .bind(DB::bind_time(fired_at))
                .bind(&task_id)
                .bind(DB::bind_time(fired_at + chrono::Duration::seconds(self.workflow_task_lease_seconds)))
                .bind(&task_id)
                .execute(&mut *tx)
                .await?;
            Self::append(
                &mut tx,
                &run_id,
                "TimerFired",
                payload,
                Some(&task_id),
                None,
            )
            .await?;
            Self::resolve_selection(
                &mut tx,
                &run_id,
                &metadata,
                "timer",
                &timer_id,
                "TimerFired",
            )
            .await?;
            Self::query("UPDATE workflow_run_timers SET status='fired',fired_at=$1 WHERE id=$2")
                .bind(DB::bind_time(fired_at))
                .bind(&timer_id)
                .execute(&mut *tx)
                .await?;
            Self::query("UPDATE workflow_tasks SET status='completed',lease_expires_at=NULL WHERE id=$1")
                .bind(&task_id)
                .execute(&mut *tx)
                .await?;
            Self::selection_resume(
                &mut tx,
                &run_id,
                &DB::string(&timer, "queue")?,
                resume,
                &metadata,
                true,
            )
            .await?;
        }
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(())
    }
    /// Whether the namespace holds the task: PHP's ownership guard looks it
    /// up before the command normalizer reads any payload.
    pub(super) async fn task_held(&self, task_id: &str) -> Result<bool> {
        Ok(Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE id=$1 AND namespace=$2")
            .bind(task_id)
            .bind(self.namespace.as_ref())
            .fetch_one(&self.pool)
            .await?
            > 0)
    }

    pub(super) async fn task_row(
        &self,
        tx: &mut Transaction<'_, DB>,
        task_id: &str,
    ) -> Result<DB::Row> {
        Self::query("SELECT t.*,c.receipt FROM workflow_tasks t LEFT JOIN dw_task_completions c ON c.task_id=t.id WHERE t.id=$1 AND t.namespace=$2")
        .bind(task_id)
        .bind(self.namespace.as_ref())
        .fetch_optional(&mut **tx)
        .await?
        .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "task_not_found"))
    }

    pub(super) async fn record_completion(
        tx: &mut Transaction<'_, DB>,
        task_id: &str,
        body: &Value,
    ) -> Result<()> {
        Self::query("INSERT INTO dw_task_completions(task_id,receipt) VALUES ($1,$2)")
            .bind(task_id)
            .bind(DB::document(body))
            .execute(&mut **tx)
            .await?;
        Ok(())
    }

    pub(super) fn duplicate_receipt(task: &DB::Row, body: &Value) -> Result<bool> {
        let status = DB::string(task, "status")?;
        if status != "completed" && status != "failed" {
            return Ok(false);
        }
        // Retryable task failures have no terminal completion receipt.
        if status == "failed" && DB::optional_document_row(task, "receipt")?.is_none() {
            return Ok(false);
        }
        // A waiting acknowledgement completes the lease without a command
        // receipt. A later completion must refuse it, not decode SQL NULL.
        let receipt = DB::optional_document_row(task, "receipt")?
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "task_not_leased"))?;
        let receipt = match receipt.get(COMPLETION_RECEIPT) {
            Some(stored) => stored["request"].clone(),
            None => receipt,
        };
        if receipt == *body {
            return Ok(true);
        }
        // Any other result for a closed activity task comes from an attempt
        // that is no longer current; the caller reports it as stale, as PHP
        // does, rather than as a conflicting duplicate.
        if DB::string(task, "task_type")? == "activity" {
            return Ok(false);
        }
        Err(refuse(StatusCode::CONFLICT, "conflicting_completion"))
    }

    /// PHP's workflow task ownership guard, in PHP's order: a task that is
    /// no longer leased first (run_closed when its run has closed), then the
    /// lease's expiry, owner and attempt, and finally the owner's
    /// registration: a claim whose owner is missing or no longer fresh
    /// (`WorkerPollFence::isFresh`) is refused `stale_worker_registration`.
    pub(super) async fn workflow_task_guard(
        tx: &mut Transaction<'_, DB>,
        task: &DB::Row,
        body: &Value,
        stale_after_seconds: i64,
    ) -> Result<()> {
        use super::query_authority::wire;
        let lease_owner = text(body, "lease_owner")?;
        let task_id = DB::string(task, "id")?;
        let attempt = body["workflow_task_attempt"]
            .as_i64()
            .map_or(Value::Null, Value::from);
        let refused = |error: &str, reason: &str, fields: Value| {
            super::workflow_task_guard::refusal(&task_id, &attempt, error, reason, fields)
        };
        let status = DB::string(task, "status")?;
        let owner = DB::optional_string(task, "lease_owner")?
            .map(|owner| owner.trim().to_owned())
            .filter(|owner| !owner.is_empty());
        let expires = DB::optional_instant(task, "lease_expires_at")?;
        if status != "leased" {
            let run =
                Self::query("SELECT status,closed_reason,closed_at FROM workflow_runs WHERE id=$1")
                    .bind(DB::string(task, "workflow_run_id")?)
                    .fetch_one(&mut **tx)
                    .await?;
            let run_status = DB::string(&run, "status")?;
            if super::run_observation_store::terminal(&run_status) {
                let stop_reason = match run_status.as_str() {
                    "cancelled" => "run_cancelled",
                    "terminated" => "run_terminated",
                    "completed" => "run_completed",
                    _ => "run_failed",
                };
                return Err(refused(
                    "Workflow run is already closed.",
                    "run_closed",
                    json!({"stop_reason":stop_reason,"cancel_requested":matches!(run_status.as_str(),"cancelled"|"terminated"),
                        "can_continue":false,"run_status":run_status,
                        "run_closed_reason":DB::optional_string(&run,"closed_reason")?,
                        "run_closed_at":DB::optional_instant(&run,"closed_at")?.map(wire),
                        "task_status":status,"lease_owner":owner,"lease_expires_at":expires.map(wire)}),
                ));
            }
        }
        let (true, Some(owner), Some(expires)) = (status == "leased", owner, expires) else {
            return Err(refused(
                "Workflow task is not currently leased.",
                "task_not_leased",
                json!({"task_status":status}),
            ));
        };
        // An expired lease is claimed again by the next poll.
        if expires <= now() {
            return Err(refused(
                "Workflow task lease has expired and is waiting for recovery.",
                "lease_expired",
                json!({"task_status":"leased","lease_owner":owner,"lease_expires_at":wire(expires)}),
            ));
        }
        if lease_owner != owner {
            return Err(refused(
                "Workflow task lease is owned by another worker.",
                "lease_owner_mismatch",
                json!({"lease_owner":owner}),
            ));
        }
        let current = DB::number(task, "attempt_count")?;
        if current > 0 && attempt.as_i64() != Some(current) {
            return Err(refused(
                "Workflow task lease attempt does not match the current claim.",
                "workflow_task_attempt_mismatch",
                json!({"current_attempt":current}),
            ));
        }
        if !Self::lease_owner_fresh(tx, &DB::string(task, "namespace")?, lease_owner, stale_after_seconds).await? {
            return Err(refused(
                "Workflow task lease owner is no longer an active worker.",
                "stale_worker_registration",
                json!({"task_status":"leased","lease_owner":lease_owner,"lease_expires_at":wire(expires)}),
            ));
        }
        Ok(())
    }

    /// `WorkflowTaskPoller::recoverUnavailableLeases`, for a workflow probe
    /// that claimed nothing. Native's candidates already lease expired
    /// leases again (`recoverExpiredTaskLease` fences no one), so what remains
    /// is an abandoned lease: unexpired, on the polled queue, held by an
    /// owner whose registration is missing or no longer fresh. Up to the scan
    /// limit (DW_EXPIRED_WORKFLOW_TASK_RECOVERY_SCAN_LIMIT), earliest lease
    /// first, each is recovered as `recoverAbandonedTaskLease` recovers it:
    /// checked again under the transition lock, its owner fenced as
    /// superseded and the task repaired (`expired_workflow_task_lease`), ready
    /// for the next claim. Whether anything was recovered.
    async fn recover_abandoned_workflow_leases(&self, queue: &str) -> Result<bool> {
        // One read outside the transition lock: the queue's lease owners
        // with their registrations; an owner without one joins nulls.
        let owners = Self::query("SELECT DISTINCT t.lease_owner,w.status,w.last_heartbeat_at FROM workflow_tasks t LEFT JOIN workflow_worker_registrations w ON w.namespace=t.namespace AND w.worker_id=t.lease_owner WHERE t.namespace=$1 AND t.queue=$2 AND t.task_type='workflow' AND t.status='leased' AND t.lease_owner IS NOT NULL")
            .bind(self.namespace.as_ref())
            .bind(queue)
            .fetch_all(&self.pool)
            .await?;
        let mut stale = Vec::new();
        for row in &owners {
            let owner = DB::string(row, "lease_owner")?;
            if !owner.trim().is_empty() && !super::worker_poll_fence::owner_fresh::<DB>(Some(row), self.worker_stale_after_seconds)? {
                stale.push(owner);
            }
        }
        if stale.is_empty() {
            return Ok(false);
        }
        let limit = self.workflow_task_recovery_scan_limit.max(1);
        let at = now();
        let mut candidates = Vec::new();
        for owner in &stale {
            let rows = Self::query("SELECT id,lease_owner,attempt_count,lease_expires_at FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type='workflow' AND status='leased' AND lease_owner=$3 ORDER BY lease_expires_at,id LIMIT $4")
                .bind(self.namespace.as_ref())
                .bind(queue)
                .bind(owner.as_str())
                .bind(limit)
                .fetch_all(&self.pool)
                .await?;
            for row in rows {
                if let Some(expires) = DB::optional_instant(&row, "lease_expires_at")?.filter(|expires| *expires > at) {
                    candidates.push((expires, DB::string(&row, "id")?, owner.clone(), DB::number(&row, "attempt_count")?));
                }
            }
        }
        candidates.sort();
        candidates.truncate(usize::try_from(limit).unwrap_or(usize::MAX));
        let mut recovered = false;
        for (_, id, owner, attempt) in candidates {
            let mut tx = self.begin().await?;
            let Some(task) = Self::query("SELECT * FROM workflow_tasks WHERE id=$1 AND namespace=$2")
                .bind(&id).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?
            else {
                tx.rollback().await?;
                continue;
            };
            // The lease attempt is the hand-off boundary: a completion that
            // committed first, a renewal, or an owner that reported again
            // leaves the task alone.
            let unchanged = DB::string(&task, "task_type")? == "workflow"
                && DB::string(&task, "status")? == "leased"
                && DB::optional_string(&task, "lease_owner")?.as_deref() == Some(owner.as_str())
                && DB::number(&task, "attempt_count")? == attempt
                && DB::optional_instant(&task, "lease_expires_at")?.is_some_and(|expires| expires > now());
            if !unchanged || Self::lease_owner_fresh(&mut tx, self.namespace.as_ref(), &owner, self.worker_stale_after_seconds).await? {
                tx.rollback().await?;
                continue;
            }
            Self::query("UPDATE workflow_worker_registrations SET status='superseded' WHERE namespace=$1 AND worker_id=$2")
                .bind(self.namespace.as_ref())
                .bind(owner.as_str())
                .execute(&mut *tx)
                .await?;
            self.repair_abandoned_task(&mut tx, &task, self.repair_codec.clone()).await?;
            tx.commit().await?;
            recovered = true;
        }
        Ok(recovered)
    }

    pub(super) fn fence(task: &DB::Row, body: &Value) -> Result<()> {
        if DB::string(task, "status")? != "leased" {
            return Err(refuse(StatusCode::CONFLICT, "task_not_leased"));
        }
        if DB::optional_string(task, "lease_owner")?.as_deref() != Some(text(body, "lease_owner")?)
        {
            return Err(refuse(StatusCode::CONFLICT, "lease_owner_mismatch"));
        }
        if DB::string(task, "task_type")? == "workflow"
            && body["workflow_task_attempt"].as_i64() != Some(DB::number(task, "attempt_count")?)
        {
            return Err(refuse(
                StatusCode::CONFLICT,
                "workflow_task_attempt_mismatch",
            ));
        }
        if DB::optional_instant(task, "lease_expires_at")?.is_none_or(|expires| expires <= now()) {
            return Err(refuse(StatusCode::CONFLICT, "lease_expired"));
        }
        Ok(())
    }

    /// An activity result for a run that completed or failed while the
    /// activity was open: PHP's `run_closed` outcome, recorded nowhere.
    /// Cancelled and terminated runs keep their own receipts.
    pub(super) async fn closed_run_outcome(
        tx: &mut Transaction<'_, DB>,
        task: &DB::Row,
        body: &Value,
        outcome: &str,
    ) -> Result<()> {
        if DB::string(task, "status")? != "leased"
            || DB::optional_string(task, "lease_owner")?.as_deref() != Some(text(body, "lease_owner")?)
        {
            return Ok(());
        }
        let run = Self::query("SELECT status FROM workflow_runs WHERE id=$1")
            .bind(DB::string(task, "workflow_run_id")?)
            .fetch_one(&mut **tx)
            .await?;
        if !matches!(DB::string(&run, "status")?.as_str(), "completed" | "failed") {
            return Ok(());
        }
        Err(super::RuntimeError::Protocol {
            status: StatusCode::CONFLICT,
            response: json!({"task_id": DB::string(task, "id")?, "activity_attempt_id": text(body, "activity_attempt_id")?,
                "outcome": outcome, "recorded": false, "reason": "run_closed", "next_task_id": null}),
        })
    }

    pub(super) async fn active_run(tx: &mut Transaction<'_, DB>, run_id: &str) -> Result<DB::Row> {
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut **tx)
            .await?;
        if !matches!(
            DB::string(&run, "status")?.as_str(),
            "pending" | "running" | "waiting"
        ) {
            return Err(refuse(StatusCode::CONFLICT, "run_closed"));
        }
        if DB::optional_string(&run, "cancellation_request_command_id")?.is_some()
            && DB::optional_instant(&run, "cancellation_deadline_at")?
                .is_none_or(|deadline| deadline <= now())
        {
            return Err(refuse(
                StatusCode::CONFLICT,
                "cancellation_deadline_expired",
            ));
        }
        Ok(run)
    }

    pub(super) async fn create_task(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        queue: &str,
        kind: &str,
        payload: Value,
    ) -> Result<String> {
        Self::create_task_dispatched(tx, run_id, queue, kind, payload, true).await
    }

    /// A new ready task. As PHP in poll mode, a task it dispatches is stamped
    /// dispatched when created; one it leaves to pollers is not.
    pub(super) async fn create_task_dispatched(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        queue: &str,
        kind: &str,
        payload: Value,
        dispatched: bool,
    ) -> Result<String> {
        tx.summary_changed(run_id);
        let task_id = id();
        // A task carries its run's priority and fairness tags, as in PHP.
        let run = Self::query("SELECT namespace,priority,fairness_key,fairness_weight,compatibility FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut **tx)
            .await?;
        let namespace = DB::string(&run, "namespace")?;
        let created = now();
        let dispatched_at = dispatched.then_some(created);
        let fairness_key = DB::optional_string(&run, "fairness_key")?;
        Self::query("INSERT INTO workflow_tasks(id,workflow_run_id,task_type,status,payload,queue,available_at,namespace,created_at,updated_at,last_dispatched_at,last_dispatch_attempt_at,priority,fairness_key,fairness_weight,compatibility) VALUES ($1,$2,$3,'ready',$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15)")
        .bind(&task_id).bind(run_id).bind(kind).bind(DB::document(&payload)).bind(queue).bind(DB::bind_time(created)).bind(&namespace)
        .bind(DB::bind_time(created)).bind(DB::bind_time(created)).bind(DB::optional_time(dispatched_at)).bind(DB::optional_time(dispatched_at))
        .bind(DB::number(&run, "priority")?).bind(fairness_key.as_deref()).bind(DB::number(&run, "fairness_weight")?)
        .bind(DB::optional_string(&run, "compatibility")?.as_deref())
        .execute(&mut **tx).await?;
        Ok(task_id)
    }

    pub(super) async fn append(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        event_type: &str,
        payload: Value,
        task_id: Option<&str>,
        command_id: Option<&str>,
    ) -> Result<()> {
        Self::append_at(tx, run_id, event_type, payload, task_id, command_id, None).await
    }

    pub(super) async fn append_at(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        event_type: &str,
        payload: Value,
        task_id: Option<&str>,
        command_id: Option<&str>,
        recorded_at: Option<DateTime<Utc>>,
    ) -> Result<()> {
        Self::record(tx, run_id, event_type, payload, task_id, command_id, recorded_at, true).await.map(|_| ())
    }

    /// Records an event and returns its id. A command id names the command
    /// without copying its snapshot, as PHP does for a bare id string.
    pub(super) async fn append_returning(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        event_type: &str,
        payload: Value,
        task_id: Option<&str>,
        command_id: Option<&str>,
    ) -> Result<String> {
        Self::record(tx, run_id, event_type, payload, task_id, command_id, None, false).await
    }

    /// Records an event linked to a command by id only, as PHP does when it
    /// passes a command id string rather than the command model: the event
    /// carries no command snapshot.
    pub(super) async fn append_by_command_id(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        event_type: &str,
        payload: Value,
        task_id: Option<&str>,
        command_id: &str,
    ) -> Result<()> {
        Self::record(tx, run_id, event_type, payload, task_id, Some(command_id), None, false).await.map(|_| ())
    }

    #[allow(clippy::too_many_arguments)]
    async fn record(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        event_type: &str,
        payload: Value,
        task_id: Option<&str>,
        command_id: Option<&str>,
        recorded_at: Option<DateTime<Utc>>,
        command_snapshot: bool,
    ) -> Result<String> {
        // The backend transition lock protects this read and write together.
        // MySQL has no UPDATE RETURNING; all adapters use this same allocation.
        let row = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut **tx)
            .await?;
        let sequence = DB::number(&row, "last_history_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "history_sequence_exhausted"))?;
        Self::query("UPDATE workflow_runs SET last_history_sequence=$1 WHERE id=$2")
            .bind(sequence)
            .bind(run_id)
            .execute(&mut **tx)
            .await?;
        let recorded_at = recorded_at.unwrap_or_else(now);
        let mut payload = payload;
        // `WorkflowHistoryEvent::record`: an event PHP records with its task
        // model carries that task's snapshot.
        if let Some(task_id) = task_id
            && TASK_SNAPSHOT_EVENTS.contains(&event_type)
            && payload.as_object().is_some_and(|fields| !fields.contains_key("task"))
            && let Some(task) = Self::query("SELECT * FROM workflow_tasks WHERE id=$1 AND workflow_run_id=$2")
                .bind(task_id)
                .bind(run_id)
                .fetch_optional(&mut **tx)
                .await?
        {
            // Only the run's own task: another run's is never copied.
            payload["task"] = Self::task_snapshot(&task)?;
        }
        // An event recorded with a command carries that command's snapshot.
        if let Some(command_id) = command_id.filter(|_| command_snapshot)
            && payload.as_object().is_some_and(|fields| !fields.contains_key("command"))
            && let Some(snapshot) = Self::command_snapshot(tx, command_id).await?
        {
            payload["command"] = snapshot;
        }
        // A metered completion (Workflow #930) charges the event as stored.
        tx.charge_event(event_type, &payload)?;
        let event_id = id();
        Self::query("INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,workflow_task_id,workflow_command_id,recorded_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8)")
        .bind(&event_id).bind(run_id).bind(sequence).bind(event_type).bind(DB::document(&payload)).bind(task_id).bind(command_id).bind(DB::bind_time(recorded_at)).execute(&mut **tx).await?;
        Self::project_activity_wait_event(tx, run_id, event_type, &payload, recorded_at).await?;
        Self::project_timer_wait_event(tx, run_id, event_type, &payload, recorded_at).await?;
        Self::project_signal_wait_event(tx, run_id, event_type, &payload, recorded_at).await?;
        Self::project_condition_wait_event(tx, run_id, event_type, &payload, recorded_at).await?;
        Self::project_child_wait_event(tx, run_id, event_type, &payload, recorded_at).await?;
        Self::project_update_wait_event(tx, run_id, event_type, &payload, recorded_at).await?;
        if let Some(reason) = closed_reason(event_type) {
            let task = payload.get("task").cloned();
            Box::pin(Self::reject_received_signals(tx, run_id, reason, task_id, task)).await?;
        }
        tx.summary_changed(run_id);
        Ok(event_id)
    }

    /// `PendingSignalCloser::closeForTerminalEvent`: once a run closes, each
    /// signal it received but never applied is rejected, in arrival order.
    /// The original command keeps its acceptance; `SignalRejected` records
    /// the later disposition. PHP records it with the signal's command id,
    /// so it has no command snapshot, and with the terminal event's task, so
    /// it repeats that event's task snapshot.
    async fn reject_received_signals(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        terminal_reason: &str,
        task_id: Option<&str>,
        task: Option<Value>,
    ) -> Result<()> {
        let signals = Self::query("SELECT id,workflow_command_id,signal_name,signal_wait_id,command_sequence FROM workflow_signal_records WHERE workflow_run_id=$1 AND status='received' ORDER BY CASE WHEN command_sequence IS NULL THEN 1 ELSE 0 END,command_sequence,received_at,id")
            .bind(run_id).fetch_all(&mut **tx).await?;
        for signal in signals {
            let (signal_id, command_id) = (DB::string(&signal, "id")?, DB::string(&signal, "workflow_command_id")?);
            let closed_at = now();
            Self::query("UPDATE workflow_signal_records SET status='rejected',outcome='rejected_not_active',rejection_reason='run_closed_before_signal_applied',rejected_at=$1,closed_at=$2 WHERE id=$3")
                .bind(DB::bind_time(closed_at)).bind(DB::bind_time(closed_at)).bind(&signal_id).execute(&mut **tx).await?;
            let mut payload = json!({"workflow_command_id": command_id, "signal_id": signal_id,
                "signal_name": DB::string(&signal, "signal_name")?,
                "signal_wait_id": DB::optional_string(&signal, "signal_wait_id")?,
                "command_sequence": DB::optional_number(&signal, "command_sequence")?,
                "rejection_reason": "run_closed_before_signal_applied", "terminal_reason": terminal_reason});
            if let Some(task) = &task {
                payload["task"] = task.clone();
            }
            Self::append_by_command_id(tx, run_id, "SignalRejected", payload, task_id, &command_id).await?;
        }
        Ok(())
    }

    /// `WorkerTerminalEventAttribution::record`: the completion or failure a
    /// workflow task recorded names the worker that reported it, in the same
    /// transaction.
    async fn attribute_worker_terminal(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        task_id: &str,
        worker: &super::runtime_credentials::Principal,
    ) -> Result<()> {
        let Some(event) = Self::query("SELECT id,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND workflow_task_id=$2 AND event_type IN ('WorkflowCompleted','WorkflowFailed') ORDER BY sequence DESC LIMIT 1")
            .bind(run_id)
            .bind(task_id)
            .fetch_optional(&mut **tx)
            .await?
        else {
            return Ok(());
        };
        let mut payload = DB::document_row(&event, "payload")?;
        let recorded = super::history_meter::php_json_len(&payload);
        super::command_context::worker_terminal(&mut payload, &DB::string(&event, "event_type")?, worker);
        // The event was charged as recorded; the attribution's growth is
        // charged before the update (Workflow #930).
        tx.charge_bytes(super::history_meter::php_json_len(&payload).saturating_sub(recorded))?;
        // The only in-place history change: a cached read would miss it.
        tx.scope_history = None;
        Self::query("UPDATE workflow_history_events SET payload=$1 WHERE id=$2")
            .bind(DB::document(&payload))
            .bind(DB::string(&event, "id")?)
            .execute(&mut **tx)
            .await?;
        Ok(())
    }

    /// `WorkflowHistoryEvent::commandSnapshot` of a stored command. A
    /// signal's or update's name comes from its record, as PHP reads it from
    /// the command payload.
    pub(super) async fn command_snapshot(tx: &mut Transaction<'_, DB>, command_id: &str) -> Result<Option<Value>> {
        let Some(row) = Self::query("SELECT * FROM workflow_commands WHERE id=$1")
            .bind(command_id)
            .fetch_optional(&mut **tx)
            .await?
        else {
            return Ok(None);
        };
        let command_type = DB::optional_string(&row, "command_type")?;
        let target_name = match command_type.as_deref() {
            Some("signal") => Self::query("SELECT signal_name FROM workflow_signal_records WHERE workflow_command_id=$1 AND workflow_instance_id=$2")
                .bind(command_id).bind(DB::string(&row, "workflow_instance_id")?).fetch_optional(&mut **tx).await?
                .map(|record| DB::optional_string(&record, "signal_name")).transpose()?.flatten(),
            Some("update") => Self::query("SELECT update_name FROM workflow_updates WHERE workflow_command_id=$1 AND workflow_instance_id=$2")
                .bind(command_id).bind(DB::string(&row, "workflow_instance_id")?).fetch_optional(&mut **tx).await?
                .map(|record| DB::optional_string(&record, "update_name")).transpose()?.flatten(),
            _ => None,
        };
        let time = |field: &str| -> Result<Option<String>> {
            Ok(DB::optional_instant(&row, field)?.map(|at| at.format("%Y-%m-%dT%H:%M:%S%.6fZ").to_string()))
        };
        Ok(Some(super::command_context::snapshot(super::command_context::CommandColumns {
            id: command_id.to_owned(),
            sequence: DB::optional_number(&row, "command_sequence")?,
            message_sequence: DB::optional_number(&row, "message_sequence")?,
            command_type,
            target_scope: DB::optional_string(&row, "target_scope")?,
            requested_run_id: DB::optional_string(&row, "requested_workflow_run_id")?,
            resolved_run_id: DB::optional_string(&row, "resolved_workflow_run_id")?,
            target_name,
            payload_codec: DB::optional_string(&row, "payload_codec")?,
            payload: DB::optional_string(&row, "payload")?,
            source: DB::optional_string(&row, "source")?,
            context: DB::optional_document_row(&row, "context")?.unwrap_or(Value::Null),
            request_id: DB::optional_string(&row, "request_id")?,
            status: DB::optional_string(&row, "status")?,
            outcome: DB::optional_string(&row, "outcome")?,
            rejection_reason: DB::optional_string(&row, "rejection_reason")?,
            accepted_at: time("accepted_at")?,
            applied_at: time("applied_at")?,
            rejected_at: time("rejected_at")?,
        })))
    }

    pub(super) fn activity_payload(activity: &DB::Row) -> Result<Value> {
        let mut payload = json!({"activity_execution_id": DB::string(activity,"id")?, "activity_type": DB::string(activity,"activity_type")?,
        "activity_class": DB::string(activity,"activity_class")?, "sequence": DB::number(activity,"sequence")?,
        "activity": {"id":DB::string(activity,"id")?,"idempotency_key":DB::string(activity,"id")?,
            "sequence":DB::number(activity,"sequence")?,"type":DB::string(activity,"activity_type")?,
            "class":DB::string(activity,"activity_class")?,"local_activity":false,
            "activity_type": DB::string(activity,"activity_type")?, "arguments": DB::optional_string(activity,"arguments")?.map(|arguments| wire(&arguments)),
            "retry_policy":DB::optional_document_row(activity,"retry_policy")?,
            "status":DB::string(activity,"status")?,"attempt_count":DB::number(activity,"attempt_count")?,
            "closed_at":DB::optional_instant(activity,"closed_at")?,
            "attempt_id":DB::optional_string(activity,"current_attempt_id")?,
            "exception":DB::optional_string(activity,"exception")?.map(|value|wire(&value)),
            "payload_codec": "avro", "queue": DB::string(activity,"queue")?}});
        let metadata = super::selections::activity_metadata(DB::optional_document_row(
            activity,
            "parallel_group_path",
        )?)?;
        super::selections::merge(&mut payload, &metadata);
        // `ActivitySnapshot::fromExecution` names the options' cancellation
        // policy and scope when set.
        if let Some(options) = DB::optional_document_row(activity, "activity_options")? {
            for field in ["cancellation_policy", "cancellation_scope_id"] {
                if let Some(value) = options[field].as_str() {
                    payload["activity"][field] = json!(value);
                }
            }
        }
        // Released history readers retain the first claim and heartbeat across
        // a retry even while the next attempt has not produced new progress.
        for field in ["started_at", "last_heartbeat_at"] {
            if let Some(value) = DB::optional_instant(activity, field)? {
                payload["activity"][field] = json!(value);
            }
        }
        if payload["activity"]["arguments"].is_null() {
            payload["activity"].as_object_mut().unwrap().remove("arguments");
        }
        if payload["activity"]["retry_policy"].is_null() {
            // A legacy absent snapshot stays absent in its durable history.
            // The activity claim may still advertise a nullable policy.
            payload["activity"]
                .as_object_mut()
                .unwrap()
                .remove("retry_policy");
        }
        Ok(payload)
    }

    fn event(row: DB::Row) -> Result<Value> {
        let event_type = DB::string(&row, "event_type")?;
        let payload =
            super::history_presentation::payload(DB::document_row(&row, "payload")?, &event_type);
        Ok(
            json!({"id": DB::string(&row,"id")?, "sequence": DB::number(&row,"sequence")?, "event_type": DB::string(&row,"event_type")?,
        "payload": payload,
        // A Waterline v1 projection may hold untimed events; PHP reads them as null.
        "recorded_at": DB::optional_instant(&row,"recorded_at")?.map(|at| at.to_rfc3339_opts(chrono::SecondsFormat::Micros, true)),
        "workflow_task_id": DB::optional_string(&row,"workflow_task_id")?, "workflow_command_id": DB::optional_string(&row,"workflow_command_id")?}),
        )
    }

    async fn history_page(
        pool: &Pool<DB>,
        run_id: &str,
        after_sequence: i64,
        page_size: i64,
    ) -> Result<(Vec<Value>, Option<String>)> {
        Self::history_page_connection(
            &mut *pool.acquire().await?,
            run_id,
            after_sequence,
            page_size,
        )
        .await
    }

    /// A poll's whole history, read in bounded pages: published PHP sends
    /// every event unless the worker asks for pages.
    /// `WorkflowTaskPoller::historyEventsWithSignalArgumentEnvelopes`: the
    /// history a worker reads shows a consumed signal's workflow sequence on
    /// its `SignalReceived`, from the signal's record. The run's own history
    /// route shows the events as recorded.
    async fn with_signal_sequences(connection: &mut DB::Connection, run_id: &str, events: &mut [Value]) -> Result<()> {
        if !events.iter().any(|event| event["event_type"] == "SignalReceived") {
            return Ok(());
        }
        // Only this page's signals, as PHP's `whereIn('id', $signalIds)` does:
        // the work stays bounded by the page, not by the run's signal count,
        // and the run fence keeps another run's record out.
        let ids = page_signal_ids(events);
        let mut sequences = std::collections::HashMap::new();
        for id in ids {
            if let Some(row) = Self::query("SELECT workflow_sequence FROM workflow_signal_records WHERE id=$1 AND workflow_run_id=$2 AND workflow_sequence IS NOT NULL")
                .bind(id.as_str())
                .bind(run_id)
                .fetch_optional(&mut *connection)
                .await?
            {
                sequences.insert(id, DB::number(&row, "workflow_sequence")?);
            }
        }
        for event in events.iter_mut().filter(|event| event["event_type"] == "SignalReceived") {
            let sequence = event["payload"]["signal_id"].as_str().and_then(|id| sequences.get(id)).copied();
            if let (Some(sequence), Some(payload)) = (sequence, event["payload"].as_object_mut()) {
                if payload.get("workflow_sequence").is_none_or(Value::is_null) {
                    payload.insert("workflow_sequence".into(), json!(sequence));
                }
            }
        }
        Ok(())
    }

    async fn whole_history(connection: &mut DB::Connection, run_id: &str) -> Result<Vec<Value>> {
        let mut events = Vec::new();
        let mut after = 0;
        loop {
            let (page, next) = Self::history_page_connection(&mut *connection, run_id, after, 1000).await?;
            if let Some(last) = page.last() {
                after = last["sequence"].as_i64().unwrap_or(after);
            }
            events.extend(page);
            if next.is_none() {
                return Ok(events);
            }
        }
    }

    pub(super) async fn history_page_connection(
        connection: &mut DB::Connection,
        run_id: &str,
        after_sequence: i64,
        page_size: i64,
    ) -> Result<(Vec<Value>, Option<String>)> {
        use base64::{Engine, engine::general_purpose::STANDARD};
        // Bound serialized history bytes before hydrating JSON. SQLite executes
        // on SQLx's connection worker, outside Tokio request executor threads.
        let rows = Self::query(DB::HISTORY_PAGE)
            .bind(run_id)
            .bind(after_sequence)
            .bind(page_size)
            .fetch_all(&mut *connection)
            .await?;
        let row = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(connection)
            .await?;
        let total = DB::number(&row, "last_history_sequence")?;
        let last = match rows.last() {
            Some(row) => DB::number(row, "sequence")?,
            None => after_sequence,
        };
        if rows.is_empty() && after_sequence < total {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "history_event_exceeds_page_budget",
            ));
        }
        let next = (last < total).then(|| STANDARD.encode(last.to_string()));
        Ok((
            rows.into_iter().map(Self::event).collect::<Result<_>>()?,
            next,
        ))
    }
}

/// A start's optional `nullable|integer|min:1` timeout. Absent or null means
/// no timeout, as in published PHP.
/// `request` arguments come from a control-plane `input`, which PHP refuses
/// as validation of `input.blob`; stored arguments keep `invalid`.
pub(super) async fn prepare_control_arguments(
    semaphore: Arc<Semaphore>,
    name: String,
    arguments: String,
    invalid: &'static str,
    request: bool,
) -> Result<PreparedControlArguments> {
    let permit = semaphore
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "signal_codec_unavailable"))?;
    // A cancelled request cannot release this permit while its synchronous
    // official codec job is still running. Each runtime permits one such job.
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        let codec = crate::codec::ValueCodec::new()
            .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid))?;
        // PHP's `resolveToArray`: the blob decodes to a list, or to a
        // non-empty map whose values are the arguments.
        let (values, arguments) = match codec.decode(&arguments) {
            Ok(crate::codec::Value::Array(values)) => (values, arguments),
            Ok(crate::codec::Value::Map(entries)) if request && !entries.is_empty() => {
                let values: Vec<_> = entries.into_values().collect();
                let encoded = codec
                    .encode(&crate::codec::Value::Array(values.clone()))
                    .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid))?;
                (values, encoded)
            }
            Ok(_) if request => return Err(super::input_envelope::not_a_list()),
            Err(error) if request => return Err(super::input_envelope::undecodable(&arguments, &error)),
            _ => return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid)),
        };
        // `signalValueFromRecord`: no arguments apply as true, one as itself.
        let value = match values.as_slice() {
            [] => crate::codec::Value::Boolean(true),
            [value] => value.clone(),
            _ => crate::codec::Value::Array(values.clone()),
        };
        let command = crate::codec::Value::Map(std::collections::BTreeMap::from([
            ("name".into(), crate::codec::Value::String(name)),
            (
                "arguments".into(),
                crate::codec::Value::Array(values.clone()),
            ),
            (
                "validation_errors".into(),
                crate::codec::Value::Array(vec![]),
            ),
        ]));
        Ok(PreparedControlArguments {
            arguments,
            values,
            value: codec
                .encode(&value)
                .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid))?,
            command: codec
                .encode(&command)
                .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid))?,
        })
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "signal_codec_unavailable"))?
}

/// The Avro command payload of a rejected signal or update: its name, the
/// original arguments, the validation errors and any diagnostics.
pub(super) async fn prepare_rejected_command(
    semaphore: Arc<Semaphore>,
    name: String,
    values: Vec<crate::codec::Value>,
    validation_errors: Value,
    diagnostics: Value,
    unavailable: &'static str,
) -> Result<String> {
    let permit = semaphore
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, unavailable))?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        use crate::codec::Value as Payload;
        // Only small server-authored diagnostics cross this conversion.
        // Original customer arguments remain official decoded Values, so a
        // JSON projection cannot round an int64 or turn bytes into strings.
        // Only an int64, such as a structural limit, is an exact number.
        fn diagnostic(value: Value) -> Option<Payload> {
            Some(match value {
                Value::Null => Payload::Null,
                Value::Bool(value) => Payload::Boolean(value),
                Value::String(value) => Payload::String(value),
                Value::Array(values) => {
                    Payload::Array(values.into_iter().map(diagnostic).collect::<Option<_>>()?)
                }
                Value::Object(values) => Payload::Map(
                    values
                        .into_iter()
                        .map(|(key, value)| Some((key, diagnostic(value)?)))
                        .collect::<Option<_>>()?,
                ),
                Value::Number(number) => Payload::Long(number.as_i64()?),
            })
        }
        let unavailable = || refuse(StatusCode::SERVICE_UNAVAILABLE, unavailable);
        let Some(Payload::Map(mut command)) = diagnostic(diagnostics) else {
            return Err(unavailable());
        };
        command.insert("name".into(), Payload::String(name));
        command.insert("arguments".into(), Payload::Array(values));
        command.insert(
            "validation_errors".into(),
            diagnostic(validation_errors).ok_or_else(unavailable)?,
        );
        crate::codec::ValueCodec::new()
            .and_then(|codec| codec.encode(&Payload::Map(command)))
            .map_err(|_| unavailable())
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, unavailable))?
}

fn signal_argument_errors(contract: &Value, name: &str, values: &[crate::codec::Value]) -> Value {
    let parameters = contract["declared_signal_contracts"]
        .as_array()
        .and_then(|contracts| contracts.iter().find(|contract| contract["name"] == name))
        .and_then(|contract| contract["parameters"].as_array())
        .expect("validated signal contract");
    let mut errors = serde_json::Map::new();
    for (index, parameter) in parameters.iter().enumerate() {
        let label = parameter["name"].as_str().unwrap_or("arguments");
        let Some(value) = values.get(index) else {
            if parameter["required"] == true {
                errors.insert(
                    label.into(),
                    json!([format!("The {label} argument is required.")]),
                );
            }
            continue;
        };
        use crate::codec::Value as Payload;
        let valid = if matches!(value, Payload::Null) {
            parameter["allows_null"]
                .as_bool()
                .unwrap_or(parameter["type"].is_null() || parameter["type"] == "")
        } else {
            match parameter["type"].as_str() {
                None | Some("mixed") => true,
                Some("array") => matches!(value, Payload::Array(_) | Payload::Map(_)),
                Some("int") => matches!(value, Payload::Long(_)),
                Some("float") => matches!(value, Payload::Long(_) | Payload::Double(_)),
                Some("bool") => matches!(value, Payload::Boolean(_)),
                Some("string") => matches!(value, Payload::String(_)),
                _ => false,
            }
        };
        if !valid {
            let kind = parameter["type"].as_str().unwrap_or("mixed");
            errors.insert(
                label.into(),
                json!([format!("The {label} argument must be of type {kind}.")]),
            );
        }
    }
    if errors.is_empty() {
        errors.insert("arguments".into(), json!(["Unexpected signal arguments."]));
    }
    Value::Object(errors)
}

fn validate_signal_arguments(
    contract: &Value,
    name: &str,
    values: &[crate::codec::Value],
) -> Result<()> {
    validate_arguments(
        contract,
        "declared_signal_contracts",
        name,
        values,
        "unsupported_signal_contract",
        "invalid_signal_arguments",
    )
}

pub(super) fn validate_arguments(
    contract: &Value,
    field: &str,
    name: &str,
    values: &[crate::codec::Value],
    unsupported: &'static str,
    invalid: &'static str,
) -> Result<()> {
    let parameters = contract[field]
        .as_array()
        .and_then(|contracts| contracts.iter().find(|contract| contract["name"] == name))
        .and_then(|contract| contract["parameters"].as_array())
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, unsupported))?;
    let variadic = parameters
        .last()
        .is_some_and(|parameter| parameter["variadic"] == true);
    let fixed_count = parameters.len() - usize::from(variadic);
    if parameters.iter().enumerate().any(|(index, parameter)| {
        parameter["default_available"] == true
            || (parameter["variadic"] == true && index + 1 != parameters.len())
            || (variadic && index < fixed_count && parameter["required"] != true)
    }) {
        return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, unsupported));
    }
    if values.len() < fixed_count || (!variadic && parameters.len() != values.len()) {
        return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid));
    }
    for (index, value) in values.iter().enumerate() {
        let parameter = &parameters[index.min(fixed_count)];
        if matches!(value, crate::codec::Value::Null) {
            if parameter["allows_null"]
                .as_bool()
                .unwrap_or(parameter["type"].is_null() || parameter["type"] == "")
            {
                continue;
            }
            return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid));
        }
        use crate::codec::Value as Payload;
        let valid = match parameter["type"].as_str() {
            None | Some("mixed") => true,
            Some("array") => matches!(value, Payload::Array(_) | Payload::Map(_)),
            Some("int") => matches!(value, Payload::Long(_)),
            Some("float") => matches!(value, Payload::Double(_) | Payload::Long(_)),
            Some("bool") => matches!(value, Payload::Boolean(_)),
            Some("string") => matches!(value, Payload::String(_)),
            _ => {
                return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, unsupported));
            }
        };
        if !valid {
            return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid));
        }
    }
    Ok(())
}

/// What a workflow task's non-terminal commands are applied with: the run
/// and task they belong to, the request's attempt, the structural limits,
/// and the batch's prepared external payloads and local activity records.
pub(super) struct Apply<'a, R> {
    pub(super) run: &'a R,
    pub(super) task: &'a R,
    pub(super) task_id: &'a str,
    pub(super) run_id: &'a str,
    pub(super) attempt: &'a Value,
    pub(super) limits: &'a super::memo_limits::StructuralLimits,
    pub(super) external: &'a super::external_resolution::PreparedCommands,
    pub(super) local: &'a std::collections::BTreeMap<usize, super::local_activities::Record>,
    pub(super) exceptions: &'a std::collections::BTreeMap<usize, String>,
}

impl<R> Clone for Apply<'_, R> {
    fn clone(&self) -> Self {
        *self
    }
}
impl<R> Copy for Apply<'_, R> {}

/// A workflow task completion's stored receipt: its request and the tasks it
/// created, so a repeat answers as the original did. Older receipts, and
/// activity receipts, are the bare request.
const COMPLETION_RECEIPT: &str = "workflow_task_completion";

/// PHP's `TERMINAL_TYPES`: the commands that close the run.
fn is_terminal(command: &Value) -> bool {
    matches!(
        command["type"].as_str(),
        Some("complete_workflow" | "fail_workflow" | "continue_as_new")
    )
}

fn timer_deadline(command: &Value) -> Result<DateTime<Utc>> {
    command["delay_seconds"]
        .as_i64()
        .filter(|delay| *delay >= 0)
        .and_then(chrono::Duration::try_seconds)
        .and_then(|delay| now().checked_add_signed(delay))
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_timer_delay"))
}

fn version_marker_payload(command: &Value) -> Result<Value> {
    // Workflow 2.5.5 freezes the marker projection and discards unknown newer
    // fields, including payload_codec. Its command-scoped options still refuse
    // use on a marker. Validate the entire batch before any durable mutation.
    for field in [
        "retry_policy",
        "start_to_close_timeout",
        "schedule_to_start_timeout",
        "schedule_to_close_timeout",
        "heartbeat_timeout",
        "worker_session",
        "execution_timeout_seconds",
        "run_timeout_seconds",
        "non_retryable",
        "timeout_kind",
        "exception",
        "failed_step_sequence",
        "failed_activity_execution_id",
        "parent_close_policy",
        "cancellation_policy",
        "cancellation_scope_id",
        "cancellation_cleanup",
        "delay_seconds",
        "timeout_seconds",
        "entries",
    ] {
        if command.get(field).is_some_and(|value| !value.is_null()) {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "unsupported_version_marker_field",
            ));
        }
    }
    let change_id = command["change_id"]
        .as_str()
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_version_marker"))?
        .trim_matches(|c| matches!(c, ' ' | '\t' | '\n' | '\r' | '\0' | '\x0b'));
    let integer = |field: &str| {
        command[field]
            .as_i64()
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_version_marker"))
    };
    let version = integer("version")?;
    let min = integer("min_supported")?;
    let max = integer("max_supported")?;
    if change_id.is_empty() {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "invalid_version_marker",
        ));
    }
    // PHP's bridge refuses a version outside its supported range with 409
    // `invalid_commands`, recording nothing.
    if min > max || version < min || version > max {
        return Err(refuse(StatusCode::CONFLICT, "invalid_commands"));
    }
    Ok(json!({"change_id":change_id,"version":version,"min_supported":min,"max_supported":max}))
}

pub(super) fn reject_fields(body: &Value, allowed: &[&str]) -> Result<()> {
    if body
        .as_object()
        .is_none_or(|map| map.keys().any(|key| !allowed.contains(&key.as_str())))
    {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_request_field",
        ));
    }
    Ok(())
}

/// A replayed answer as a workflow poll delivers it: a lease takes this
/// request's history encoding.
pub(super) fn replayed_poll(mut replay: Value, encoding: Option<&'static str>) -> Result<Value> {
    if replay["task"].is_object() {
        super::history_pages::compress(&mut replay["task"], encoding)?;
    }
    replay["server_capabilities"] = capabilities();
    Ok(replay)
}

pub(crate) fn capabilities() -> Value {
    // Prepared local activities are not served yet: like a PHP server whose
    // bridge lacks the prepared role, no groups and no cancellation policies.
    let mut value = json!({"supported_workflow_task_commands": ["schedule_activity", "start_timer", "start_child_workflow", "open_condition_wait", "open_signal_wait", "record_version_marker", "record_side_effect", "record_local_activity", "complete_workflow", "fail_workflow", "continue_as_new", "complete_update", "fail_update"],
        "worker_deregistration_fencing":{"schema":"durable-workflow.v2.worker-deregistration.v1","supported":true,"receipt_retention_seconds":600,"endpoint":"/worker/registrations/{workerId}/deregister"},
        "workflow_memo_updates": false, "cooperative_cancellation": true, "prepared_local_activities": false,
        "prepared_local_activity_groups": false, "prepared_local_activity_cancellation_policies": [],
        "worker_sessions": super::worker_sessions::capability(), "worker_session_verbs": ["create", "heartbeat", "close"],
        "sticky_execution": false, "local_activities": super::local_activities::manifest(), "message_streams": super::message_streams::worker_capability(),
        "update_validation_tasks": true, "synchronous_update_validation": super::update_validation::capability(),
        "workflow_updates": true, "query_tasks": true, "query_task_authority":true, "query_task_poll_request_idempotency": true,
        "activity_lease_renewal": true, "activity_cancellation_acknowledgement": true});
    value["runtime_external_payload_transport"] = super::payload_discovery::transport_manifest();
    value["supported_workflow_task_commands"]
        .as_array_mut()
        .expect("commands")
        .push(json!("upsert_search_attributes"));
    value["typed_search_attributes"] = json!({"supported":true,
        "minimum_worker_protocol_version":super::search_attribute_upserts::MINIMUM_PROTOCOL_VERSION,
        "worker_capability":super::search_attribute_upserts::CAPABILITY,
        "canonical_types":["string","keyword","keyword_list","int","float","bool","datetime"],
        "command_field":"attribute_types","history_event":"SearchAttributesUpserted",
        "history_field":"attribute_types","legacy_history_rule":"absent_metadata_is_unknown_type_identity"});
    if super::memos::UPDATES_SUPPORTED {
        value["supported_workflow_task_commands"]
            .as_array_mut()
            .expect("commands")
            .push(json!("upsert_memo"));
        value["workflow_memo_updates"] = json!({"supported":true, "type":"upsert_memo",
            "category":"non_terminal_command", "worker_capability":"memo_upserts",
            "minimum_protocol_version":"1.14", "required_fields":["type","entries"],
            "optional_fields":[], "entries":{"shape":"payload-envelope<avro-map<string, Value|null>>",
                "payload_envelope_field":true,"codec":"avro","value_schema":"durable_workflow.protocol.Value",
                "key_pattern":"^(?!-?[0-9]+$)[A-Za-z0-9_.:-]{1,64}$", "null_value":"delete_key",
                "max_keys_per_run":100,"max_value_size_bytes":10240,"max_total_size_bytes":65536,
                "size_measurement":"avro_single_object_bytes",
                "runtime_configured_total_limit":"workflows.v2.structural_limits.memo_size_bytes"}});
    }
    value
}

#[cfg(test)]
#[path = "store_record_tests.rs"]
mod record_tests;

/// What a start records besides its request: the prepared initial memo and
/// the validated search attributes.
pub(super) struct StartMetadata<'a> {
    pub(super) memo: Option<&'a super::memos::PreparedMemo>,
    pub(super) search_attributes: Option<&'a super::search_attribute_upserts::StartSearchAttributes>,
}
