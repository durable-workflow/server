//! Server 2.10.0's `WorkflowDefinitionRouting` and
//! `WorkflowReplayEligibility`. A run whose start recorded the definition
//! fingerprint its worker cohort agreed on (`workflow_definition_fingerprint_source`
//! `worker`) is pinned to that definition: its workflow tasks go to a worker
//! advertising the recorded fingerprint. A worker with another definition gets
//! them only when no live peer advertises the recorded one, and only if it
//! validates entry arguments (`workflow_entry_arguments_validation`), so it can
//! safely refuse incompatible input on replay.
//!
//! Native refuses to start with `DW_V2_PIN_TO_RECORDED_FINGERPRINT` turned off,
//! so pinning is always enabled here.
use super::transaction::Transaction;
use super::{
    Result,
    backend::Backend,
    store::{Store, now},
};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

/// The worker capability that lets a changed definition replay a pinned run.
pub(super) const ENTRY_VALIDATION_CAPABILITY: &str = "workflow_entry_arguments_validation";

/// PHP's `stringValue`: a trimmed, non-empty string.
fn trimmed(value: Option<&str>) -> Option<String> {
    value.map(str::trim).filter(|value| !value.is_empty()).map(str::to_owned)
}

fn has_capability(capabilities: &Value, capability: &str) -> bool {
    capabilities.as_array().is_some_and(|items| items.iter().any(|item| item == capability))
}

/// `context`: the run's recorded definition and where a decision applies.
pub(super) struct DefinitionContext {
    pub(super) worker_pinned: bool,
    pub(super) recorded: Option<String>,
    pub(super) namespace: String,
    pub(super) workflow_type: String,
    pub(super) task_queue: String,
    pub(super) required_build_id: Option<String>,
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
    pub(super) async fn definition_context(
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        task_queue: Option<&str>,
        required_build_id: Option<String>,
    ) -> Result<DefinitionContext> {
        let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(DB::string(run, "id")?)
            .fetch_optional(&mut **tx)
            .await?
            .map(|row| DB::document_row(&row, "payload"))
            .transpose()?
            .unwrap_or(Value::Null);
        Ok(DefinitionContext {
            worker_pinned: started["workflow_definition_fingerprint_source"] == "worker",
            recorded: trimmed(started["workflow_definition_fingerprint"].as_str()),
            namespace: DB::string(run, "namespace")?,
            workflow_type: DB::string(run, "workflow_type")?,
            task_queue: match task_queue {
                Some(queue) => queue.to_owned(),
                None => DB::string(run, "queue")?,
            },
            required_build_id: required_build_id.or(trimmed(DB::optional_string(run, "compatibility")?.as_deref())),
        })
    }

    /// `forWorker`: whether this worker may take the run's workflow task, and
    /// PHP's status for the decision.
    pub(super) async fn definition_allows(
        &self,
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        worker: &DB::Row,
        context: &DefinitionContext,
    ) -> Result<(bool, &'static str)> {
        if !context.worker_pinned {
            return Ok((true, "not_worker_pinned"));
        }
        let Some(recorded) = &context.recorded else {
            return Ok((false, "recorded_definition_missing"));
        };
        let advertised = DB::optional_document_row(worker, "workflow_definition_fingerprints")?
            .and_then(|fingerprints| trimmed(fingerprints[&context.workflow_type].as_str()));
        let Some(advertised) = advertised else {
            return Ok((false, "worker_definition_missing"));
        };
        if &advertised == recorded {
            return Ok((true, "matching_definition"));
        }
        for (_, fingerprint, _) in self.definition_peers(tx, run, context).await? {
            if fingerprint.as_deref() == Some(recorded.as_str()) {
                return Ok((false, "matching_definition_preferred"));
            }
        }
        // Match the embedded resolver: prefer the recorded source when it is
        // available, otherwise let the current definition validate replay.
        let capabilities = DB::optional_document_row(worker, "capabilities")?.unwrap_or(Value::Null);
        Ok(if has_capability(&capabilities, ENTRY_VALIDATION_CAPABILITY) {
            (true, "replay_required")
        } else {
            (false, "entry_validation_unavailable")
        })
    }

