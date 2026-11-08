//! Bounded transient queries over an immutable, quiescent history snapshot.
//! These cache entries are native-owned; PHP cache values are never decoded.
use super::transaction::Transaction;
use super::{
    Result,
    backend::Backend,
    envelope,
    query_authority::{self as authority, QueryTaskConfig},
    refuse,
    store::Store,
    text, wire,
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::{sync::Arc, time::Duration};
use tokio::sync::Semaphore;

const PREFIX: &str = "dw-native-query:";
const MAX_PENDING: usize = 16;
pub(super) const MAX_ENTRIES: usize = 64;
/// One row per namespace listing its open query task ids (Server #964).
const OPEN_PREFIX: &str = "dw-native-query-open:";
/// Receipts filed by the minute their rows expire, and the sweep's watermark.
const EXPIRY_PREFIX: &str = "dw-native-query-expiry:";
const EXPIRY_MARK: &str = "dw-native-query-expiry-mark";
/// The one-time cutover of 3.0.1 query rows.
const CUTOVER_MARK: &str = "dw-native-query-cutover";
const PAGE_KEYS: usize = 128;
const SWEEP_MINUTES: usize = 30;
const SWEEP_PAGES: usize = 2;
/// Bookkeeping rows never expire on their own.
const BOOKKEEPING_EXPIRATION: i64 = i64::MAX / 2;
const SNAPSHOT_BYTES: usize = 1024 * 1024;
pub(super) const ARGUMENT_BYTES: usize = 64 * 1024;
pub(super) const RESULT_BYTES: usize = 256 * 1024;

#[path = "query_poll_requests.rs"]
mod poll_requests;
#[cfg(test)]
#[path = "query_empty_poll_tests.rs"]
mod empty_poll_tests;

enum QueryClaim {
    Task(Value),
    Empty,
    StaleWorker,
    // A newer poll request from the same worker replaced this one.
    Superseded,
    // `pendingTaskWorkflowBlockStatus`: every matching query waits on its run.
    Blocked(&'static str),
}

/// Private handoff from the immediate poll after it checked this proof,
/// fence and fresh current-request row under the same transition lock.
struct CheckedQueryPoll<'a> {
    proof: &'a str,
}

pub(super) const QUERY_WORKER_PROOF_SQL: &str = "SELECT w.worker_id,w.namespace,w.last_heartbeat_at,i.token,i.worker_id AS incarnation_worker_id,i.namespace AS incarnation_namespace FROM workflow_worker_registrations w JOIN dw_worker_registration_incarnations i ON i.worker_id=w.worker_id AND i.namespace=w.namespace AND i.status='active' WHERE w.namespace=$1 AND w.worker_id=$2 AND w.status='active' LIMIT 2";

const LEASED_WORKFLOW_TASKS: &str = "SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status='leased' AND (lease_expires_at IS NULL OR lease_expires_at>$2)";

/// Laravel binds "now" in whole seconds when PHP compares task leases.
fn lease_now() -> chrono::DateTime<chrono::Utc> {
    use chrono::Timelike;
    let at = super::store::now();
    at.with_nanosecond(0).unwrap_or(at)
}

/// The resume kinds PHP's `isWorkflowResumePayload` recognizes. Native's
/// activity and ordinary timer resumes name their source without a kind.
fn resume_kind(payload: &Value) -> Option<&'static str> {
    match payload["resume_source_kind"].as_str() {
        Some("workflow_signal") => Some("workflow_signal"),
        Some("workflow_update") => Some("workflow_update"),
        Some("activity_execution") => Some("activity_execution"),
        Some("child_workflow_run") => Some("child_workflow_run"),
        Some("timer") => Some("timer"),
        Some(_) => None,
        None if payload["activity_execution_id"].is_string() => Some("activity_execution"),
        None if payload["timer_id"].is_string() => Some("timer"),
        None => None,
    }
}

fn poll_result(task: Option<Value>, status: &str) -> Value {
    json!({"task":task,"poll_status":status,"server_capabilities":super::store::capabilities()})
}

fn now() -> i64 {
    chrono::Utc::now().timestamp()
}
/// An open set's ids: unique canonical ULIDs, at most `MAX_ENTRIES`, and
/// none from a row that names another namespace.
fn listed_ids(set: &Value, namespace: &str) -> Vec<String> {
    if set["namespace"] != namespace {
        return vec![];
    }
    let mut ids: Vec<String> = vec![];
    for id in set["ids"].as_array().into_iter().flatten().filter_map(Value::as_str) {
        if ids.len() < MAX_ENTRIES && canonical_id(id) && !ids.iter().any(|seen| seen == id) {
            ids.push(id.to_owned());
        }
    }
    ids
}

/// A query task id as native writes it: a ULID in its canonical text.
fn canonical_id(id: &str) -> bool {
    id.parse::<ulid::Ulid>().is_ok_and(|parsed| parsed.to_string() == id)
}

fn key(id: &str) -> Result<String> {
    // A task ID cannot address another application's cache key.
    if id.parse::<ulid::Ulid>().is_err() {
        return Err(refuse(StatusCode::NOT_FOUND, "query_task_not_found"));
    }
    Ok(format!("{PREFIX}{id}"))
}

pub(super) async fn empty_arguments(semaphore: Arc<Semaphore>) -> Result<String> {
    encode_arguments(semaphore, crate::codec::Value::Array(vec![])).await
}

/// One Avro Value blob with the official codec, off the async workers.
/// Encodes arguments with their map entries in the order given, as PHP's
/// serializer writes a decoded request.
pub(super) async fn encode_ordered_arguments(semaphore: Arc<Semaphore>, arguments: crate::codec::OrderedValue) -> Result<String> {
    let permit = semaphore
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        let codec = crate::codec::ValueCodec::new()
            .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))?;
        codec
            .encode_ordered_value(&arguments)
            .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))?
}

pub(super) async fn encode_arguments(semaphore: Arc<Semaphore>, arguments: crate::codec::Value) -> Result<String> {
    let permit = semaphore
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        let codec = crate::codec::ValueCodec::new()
            .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))?;
        codec
            .encode(&arguments)
            .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))?
}

pub(super) async fn decode(
    semaphore: Arc<Semaphore>,
    blob: String,
    arguments: bool,
    invalid: &'static str,
) -> Result<Vec<crate::codec::Value>> {
    let permit = semaphore
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        let codec = crate::codec::ValueCodec::new()
            .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid))?;
        let value = codec
            .decode(&blob)
            .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid))?;
        if arguments {
            match value {
                crate::codec::Value::Array(values) => Ok(values),
                _ => Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid)),
            }
        } else {
            Ok(vec![value])
        }
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))?
}

/// A query's arguments as the server reads them: a list of values, or why
/// they are not one. Only a declared query contract reads them
/// (`validateContractedQueryArguments`); otherwise the worker receives the
/// arguments as given. A string-keyed map decodes to a PHP array too, of
/// named arguments (`WorkflowQueryContract::normalizeNamedArguments`); only a
/// scalar is not an array.
pub(super) enum QueryArguments {
    Decoded(super::query_contract::Arguments),
    Undecodable,
    NotAList,
}

/// Decodes a query's arguments without refusing them.
pub(super) async fn read_arguments(semaphore: Arc<Semaphore>, blob: String) -> Result<QueryArguments> {
    let permit = semaphore
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        let Ok(codec) = crate::codec::ValueCodec::new() else {
            return QueryArguments::Undecodable;
        };
        match codec.decode_ordered(&blob) {
            Ok(value) => super::query_contract::Arguments::from_value(value)
                .map_or(QueryArguments::NotAList, QueryArguments::Decoded),
            Err(_) => QueryArguments::Undecodable,
        }
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))
}

/// One admission attempt: an answer, or no worker to route the query to.
pub(super) enum QueryAttempt {
    Answered((StatusCode, Value)),
    Unroutable { run_id: String, route: super::query_route::QueryRoute },
}

/// `queryFailed`: PHP's refusal body for one query.
fn query_refusal(status: StatusCode, workflow_id: &str, run_id: &str, name: &str, reason: &str, message: &str) -> super::RuntimeError {
    super::RuntimeError::Protocol {
        status,
        response: json!({"workflow_id":workflow_id,"run_id":run_id,"target_scope":"instance","query_name":name,
            "result":null,"reason":reason,"message":message}),
    }
}

