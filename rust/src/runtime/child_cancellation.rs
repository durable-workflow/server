//! Published PHP's child cancellation cascade (Workflow 2.9.0
//! `ChildCancellation` and `WorkflowStub::attemptRequestCancellationFromParent`).
//! A parent that takes a cooperative cancellation while it waits on a child
//! whose policy is `try_cancel` or `wait_cancellation_completed` asks the child
//! to cancel, under the same root request and deadline. A waiting policy holds
//! the parent's delivery until the child closes.
use super::transaction::Transaction;
use super::{
    Result,
    backend::Backend,
    cooperative::{encode_command, timestamp},
    refuse,
    store::{Store, id, now},
};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::sync::Arc;
use tokio::sync::Semaphore;

/// How many levels of children one parent close may close through policies.
const MAX_POLICY_DEPTH: u8 = 32;

/// `ParentCloseCancellation::CLEANUP_TIMEOUT_SECONDS`.
const PARENT_CLOSE_CLEANUP_SECONDS: i64 = 600;

/// The child at a delivery's sequence, from its consistent link.
pub(super) struct ChildBoundary {
    pub(super) child_instance: String,
    pub(super) child_run: String,
    pub(super) policy: String,
}

/// `CancellationContext::forDescendant`: the root's request, reason,
/// requester, source, request time and deadline, with the child appended to
/// the lineage under its own request id.
fn descendant(parent: &Value, request_id: &str, instance: &str, run: &str) -> Value {
    let mut lineage = parent["lineage"].as_array().cloned().unwrap_or_default();
    lineage.push(json!({"request_id": request_id, "workflow_instance_id": instance, "workflow_run_id": run}));
    json!({"schema": parent["schema"], "request_id": request_id, "root_request_id": parent["root_request_id"],
        "root_workflow_instance_id": parent["root_workflow_instance_id"], "root_workflow_run_id": parent["root_workflow_run_id"],
        "parent_request_id": parent["request_id"], "reason": parent["reason"], "requester": parent["requester"],
        "source": parent["source"], "requested_at": parent["requested_at"],
        "cleanup_deadline_at": parent["cleanup_deadline_at"], "lineage": lineage})
}