    /// `eligibleWorkers`: the live workers on the run's queue (and build) that
    /// handle its type, take workflow tasks and can replay its history. Each
    /// is its id, advertised fingerprint and capabilities.
    pub(super) async fn definition_peers(
        &self,
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        context: &DefinitionContext,
    ) -> Result<Vec<(String, Option<String>, Value)>> {
        let rows = Self::query("SELECT worker_id,build_id,status,last_heartbeat_at,workflow_definition_fingerprints,supported_workflow_types,max_concurrent_workflow_tasks,capabilities,process_metrics FROM workflow_worker_registrations WHERE namespace=$1 AND task_queue=$2 AND status='active' ORDER BY worker_id")
            .bind(&context.namespace)
            .bind(&context.task_queue)
            .fetch_all(&mut **tx)
            .await?;
        let mut peers = Vec::new();
        for row in rows {
            if context
                .required_build_id
                .as_deref()
                .is_some_and(|build| DB::optional_string(&row, "build_id").ok().flatten().as_deref() != Some(build))
            {
                continue;
            }
            let types = DB::optional_document_row(&row, "supported_workflow_types")?.unwrap_or(Value::Null);
            if !types.as_array().is_some_and(|types| types.iter().any(|kind| kind == context.workflow_type.as_str()))
                || DB::optional_number(&row, "max_concurrent_workflow_tasks")?.is_some_and(|max| max < 1)
            {
                continue;
            }
            if !self.replay_eligible(tx, run, &row).await? {
                continue;
            }
            let fingerprint = DB::optional_document_row(&row, "workflow_definition_fingerprints")?
                .and_then(|fingerprints| trimmed(fingerprints[&context.workflow_type].as_str()));
            let capabilities = DB::optional_document_row(&row, "capabilities")?.unwrap_or(Value::Null);
            peers.push((DB::string(&row, "worker_id")?, fingerprint, capabilities));
        }
        Ok(peers)
    }

    /// `WorkflowReplayEligibility::allows` for a registered worker, with the
    /// protocol version it last registered or heartbeated with.
    async fn replay_eligible(&self, tx: &mut Transaction<'_, DB>, run: &DB::Row, worker: &DB::Row) -> Result<bool> {
        // `WorkerPollFence::isFresh`: active, with a heartbeat inside the
        // stale window.
        let fresh = DB::string(worker, "status")? == "active"
            && DB::optional_instant(worker, "last_heartbeat_at")?.is_some_and(|heartbeat| {
                heartbeat >= now() - chrono::Duration::seconds(self.worker_stale_after_seconds)
            });
        if !fresh {
            return Ok(false);
        }
        let capabilities = DB::optional_document_row(worker, "capabilities")?.unwrap_or(Value::Null);
        let protocol = DB::optional_document_row(worker, "process_metrics")?
            .and_then(|metrics| trimmed(metrics[super::worker_management::PROTOCOL_METRIC].as_str()));
        if Self::cancellation_request(run)?.is_some()
            && !super::cooperative::supports(&capabilities, protocol.as_deref().unwrap_or(""))
        {
            return Ok(false);
        }
        // `WorkflowMetadataCapabilityPolicy::canReplayRun`: memo history needs
        // `memo_upserts`, typed search attribute history needs typed upserts.
        let run_id = DB::string(run, "id")?;
        if !has_capability(&capabilities, "memo_upserts")
            && Self::scalar("SELECT COUNT(*) FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='MemoUpserted'")
                .bind(&run_id)
                .fetch_one(&mut **tx)
                .await?
                > 0
        {
            return Ok(false);
        }
        if !has_capability(&capabilities, super::search_attribute_upserts::CAPABILITY) {
            let typed = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='SearchAttributesUpserted' ORDER BY sequence LIMIT 1001")
                .bind(&run_id)
                .fetch_all(&mut **tx)
                .await?;
            for event in &typed {
                if DB::document_row(event, "payload")?.get("attribute_types").is_some() {
                    return Ok(false);
                }
            }
        }
        Ok(true)
    }