/// `QueryTaskQueueFullException`, answered as PHP does: 429 with the query
/// refusal body, and no Retry-After.
fn queue_full(workflow_id: &str, run_id: &str, name: &str, queue: &str, namespace: &str, limit: usize) -> super::RuntimeError {
    query_refusal(
        StatusCode::TOO_MANY_REQUESTS,
        workflow_id,
        run_id,
        name,
        "query_task_queue_full",
        &format!("Query task queue [{queue}] in namespace [{namespace}] reached the configured pending limit of {limit}."),
    )
}

/// A query refused because no worker can take it.
fn route_refusal(workflow_id: &str, run_id: &str, name: &str, route: &super::query_route::QueryRoute) -> super::RuntimeError {
    query_refusal(
        StatusCode::CONFLICT,
        workflow_id,
        run_id,
        name,
        route.reason.unwrap_or("query_worker_unavailable"),
        route.message.as_deref().unwrap_or("No compatible query-capable worker is available for this workflow query."),
    )
}

/// `terminalRunQueryFailure`: failed, cancelled and terminated runs refuse
/// queries.
fn run_not_active(workflow_id: &str, run_id: &str, name: &str, status: &str) -> super::RuntimeError {
    let mut error = query_refusal(
        StatusCode::CONFLICT,
        workflow_id,
        run_id,
        name,
        "run_not_active",
        &format!("Workflow query [{name}] cannot execute because run [{run_id}] is terminal with status [{status}]."),
    );
    if let super::RuntimeError::Protocol { response, .. } = &mut error {
        response["run_status"] = json!(status);
        response["is_terminal"] = json!(true);
    }
    error
}

/// The run statuses PHP still answers queries for.
fn admits_queries(status: &str) -> bool {
    matches!(status, "pending" | "running" | "waiting" | "completed")
}

/// `validateContractedQueryArguments`: a run that declares no queries leaves
/// the name and arguments to the worker (`None`). A declared query's
/// arguments must decode to a PHP array; `WorkflowQueryContract` then matches
/// them to the query's parameter contract, and the worker receives the
/// normalized positional list.
fn check_query_contract(
    contract: &Value,
    workflow_id: &str,
    run_id: &str,
    name: &str,
    arguments: &QueryArguments,
) -> Result<Option<Vec<crate::codec::OrderedValue>>> {
    let declared = |field: &str| contract[field].as_array().is_some_and(|items| !items.is_empty());
    if !declared("declared_queries") && !declared("declared_query_contracts") {
        return Ok(None);
    }
    if !contract["declared_queries"].as_array().is_some_and(|names| names.iter().any(|v| v == name)) {
        return Err(query_refusal(StatusCode::NOT_FOUND, workflow_id, run_id, name, "rejected_unknown_query",
            &format!("Workflow query [{name}] is not declared on workflow [{workflow_id}].")));
    }
    let refused = |message: String, errors: Value| {
        let mut refusal = query_refusal(StatusCode::UNPROCESSABLE_ENTITY, workflow_id, run_id, name, "invalid_query_arguments", &message);
        if let super::RuntimeError::Protocol { response, .. } = &mut refusal {
            response["validation_errors"] = errors;
        }
        refusal
    };
    let arguments = match arguments {
        QueryArguments::Decoded(arguments) => arguments,
        QueryArguments::Undecodable => return Err(refused(format!("Workflow query [{name}] arguments could not be decoded."),
            json!({"arguments": ["Query arguments could not be decoded."]}))),
        QueryArguments::NotAList => return Err(refused(format!("Workflow query [{name}] arguments must decode to an array."),
            json!({"arguments": ["Query arguments must decode to an array."]}))),
    };
    let parameters = contract["declared_query_contracts"]
        .as_array()
        .and_then(|contracts| contracts.iter().find(|entry| entry["name"] == name));
    match super::query_contract::validate(parameters, name, arguments.clone()) {
        Ok(normalized) => Ok(Some(normalized)),
        Err(super::query_contract::Refusal::Invalid(errors)) => Err(refused(
            format!("Workflow query [{name}] argument validation failed."), super::query_contract::errors_json(&errors))),
        // Published PHP fails here with an uncaught Avro `invalid_map_key`
        // (500). Native keeps the status and says which default failed.
        Err(super::query_contract::Refusal::UnencodableDefault(parameter)) => Err(query_refusal(
            StatusCode::INTERNAL_SERVER_ERROR, workflow_id, run_id, name, "query_default_unencodable",
            &format!("Workflow query [{name}] parameter [{parameter}] has a registered default with an integer key outside a list, which the Avro Value codec cannot encode."))),
    }
}