fn child_status(event_type: &str) -> &'static str {
    match event_type {
        "WorkflowCompleted" => "completed",
        "WorkflowFailed" => "failed",
        "WorkflowCancelled" => "cancelled",
        _ => "terminated",
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
    /// The cancellation context of a run's current request, if any.
    async fn current_cancellation_context(tx: &mut Transaction<'_, DB>, run_id: &str) -> Result<Option<Value>> {
        let run = Self::query("SELECT cancellation_request_command_id FROM workflow_runs WHERE id=$1")
            .bind(run_id).fetch_one(&mut **tx).await?;
        let Some(request_id) = DB::optional_string(&run, "cancellation_request_command_id")? else {
            return Ok(None);
        };
        let event = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND workflow_command_id=$2 AND event_type='CooperativeCancellationRequested' ORDER BY sequence LIMIT 1")
            .bind(run_id).bind(&request_id).fetch_optional(&mut **tx).await?;
        Ok(match event {
            Some(event) => Some(DB::document_row(&event, "payload")?["cancellation"].clone()),
            None => None,
        })
    }

    /// `ChildCancellation::prepare`, called under the parent's transition
    /// lock with the delivery's claimed task. Records the request to the
    /// child and its resolution once the child closes. Returns whether the
    /// delivery waits for the child.
    pub(super) async fn prepare_child_cancellation(
        tx: &mut Transaction<'_, DB>,
        task: &DB::Row,
        request_id: &str,
        parent_context: &Value,
        sequence: i64,
        boundary: &ChildBoundary,
        codec: Arc<Semaphore>,
    ) -> Result<bool> {
        let parent_run = DB::string(task, "workflow_run_id")?;
        let task_id = DB::string(task, "id")?;
        let marks = Self::query("SELECT event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND workflow_command_id=$2 AND event_type IN ('ChildCancellationRequested','ChildCancellationResolved')")
            .bind(&parent_run).bind(request_id).fetch_all(&mut **tx).await?;
        let mut requested = false;
        let mut resolved = false;
        for mark in &marks {
            let payload = DB::document_row(mark, "payload")?;
            if payload["sequence"] == sequence && payload["child_workflow_run_id"] == boundary.child_run.as_str() {
                match DB::string(mark, "event_type")?.as_str() {
                    "ChildCancellationRequested" => requested = true,
                    _ => resolved = true,
                }
            }
        }
        // `ChildRunHistory`: a timed-out child is not a terminal resolution here.
        let terminal = Self::query("SELECT id,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('WorkflowCompleted','WorkflowFailed','WorkflowCancelled','WorkflowTerminated') ORDER BY sequence LIMIT 1")
            .bind(&boundary.child_run).fetch_optional(&mut **tx).await?;
        let snapshot = Self::task_snapshot(task)?;
        if !requested && terminal.is_none() {
            let (outcome, rejection) =
                Self::request_child_cancellation_from_parent(tx, boundary, parent_context, None, codec).await?;
            let mut payload = Self::child_cancellation_payload(tx, sequence, boundary, parent_context).await?;
            payload["request_outcome"] = json!(outcome);
            payload["rejection_reason"] = json!(rejection);
            payload["task"] = snapshot.clone();
            Self::append_by_command_id(tx, &parent_run, "ChildCancellationRequested", payload, Some(&task_id), request_id).await?;
        }
        if let Some(terminal) = &terminal
            && !resolved
        {
            let kind = DB::string(terminal, "event_type")?;
            let mut payload = Self::child_cancellation_payload(tx, sequence, boundary, parent_context).await?;
            payload["child_status"] = json!(child_status(&kind));
            payload["child_terminal_history_event_id"] = json!(DB::string(terminal, "id")?);
            payload["child_terminal_event_type"] = json!(kind);
            payload["reason"] = DB::document_row(terminal, "payload")?["reason"].clone();
            payload["task"] = snapshot;
            Self::append_by_command_id(tx, &parent_run, "ChildCancellationResolved", payload, Some(&task_id), request_id).await?;
        }
        Ok(boundary.policy == "wait_cancellation_completed" && terminal.is_none())
    }

    /// The fields `ChildCancellationRequested` and `...Resolved` share: the
    /// parent's request and budget, and the child's current request.
    async fn child_cancellation_payload(
        tx: &mut Transaction<'_, DB>,
        sequence: i64,
        boundary: &ChildBoundary,
        parent: &Value,
    ) -> Result<Value> {
        let child = Self::current_cancellation_context(tx, &boundary.child_run).await?.unwrap_or(Value::Null);
        Ok(json!({"sequence": sequence, "policy": boundary.policy, "parent_request_id": parent["request_id"],
            "root_request_id": parent["root_request_id"], "cleanup_deadline_at": parent["cleanup_deadline_at"],
            "child_workflow_instance_id": boundary.child_instance, "child_workflow_run_id": boundary.child_run,
            "child_request_id": child["request_id"], "child_root_request_id": child["root_request_id"],
            "child_cleanup_deadline_at": child["cleanup_deadline_at"]}))
    }

    /// `ParentClosePolicyEnforcer::enforce`: once a parent closes, each child
    /// still open under a policy other than `abandon` is handled in link
    /// order, and the parent records `ParentClosePolicyApplied` or
    /// `ParentClosePolicyFailed`:
    /// - `request_cancel` and `terminate` cancel or terminate the child as
    ///   PHP's `attemptCancel` and `attemptTerminate` do, which applies the
    ///   child's own policies in turn;
    /// - `request_cancellation` asks the child to cancel under the parent's
    ///   cooperative request, once. A parent without one first records its
    ///   own policy-owned root (`ParentCloseCancellation::ensureOrigin`).
    pub(super) async fn enforce_parent_close_policies(tx: &mut Transaction<'_, DB>, parent: &DB::Row, closed_reason: &str, depth: u8) -> Result<()> {
        let parent_run = DB::string(parent, "id")?;
        let mut context = Self::current_cancellation_context(tx, &parent_run).await?;
        let links = Self::query("SELECT * FROM workflow_links WHERE parent_workflow_run_id=$1 AND link_type='child_workflow' AND parent_close_policy IN ('request_cancel','terminate','request_cancellation') ORDER BY sequence,id LIMIT 1001")
            .bind(&parent_run).fetch_all(&mut **tx).await?;
        for link in links {
            let policy = DB::string(&link, "parent_close_policy")?;
            let boundary = ChildBoundary {
                child_instance: DB::string(&link, "child_workflow_instance_id")?,
                child_run: DB::string(&link, "child_workflow_run_id")?,
                policy: policy.clone(),
            };
            let child = Self::query("SELECT * FROM workflow_runs WHERE id=$1").bind(&boundary.child_run).fetch_one(&mut **tx).await?;
            if !matches!(DB::string(&child, "status")?.as_str(), "pending" | "running" | "waiting") {
                continue;
            }
            let reason = format!("Parent workflow closed ({closed_reason}); parent-close policy: {policy}.");
            if policy != "request_cancellation" {
                let command = if policy == "terminate" {
                    super::cancellation::TerminalCommand::Terminate
                } else {
                    super::cancellation::TerminalCommand::Cancel
                };
                let error = Box::pin(Self::close_child_for_policy(tx, &child, command, &reason, depth)).await?;
                let mut payload = json!({"child_instance_id": boundary.child_instance, "child_run_id": boundary.child_run,
                    "policy": policy, "reason": reason});
                if error.is_none()
                    && let Some(sequence) = DB::optional_number(&link, "sequence")?
                {
                    // `markChildCallClosedByParentPolicy`: the parent's open
                    // call for this child is closed with the policy, whichever
                    // run the child continued into.
                    let at = now();
                    Self::query("UPDATE workflow_child_calls SET status=$1,closed_reason=$2,closed_at=$3,updated_at=$4 WHERE parent_workflow_run_id=$5 AND sequence=$6 AND status IN ('scheduled','started')")
                        .bind(command.closed()).bind(command.closed()).bind(DB::bind_time(at)).bind(DB::bind_time(at))
                        .bind(&parent_run).bind(sequence)
                        .execute(&mut **tx).await?;
                }
                let kind = match error {
                    None => "ParentClosePolicyApplied",
                    Some(error) => {
                        payload["error"] = json!(error);
                        "ParentClosePolicyFailed"
                    }
                };
                Self::append(tx, &parent_run, kind, payload, None, None).await?;
                continue;
            }
            Self::apply_request_cancellation_policy(tx, parent, &link, &boundary, closed_reason, &mut context).await?;
        }
        Ok(())
    }

    /// The `request_cancellation` policy for one open child: once per
    /// child, under the parent's own request, or else under the root
    /// `ensureOrigin` records the first time it is needed (`context`).
    async fn apply_request_cancellation_policy(
        tx: &mut Transaction<'_, DB>,
        parent: &DB::Row,
        link: &DB::Row,
        boundary: &ChildBoundary,
        closed_reason: &str,
        context: &mut Option<Value>,
    ) -> Result<()> {
        let parent_run = DB::string(parent, "id")?;
        let applied = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='ParentClosePolicyApplied'")
            .bind(&parent_run).fetch_all(&mut **tx).await?;
        let mut already = false;
        for event in &applied {
            let payload = DB::document_row(event, "payload")?;
            already |= payload["child_run_id"] == boundary.child_run.as_str() && payload["policy"] == "request_cancellation";
        }
        if already {
            return Ok(());
        }
        let reason = format!("Parent workflow closed ({closed_reason}); parent-close policy: request_cancellation.");
        // A parent with an accepted request keeps it as the root, even
        // one whose context is unavailable; otherwise it records its own.
        let mut failure = "cancellation_parent_context_unavailable";
        if context.is_none() && DB::optional_string(parent, "cancellation_request_command_id")?.is_none() {
            match Self::ensure_parent_close_origin(tx, &parent_run, &reason).await? {
                Some(origin) => *context = Some(origin),
                None => failure = "cancellation_parent_closure_unavailable",
            }
        }
        let Some(context) = context.as_ref() else {
            // PHP records the refused request, or the exception
            // `ensureOrigin` throws, as the policy's failure.
            let mut payload = json!({"child_instance_id": boundary.child_instance, "child_run_id": boundary.child_run,
                "policy": "request_cancellation", "reason": reason, "error": failure});
            if failure == "cancellation_parent_context_unavailable" {
                payload["request_diagnostics"] = json!([]);
                payload["request_id"] = Value::Null;
                payload["cancellation"] = Value::Null;
            }
            Self::append(tx, &parent_run, "ParentClosePolicyFailed", payload, None, None).await?;
            return Ok(());
        };
        // `CommandContext::workflow`: the enforcer asks as the parent,
        // naming the child's link and its sequence.
        let policy_caller = json!({"caller": {"type": "workflow", "label": "Workflow"},
            "auth": {"status": "not_applicable", "method": "none"},
            "workflow": {"parent_instance_id": DB::string(parent, "workflow_instance_id")?, "parent_run_id": parent_run,
                "sequence": DB::optional_number(link, "sequence")?.unwrap_or(0), "child_call_id": DB::string(link, "id")?}});
        let existing = Self::current_cancellation_context(tx, &boundary.child_run).await?;
        let (outcome, rejection) =
            Self::request_child_cancellation_from_parent(tx, boundary, context, Some(&policy_caller), Arc::new(Semaphore::new(1))).await?;
        let mut payload = json!({"child_instance_id": boundary.child_instance, "child_run_id": boundary.child_run,
            "policy": "request_cancellation", "reason": reason});
        let kind = if outcome == "accepted" {
            let child = Self::current_cancellation_context(tx, &boundary.child_run).await?.unwrap_or(Value::Null);
            payload["request_id"] = child["request_id"].clone();
            payload["cancellation"] = child;
            "ParentClosePolicyApplied"
        } else {
            payload["error"] = json!(rejection.unwrap_or("cancellation_request_rejected"));
            payload["request_diagnostics"] = match &existing {
                Some(existing) if rejection == Some("cancellation_root_conflict") => json!({
                    "existing_request_id": existing["request_id"], "existing_root_request_id": existing["root_request_id"],
                    "existing_cleanup_deadline_at": existing["cleanup_deadline_at"],
                    "incoming_root_request_id": context["root_request_id"], "incoming_cleanup_deadline_at": context["cleanup_deadline_at"]}),
                _ => json!([]),
            };
            payload["request_id"] = Value::Null;
            payload["cancellation"] = Value::Null;
            "ParentClosePolicyFailed"
        };
        Self::append(tx, &parent_run, kind, payload, None, None).await?;
        Ok(())
    }

    /// `ParentCloseCancellation::ensureOrigin`: a closed parent without a
    /// request of its own records one policy-owned root after its terminal
    /// event, requested at that event's time with a 600-second cleanup
    /// budget. Returns that root's context, recorded once per closed run, or
    /// nothing when the run has no timed terminal event
    /// (`cancellation_parent_closure_unavailable`).
    async fn ensure_parent_close_origin(tx: &mut Transaction<'_, DB>, parent_run: &str, reason: &str) -> Result<Option<Value>> {
        let Some(closure) = Self::query("SELECT id,sequence,event_type,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('WorkflowCompleted','WorkflowFailed','WorkflowCancelled','WorkflowTerminated','WorkflowTimedOut') ORDER BY sequence DESC LIMIT 1")
            .bind(parent_run).fetch_optional(&mut **tx).await?
        else {
            return Ok(None);
        };
        let Some(requested_at) = DB::optional_instant(&closure, "recorded_at")? else {
            return Ok(None);
        };
        let closure_sequence = DB::number(&closure, "sequence")?;
        let origin = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='ParentCloseCancellationRequested' AND sequence>$2 ORDER BY sequence LIMIT 1")
            .bind(parent_run).bind(closure_sequence).fetch_optional(&mut **tx).await?;
        if let Some(origin) = origin {
            return Ok(Some(DB::document_row(&origin, "payload")?["cancellation"].clone()));
        }
        let instance = Self::query("SELECT workflow_instance_id FROM workflow_runs WHERE id=$1")
            .bind(parent_run).fetch_one(&mut **tx).await?;
        let instance = DB::string(&instance, "workflow_instance_id")?;
        let request_id = id();
        let context = json!({"schema": "durable-workflow.cancellation-context/v1", "request_id": request_id,
            "root_request_id": request_id, "root_workflow_instance_id": instance, "root_workflow_run_id": parent_run,
            "parent_request_id": null, "reason": reason,
            "requester": {"type": "workflow", "id": instance, "label": "Parent-close policy"},
            "source": "parent_close_policy", "requested_at": timestamp(requested_at),
            "cleanup_deadline_at": timestamp(requested_at + chrono::Duration::seconds(PARENT_CLOSE_CLEANUP_SECONDS)),
            "lineage": [{"request_id": request_id, "workflow_instance_id": instance, "workflow_run_id": parent_run}]});
        Self::append(tx, parent_run, "ParentCloseCancellationRequested", json!({
            "parent_terminal_history_event_id": DB::string(&closure, "id")?,
            "parent_terminal_event_type": DB::string(&closure, "event_type")?,
            "cancellation": context}), None, None).await?;
        Ok(Some(context))
    }

    /// `WorkflowStub::attemptCancel` and `attemptTerminate` as the enforcer
    /// calls them: a PHP API command on the child instance's current run,
    /// then the same close an HTTP cancel or terminate performs, which applies
    /// the child's own policies in turn. Returns the error the enforcer
    /// records instead when native cannot close that child yet, before
    /// anything is written; the parent's close still stands.
    pub(super) async fn close_child_for_policy(
        tx: &mut Transaction<'_, DB>,
        child: &DB::Row,
        command: super::cancellation::TerminalCommand,
        reason: &str,
        depth: u8,
    ) -> Result<Option<&'static str>> {
        let instance = DB::string(child, "workflow_instance_id")?;
        let Some(run) = Self::query("SELECT r.* FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1")
            .bind(&instance).fetch_optional(&mut **tx).await?
        else {
            return Ok(Some("instance_not_found"));
        };
        if !matches!(DB::string(&run, "status")?.as_str(), "pending" | "running" | "waiting") {
            return Ok(None);
        }
        let run_id = DB::string(&run, "id")?;
        let unavailable = command.propagation_unavailable();
        // Each level is one recursive close in this transaction. PHP has no
        // bound; native stops at a depth no ordinary workflow tree reaches.
        if depth >= MAX_POLICY_DEPTH {
            return Ok(Some(unavailable));
        }
        let activities = Self::scalar("SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=$1 AND status IN ('pending','running')")
            .bind(&run_id).fetch_one(&mut **tx).await?;
        let timers = Self::scalar("SELECT COUNT(*) FROM workflow_run_timers WHERE workflow_run_id=$1 AND status='pending'")
            .bind(&run_id).fetch_one(&mut **tx).await?;
        if activities > 1000 || timers > 1000 {
            return Ok(Some("cancellation_inventory_exceeds_budget"));
        }
        let (reason, blob) = super::cancellation::prepare_reason(&json!({"reason": reason}), Arc::new(Semaphore::new(1))).await?;
        let command_id = id();
        let command_sequence = DB::number(&run, "last_command_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let at = now();
        let context = json!({"caller": {"type": "php", "label": "PHP API"}, "auth": {"status": "not_applicable", "method": "none"}});
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,target_scope,source,status,outcome,workflow_type,payload_codec,payload,command_sequence,accepted_at,applied_at,context) VALUES ($1,$2,$3,$4,$5,'instance','php','accepted',$6,$7,'avro',$8,$9,$10,$11,$12)")
            .bind(&command_id).bind(&instance).bind(&run_id).bind(&run_id).bind(command.command_type()).bind(command.closed())
            .bind(DB::string(&run, "workflow_type")?).bind(blob.as_deref()).bind(command_sequence)
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(DB::document(&context))
            .execute(&mut **tx).await?;
        Self::query("UPDATE workflow_runs SET last_command_sequence=$1 WHERE id=$2")
            .bind(command_sequence)
            .bind(&run_id)
            .execute(&mut **tx)
            .await?;
        Self::finish_close(tx, &run, command, &command_id, reason.as_deref(), at, depth + 1).await?;
        Ok(None)
    }

    /// `WorkflowStub::attemptRequestCancellationFromParent`: the child takes
    /// the parent's request as a descendant, with the root's deadline even if
    /// it has passed. A child that already has a request under the same root
    /// keeps it; one under another root refuses. Returns the outcome and
    /// rejection reason.
    ///
    /// The cascade loads the child's run, so its request targets that run as
    /// a PHP API command. A parent-close policy loads the child's instance
    /// and asks under `policy_caller`, the workflow command context.
    async fn request_child_cancellation_from_parent(
        tx: &mut Transaction<'_, DB>,
        boundary: &ChildBoundary,
        parent: &Value,
        policy_caller: Option<&Value>,
        codec: Arc<Semaphore>,
    ) -> Result<(&'static str, Option<&'static str>)> {
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(&boundary.child_run).fetch_one(&mut **tx).await?;
        if !matches!(DB::string(&run, "status")?.as_str(), "pending" | "running" | "waiting") {
            return Ok(("rejected", Some("run_not_active")));
        }
        if let Some(existing) = Self::current_cancellation_context(tx, &boundary.child_run).await? {
            return Ok(if existing["root_request_id"] == parent["root_request_id"] {
                ("accepted", None)
            } else {
                ("rejected", Some("cancellation_root_conflict"))
            });
        }
        let deadline = parent["cleanup_deadline_at"]
            .as_str()
            .and_then(|at| DateTime::parse_from_rfc3339(at).ok())
            .map(|at| at.with_timezone(&Utc))
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "cancellation_parent_context_unavailable"))?;
        let request_id = id();
        let at = now();
        let context = descendant(parent, &request_id, &boundary.child_instance, &boundary.child_run);
        let reason = parent["reason"].as_str().map(str::to_owned);
        let blob = encode_command(json!({"reason": reason, "cleanup_deadline_at": timestamp(deadline), "cancellation": context}), codec).await?;
        let sequence = DB::number(&run, "last_command_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        // `CommandContext::phpApi`: the run-targeted stub's default context,
        // with no principal.
        let (command_context, target_scope, source, requested) = match policy_caller {
            Some(caller) => (caller.clone(), "instance", "workflow", None),
            None => (json!({"caller": {"type": "php", "label": "PHP API"}, "auth": {"status": "not_applicable", "method": "none"}}),
                "run", "php", Some(boundary.child_run.as_str())),
        };
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,requested_workflow_run_id,resolved_workflow_run_id,command_type,target_scope,source,context,status,outcome,workflow_type,workflow_class,payload_codec,payload,command_sequence,accepted_at,applied_at) VALUES ($1,$2,$3,$4,$5,'request_cancellation',$6,$7,$8,'accepted','cancellation_requested',$9,$10,'avro',$11,$12,$13,$14)")
            .bind(&request_id).bind(&boundary.child_instance).bind(&boundary.child_run).bind(requested).bind(&boundary.child_run)
            .bind(target_scope).bind(source).bind(DB::document(&command_context)).bind(DB::string(&run, "workflow_type")?).bind(DB::string(&run, "workflow_class")?)
            .bind(&blob).bind(sequence).bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut **tx).await?;
        // The child's own request time; its budget is the root's.
        Self::query("UPDATE workflow_runs SET cancellation_request_command_id=$1,cancellation_requested_at=$2,cancellation_deadline_at=$3,last_progress_at=$4,last_command_sequence=$5 WHERE id=$6")
            .bind(&request_id).bind(DB::bind_time(at)).bind(DB::bind_time(deadline)).bind(DB::bind_time(at)).bind(sequence)
            .bind(&boundary.child_run).execute(&mut **tx).await?;
        let mut payload = json!({"workflow_command_id": request_id, "workflow_instance_id": boundary.child_instance,
            "workflow_run_id": boundary.child_run, "command_type": "request_cancellation",
            "cleanup_deadline_at": timestamp(deadline), "cancellation": context});
        if let Some(reason) = reason {
            payload["reason"] = json!(reason);
        }
        Self::append(tx, &boundary.child_run, "CooperativeCancellationRequested", payload, None, Some(&request_id)).await?;
        let open = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')")
            .bind(&boundary.child_run).fetch_one(&mut **tx).await?;
        if open == 0 {
            Self::create_task(tx, &boundary.child_run, &DB::string(&run, "queue")?, "workflow", json!({
                "resume_source_kind": "cancellation_request", "resume_source_id": request_id, "workflow_command_id": request_id})).await?;
        }
        tx.summary_changed(&boundary.child_run);
        Ok(("accepted", None))
    }
}