    /// `inspect`: how the run's next ready workflow task would be routed,
    /// with counts of the eligible workers by definition, for describe,
    /// repair and debug.
    pub(super) async fn definition_inspect(
        &self,
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        task_queue: Option<&str>,
    ) -> Result<Value> {
        let run_id = DB::string(run, "id")?;
        let task = Self::query("SELECT queue,compatibility FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status='ready' AND (available_at IS NULL OR available_at<=$2) ORDER BY id")
            .bind(&run_id)
            .bind(DB::bind_time(now()))
            .fetch_all(&mut **tx)
            .await?
            .into_iter()
            .find(|task| task_queue.is_none_or(|queue| DB::optional_string(task, "queue").ok().flatten().as_deref() == Some(queue)));
        let (queue, required) = match &task {
            Some(task) => (
                task_queue.map(str::to_owned).or(DB::optional_string(task, "queue")?),
                trimmed(DB::optional_string(task, "compatibility")?.as_deref()),
            ),
            None => (task_queue.map(str::to_owned), None),
        };
        let context = Self::definition_context(tx, run, queue.as_deref(), required).await?;
        let (mut matching, mut changed, mut missing, mut unsafe_replacement) = (0, 0, 0, 0);
        let mut examples = Vec::new();
        let peers = self.definition_peers(tx, run, &context).await?;
        for (worker_id, fingerprint, capabilities) in &peers {
            match fingerprint {
                None => missing += 1,
                Some(fingerprint) if Some(fingerprint) == context.recorded.as_ref() => matching += 1,
                Some(_) => {
                    changed += 1;
                    if !has_capability(capabilities, ENTRY_VALIDATION_CAPABILITY) {
                        unsafe_replacement += 1;
                    }
                }
            }
            if examples.len() < 10 {
                examples.push(json!({"worker_id": worker_id, "fingerprint": fingerprint}));
            }
        }
        let eligible = matching + changed + missing;
        let status = match () {
            _ if !context.worker_pinned => "not_worker_pinned",
            _ if context.recorded.is_none() => "recorded_definition_missing",
            _ if matching > 0 => "matching_definition_available",
            _ if changed > unsafe_replacement => "replay_required",
            _ if unsafe_replacement > 0 => "entry_validation_unavailable",
            _ if missing > 0 => "worker_definition_missing",
            _ => "no_eligible_worker",
        };
        let terminal = !matches!(DB::string(run, "status")?.as_str(), "pending" | "running" | "waiting");
        let runnable = !terminal && task.is_some();
        let blocked = runnable
            && context.worker_pinned
            && matches!(status, "recorded_definition_missing" | "worker_definition_missing" | "no_eligible_worker" | "entry_validation_unavailable");
        let resolution = match () {
            _ if blocked && status == "entry_validation_unavailable" => "Upgrade the replacement worker SDK to an implementation advertising workflow_entry_arguments_validation, or restore the recorded definition. The current replacement cannot safely refuse incompatible entry input.",
            _ if blocked => "Register a fresh worker for the original namespace, task queue, workflow type, build and replay capabilities with its definition fingerprint. Do not rewrite the run history.",
            _ if status == "replay_required" => "Current workers may replay the original history. Use versioned branches for command changes; incompatible replay still refuses.",
            _ => "Recorded build, namespace, type and replay-capability guards remain in force.",
        };
        Ok(json!({
            "pinning_enabled": true, "worker_pinned": context.worker_pinned,
            "recorded_fingerprint": context.recorded, "workflow_type": context.workflow_type,
            "task_queue": context.task_queue, "required_build_id": context.required_build_id,
            "status": status, "blocked": blocked, "runnable_task": runnable,
            "eligible_worker_count": eligible, "matching_worker_count": matching,
            "changed_worker_count": changed, "missing_fingerprint_count": missing,
            "entry_validation_unavailable_count": unsafe_replacement,
            "workers": examples, "workers_truncated": eligible > examples.len(),
            "resolution": resolution,
        }))
    }

    /// `forQueue`: bounded examples of the queue's runs with a due ready
    /// workflow task, never a repair or a payload read.
    pub(super) async fn definition_for_queue(&self, tx: &mut Transaction<'_, DB>, task_queue: &str) -> Result<Value> {
        let runs = Self::query("SELECT * FROM workflow_runs WHERE namespace=$1 AND queue=$2 AND id IN (SELECT workflow_run_id FROM workflow_tasks WHERE task_type='workflow' AND status='ready' AND queue=$3 AND (available_at IS NULL OR available_at<=$4)) ORDER BY id LIMIT 11")
            .bind(self.namespace.as_ref())
            .bind(task_queue)
            .bind(task_queue)
            .bind(DB::bind_time(now()))
            .fetch_all(&mut **tx)
            .await?;
        let mut examples = Vec::new();
        for run in runs.iter().take(10) {
            if !matches!(DB::string(run, "status")?.as_str(), "pending" | "running" | "waiting") {
                continue;
            }
            let mut example = json!({"workflow_id": DB::string(run, "workflow_instance_id")?, "run_id": DB::string(run, "id")?});
            if let (Some(example), Value::Object(inspected)) =
                (example.as_object_mut(), self.definition_inspect(tx, run, Some(task_queue)).await?)
            {
                example.extend(inspected);
            }
            examples.push(example);
        }
        let blocked = examples.iter().filter(|row| row["blocked"] == true).count();
        Ok(json!({"sample_limit": 10, "has_more_ready_runs": runs.len() > 10,
            "blocked_sample_count": blocked, "runs": examples}))
    }
}
