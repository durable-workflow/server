//! Sequential child outcomes and abandoned-parent cleanup retain the original call.
//! A retried child keeps the call under a new link (`child_retries`).
//! Parallel child groups remain unqualified and are refused at admission
//! rather than silently discarded.
use super::schedule_storage::iso8601;
use super::transaction::Transaction;
use super::{
    Result,
    backend::Backend,
    envelope, refuse,
    store::{Store, id, now, reject_fields},
    text, wire,
};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

#[derive(Clone, Copy)]
pub(super) enum ChildResolution<'a> {
    Completed(&'a str),
    Cancelled(&'a Value),
    Terminated(&'a Value),
    Failed(&'a Value),
}

enum ChildParentResume {
    New,
    Existing { id: String, status: String },
    UpdateOnly,
}

pub(super) fn validate_child(command: &Value) -> Result<()> {
    reject_fields(
        command,
        &[
            "type",
            "workflow_type",
            "arguments",
            "queue",
            "parent_close_policy",
            "cancellation_policy",
            "retry_policy",
            "execution_timeout_seconds",
            "run_timeout_seconds",
        ],
    )?;
    if text(command, "workflow_type")?.len() > 191
        || command.get("queue").is_some_and(|queue| {
            queue
                .as_str()
                .is_none_or(|queue| queue.trim().is_empty() || queue.len() > 191)
        })
    {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_child_options",
        ));
    }
    policies(command)?;
    envelope(command, "arguments")?;
    // A deadline or backoff too far away for a timestamp is refused, as a
    // timer delay or an activity backoff is (PHP's Carbon wraps it).
    let now = now();
    for field in ["execution_timeout_seconds", "run_timeout_seconds"] {
        if let Some(seconds) = super::child_retries::timeout(command, field)
            && super::child_retries::after(now, seconds).is_none()
        {
            return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_child_timeout"));
        }
    }
    // PHP reads a backoff object's values as a list (`array_values`).
    if let Some(mut backoff) = super::child_retries::list(&command["retry_policy"]["backoff_seconds"])
        && backoff.any(|entry| entry.as_i64().is_some_and(|seconds| super::child_retries::after(now, seconds).is_none()))
    {
        return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_child_retry_policy"));
    }
    Ok(())
}

const PARENT_CLOSE_POLICIES: [&str; 4] = ["abandon", "request_cancel", "request_cancellation", "terminate"];
const CANCELLATION_POLICIES: [&str; 3] = ["try_cancel", "wait_cancellation_completed", "abandon"];

/// A child's parent-close and cancellation policies.
pub(super) struct ChildPolicies {
    pub(super) parent_close: String,
    pub(super) cancellation: String,
}

impl ChildPolicies {
    /// `WorkerController::childCancellationPolicyGuard`: these policies need
    /// a cooperative claim on protocol 1.20.
    pub(super) fn cooperative(&self) -> bool {
        self.parent_close == "request_cancellation" || self.cancellation != "abandon"
    }
}

/// Published PHP's rules for the two policies. Laravel trims the request and
/// turns blank strings into null, so a blank policy is the default `abandon`.
/// The request rules (`nullable|string`, and `in:` for the cancellation
/// policy) refuse first; the command normalizer then checks the parent-close
/// value.
pub(super) fn policies(command: &Value) -> Result<ChildPolicies> {
    let mut errors = Vec::new();
    let mut read = |field: &str| match command.get(field) {
        None | Some(Value::Null) => None,
        Some(Value::String(text)) => Some(text.trim().to_owned()).filter(|text| !text.is_empty()),
        Some(_) => {
            errors.push((format!("commands.0.{field}"), vec![format!("The commands.0.{field} field must be a string.")]));
            None
        }
    };
    let parent_close = read("parent_close_policy");
    let cancellation = read("cancellation_policy");
    if cancellation.as_deref().is_some_and(|value| !CANCELLATION_POLICIES.contains(&value)) {
        errors.push(("commands.0.cancellation_policy".into(), vec!["The selected commands.0.cancellation_policy is invalid.".into()]));
    }
    if let Some(error) = super::laravel_validation::failure_messages(errors) {
        return Err(error);
    }
    if parent_close.as_deref().is_some_and(|value| !PARENT_CLOSE_POLICIES.contains(&value)) {
        let message = "The parent_close_policy must be one of: abandon, request_cancel, request_cancellation, terminate.";
        return Err(super::laravel_validation::failure(vec![("commands.0.parent_close_policy", vec![message])]).expect("one message"));
    }
    Ok(ChildPolicies {
        parent_close: parent_close.unwrap_or_else(|| "abandon".into()),
        cancellation: cancellation.unwrap_or_else(|| "abandon".into()),
    })
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
    async fn child_parent_resume(
        tx: &mut Transaction<'_, DB>,
        parent: &DB::Row,
    ) -> Result<ChildParentResume> {
        let tasks = Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased') ORDER BY id LIMIT 1001")
            .bind(DB::string(parent,"id")?).fetch_all(&mut **tx).await?;
        if tasks.is_empty() {
            return Ok(ChildParentResume::New);
        }
        if Self::cancellation_request(parent)?.is_none() {
            // `dispatchParentResumeTasks`: a ready workflow task already
            // replays the parent with this resolution in its history, so it
            // serves as the resume and its payload stays as it is. An update
            // task does not replay the workflow. A leased task's worker may
            // have read history before this resolution, and published PHP
            // then leaves the parent without a task until repair; native
            // gives the resolution its own resume task in both cases.
            for task in &tasks {
                if DB::string(task, "status")? == "ready"
                    && DB::document_row(task, "payload")?["workflow_update_id"]
                        .as_str()
                        .is_none()
                {
                    return Ok(ChildParentResume::Existing {
                        id: DB::string(task, "id")?,
                        status: "ready".into(),
                    });
                }
            }
            return Ok(ChildParentResume::New);
        }
        if tasks.len() > 1000 {
            return Err(refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "cancellation_inventory_exceeds_budget",
            ));
        }
        for task in tasks {
            if DB::document_row(&task, "payload")?["workflow_update_id"]
                .as_str()
                .is_none()
            {
                return Ok(ChildParentResume::Existing {
                    id: DB::string(&task, "id")?,
                    status: DB::string(&task, "status")?,
                });
            }
        }
        // An update claim does not replay the root workflow. Preserve the
        // unavailable association instead of presenting it as a root resume.
        Ok(ChildParentResume::UpdateOnly)
    }

    /// A child link whose call row agrees with it, and its recorded parent-close
    /// and cancellation policies (PHP reads a missing cancellation policy as
    /// `abandon`), or `None`.
    pub(super) async fn child_link_policies(
        tx: &mut Transaction<'_, DB>,
        link: &DB::Row,
    ) -> Result<Option<(String, String)>> {
        if DB::string(link, "link_type")? != "child_workflow" {
            return Ok(None);
        }
        let Some(parent_close) = DB::optional_string(link, "parent_close_policy")? else {
            return Ok(None);
        };
        let calls = Self::query("SELECT * FROM workflow_child_calls WHERE parent_workflow_run_id=$1 AND sequence=$2 LIMIT 2")
            .bind(DB::string(link,"parent_workflow_run_id")?).bind(DB::number(link,"sequence")?)
            .fetch_all(&mut **tx).await?;
        let Some(call) = calls.first().filter(|_| calls.len() == 1) else {
            return Ok(None);
        };
        let metadata = DB::document_row(call, "metadata")?;
        let cancellation = metadata["cancellation_policy"].as_str().unwrap_or("abandon").to_owned();
        // A retried call (`markChildCallRetryStarted`) has a link per attempt:
        // the first under the call's id, each retry under its own, and the
        // call names the latest attempt's run.
        let retried = metadata["attempt_count"].as_i64().is_some_and(|attempts| attempts > 1);
        let consistent = DB::string(call, "parent_workflow_instance_id")?
            == DB::string(link, "parent_workflow_instance_id")?
            && DB::optional_string(call, "resolved_child_instance_id")?
                == Some(DB::string(link, "child_workflow_instance_id")?)
            && (retried
                || DB::optional_string(call, "resolved_child_run_id")?
                    == Some(DB::string(link, "child_workflow_run_id")?))
            && DB::optional_string(call, "parent_close_policy")?.as_deref() == Some(parent_close.as_str())
            && (retried || metadata["child_call_id"] == DB::string(link, "id")?)
            && matches!(
                DB::string(call, "status")?.as_str(),
                "started" | "completed" | "failed" | "cancelled"
            );
        Ok(consistent.then_some((parent_close, cancellation)))
    }

    /// `externalCommandContract`: the type's contract from the most recently
    /// heartbeated active worker, in the start's cohort when it has one.
    pub(super) async fn attach_workflow_contract(
        tx: &mut Transaction<'_, DB>,
        queue: &str,
        workflow_type: &str,
        cohort: Option<&super::start_definition::Cohort>,
        started: &mut Value,
    ) -> Result<()> {
        let instance = Self::query("SELECT namespace FROM workflow_instances WHERE id=$1")
            .bind(text(started, "workflow_instance_id")?)
            .fetch_one(&mut **tx)
            .await?;
        let namespace = DB::string(&instance, "namespace")?;
        let workers = Self::query("SELECT build_id,supported_workflow_types,workflow_command_contracts FROM workflow_worker_registrations WHERE task_queue=$1 AND status='active' AND workflow_command_contracts IS NOT NULL AND last_heartbeat_at>$2 AND namespace=$3 ORDER BY last_heartbeat_at DESC,id DESC LIMIT 100")
            .bind(queue).bind(DB::bind_time(now() - chrono::Duration::seconds(60))).bind(&namespace).fetch_all(&mut **tx).await?;
        for worker in workers {
            let build = DB::optional_string(&worker, "build_id")?;
            if cohort.is_some_and(|cohort| !cohort.admits(build.as_deref())) {
                continue;
            }
            if DB::document_row(&worker, "supported_workflow_types")?
                .as_array()
                .is_some_and(|types| types.iter().any(|value| value == workflow_type))
            {
                let contracts = DB::document_row(&worker, "workflow_command_contracts")?;
                if let Some(contract) = contracts.get(workflow_type) {
                    // Older development registrations may predate validation.
                    // Never turn a malformed entry into authoritative empties.
                    let Ok(contract) = super::worker_contracts::declaration(contract) else {
                        continue;
                    };
                    for (source, target) in [
                        ("signals", "declared_signals"),
                        ("signal_contracts", "declared_signal_contracts"),
                        ("queries", "declared_queries"),
                        ("query_contracts", "declared_query_contracts"),
                        ("updates", "declared_updates"),
                        ("update_contracts", "declared_update_contracts"),
                        ("update_validators", "declared_update_validators"),
                    ] {
                        if let Some(value) = contract.get(source) {
                            started[target] = value.clone();
                        }
                    }
                    // WorkflowStartService's defaults for an external worker's
                    // contract, which make it a complete durable contract.
                    started["declared_entry_method"] = json!("handle");
                    started["declared_entry_mode"] = json!("canonical");
                    started["declared_entry_declaring_class"] = json!(format!("external:{workflow_type}"));
                    break;
                }
            }
        }
        Ok(())
    }

    pub(super) async fn start_child(
        tx: &mut Transaction<'_, DB>,
        parent: &DB::Row,
        task_id: &str,
        command: &Value,
        sequence: i64,
    ) -> Result<String> {
        let parent_run = DB::string(parent, "id")?;
        let parent_instance = DB::string(parent, "workflow_instance_id")?;
        // Published PHP starts a child whatever else the parent has open:
        // activities, timers, waits and other children. A replayed completion
        // is handled by the enclosing immutable task receipt.
        let workflow_type = text(command, "workflow_type")?;
        let queue = command
            .get("queue")
            .map_or(Ok(DB::string(parent, "queue")?), |_| {
                text(command, "queue").map(str::to_owned)
            })?;
        let arguments = envelope(command, "arguments")?;
        let policies = policies(command)?;
        let child_instance = id();
        let child_run = id();
        let call = id();
        // A child lives in its parent's namespace (ChildWorkflowNamespaceProjection).
        let namespace = DB::optional_string(parent, "namespace")?;
        let started_at = now();
        // `ChildCallService`: a child keeps its parent's build pin. A child of
        // an unpinned parent is unpinned too (checked against published 2.11.1).
        let compatibility = DB::optional_string(parent, "compatibility")?.filter(|build| !build.trim().is_empty());
        // `applyStartChildWorkflow`: each deadline follows its own timeout.
        // A child without timeout options has no budget or deadline, whatever
        // its parent's.
        let execution_timeout = super::child_retries::timeout(command, "execution_timeout_seconds");
        let run_timeout = super::child_retries::timeout(command, "run_timeout_seconds");
        let execution_deadline = super::child_retries::deadline(started_at, execution_timeout)?;
        let run_deadline = super::child_retries::deadline(started_at, run_timeout)?;
        let retry_policy = super::child_retries::retry_snapshot(command);
        let timeout_policy = super::child_retries::timeout_snapshot(execution_timeout, run_timeout);
        Self::query("INSERT INTO workflow_instances(id,namespace,workflow_type,workflow_class,current_run_id,run_count,started_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,1,$6,$7,$8)")
            .bind(&child_instance).bind(namespace.as_deref()).bind(workflow_type).bind(workflow_type).bind(&child_run).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).execute(&mut **tx).await?;
        Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,namespace,workflow_type,workflow_class,run_number,status,payload_codec,arguments,queue,started_at,created_at,updated_at,last_progress_at,compatibility,run_timeout_seconds,execution_deadline_at,run_deadline_at) VALUES ($1,$2,$3,$4,$5,1,'pending','avro',$6,$7,$8,$9,$10,$11,$12,NULLIF($13,0),$14,$15)")
            .bind(&child_run).bind(&child_instance).bind(namespace.as_deref()).bind(workflow_type).bind(workflow_type).bind(&arguments).bind(&queue).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(compatibility.as_deref())
            // Timeouts are at least one second; zero binds "no timeout".
            .bind(run_timeout.unwrap_or(0)).bind(DB::optional_time(execution_deadline)).bind(DB::optional_time(run_deadline)).execute(&mut **tx).await?;
        // `inheritTypedVisibilityMetadata`: the child takes its parent's memos
        // and search attributes.
        let memos = Self::inheritable_memos(tx, &parent_run).await?;
        Self::inherit_visibility(tx, memos, &parent_run, &child_run, &child_instance, started_at).await?;
        Self::query("INSERT INTO workflow_child_calls(parent_workflow_run_id,parent_workflow_instance_id,sequence,child_workflow_type,child_workflow_class,resolved_child_instance_id,resolved_child_run_id,parent_close_policy,cancellation_propagation,queue,status,scheduled_at,started_at,arguments,metadata,created_at,updated_at,retry_policy,timeout_policy) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,($9<>'abandon'),$10,'started',$11,$12,$13,$14,$15,$16,$17,$18)")
            .bind(&parent_run).bind(&parent_instance).bind(sequence).bind(workflow_type).bind(workflow_type).bind(&child_instance).bind(&child_run).bind(&policies.parent_close).bind(&policies.cancellation).bind(&queue).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at))
            .bind(DB::document(&json!({"payload":arguments}))).bind(DB::document(&json!({"child_call_id":call,"attempt_count":1,"cancellation_policy":policies.cancellation}))).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at))
            .bind(retry_policy.as_ref().map(DB::document)).bind(timeout_policy.as_ref().map(DB::document)).execute(&mut **tx).await?;
        Self::query("INSERT INTO workflow_links(id,link_type,sequence,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,child_workflow_run_id,is_primary_parent,parent_close_policy,created_at,updated_at) VALUES ($1,'child_workflow',$2,$3,$4,$5,$6,TRUE,$7,$8,$9)")
            .bind(&call).bind(sequence).bind(&parent_instance).bind(&parent_run).bind(&child_instance).bind(&child_run).bind(&policies.parent_close).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).execute(&mut **tx).await?;
        let scheduled = json!({"sequence":sequence,"workflow_link_id":call,"child_call_id":call,"child_workflow_instance_id":child_instance,"child_workflow_run_id":child_run,"child_workflow_class":workflow_type,"child_workflow_type":workflow_type,"parent_close_policy":policies.parent_close,"cancellation_policy":policies.cancellation,"retry_policy":retry_policy,"timeout_policy":timeout_policy});
        Self::append(
            tx,
            &parent_run,
            "ChildWorkflowScheduled",
            scheduled.clone(),
            Some(task_id),
            None,
        )
        .await?;
        let deadlines = json!({"execution_timeout_seconds":execution_timeout,"run_timeout_seconds":run_timeout,
            "execution_deadline_at":execution_deadline.map(iso8601),"run_deadline_at":run_deadline.map(iso8601)});
        let mut started = scheduled;
        started["child_run_number"] = json!(1);
        for (field, value) in deadlines.as_object().expect("an object") {
            started[field] = value.clone();
        }
        Self::append(
            tx,
            &parent_run,
            "ChildRunStarted",
            started,
            Some(task_id),
            None,
        )
        .await?;
        let mut child_started = json!({"workflow_class":workflow_type,"workflow_type":workflow_type,"workflow_instance_id":child_instance,"workflow_run_id":child_run,"parent_workflow_instance_id":parent_instance,"parent_workflow_run_id":parent_run,"parent_sequence":sequence,"workflow_link_id":call,"child_call_id":call,"retry_policy":retry_policy,"timeout_policy":timeout_policy});
        for (field, value) in deadlines.as_object().expect("an object") {
            child_started[field] = value.clone();
        }
        Self::attach_workflow_contract(tx, &queue, workflow_type, None, &mut child_started).await?;
        Self::append(tx, &child_run, "WorkflowStarted", child_started, None, None).await?;
        let child_task = Self::create_task(tx, &child_run, &queue, "workflow", json!({})).await?;
        Self::query("UPDATE workflow_runs SET status='waiting' WHERE id=$1")
            .bind(&parent_run)
            .execute(&mut **tx)
            .await?;
        Ok(child_task)
    }

    pub(super) async fn complete_child(
        tx: &mut Transaction<'_, DB>,
        child: &DB::Row,
        output: &str,
        closed_at: DateTime<Utc>,
    ) -> Result<()> {
        Self::resolve_child(tx, child, ChildResolution::Completed(output), closed_at).await
    }

    pub(super) async fn resolve_child(
        tx: &mut Transaction<'_, DB>,
        child: &DB::Row,
        resolution: ChildResolution<'_>,
        closed_at: DateTime<Utc>,
    ) -> Result<()> {
        let child_run = DB::string(child, "id")?;
        let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(&child_run).fetch_one(&mut **tx).await?;
        let original = DB::document_row(&started, "payload")?;
        let links = Self::query("SELECT * FROM workflow_links WHERE child_workflow_run_id=$1 AND link_type='child_workflow' LIMIT 2").bind(&child_run).fetch_all(&mut **tx).await?;
        if links.is_empty() && original["parent_workflow_run_id"].is_null() {
            return Ok(());
        }
        if links.is_empty() {
            return Err(refuse(StatusCode::CONFLICT, "child_call_mismatch"));
        }
        if links.len() != 1 {
            return Err(refuse(
                StatusCode::CONFLICT,
                "unsupported_child_parent_links",
            ));
        }
        let link = &links[0];
        let parent_run = DB::string(link, "parent_workflow_run_id")?;
        let sequence = DB::number(link, "sequence")?;
        let link_id = DB::string(link, "id")?;
        // `createParentResumeTasks` resolves only an open parent run, before
        // reading the call: a closed or replaced parent takes no resolution,
        // and its call stays as the parent left it. An abandoned child, or a
        // child that continued as new after its parent closed (its call still
        // names the first run, `markChildCallContinued`), closes then.
        let Some(parent) = Self::query("SELECT r.*,i.current_run_id FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE r.id=$1")
            .bind(&parent_run).fetch_optional(&mut **tx).await?
        else {
            return Ok(());
        };
        if !matches!(
            DB::string(&parent, "status")?.as_str(),
            "pending" | "running" | "waiting"
        ) || DB::string(&parent, "current_run_id")? != parent_run
        {
            return Ok(());
        }
        if DB::string(&parent, "workflow_instance_id")?
            != DB::string(link, "parent_workflow_instance_id")?
        {
            return Err(refuse(StatusCode::CONFLICT, "child_call_mismatch"));
        }
        // A child that continued as new keeps its first call: its started
        // event names the continue-as-new link and the original call, and the
        // parent's link to it is a new one (`applyContinueAsNew`).
        let continued = original["continued_from_run_id"].is_string();
        // A retried child keeps its first call too; its own link is new
        // (`startChildRetryIfAvailable`).
        let retried = original["retry_of_child_workflow_run_id"].is_string();
        let call_id = if continued || retried {
            original["child_call_id"].as_str().map(str::to_owned).ok_or_else(|| refuse(StatusCode::CONFLICT, "child_call_mismatch"))?
        } else {
            link_id.clone()
        };
        if original["parent_workflow_run_id"] != parent_run
            || original["parent_workflow_instance_id"]
                != DB::string(link, "parent_workflow_instance_id")?
            || original["parent_sequence"] != sequence
            || (!continued && original["workflow_link_id"] != link_id)
            || original["child_call_id"] != call_id
            || original["workflow_instance_id"] != DB::string(child, "workflow_instance_id")?
            || original["workflow_run_id"] != child_run
        {
            return Err(refuse(StatusCode::CONFLICT, "child_call_mismatch"));
        }
        let calls = Self::query("SELECT * FROM workflow_child_calls WHERE parent_workflow_run_id=$1 AND sequence=$2 LIMIT 2").bind(&parent_run).bind(sequence).fetch_all(&mut **tx).await?;
        let call = calls
            .first()
            .filter(|_| calls.len() == 1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "child_call_mismatch"))?;
        if DB::string(call, "status")? != "started"
            || DB::optional_string(call, "resolved_child_run_id")?.as_deref() != Some(&child_run)
            || DB::optional_string(call, "resolved_child_instance_id")?.as_deref()
                != Some(&DB::string(child, "workflow_instance_id")?)
            || DB::document_row(call, "metadata")?["child_call_id"] != call_id
            // A continued child may run a new workflow type; the call keeps
            // the type it started with, as PHP's does.
            || (!continued && DB::string(call, "child_workflow_type")? != DB::string(child, "workflow_type")?)
            || DB::string(link, "child_workflow_instance_id")?
                != DB::string(child, "workflow_instance_id")?
            || DB::string(call, "parent_workflow_instance_id")?
                != DB::string(link, "parent_workflow_instance_id")?
        {
            return Err(refuse(StatusCode::CONFLICT, "child_call_mismatch"));
        }
        // `dispatchParentResumeTasksForRun`: a failed child is retried, when
        // its policy allows, before the parent sees it fail.
        if matches!(resolution, ChildResolution::Failed(_))
            && Self::start_child_retry(tx, &parent, link, call, child, sequence, &call_id).await?
        {
            return Ok(());
        }
        let (status, event_type) = match resolution {
            ChildResolution::Completed(_) => ("completed", "ChildRunCompleted"),
            ChildResolution::Cancelled(_) => ("cancelled", "ChildRunCancelled"),
            ChildResolution::Terminated(_) => ("terminated", "ChildRunTerminated"),
            ChildResolution::Failed(_) => ("failed", "ChildRunFailed"),
        };
        Self::query("UPDATE workflow_child_calls SET status=$1,closed_reason=$2,closed_at=$3,updated_at=$4 WHERE id=$5")
            .bind(status).bind(status).bind(DB::bind_time(closed_at)).bind(DB::bind_time(closed_at)).bind(DB::number(call, "id")?).execute(&mut **tx).await?;
        if let ChildResolution::Failed(failure) = resolution {
            Self::query("UPDATE workflow_child_calls SET failure_reference=$1 WHERE id=$2")
                .bind(text(failure, "failure_id")?)
                .bind(DB::number(call, "id")?)
                .execute(&mut **tx)
                .await?;
        }
        let parent_resume = Self::child_parent_resume(tx, &parent).await?;
        // The event names the child run's closed reason: a run that timed
        // out failed with `timed_out`. The call keeps its status.
        let closed_reason = match resolution {
            ChildResolution::Failed(failure) if failure["timeout_kind"].is_string() => "timed_out",
            _ => status,
        };
        let mut payload = json!({"sequence":sequence,"workflow_link_id":link_id,"child_call_id":call_id,"child_workflow_instance_id":DB::string(child,"workflow_instance_id")?,"child_workflow_run_id":child_run,"child_workflow_class":DB::string(child,"workflow_class")?,"child_workflow_type":DB::string(child,"workflow_type")?,"child_run_number":DB::number(child,"run_number")?,"child_status":status,"closed_reason":closed_reason,"closed_at":closed_at});
        match resolution {
            ChildResolution::Completed(output) => {
                payload["output"] = wire(output);
                payload["result"] = wire(output);
                payload["payload_codec"] = json!("avro");
            }
            ChildResolution::Cancelled(failure)
            | ChildResolution::Terminated(failure)
            | ChildResolution::Failed(failure) => {
                for field in [
                    "failure_id",
                    "failure_category",
                    "exception_class",
                    "message",
                ] {
                    payload[field] = failure[field].clone();
                }
                if matches!(resolution, ChildResolution::Failed(_)) {
                    for field in ["exception_type", "exception"] {
                        if !failure[field].is_null() {
                            payload[field] = failure[field].clone();
                        }
                    }
                }
            }
        }
        Self::append(tx, &parent_run, event_type, payload, None, None).await?;
        let (task_id, task_status) = match parent_resume {
            ChildParentResume::UpdateOnly => return Ok(()),
            ChildParentResume::Existing { id, status } => (id, status),
            ChildParentResume::New => {
                let task = Self::create_task(tx, &parent_run, &DB::string(&parent,"queue")?, "workflow", json!({"workflow_wait_kind":"child","child_call_id":call_id,"child_workflow_run_id":child_run,"resume_source_kind":"child_workflow_run","resume_source_id":child_run,"workflow_sequence":sequence,"workflow_event_type":event_type,"open_wait_id":format!("child:{call_id}")})).await?;
                (task, "ready".into())
            }
        };
        Self::query("UPDATE workflow_run_waits SET task_backed=TRUE,task_id=$1,task_type='workflow',task_status=$2 WHERE workflow_run_id=$3 AND wait_id=$4 AND kind='child' AND status IN ('resolved','cancelled')")
            .bind(task_id).bind(task_status).bind(&parent_run).bind(format!("child:{call_id}")).execute(&mut **tx).await?;
        Ok(())
    }
    /// `startChildRetryIfAvailable`: when the call's retry policy has
    /// attempts left and the failure is retryable, the child instance starts
    /// a new run after the policy's backoff, and the parent keeps waiting on
    /// the same call. Returns whether a retry started.
    async fn start_child_retry(
        tx: &mut Transaction<'_, DB>,
        parent: &DB::Row,
        link: &DB::Row,
        call: &DB::Row,
        failed: &DB::Row,
        sequence: i64,
        call_id: &str,
    ) -> Result<bool> {
        let parent_run = DB::string(parent, "id")?;
        let failed_run = DB::string(failed, "id")?;
        // The policy the parent recorded when it scheduled the call, or the
        // call's own when that event is in an earlier run.
        let mut scheduled = Value::Null;
        for row in Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='ChildWorkflowScheduled' ORDER BY sequence")
            .bind(&parent_run).fetch_all(&mut **tx).await?
        {
            let payload = DB::document_row(&row, "payload")?;
            if payload["sequence"] == sequence {
                scheduled = payload;
                break;
            }
        }
        let policy = if scheduled["retry_policy"].is_object() || scheduled["retry_policy"].is_array() {
            scheduled["retry_policy"].clone()
        } else {
            DB::optional_document_row(call, "retry_policy")?
                .filter(|policy| policy.is_object() || policy.is_array())
                .unwrap_or(Value::Null)
        };
        if policy.is_null() {
            return Ok(false);
        }
        let attempts = Self::scalar("SELECT COUNT(*) FROM workflow_links WHERE parent_workflow_run_id=$1 AND sequence=$2 AND link_type='child_workflow'")
            .bind(&parent_run).bind(sequence).fetch_one(&mut **tx).await?;
        if attempts >= super::child_retries::max_attempts(&policy) {
            return Ok(false);
        }
        let failure = Self::query("SELECT CASE WHEN non_retryable THEN 1 ELSE 0 END AS non_retryable_flag,exception_class FROM workflow_failures WHERE workflow_run_id=$1 ORDER BY created_at,id LIMIT 1")
            .bind(&failed_run).fetch_optional(&mut **tx).await?;
        let failure = match failure {
            Some(row) => Some((DB::number(&row, "non_retryable_flag")? != 0, DB::optional_string(&row, "exception_class")?)),
            None => None,
        };
        let failed_event = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowFailed' ORDER BY sequence DESC LIMIT 1")
            .bind(&failed_run).fetch_optional(&mut **tx).await?;
        let failed_event = match failed_event {
            Some(row) => Some(DB::document_row(&row, "payload")?),
            None => None,
        };
        if super::child_retries::non_retryable(&policy, failure, failed_event.as_ref()) {
            return Ok(false);
        }
        let timeout_policy = if scheduled["timeout_policy"].is_object() { scheduled["timeout_policy"].clone() } else { Value::Null };
        let execution_timeout = timeout_policy["execution_timeout_seconds"].as_i64();
        let run_timeout = timeout_policy["run_timeout_seconds"].as_i64();
        let at = now();
        let backoff = super::child_retries::backoff_seconds(&policy, attempts);
        let available_at = super::child_retries::after(at, backoff)
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_child_retry_policy"))?;
        let instance = DB::string(failed, "workflow_instance_id")?;
        // The latest run as a row: an aggregate of MySQL's unsigned column
        // does not decode as a signed integer.
        let latest = Self::query("SELECT run_number FROM workflow_runs WHERE workflow_instance_id=$1 ORDER BY run_number DESC LIMIT 1")
            .bind(&instance).fetch_one(&mut **tx).await?;
        let run_number = DB::number(&latest, "run_number")? + 1;
        // The execution deadline spans every attempt; each run gets its own.
        let execution_deadline = match DB::optional_instant(failed, "execution_deadline_at")? {
            Some(deadline) => Some(deadline),
            None => super::child_retries::deadline(at, execution_timeout)?,
        };
        let run_deadline = super::child_retries::deadline(at, run_timeout)?;
        let retry_run = id();
        Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,run_number,workflow_class,workflow_type,namespace,business_key,visibility_labels,status,compatibility,payload_codec,arguments,run_timeout_seconds,execution_deadline_at,run_deadline_at,connection,queue,started_at,last_progress_at,created_at,updated_at) SELECT $1,workflow_instance_id,$2,workflow_class,workflow_type,namespace,business_key,visibility_labels,'pending',compatibility,payload_codec,arguments,NULLIF($3,0),$4,$5,connection,queue,$6,$7,$8,$9 FROM workflow_runs WHERE id=$10")
            .bind(&retry_run).bind(run_number).bind(run_timeout.unwrap_or(0)).bind(DB::optional_time(execution_deadline)).bind(DB::optional_time(run_deadline))
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(&failed_run)
            .execute(&mut **tx).await?;
        // The retry takes the failed run's memos and search attributes.
        let memos = Self::inheritable_memos(tx, &failed_run).await?;
        Self::inherit_visibility(tx, memos, &failed_run, &retry_run, &instance, at).await?;
        // Each placeholder appears once, in order, as MySQL's templates require.
        Self::query("UPDATE workflow_instances SET current_run_id=$1,run_count=CASE WHEN run_count>$2 THEN run_count ELSE $3 END,updated_at=$4 WHERE id=$5")
            .bind(&retry_run).bind(run_number).bind(run_number).bind(DB::bind_time(at)).bind(&instance).execute(&mut **tx).await?;
        let parent_close = DB::optional_string(link, "parent_close_policy")?.unwrap_or_else(|| "abandon".into());
        let retry_link = id();
        Self::query("INSERT INTO workflow_links(id,link_type,sequence,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,child_workflow_run_id,is_primary_parent,parent_close_policy,created_at,updated_at) VALUES ($1,'child_workflow',$2,$3,$4,$5,$6,TRUE,$7,$8,$9)")
            .bind(&retry_link).bind(sequence).bind(DB::string(parent, "workflow_instance_id")?).bind(&parent_run).bind(&instance).bind(&retry_run)
            .bind(&parent_close).bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut **tx).await?;
        let workflow_type = DB::string(failed, "workflow_type")?;
        let workflow_class = DB::string(failed, "workflow_class")?;
        let deadlines = json!({"execution_timeout_seconds":execution_timeout,"run_timeout_seconds":run_timeout,
            "execution_deadline_at":execution_deadline.map(iso8601),"run_deadline_at":run_deadline.map(iso8601)});
        let retry = json!({"retry_attempt":attempts + 1,"retry_of_child_workflow_run_id":failed_run});
        let mut started = json!({"sequence":sequence,"workflow_link_id":retry_link,"child_call_id":call_id,
            "child_workflow_instance_id":instance,"child_workflow_run_id":retry_run,"child_workflow_class":workflow_class,
            "child_workflow_type":workflow_type,"child_run_number":run_number,"parent_close_policy":parent_close,
            "retry_backoff_seconds":backoff,"retry_policy":policy,"timeout_policy":timeout_policy});
        for source in [&retry, &deadlines] {
            for (field, value) in source.as_object().expect("an object") {
                started[field] = value.clone();
            }
        }
        // PHP records the parent's event without its null fields.
        if let Some(fields) = started.as_object_mut() {
            fields.retain(|_, value| !value.is_null());
        }
        Self::append(tx, &parent_run, "ChildRunStarted", started, None, None).await?;
        let mut child_started = json!({"workflow_class":workflow_class,"workflow_type":workflow_type,"workflow_instance_id":instance,
            "workflow_run_id":retry_run,"parent_workflow_instance_id":DB::string(parent, "workflow_instance_id")?,
            "parent_workflow_run_id":parent_run,"parent_sequence":sequence,"workflow_link_id":retry_link,"child_call_id":call_id,
            "retry_policy":policy,"timeout_policy":timeout_policy});
        for source in [&retry, &deadlines] {
            for (field, value) in source.as_object().expect("an object") {
                child_started[field] = value.clone();
            }
        }
        let queue = DB::string(failed, "queue")?;
        Self::attach_workflow_contract(tx, &queue, &workflow_type, None, &mut child_started).await?;
        Self::append(tx, &retry_run, "WorkflowStarted", child_started, None, None).await?;
        let task = Self::create_task(tx, &retry_run, &queue, "workflow", json!({})).await?;
        if backoff > 0 {
            Self::query("UPDATE workflow_tasks SET available_at=$1 WHERE id=$2")
                .bind(DB::bind_time(available_at)).bind(&task).execute(&mut **tx).await?;
        }
        // `markChildCallRetryStarted`.
        let mut metadata = DB::optional_document_row(call, "metadata")?.filter(Value::is_object).unwrap_or_else(|| json!({}));
        metadata["child_call_id"] = json!(call_id);
        metadata["attempt_count"] = json!(attempts + 1);
        metadata["last_retry_of_child_workflow_run_id"] = json!(failed_run);
        metadata["last_retry_backoff_seconds"] = json!(backoff);
        Self::query("UPDATE workflow_child_calls SET resolved_child_instance_id=$1,resolved_child_run_id=$2,status='started',started_at=$3,metadata=$4,updated_at=$5 WHERE id=$6")
            .bind(&instance).bind(&retry_run).bind(DB::bind_time(at)).bind(DB::document(&metadata)).bind(DB::bind_time(at))
            .bind(DB::number(call, "id")?).execute(&mut **tx).await?;
        tx.summary_changed(&parent_run);
        Ok(true)
    }
}