/// The arguments a routed query's task carries: a declared query's
/// normalized list encoded again, or the request's arguments as given.
fn routed_arguments(normalized: Option<Vec<crate::codec::OrderedValue>>, arguments: &str) -> Result<String> {
    match normalized {
        Some(values) => crate::codec::ValueCodec::new()
            .and_then(|codec| codec.encode_ordered_value(&crate::codec::OrderedValue::Array(values)))
            .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable")),
        None => Ok(arguments.to_owned()),
    }
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
    /// The namespace's open query tasks (pending or leased), at most
    /// `MAX_ENTRIES`, read by key from the namespace's open set. An answered,
    /// failed or timed-out task keeps its receipt, readable by key, until it
    /// expires, but holds no queue capacity, as in PHP, whose admission counts
    /// only pending task ids (Server #964). Nothing here scans the cache
    /// table: every read is by primary key, with a fixed bound per call.
    pub(super) async fn query_entries(
        &self,
        tx: &mut Transaction<'_, DB>,
    ) -> Result<Vec<(String, Value)>> {
        Self::cut_over_query_rows(tx).await?;
        Self::sweep_query_receipts(tx).await?;
        let namespace = self.namespace.as_ref();
        let listed = Self::open_set(tx, namespace).await?;
        let mut entries = vec![];
        let mut open = vec![];
        for id in &listed {
            let key = key(id)?;
            let Some(row) = Self::query(DB::QUERY_CACHE_GET).bind(&key).fetch_optional(&mut **tx).await? else {
                continue;
            };
            if DB::number(&row, "expiration")? <= now() {
                Self::query(DB::QUERY_CACHE_DELETE).bind(&key).execute(&mut **tx).await?;
                continue;
            }
            let mut task: Value = serde_json::from_str(&DB::string(&row, "value")?)?;
            // A member must be this namespace's task under its own id.
            if task["query_task_id"] != id.as_str() || task["namespace"] != namespace {
                continue;
            }
            if authority::expire(&mut task, authority::now())? {
                Self::put_query(tx, &key, &task, authority::expiration(&task)?).await?;
            }
            if matches!(task["status"].as_str(), Some("pending" | "leased")) {
                open.push(id.clone());
                entries.push((key, task));
            }
        }
        if open != listed {
            Self::write_open_set(tx, namespace, &open).await?;
        }
        Ok(entries)
    }

    /// The open-set row's key: one per namespace, from a digest so any
    /// namespace name fits the cache key column. The row also names its
    /// namespace in full.
    fn open_set_key(namespace: &str) -> String {
        use sha2::{Digest, Sha256};
        let digest: String = Sha256::digest(namespace.as_bytes()).iter().take(16).map(|byte| format!("{byte:02x}")).collect();
        format!("{OPEN_PREFIX}{digest}")
    }

    /// Whether, read in `tx`, a locked claim in this namespace would find no
    /// open query task and write no query bookkeeping (Server #1057): the
    /// 3.0.1 cutover is done, the receipt sweep has no minute to retire, and
    /// the namespace's open set is absent or exactly empty. A task joins the
    /// open set in the transaction that writes it, so a snapshot that cannot
    /// see the membership cannot see the task either. A row this reader does
    /// not recognize exactly is not evidence of an empty namespace; the
    /// caller then takes the locked path, which keeps its own handling.
    pub(super) async fn query_bookkeeping_idle(tx: &mut Transaction<'_, DB>, namespace: &str) -> Result<bool> {
        let Some(Some(cutover)) = Self::cache_row(tx, CUTOVER_MARK).await? else {
            return Ok(false);
        };
        if cutover["done"] != true {
            return Ok(false);
        }
        // `sweep_query_receipts` writes when its mark is missing or a whole
        // minute after the mark has passed.
        let Some(Some(mark)) = Self::cache_row(tx, EXPIRY_MARK).await? else {
            return Ok(false);
        };
        if mark["minute"].as_i64().and_then(|minute| minute.checked_add(1)).is_none_or(|next| next < now().div_euclid(60)) {
            return Ok(false);
        }
        Ok(match Self::cache_row(tx, &Self::open_set_key(namespace)).await? {
            None => true,
            Some(set) => set == Some(json!({"namespace": namespace, "ids": []})),
        })
    }

    /// A bookkeeping row as stored: absent, or its value if it parses.
    async fn cache_row(tx: &mut Transaction<'_, DB>, key: &str) -> Result<Option<Option<Value>>> {
        let Some(row) = Self::query(DB::QUERY_CACHE_GET).bind(key).fetch_optional(&mut **tx).await? else {
            return Ok(None);
        };
        Ok(Some(serde_json::from_str(&DB::string(&row, "value")?).ok()))
    }

    /// The namespace's listed open task ids: unique canonical ULIDs, at most
    /// `MAX_ENTRIES`. A row naming another namespace, or none, lists nothing.
    async fn open_set(tx: &mut Transaction<'_, DB>, namespace: &str) -> Result<Vec<String>> {
        let Some(set) = Self::cache_document(tx, &Self::open_set_key(namespace)).await? else {
            return Ok(vec![]);
        };
        Ok(listed_ids(&set, namespace))
    }

    /// Open tasks of this namespace on `queue` a poll could claim now: read
    /// lock-free from the namespace's open set, every row by key (Server
    /// #964). Only a readiness hint; the claim decides under the lock.
    pub(super) async fn ready_query_keys(&self, queue: &str) -> Result<Vec<String>> {
        let namespace = self.namespace.as_ref();
        let Some(row) = Self::query(DB::QUERY_CACHE_GET).bind(Self::open_set_key(namespace)).fetch_optional(&self.pool).await? else {
            return Ok(vec![]);
        };
        let set: Value = serde_json::from_str(&DB::string(&row, "value")?).unwrap_or(Value::Null);
        let at = super::store::now();
        let wire_now = authority::wire(at);
        let mut ready = vec![];
        for id in listed_ids(&set, namespace) {
            let key = key(&id)?;
            let Some(row) = Self::query(DB::QUERY_CACHE_GET).bind(&key).fetch_optional(&self.pool).await? else {
                continue;
            };
            if DB::number(&row, "expiration")? <= at.timestamp() {
                continue;
            }
            let Ok(task) = serde_json::from_str::<Value>(&DB::string(&row, "value")?) else {
                continue;
            };
            let after = |field: &str| task[field].as_str().is_some_and(|time| time > wire_now.as_str());
            let claimable = match task["status"].as_str() {
                Some("pending") => true,
                Some("leased") => !after("lease_expires_at"),
                _ => false,
            };
            if task["query_task_id"] == id.as_str() && task["namespace"] == namespace && task["task_queue"] == queue
                && after("query_deadline_at") && claimable
            {
                ready.push(key);
            }
        }
        Ok(ready)
    }

    async fn write_open_set(tx: &mut Transaction<'_, DB>, namespace: &str, ids: &[String]) -> Result<()> {
        Self::put_cache_document(tx, &Self::open_set_key(namespace), &json!({"namespace": namespace, "ids": ids})).await
    }

    /// Adds an open task to its namespace's set, at most `MAX_ENTRIES`.
    async fn list_open(tx: &mut Transaction<'_, DB>, namespace: &str, id: &str) -> Result<()> {
        let mut ids = Self::open_set(tx, namespace).await?;
        if !ids.iter().any(|listed| listed == id) {
            if ids.len() >= MAX_ENTRIES {
                return Err(refuse(StatusCode::SERVICE_UNAVAILABLE, "query_task_queue_full"));
            }
            ids.push(id.to_owned());
        }
        Self::write_open_set(tx, namespace, &ids).await
    }

    /// A native bookkeeping row's document, by key.
    async fn cache_document(tx: &mut Transaction<'_, DB>, key: &str) -> Result<Option<Value>> {
        let Some(row) = Self::query(DB::QUERY_CACHE_GET).bind(key).fetch_optional(&mut **tx).await? else {
            return Ok(None);
        };
        Ok(serde_json::from_str(&DB::string(&row, "value")?).ok())
    }

    async fn put_cache_document(tx: &mut Transaction<'_, DB>, key: &str, value: &Value) -> Result<()> {
        // Bookkeeping rows never expire on their own; the sweep and the open
        // set maintain them.
        let expiration = BOOKKEEPING_EXPIRATION;
        let updated = Self::query(DB::QUERY_CACHE_UPDATE)
            .bind(value.to_string()).bind(expiration).bind(key)
            .execute(&mut **tx).await?;
        if DB::affected(updated) == 0 {
            Self::query(DB::QUERY_CACHE_INSERT)
                .bind(key).bind(value.to_string()).bind(expiration)
                .execute(&mut **tx).await?;
        }
        Ok(())
    }

    /// Files a query row under the minute it expires, for the sweep: a minute
    /// keeps pages of at most `PAGE_KEYS` keys, named by number.
    async fn file_expiry(tx: &mut Transaction<'_, DB>, key: &str, expiration: i64) -> Result<()> {
        // A row already expired goes now: its minute may be behind the sweep.
        if expiration <= now() {
            Self::query(DB::QUERY_CACHE_DELETE_EXPIRED).bind(key).bind(now()).execute(&mut **tx).await?;
            return Ok(());
        }
        let minute = expiration.div_euclid(60);
        let header_key = format!("{EXPIRY_PREFIX}{minute}");
        let header = Self::cache_document(tx, &header_key).await?.unwrap_or_else(|| json!({"first": 0, "pages": 0}));
        let first = header["first"].as_i64().unwrap_or(0).max(0);
        let mut pages = header["pages"].as_i64().unwrap_or(0).max(first);
        let mut page = if pages > first {
            Self::cache_document(tx, &format!("{header_key}:{}", pages - 1)).await?
        } else {
            None
        };
        if page.as_ref().is_none_or(|page| page["keys"].as_array().is_none_or(|keys| keys.len() >= PAGE_KEYS)) {
            pages += 1;
            page = Some(json!({"keys": []}));
        }
        let mut page = page.unwrap_or_else(|| json!({"keys": []}));
        if let Some(keys) = page["keys"].as_array_mut() {
            keys.push(json!(key));
        }
        Self::put_cache_document(tx, &format!("{header_key}:{}", pages - 1), &page).await?;
        Self::put_cache_document(tx, &header_key, &json!({"first": first, "pages": pages})).await
    }

    /// Deletes expired query rows, minute by minute behind a watermark: at
    /// most `SWEEP_MINUTES` minutes, `SWEEP_PAGES` pages and their keys per
    /// call, every one by key. A key that has not expired (its expiry moved)
    /// is filed again under its own minute. Everything happens in the
    /// caller's transaction, so a crash keeps or loses the whole step.
    async fn sweep_query_receipts(tx: &mut Transaction<'_, DB>) -> Result<()> {
        let current = now().div_euclid(60);
        let Some(mark) = Self::cache_document(tx, EXPIRY_MARK).await?.and_then(|mark| mark["minute"].as_i64()) else {
            // The first sweep starts here; earlier minutes hold nothing, as
            // filing deletes a row that has already expired.
            return Self::put_cache_document(tx, EXPIRY_MARK, &json!({"minute": current - 1})).await;
        };
        let mut minute = mark;
        let mut pages_left = SWEEP_PAGES;
        for _ in 0..SWEEP_MINUTES {
            if minute + 1 >= current || pages_left == 0 {
                break;
            }
            let header_key = format!("{EXPIRY_PREFIX}{}", minute + 1);
            if let Some(header) = Self::cache_document(tx, &header_key).await? {
                let pages = header["pages"].as_i64().unwrap_or(0).max(0);
                let mut first = header["first"].as_i64().unwrap_or(0).clamp(0, pages);
                while first < pages && pages_left > 0 {
                    let page_key = format!("{header_key}:{first}");
                    let keys = Self::cache_document(tx, &page_key).await?
                        .and_then(|page| page["keys"].as_array().cloned())
                        .unwrap_or_default();
                    for key in keys.iter().filter_map(Value::as_str).take(PAGE_KEYS) {
                        let deleted = Self::query(DB::QUERY_CACHE_DELETE_EXPIRED).bind(key).bind(now()).execute(&mut **tx).await?;
                        if DB::affected(deleted) == 0
                            && let Some(row) = Self::query(DB::QUERY_CACHE_GET).bind(key).fetch_optional(&mut **tx).await?
                        {
                            let expiration = DB::number(&row, "expiration")?;
                            if expiration.div_euclid(60) > minute + 1 {
                                Self::file_expiry(tx, key, expiration).await?;
                            }
                        }
                    }
                    Self::query(DB::QUERY_CACHE_DELETE).bind(&page_key).execute(&mut **tx).await?;
                    first += 1;
                    pages_left -= 1;
                }
                if first < pages {
                    Self::put_cache_document(tx, &header_key, &json!({"first": first, "pages": pages})).await?;
                    break;
                }
                Self::query(DB::QUERY_CACHE_DELETE).bind(&header_key).execute(&mut **tx).await?;
            }
            minute += 1;
        }
        if minute != mark {
            Self::put_cache_document(tx, EXPIRY_MARK, &json!({"minute": minute})).await?;
        }
        Ok(())
    }

    /// 3.0.1 kept query rows without open sets or expiry files. Until the
    /// cutover marker says it is done, each call reads one primary-key page
    /// of the cache table (at most 64 rows, whatever their keys), lists those
    /// rows' open tasks in their namespaces' sets and files every one for
    /// expiry. It runs once for the whole server, never per namespace.
    async fn cut_over_query_rows(tx: &mut Transaction<'_, DB>) -> Result<()> {
        let marker = Self::cache_document(tx, CUTOVER_MARK).await?.unwrap_or_else(|| json!({"after": "", "done": false}));
        if marker["done"] == true {
            return Ok(());
        }
        let after = marker["after"].as_str().unwrap_or_default().to_owned();
        let rows = Self::query(DB::QUERY_CACHE_PAGE).bind(&after).fetch_all(&mut **tx).await?;
        let mut last = after;
        for row in &rows {
            let key = DB::string(row, "key")?;
            last.clone_from(&key);
            let Some(id) = key.strip_prefix("dw-native-query:") else {
                continue;
            };
            let expiration = DB::number(row, "expiration")?;
            // An expired row is deleted here and never listed.
            Self::file_expiry(tx, &key, expiration).await?;
            if expiration <= now() {
                continue;
            }
            let Ok(mut task) = serde_json::from_str::<Value>(&DB::string(row, "value")?) else {
                continue;
            };
            // A task past its deadline closes before it could take a slot.
            if authority::expire(&mut task, authority::now())? {
                Self::put_query(tx, &key, &task, authority::expiration(&task)?).await?;
            }
            if task["query_task_id"] == id
                && canonical_id(id)
                && matches!(task["status"].as_str(), Some("pending" | "leased"))
                && let Some(namespace) = task["namespace"].as_str()
            {
                match Self::list_open(tx, namespace, id).await {
                    // A full set leaves this task to its deadline; nothing
                    // else may pass silently, so the page rolls back.
                    Err(super::RuntimeError::Refused { reason: "query_task_queue_full", .. }) => {}
                    other => other?,
                }
            }
        }
        let done = rows.len() < 64;
        Self::put_cache_document(tx, CUTOVER_MARK, &json!({"after": last, "done": done})).await
    }

    async fn put_query(
        tx: &mut Transaction<'_, DB>,
        key: &str,
        value: &Value,
        expiration: i64,
    ) -> Result<()> {
        Self::query(DB::QUERY_CACHE_UPDATE)
            .bind(value.to_string())
            .bind(expiration)
            .bind(key)
            .execute(&mut **tx)
            .await?;
        Ok(())
    }

    /// `workflowBound` and `withCurrentRunGuard` for a query: the workflow
    /// is in this namespace, and a selected run is its current run.
    pub(super) async fn query_target(&self, workflow_id: &str, selected_run: Option<&str>) -> Result<()> {
        let instance = Self::query("SELECT current_run_id FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(workflow_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&self.pool)
            .await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        if let Some(selected) = selected_run
            && DB::optional_string(&instance, "current_run_id")?.as_deref() != Some(selected)
        {
            return Err(refuse(StatusCode::CONFLICT, "historical_run_command_rejected"));
        }
        Ok(())
    }

    /// `WorkflowQueryTaskBroker::query`. While no worker can take the query,
    /// it waits up to the query timeout for one to register
    /// (`awaitRegisteredQueryRoute`), then refuses with the route's reason.
    pub(super) async fn query_workflow(
        &self,
        workflow_id: &str,
        selected_run: Option<&str>,
        name: &str,
        arguments: String,
        values: QueryArguments,
        config: &QueryTaskConfig,
        command: Option<&Value>,
    ) -> Result<(StatusCode, Value)> {
        let wait_until =
            tokio::time::Instant::now() + Duration::from_secs(config.timeout_seconds.max(0) as u64);
        loop {
            match self
                .query_attempt(workflow_id, selected_run, name, &arguments, &values, config, command)
                .await?
            {
                QueryAttempt::Answered(answer) => return Ok(answer),
                QueryAttempt::Unroutable { run_id, route } => {
                    let now = tokio::time::Instant::now();
                    if route.reason == Some("query_worker_unavailable") && now < wait_until {
                        tokio::time::sleep((wait_until - now).min(Duration::from_millis(250))).await;
                        continue;
                    }
                    return Err(route_refusal(workflow_id, &run_id, name, &route));
                }
            }
        }
    }

    async fn query_attempt(
        &self,
        workflow_id: &str,
        selected_run: Option<&str>,
        name: &str,
        arguments: &str,
        values: &QueryArguments,
        config: &QueryTaskConfig,
        command: Option<&Value>,
    ) -> Result<QueryAttempt> {
        // PHP's refusal order: terminal run, query contract, then route. These
        // reads run before the transaction so that no request holds one
        // connection while waiting for another.
        let lookup = "SELECT r.* FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE i.id=$1 AND r.id=COALESCE($2,i.current_run_id) AND r.namespace=$3";
        let run = Self::query(lookup)
            .bind(workflow_id).bind(selected_run).bind(self.namespace.as_ref()).fetch_optional(&self.pool).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        if DB::string(&run, "namespace")? != self.namespace.as_ref()
            || DB::string(&run, "workflow_instance_id")? != workflow_id
            || selected_run.is_some_and(|id| DB::string(&run, "id").ok().as_deref() != Some(id))
        {
            return Err(refuse(StatusCode::NOT_FOUND, "instance_not_found"));
        }
        let run_id = DB::string(&run, "id")?;
        let status = DB::string(&run, "status")?;
        if !admits_queries(&status) {
            return Err(run_not_active(workflow_id, &run_id, name, &status));
        }
        let contract = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(&run_id).fetch_one(&self.pool).await?;
        // The worker receives a declared query's normalized arguments, encoded
        // again (`Serializer::serializeWithCodec`).
        let normalized = check_query_contract(&DB::document_row(&contract, "payload")?, workflow_id, &run_id, name, values)?;
        let arguments = routed_arguments(normalized, arguments)?;
        let route = self.query_route(&run, config.worker_stale_after_seconds).await?;
        if !route.servable {
            return Ok(QueryAttempt::Unroutable { run_id, route });
        }
        let mut tx = self.begin().await?;
        // The run read again under the transaction is the one admitted.
        let run = Self::query(lookup)
            .bind(workflow_id).bind(selected_run).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?
            .filter(|row| DB::string(row, "id").ok().as_deref() == Some(run_id.as_str()))
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        let status = DB::string(&run, "status")?;
        if !admits_queries(&status) {
            return Err(run_not_active(workflow_id, &run_id, name, &status));
        }
        // `enqueue`: a workflow task leased now fixes the query's history
        // cutoff at the run's current sequence. Without one, the claim reads
        // the history it finds then. Either way the query waits for its claim.
        let observed = DB::number(&run, "last_history_sequence")?;
        let leased = Self::scalar(LEASED_WORKFLOW_TASKS)
            .bind(&run_id)
            .bind(DB::bind_time(lease_now()))
            .fetch_one(&mut *tx)
            .await?;
        let cutoff = (leased != 0).then_some(observed);
        let entries = self.query_entries(&mut tx).await?;
        let pending = entries
            .iter()
            .filter(|(_, v)| matches!(v["status"].as_str(), Some("pending" | "leased")))
            .count();
        let queue = DB::string(&run, "queue")?;
        if entries.len() >= MAX_ENTRIES || pending >= MAX_PENDING {
            let limit = if pending >= MAX_PENDING { MAX_PENDING } else { MAX_ENTRIES };
            return Err(queue_full(workflow_id, &run_id, name, &queue, self.namespace.as_ref(), limit));
        }
        // Native delivers one bounded history page; a run already past it
        // cannot be queried.
        let (history, next) = Self::history_page_connection(&mut tx, &run_id, 0, 1000).await?;
        if next.is_some() || serde_json::to_vec(&history)?.len() > SNAPSHOT_BYTES {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "query_snapshot_exceeds_budget",
            ));
        }
        drop(history);
        let task_id = super::store::id();
        let key = key(&task_id)?;
        let admitted = authority::now();
        let (deadline, retained_until) = config.timing(admitted)?;
        let mut task = json!({"query_task_id":task_id,"query_task_attempt":0,"status":"pending",
            "namespace":self.namespace.as_ref(),"workflow_id":workflow_id,"run_id":run_id,
            "workflow_type":DB::string(&run,"workflow_type")?,"workflow_class":DB::string(&run,"workflow_class")?,
            "task_queue":DB::string(&run,"queue")?,"payload_codec":"avro","query_name":name,
            "workflow_arguments":wire(&DB::string(&run,"arguments")?),"query_arguments":wire(&arguments),
            "run_status":status,"last_history_sequence":observed,
            "history_observed_sequence":observed,"history_cutoff_sequence":cutoff,
            "query_admitted_at":authority::wire(admitted),"query_deadline_at":authority::wire(deadline),
            "receipt_expires_at":authority::wire(retained_until)});
        // `WorkflowQueryTaskBroker`: the task keeps the request's command
        // context and its principal, which the worker and the answer receive.
        if let Some(command) = command {
            task["command_context"] = command.clone();
            if let Some(principal) = query_principal(command) {
                task["principal"] = principal;
            }
        }
        authority::expire(&mut task, authority::now())?;
        if serde_json::to_vec(&task)?.len() > SNAPSHOT_BYTES {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "query_snapshot_exceeds_budget",
            ));
        }
        Self::query(DB::QUERY_CACHE_INSERT)
            .bind(&key)
            .bind(task.to_string())
            .bind(authority::expiration(&task)?)
            .execute(&mut *tx)
            .await?;
        match Self::list_open(&mut tx, self.namespace.as_ref(), &task_id).await {
            Err(super::RuntimeError::Refused { reason: "query_task_queue_full", .. }) => {
                return Err(queue_full(workflow_id, &run_id, name, &queue, self.namespace.as_ref(), MAX_ENTRIES));
            }
            other => other?,
        }
        Self::file_expiry(&mut tx, &key, authority::expiration(&task)?).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        // Never hold the transition lock or a pooled connection across the wait.
        // Periodic probes observe results committed by another server process.
        let mut last = json!({"status":"pending"});
        loop {
            let wake = self.wake.notified();
            let remaining = (deadline - authority::now()).to_std().unwrap_or_default();
            let read = Self::query(DB::QUERY_CACHE_GET)
                .bind(&key)
                .fetch_optional(&self.pool);
            let row = match tokio::time::timeout(remaining, read).await {
                Err(_) => {
                    return Err(self.query_timeout(&run, workflow_id, &run_id, name, &last, config).await?);
                }
                Ok(row) => row?,
            };
            let Some(row) = row else {
                return Err(refuse(StatusCode::CONFLICT, "query_task_timed_out"));
            };
            let task: Value = serde_json::from_str(&DB::string(&row, "value")?)?;
            match task["status"].as_str() {
                Some("completed") => {
                    let mut answer = json!({"workflow_id":workflow_id,"run_id":run_id,"target_scope":"instance",
                        "query_name":name,"result":task["result"],"result_envelope":task["result_envelope"],"reason":null});
                    if task["principal"].is_object() {
                        answer["principal"] = task["principal"].clone();
                    }
                    return Ok(QueryAttempt::Answered((StatusCode::OK, answer)));
                }
                Some("failed") => {
                    let status = match task["failure"]["reason"].as_str() {
                        Some("rejected_unknown_query") => StatusCode::NOT_FOUND,
                        Some("invalid_query_arguments") => StatusCode::UNPROCESSABLE_ENTITY,
                        Some("query_snapshot_exceeds_budget") => StatusCode::PAYLOAD_TOO_LARGE,
                        _ => StatusCode::CONFLICT,
                    };
                    let mut body = task["failure"].clone();
                    body["workflow_id"] = json!(workflow_id);
                    body["run_id"] = json!(run_id);
                    body["target_scope"] = json!("instance");
                    body["query_name"] = json!(name);
                    body["result"] = Value::Null;
                    if body["message"].as_str().is_none_or(str::is_empty) {
                        body["message"] = json!("Query failed on the worker.");
                    }
                    return Ok(QueryAttempt::Answered((status, body)));
                }
                Some("timed_out") => {
                    return Err(self.query_timeout(&run, workflow_id, &run_id, name, &last, config).await?);
                }
                _ if authority::deadline(&task)
                    .is_some_and(|deadline| authority::now() >= deadline) =>
                {
                    // The stored deadline already fences every new outcome.
                    // Cleanup must not delay the caller behind a busy writer.
                    return Err(self.query_timeout(&run, workflow_id, &run_id, name, &task, config).await?);
                }
                _ if authority::deadline(&task).is_none() => {
                    let mut tx = self.begin().await?;
                    // Recheck under the same lock as completion: a committed
                    // result wins over a racing timeout.
                    let row = Self::query(DB::QUERY_CACHE_GET)
                        .bind(&key)
                        .fetch_one(&mut *tx)
                        .await?;
                    let mut task: Value = serde_json::from_str(&DB::string(&row, "value")?)?;
                    if matches!(task["status"].as_str(), Some("completed" | "failed")) {
                        tx.rollback().await?;
                        continue;
                    }
                    authority::expire(&mut task, authority::now())?;
                    Self::put_query(&mut tx, &key, &task, authority::expiration(&task)?).await?;
                    tx.commit().await?;
                    continue;
                }
                _ => {}
            }
            last = task;
            tokio::select! { _ = wake => {}, _ = tokio::time::sleep(Duration::from_millis(50)) => {} }
        }
    }

    /// `timeoutFailure`: why a query got no answer before its deadline.
    async fn query_timeout(
        &self,
        run: &DB::Row,
        workflow_id: &str,
        run_id: &str,
        name: &str,
        task: &Value,
        config: &QueryTaskConfig,
    ) -> Result<super::RuntimeError> {
        let status = task["status"].as_str().unwrap_or("unknown");
        let (reason, message) = match status {
            "pending" => {
                let route = self.query_route(run, config.worker_stale_after_seconds).await?;
                if !route.servable {
                    return Ok(route_refusal(workflow_id, run_id, name, &route));
                }
                ("query_task_not_claimed", format!(
                    "Timed out waiting for a compatible worker to claim workflow query [{name}] on task queue [{}].",
                    route.task_queue
                ))
            }
            "leased" => ("query_worker_execution_timeout", match task["lease_owner"].as_str().filter(|owner| !owner.is_empty()) {
                Some(owner) => format!("Timed out waiting for worker [{owner}] to complete workflow query [{name}]."),
                None => format!("Timed out waiting for the worker that leased workflow query [{name}] to complete it."),
            }),
            other => ("query_worker_timeout", format!(
                "Timed out waiting for workflow query [{name}] to complete; last query task status was [{other}]."
            )),
        };
        Ok(query_refusal(StatusCode::GATEWAY_TIMEOUT, workflow_id, run_id, name, reason, &message))
    }

    pub(super) async fn poll_query(&self, body: Value, config: &QueryTaskConfig) -> Result<Value> {
        let owner = text(&body, "worker_id")?;
        self.refuse_draining_worker(owner, text(&body, "task_queue")?).await?;
        // `queryPollTimeoutSeconds`: the requested wait, or the worker poll
        // wait (DW_WORKER_POLL_TIMEOUT), capped by DW_QUERY_TASK_POLL_TIMEOUT.
        let timeout = match body.get("timeout_seconds") {
            None | Some(Value::Null) => u64::try_from(config.worker_poll_timeout_seconds).unwrap_or(0),
            Some(value) => super::laravel_validation::integer(value)
                .filter(|v| (0..=60).contains(v))
                .and_then(|v| u64::try_from(v).ok())
                .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_poll_timeout"))?,
        };
        let timeout = timeout.min(config.poll_timeout_seconds.max(1) as u64);
        let deadline = tokio::time::Instant::now() + Duration::from_secs(timeout);
        // One request belongs to the registration observed before its wait.
        // A later same-ID registration cannot silently upgrade this poll.
        let request_id = body
            .get("poll_request_id")
            .filter(|value| !value.is_null())
            .map(|_| text(&body, "poll_request_id"))
            .transpose()?;
        let Some(proof) =
            Self::query_worker_proof(&self.pool, self.namespace.as_ref(), owner, config).await?
        else {
            return Ok(poll_result(None, "stale_worker_registration"));
        };
        // `WorkerPollFence`, as for workflow and activity polls.
        let fence = match self.admit_worker_poll(owner, text(&body, "task_queue")?).await? {
            super::worker_poll_fence::PollAdmission::Stale => return Ok(poll_result(None, "stale_worker_registration")),
            super::worker_poll_fence::PollAdmission::Fenced(fence) => Some(fence),
            super::worker_poll_fence::PollAdmission::Unregistered => None,
        };
        match request_id {
            None => {
                self.wait_for_query(&body, config, &proof, fence.as_ref(), timeout, deadline, None)
                    .await
            }
            Some(request_id) => {
                let keys = self.poll_keys(&body, request_id).await?;
                self.coordinated_query_poll(&body, config, &proof, fence.as_ref(), timeout, deadline, &keys)
                    .await
            }
        }
    }

    // One poll's long wait. With request keys, a newer request from the same
    // worker ends this wait, as in published PHP.
    async fn wait_for_query(
        &self,
        body: &Value,
        config: &QueryTaskConfig,
        proof: &str,
        fence: Option<&super::worker_poll_fence::WorkerPollFence>,
        timeout: u64,
        deadline: tokio::time::Instant,
        keys: Option<&poll_requests::PollKeys>,
    ) -> Result<Value> {
        let mut cross_kind = if timeout > 0 {
            self.poll_wake_snapshot(body, "query").await?
        } else {
            None
        };
        // Only the first probe claims under the lock unconditionally; later
        // probes claim after a lock-free read finds something to claim or refuse.
        let mut first = true;
        let mut held = false;
        let mut pace = super::poll_readiness::ReadPace::new();
        loop {
            let wake = self.wake.notified();
            // `performPoll`: a registration replaced or silenced during the
            // wait ends the poll empty, leasing nothing.
            if let Some(fence) = fence
                && !self.worker_poll_fence_holds(fence, text(body, "worker_id")?).await?
            {
                return Ok(poll_result(None, "empty"));
            }
            if std::mem::take(&mut first) || self.query_poll_ready(body, config, proof, keys).await? {
                match self.claim_query(body, config, proof, fence, keys).await? {
                    QueryClaim::Task(task) => return Ok(poll_result(Some(task), "leased")),
                    QueryClaim::StaleWorker => return Ok(poll_result(None, "empty")),
                    QueryClaim::Superseded => return Ok(poll_result(None, "empty")),
                    // `performPoll`: a query held by a ready resume task ends
                    // the poll at once; one held by a leased task waits.
                    QueryClaim::Blocked("workflow_task_pending") => {
                        return Ok(poll_result(None, "workflow_task_pending"));
                    }
                    QueryClaim::Blocked(_) => held = true,
                    QueryClaim::Empty => held = false,
                }
            }
            if tokio::time::Instant::now() >= deadline {
                return Ok(poll_result(None, if held { "workflow_task_leased" } else { "empty" }));
            }
            if self
                .poll_queue_changed(&body, "query", &mut cross_kind)
                .await?
                && tokio::time::Instant::now() < deadline
            {
                return Ok(
                    json!({"task":null,"poll_status":"task_queue_changed","server_capabilities":super::store::capabilities()}),
                );
            }
            pace.wait(wake, deadline).await;
        }
    }

    async fn query_worker_proof<'e, E>(
        executor: E,
        namespace: &str,
        owner: &str,
        config: &QueryTaskConfig,
    ) -> Result<Option<String>>
    where
        E: Executor<'e, Database = DB>,
    {
        let rows = Self::query(QUERY_WORKER_PROOF_SQL)
            .bind(namespace.to_owned()).bind(owner.to_owned()).fetch_all(executor).await?;
        if rows.len() != 1 {
            return Ok(None);
        }
        let row = &rows[0];
        if DB::string(row, "worker_id")? != owner
            || DB::string(row, "namespace")? != namespace
            || DB::string(row, "incarnation_worker_id")? != owner
            || DB::string(row, "incarnation_namespace")? != namespace
            || DB::instant(row, "last_heartbeat_at")?
                <= QueryTaskConfig::add(
                    authority::now(),
                    -config.worker_stale_after_seconds.max(1),
                )?
        {
            return Ok(None);
        }
        use sha2::{Digest, Sha256};
        let digest: String = Sha256::digest(DB::string(row, "token")?.as_bytes())
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect();
        Ok(Some(digest))
    }

    fn query_delivery(task: &Value) -> Value {
        let mut delivered = task.clone();
        for field in [
            "receipt_expires_at",
            "terminal_receipt",
            "registration_incarnation",
            "legacy_refusal_at",
            "poll_request_id",
        ] {
            delivered
                .as_object_mut()
                .expect("native query task")
                .remove(field);
        }
        delivered["server_time"] = json!(authority::wire(authority::now()));
        delivered
    }

    /// Whether a held query poll should claim again. `proof` is the
    /// registration this poll belongs to.
    async fn query_poll_ready(
        &self,
        body: &Value,
        config: &QueryTaskConfig,
        proof: &str,
        keys: Option<&poll_requests::PollKeys>,
    ) -> Result<bool> {
        let owner = text(body, "worker_id")?;
        // The claim refuses a replaced, removed or stale registration, and
        // ends a poll a newer request from the same worker has replaced.
        if Self::query_worker_proof(&self.pool, self.namespace.as_ref(), owner, config)
            .await?
            .as_deref()
            != Some(proof)
        {
            return Ok(true);
        }
        if let Some(keys) = keys
            && self.poll_request_superseded(keys).await?
        {
            return Ok(true);
        }
        let candidates = self.ready_query_keys(text(body, "task_queue")?).await?;
        if candidates.is_empty() {
            return Ok(false);
        }
        // A query held by a leased workflow task stays held until that task
        // ends, which wakes this poll; reading that needs no write lock.
        let mut read = self.begin_read().await?;
        let mut ready = false;
        for candidate in candidates {
            let Some(row) = Self::query(DB::QUERY_CACHE_GET)
                .bind(&candidate)
                .fetch_optional(&mut *read)
                .await?
            else {
                continue;
            };
            let task: Value = serde_json::from_str(&DB::string(&row, "value")?)?;
            if Self::query_hold(&mut read, &task).await? != Some("workflow_task_leased") {
                ready = true;
                break;
            }
        }
        read.commit().await?;
        Ok(ready)
    }

    async fn claim_query(
        &self,
        body: &Value,
        config: &QueryTaskConfig,
        expected_proof: &str,
        fence: Option<&super::worker_poll_fence::WorkerPollFence>,
        keys: Option<&poll_requests::PollKeys>,
    ) -> Result<QueryClaim> {
        let mut tx = self.begin().await?;
        let claim = self.claim_query_in(&mut tx, body, config, expected_proof, fence, keys, None).await?;
        tx.commit().await?;
        Ok(claim)
    }

    async fn claim_query_in(
        &self,
        tx: &mut Transaction<'_, DB>,
        body: &Value,
        config: &QueryTaskConfig,
        expected_proof: &str,
        fence: Option<&super::worker_poll_fence::WorkerPollFence>,
        keys: Option<&poll_requests::PollKeys>,
        checked: Option<CheckedQueryPoll<'_>>,
    ) -> Result<QueryClaim> {
        let owner = text(body, "worker_id")?;
        let queue = text(body, "task_queue")?;
        let request_id = body
            .get("poll_request_id")
            .filter(|value| !value.is_null())
            .map(|_| text(body, "poll_request_id"))
            .transpose()?;
        let worker = Self::query("SELECT * FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2 AND status='active'")
            .bind(self.namespace.as_ref()).bind(owner)
            .fetch_optional(&mut **tx).await?;
        let Some(worker) = worker else {
            return Ok(QueryClaim::StaleWorker);
        };
        let proof = match checked {
            Some(checked) if checked.proof == expected_proof => checked.proof.to_owned(),
            _ => {
                let proof = Self::query_worker_proof(&mut **tx, self.namespace.as_ref(), owner, config).await?;
                let Some(proof) = proof.filter(|proof| proof == expected_proof) else {
                    return Ok(QueryClaim::StaleWorker);
                };
                if let Some(fence) = fence
                    && !self.worker_poll_fence_holds_in(tx, fence, owner).await?
                {
                    return Ok(QueryClaim::StaleWorker);
                }
                if let Some(keys) = keys
                    && !Self::poll_request_is_current(tx, keys).await?
                {
                    return Ok(QueryClaim::Superseded);
                }
                proof
            }
        };
        if DB::string(&worker, "task_queue")? != queue {
            return Err(refuse(StatusCode::CONFLICT, "task_queue_mismatch"));
        }
        let types = DB::document_row(&worker, "supported_workflow_types")?;
        // A registration without capabilities has none (`stringArray`).
        let capabilities = DB::optional_document_row(&worker, "capabilities")?.unwrap_or_default();
        if !capabilities
            .as_array()
            .is_some_and(|values| values.iter().any(|v| v == "query_tasks"))
        {
            return Ok(QueryClaim::Empty);
        }
        let mut held = None;
        for (key, mut task) in self.query_entries(tx).await? {
            if !matches!(task["status"].as_str(), Some("pending" | "leased"))
                || task["namespace"] != self.namespace.as_ref()
                || authority::deadline(&task).is_none_or(|deadline| deadline <= authority::now())
                || task["task_queue"] != queue
                || !types
                    .as_array()
                    .is_some_and(|types| types.contains(&task["workflow_type"]))
            {
                continue;
            }
            // `queryTaskMatchesPoller` matches the workflow type, not the
            // query name: a worker answers a query it does not declare.
            if task["workflow_type"].as_str().is_none() {
                continue;
            }
            if task["status"] == "leased"
                && authority::timestamp(&task["lease_expires_at"])
                    .is_some_and(|lease| lease > authority::now())
            {
                let existing_owner = task["lease_owner"].as_str().unwrap_or("");
                let existing_proof = Self::query_worker_proof(
                    &mut **tx,
                    self.namespace.as_ref(),
                    existing_owner,
                    config,
                )
                .await?;
                // A live lease belongs to the poll request that claimed it. As in
                // published PHP, only a retry of that same request receives it
                // again; any other poll from the same worker is an independent slot.
                if existing_proof
                    .as_deref()
                    .is_some_and(|proof| task["registration_incarnation"] == proof)
                {
                    if existing_owner == owner
                        && request_id.is_some_and(|id| task["poll_request_id"] == id)
                    {
                        let delivered = Self::query_delivery(&task);
                        return Ok(QueryClaim::Task(delivered));
                    }
                    continue;
                }
            }
            if let Some(hold) = Self::query_hold(tx, &task).await? {
                if held != Some("workflow_task_leased") {
                    held = Some(hold);
                }
                continue;
            }
            if !Self::query_delivery_history(tx, &mut task).await? {
                Self::put_query(tx, &key, &task, authority::expiration(&task)?).await?;
                continue;
            }
            let next_attempt = task["query_task_attempt"]
                .as_i64()
                .and_then(|attempt| attempt.checked_add(1))
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "query_task_attempt_exhausted"))?;
            task["query_task_attempt"] = json!(next_attempt);
            task["status"] = json!("leased");
            task["lease_owner"] = json!(owner);
            let time = authority::now();
            let expires = QueryTaskConfig::add(time, config.lease_timeout_seconds.max(1))?
                .min(authority::deadline(&task).expect("checked original deadline"));
            if expires <= time {
                continue;
            }
            task["lease_expires_at"] = json!(authority::wire(expires));
            task["registration_incarnation"] = json!(proof);
            task["poll_request_id"] = request_id.map_or(Value::Null, |id| json!(id));
            Self::put_query(tx, &key, &task, authority::expiration(&task)?).await?;
            return Ok(QueryClaim::Task(Self::query_delivery(&task)));
        }
        // The caller commits cleanup even when no compatible task is found.
        Ok(held.map_or(QueryClaim::Empty, QueryClaim::Blocked))
    }

    /// `canClaimPendingTaskForPoller`'s run checks, in PHP's order. A live
    /// workflow task lease holds the claim. So does a due resume task (signal,
    /// update, activity, child or timer) whose effect the query cannot see,
    /// except one created after a query with a cutoff was admitted, or a
    /// signal whose `SignalReceived` the query already observed.
    async fn query_hold(conn: &mut DB::Connection, task: &Value) -> Result<Option<&'static str>> {
        let run_id = task["run_id"].as_str().unwrap_or_default();
        let leased = Self::scalar(LEASED_WORKFLOW_TASKS)
            .bind(run_id)
            .bind(DB::bind_time(lease_now()))
            .fetch_one(&mut *conn)
            .await?;
        if leased != 0 {
            return Ok(Some("workflow_task_leased"));
        }
        let ready = Self::query("SELECT payload,available_at,created_at FROM workflow_tasks WHERE workflow_run_id=$1 AND queue=$2 AND task_type='workflow' AND status='ready' ORDER BY id")
            .bind(run_id)
            .bind(task["task_queue"].as_str().unwrap_or_default())
            .fetch_all(&mut *conn)
            .await?;
        let at = super::store::now();
        let cutoff = !task["history_cutoff_sequence"].is_null();
        let admitted = authority::timestamp(&task["query_admitted_at"]);
        let observed = task["history_observed_sequence"].as_i64().unwrap_or(0);
        for row in ready {
            if DB::optional_instant(&row, "available_at")?.is_some_and(|available| available > at) {
                continue;
            }
            if cutoff
                && let (Some(admitted), Some(created)) =
                    (admitted, DB::optional_instant(&row, "created_at")?)
                && created > admitted
            {
                continue;
            }
            let payload = DB::document_row(&row, "payload")?;
            let Some(kind) = resume_kind(&payload) else {
                continue;
            };
            if kind == "workflow_signal"
                && observed >= 1
                && let Some(signal) = payload["workflow_signal_id"]
                    .as_str()
                    .or(payload["resume_source_id"].as_str())
            {
                let received = Self::query(DB::SIGNAL_RECEIVED_SEQUENCE_SQL)
                    .bind(run_id)
                    .bind(signal)
                    .fetch_optional(&mut *conn)
                    .await?;
                if let Some(received) = received
                    && DB::number(&received, "sequence")? <= observed
                {
                    continue;
                }
            }
            return Ok(Some("workflow_task_pending"));
        }
        Ok(None)
    }

    /// `queryTaskPayload`: the history a claim delivers, read at claim time
    /// and never past the admitted cutoff or the run's current history. A run
    /// that grew past native's one bounded page fails the query instead.
    async fn query_delivery_history(conn: &mut DB::Connection, task: &mut Value) -> Result<bool> {
        let run_id = task["run_id"].as_str().unwrap_or_default().to_owned();
        let run = Self::query("SELECT status,last_history_sequence FROM workflow_runs WHERE id=$1")
            .bind(&run_id)
            .fetch_one(&mut *conn)
            .await?;
        let last = DB::number(&run, "last_history_sequence")?;
        let cutoff = task["history_cutoff_sequence"]
            .as_i64()
            .map(|cutoff| cutoff.min(last).max(0));
        let (mut history, next) = Self::history_page_connection(conn, &run_id, 0, 1000).await?;
        let covered = history
            .last()
            .and_then(|event| event["sequence"].as_i64())
            .unwrap_or(0);
        if let Some(cutoff) = cutoff {
            history.retain(|event| event["sequence"].as_i64().is_some_and(|sequence| sequence <= cutoff));
        }
        let complete = next.is_none() || cutoff.is_some_and(|cutoff| covered >= cutoff);
        if !complete || serde_json::to_vec(&history)?.len() > SNAPSHOT_BYTES {
            task["status"] = json!("failed");
            task["failure"] = json!({"reason":"query_snapshot_exceeds_budget",
                "message":"The run's history is larger than one query snapshot."});
            return Ok(false);
        }
        task["run_status"] = json!(DB::string(&run, "status")?);
        task["history_events"] = json!(history);
        task["last_history_sequence"] = json!(cutoff.unwrap_or(last));
        task["history_cutoff_sequence"] = json!(cutoff);
        Ok(true)
    }

    fn cached_query(row: &DB::Row, id: &str, namespace: &str) -> Result<Value> {
        if DB::number(row, "expiration")? <= now() {
            return Err(refuse(StatusCode::NOT_FOUND, "query_task_not_found"));
        }
        let task: Value = serde_json::from_str(&DB::string(row, "value")?)?;
        authority::scope(&task, id, namespace)?;
        Ok(task)
    }

    async fn query_claim_reason<'e, E>(
        executor: E,
        task: &Value,
        owner: &str,
        attempt: i64,
        config: &QueryTaskConfig,
    ) -> Result<Option<&'static str>>
    where
        E: Executor<'e, Database = DB>,
    {
        let reason = authority::claim_reason(task, owner, attempt, authority::now());
        if reason.is_some_and(|reason| reason != "lease_expired") {
            return Ok(reason);
        }
        let proof = Self::query_worker_proof(
            executor,
            task["namespace"].as_str().unwrap_or(""),
            owner,
            config,
        )
        .await?;
        if proof
            .as_deref()
            .is_none_or(|proof| task["registration_incarnation"] != proof)
        {
            return Ok(Some("stale_worker_registration"));
        }
        // Database reads can consume the remaining lease. Recheck against a
        // fresh clock after observing current registration authority.
        Ok(authority::claim_reason(
            task,
            owner,
            attempt,
            authority::now(),
        ))
    }

    pub(super) async fn query_status(
        &self,
        id: &str,
        owner: &str,
        attempt: i64,
        config: &QueryTaskConfig,
    ) -> Result<Value> {
        let key = key(id)?;
        let row = Self::query(DB::QUERY_CACHE_GET)
            .bind(&key)
            .fetch_optional(&self.pool)
            .await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "query_task_not_found"))?;
        let task = Self::cached_query(&row, id, self.namespace.as_ref())?;
        // Observation reveals timing only to the bound claim. It neither
        // expires a row nor writes a tombstone, heartbeat or receipt.
        if task["lease_owner"] != owner {
            return Err(authority::reject_reason("lease_owner_mismatch"));
        }
        if task["query_task_attempt"] != attempt {
            return Err(authority::reject_reason("query_task_attempt_mismatch"));
        }
        let reason = Self::query_claim_reason(&self.pool, &task, owner, attempt, config).await?;
        let status = match reason {
            Some("query_deadline_unavailable") => "failed",
            Some("query_task_timed_out") => "timed_out",
            _ => task["status"].as_str().unwrap_or("failed"),
        };
        Ok(
            json!({"query_task_id":id,"query_task_attempt":attempt,"lease_owner":owner,
            "query_admitted_at":task["query_admitted_at"],"query_deadline_at":task["query_deadline_at"],
            "lease_expires_at":task["lease_expires_at"],"server_time":authority::wire(authority::now()),
            "query_task_status":status,"can_continue":reason.is_none(),"reason":reason,"status":200}),
        )
    }

    pub(super) async fn guard_query_outcome(
        &self,
        id: &str,
        body: &Value,
        failed: bool,
        config: &QueryTaskConfig,
    ) -> Result<Option<Value>> {
        self.query_outcome(id, body, failed, config, false).await
    }

    pub(super) async fn finish_query(
        &self,
        task_id: &str,
        body: Value,
        failed: bool,
        config: &QueryTaskConfig,
    ) -> Result<Value> {
        self.query_outcome(task_id, &body, failed, config, true)
            .await?
            .ok_or_else(|| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_outcome_unavailable"))
    }

    async fn query_outcome(
        &self,
        task_id: &str,
        body: &Value,
        failed: bool,
        config: &QueryTaskConfig,
        commit: bool,
    ) -> Result<Option<Value>> {
        if serde_json::to_vec(&body)?.len() > 512 * 1024 {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "query_completion_exceeds_budget",
            ));
        }
        let key = key(task_id)?;
        let owner = text(body, "lease_owner")?;
        let attempt = body["query_task_attempt"]
            .as_i64()
            .filter(|v| *v > 0)
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_query_task_attempt",
                )
            })?;
        let fingerprint = authority::operation(body, failed)?;
        // Retained decisions are immutable. Reconcile them using a scoped
        // read before acquiring a writer or preparing the result again.
        let observed = Self::query(DB::QUERY_CACHE_GET)
            .bind(&key)
            .fetch_optional(&self.pool)
            .await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "query_task_not_found"))?;
        let observed = Self::cached_query(&observed, task_id, self.namespace.as_ref())?;
        if let Some(receipt) =
            authority::receipt(&observed, owner, attempt, &fingerprint, authority::now())?
        {
            return Ok(Some(receipt));
        }
        // A decision may commit between this read and writer admission. The
        // transaction repeats receipt lookup before any fresh-authority fence.
        let mut tx = self.begin().await?;
        let row = Self::query(DB::QUERY_CACHE_GET)
            .bind(&key)
            .fetch_optional(&mut *tx)
            .await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "query_task_not_found"))?;
        let mut task = Self::cached_query(&row, task_id, self.namespace.as_ref())?;
        if let Some(receipt) =
            authority::receipt(&task, owner, attempt, &fingerprint, authority::now())?
        {
            tx.rollback().await?;
            return Ok(Some(receipt));
        }
        if authority::expire(&mut task, authority::now())? {
            let reason = if authority::deadline(&task).is_none() {
                "query_deadline_unavailable"
            } else {
                "query_task_timed_out"
            };
            Self::put_query(&mut tx, &key, &task, authority::expiration(&task)?).await?;
            tx.commit().await?;
            self.wake.notify_waiters();
            return Err(authority::reject_reason(reason));
        }
        if let Some(reason) =
            Self::query_claim_reason(&mut *tx, &task, owner, attempt, config).await?
        {
            return Err(authority::reject_reason(reason));
        }
        if !commit {
            tx.rollback().await?;
            return Ok(None);
        }
        let reason: Option<String>;
        let failure;
        let result;
        let mut plain = None;
        if failed {
            let supplied = &body["failure"];
            if !supplied.is_object() || serde_json::to_vec(supplied)?.len() > 32 * 1024 {
                return Err(refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_query_failure",
                ));
            }
            reason = Some(
                supplied["reason"]
                    .as_str()
                    .filter(|v| !v.is_empty() && v.len() <= 255)
                    .unwrap_or("query_rejected")
                    .to_owned(),
            );
            failure = Some(supplied.clone());
            result = None;
        } else {
            // `completeQueryTask`: a worker may also send the plain `result`,
            // which published PHP returns to the caller beside the envelope.
            plain = body.get("result").filter(|value| !value.is_null()).cloned();
            let supplied = if plain.is_none() || body.get("result_envelope").is_some_and(|value| !value.is_null()) {
                Some(envelope(body, "result_envelope")?)
            } else {
                None
            };
            let plain_bytes = plain.as_ref().map(serde_json::to_vec).transpose()?.map_or(0, |bytes| bytes.len());
            if supplied.as_ref().map_or(0, String::len) + plain_bytes > RESULT_BYTES {
                return Err(refuse(
                    StatusCode::PAYLOAD_TOO_LARGE,
                    "query_result_exceeds_budget",
                ));
            }
            result = supplied.map(|supplied| wire(&supplied));
            failure = None;
            reason = None;
        }
        let decided_at = authority::now();
        if let Some(reason) = authority::claim_reason(&task, owner, attempt, decided_at) {
            return Err(authority::reject_reason(reason));
        }
        let decision = json!({"query_task_id":task_id,"query_task_attempt":attempt,
            "outcome":if failed {"failed"} else {"completed"},"reason":reason,"status":200,"recorded":true});
        task["status"] = json!(if failed { "failed" } else { "completed" });
        if let Some(failure) = failure {
            task["failure"] = failure;
            task["failure"]["reason"] = json!(reason);
        }
        if let Some(result) = result {
            task["result_envelope"] = result;
        }
        if let Some(plain) = plain {
            task["result"] = plain;
        }
        task["terminal_receipt"] = json!({"lease_owner":owner,"attempt":attempt,
            "fingerprint":fingerprint,"decision":decision,"decided_at":authority::wire(decided_at)});
        // Results reserve their slot at admission, and discard the large replay
        // snapshot. No workflow rows, histories, cursors or waits are changed.
        authority::discard_snapshot(&mut task);
        Self::put_query(&mut tx, &key, &task, authority::expiration(&task)?).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(Some(decision))
    }
}

/// `commandContextPrincipal`: the context principal's type, id and label.
fn query_principal(command: &Value) -> Option<Value> {
    let principal = &command["context"]["principal"];
    let fields: serde_json::Map<String, Value> = ["type", "id", "label"]
        .into_iter()
        .filter_map(|field| {
            principal[field].as_str().filter(|value| !value.is_empty()).map(|value| (field.to_owned(), json!(value)))
        })
        .collect();
    (!fields.is_empty()).then_some(Value::Object(fields))
}
