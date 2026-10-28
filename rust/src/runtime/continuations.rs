//! Atomic continue-as-new. Received signals, pending updates and message
//! stream cursors move to the continued run like PHP; everything else the
//! run has open stays with the closed predecessor.
use super::transaction::Transaction;
use super::{
    Result,
    backend::Backend,
    envelope, refuse,
    store::{Store, id, now, reject_fields},
    wire,
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

pub(super) fn validate(command: &Value) -> Result<()> {
    reject_fields(command, &["type", "arguments", "workflow_type", "queue"])?;
    for field in ["workflow_type", "queue"] {
        if command.get(field).is_some_and(|value| !value.is_null()) {
            super::text(command, field)?;
        }
    }
    if command
        .get("arguments")
        .is_some_and(|value| !value.is_null())
    {
        envelope(command, "arguments")?;
    }
    Ok(())
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
    /// `WorkflowController::runs`: every run in run-number order, as PHP's
    /// `formatRunListEntry` renders it. The current run is the highest
    /// numbered one.
    pub(super) async fn list_runs(&self, workflow: &str) -> Result<Value> {
        let rows=Self::query("SELECT r.id,r.workflow_type,r.business_key,r.status,r.run_number,r.queue,r.compatibility,r.started_at,r.closed_at,r.import_source FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE i.id=$1 AND i.namespace=$2 AND r.namespace=$3 ORDER BY r.run_number LIMIT 1025")
            .bind(workflow).bind(self.namespace.as_ref()).bind(self.namespace.as_ref()).fetch_all(&self.pool).await?;
        if rows.is_empty() {
            return Err(refuse(StatusCode::NOT_FOUND, "instance_not_found"));
        }
        if rows.len() > 1024 {
            return Err(refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "workflow_runs_exceed_read_budget",
            ));
        }
        let current = DB::string(rows.last().expect("one run"), "id")?;
        let fleet = self.fleet_snapshots(super::store::now()).await?;
        let mut runs = Vec::with_capacity(rows.len());
        for row in rows {
            let run = DB::string(&row, "id")?;
            let status = DB::string(&row, "status")?;
            let queue = DB::optional_string(&row, "queue")?;
            let compatibility = DB::optional_string(&row, "compatibility")?;
            let required = compatibility.as_deref().map(str::trim).filter(|value| !value.is_empty());
            let (supported, reason) = super::run_diagnostics::fleet_supports(&fleet, required, queue.as_deref());
            let memo = self.run_memo(&run).await?;
            let mut entry = json!({"run_id":run,"run_number":DB::number(&row,"run_number")?,
                "workflow_type":DB::string(&row,"workflow_type")?,"business_key":DB::optional_string(&row,"business_key")?,
                "status":status,"status_bucket":super::visibility::status_bucket(&status)?,
                "is_terminal":matches!(status.as_str(), "completed" | "failed" | "cancelled" | "terminated"),
                "is_current_run":run == current,"task_queue":queue,"compatibility":compatibility,
                "compatibility_status":if supported { "compatible" } else { "no_compatible_worker" },
                "compatibility_supported_in_fleet":supported,"compatibility_fleet_reason":reason,
                "started_at":super::task_queues::json_time(DB::optional_instant(&row,"started_at")?),
                "closed_at":super::task_queues::json_time(DB::optional_instant(&row,"closed_at")?),"memo":memo});
            // `formatRunListEntry` leaves a Waterline v1 projection's memo out.
            if DB::optional_string(&row, "import_source")?.as_deref() == Some(super::waterline_import::IMPORT_SOURCE) {
                entry.as_object_mut().expect("run entry").remove("memo");
            }
            runs.push(entry);
        }
        Ok(json!({"workflow_id":workflow,"run_count":runs.len(),"runs":runs}))
    }

    pub(super) async fn continue_run(
        &self,
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        task: &DB::Row,
        command: &Value,
        sequence: i64,
    ) -> Result<String> {
        let old = DB::string(run, "id")?;
        let workflow = DB::string(run, "workflow_instance_id")?;
        let instance = Self::query(
            "SELECT current_run_id FROM workflow_instances WHERE id=$1 AND namespace=$2",
        )
        .bind(&workflow)
        .bind(self.namespace.as_ref())
        .fetch_one(&mut **tx)
        .await?;
        if DB::string(&instance, "current_run_id")? != old {
            return Err(refuse(
                StatusCode::CONFLICT,
                "continue_as_new_run_not_current",
            ));
        }
        // Published PHP continues a run whatever it has open
        // (`applyContinueAsNew`). Its activities, timers, children and waits
        // stay with the closed predecessor, and no parent-close policy
        // applies. Message streams pass to the next run when the completion
        // records its stream acknowledgements. Durable output streams are
        // rows of their run and stay with the predecessor. Core touches
        // neither service calls nor query tasks: a call keeps the predecessor
        // as its caller (`ServiceCallWorkflowDelivery` skips a terminal
        // caller), and a query admitted against the predecessor is still
        // leased and answered from its history (continue-transfers fixture).
        // Core moves only open calls with the `workflow_run` binding to the
        // next run (`LinkedServiceCallCloser::advanceForContinuation`). Native
        // resolves no call to that binding, and takeover refuses an open one.
        // A call linked to this run through an `activity_execution` binding
        // stays with the predecessor, as in Core.
        let rows = Self::query(DB::MEMO_READ)
            .bind(&old)
            .fetch_all(&mut **tx)
            .await?;
        if rows.len() > super::memos::MAX_ENTRIES {
            return Err(refuse(
                StatusCode::CONFLICT,
                "workflow_memo_validation_failed",
            ));
        }
        let mut memos = Vec::with_capacity(rows.len());
        for memo in rows {
            let portable = if DB::optional_number(&memo, "portable_value_sequence")?
                == Some(DB::number(&memo, "upserted_at_sequence")?)
            {
                DB::optional_document_row(&memo, "portable_value")?
                    .filter(|value| value.is_object() || value.is_array())
            } else {
                None
            };
            memos.push((
                DB::string(&memo, "key")?,
                DB::document_row(&memo, "value")?,
                portable,
            ));
        }
        // Validate the selected typed authority off the executor before any
        // successor mutation. Stale portable bytes retain the legacy fallback.
        super::memos::read_stored(self.memo_codec.clone(), memos.clone()).await?;
        let next = id();
        let link = id();
        let started = now();
        let number = DB::number(run, "run_number")?
            .checked_add(1)
            .filter(|number| *number <= i32::MAX as i64)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "run_sequence_exhausted"))?;
        let timeout = DB::optional_number(run, "run_timeout_seconds")?;
        let deadline = timeout
            .map(|seconds| {
                chrono::Duration::try_seconds(seconds)
                    .and_then(|duration| started.checked_add_signed(duration))
                    .ok_or_else(|| refuse(StatusCode::CONFLICT, "invalid_stored_run_timeout"))
            })
            .transpose()?;
        let arguments = if command
            .get("arguments")
            .is_some_and(|value| !value.is_null())
        {
            envelope(command, "arguments")?
        } else {
            DB::string(run, "arguments")?
        };
        // `applyContinueAsNew`: the command may name a new workflow type and
        // queue. The type also becomes the run's and the instance's class.
        let routed = |field: &str| -> Result<String> {
            match command.get(field) {
                Some(value) if !value.is_null() => Ok(super::text(command, field)?.to_owned()),
                _ => DB::string(run, field),
            }
        };
        let kind = routed("workflow_type")?;
        let class = kind.clone();
        let queue = routed("queue")?;
        Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,workflow_type,workflow_class,run_number,status,payload_codec,arguments,connection,queue,business_key,visibility_labels,compatibility,run_timeout_seconds,execution_deadline_at,run_deadline_at,started_at,created_at,updated_at,namespace,last_progress_at) SELECT $1,workflow_instance_id,$2,$3,$4,'pending',payload_codec,$5,connection,$6,business_key,visibility_labels,compatibility,run_timeout_seconds,execution_deadline_at,$7,$8,$9,$10,namespace,$11 FROM workflow_runs WHERE id=$12")
            .bind(&next).bind(&kind).bind(&class).bind(number).bind(&arguments).bind(&queue).bind(DB::optional_time(deadline))
            .bind(DB::bind_time(started)).bind(DB::bind_time(started))
            .bind(DB::bind_time(started)).bind(DB::bind_time(started)).bind(&old)
            .execute(&mut **tx).await?;
        for (key, projection, portable) in memos {
            Self::query(DB::MEMO_INHERIT_INSERT)
                .bind(&next)
                .bind(&workflow)
                .bind(key)
                .bind(DB::document(&projection))
                .bind(DB::bind_time(started))
                .bind(DB::bind_time(started))
                .bind(portable.as_ref().map(DB::document))
                .execute(&mut **tx)
                .await?;
        }
        // `SearchAttributeUpsertService::inheritFromParent` at sequence 1.
        Self::query(DB::SEARCH_ATTRIBUTE_INHERIT)
            .bind(&next)
            .bind(&workflow)
            .bind(DB::bind_time(started))
            .bind(DB::bind_time(started))
            .bind(&old)
            .execute(&mut **tx)
            .await?;
        Self::query("UPDATE workflow_instances SET current_run_id=$1,run_count=$2,workflow_class=$3,updated_at=$4 WHERE id=$5")
            .bind(&next).bind(number).bind(&class).bind(DB::bind_time(started)).bind(&workflow).execute(&mut **tx).await?;
        Self::query("INSERT INTO workflow_links(id,link_type,sequence,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,child_workflow_run_id,is_primary_parent,parent_close_policy,created_at,updated_at) VALUES ($1,'continue_as_new',$2,$3,$4,$5,$6,TRUE,'abandon',$7,$8)")
            .bind(&link).bind(sequence).bind(&workflow).bind(&old).bind(&workflow).bind(&next)
            .bind(DB::bind_time(started)).bind(DB::bind_time(started)).execute(&mut **tx).await?;
        let parent = Self::continue_child_links(tx, &old, &next, number, &class, &kind, started).await?;
        Self::append(tx,&next,"WorkflowStarted",json!({"workflow_class":class,"workflow_type":kind,
            "workflow_instance_id":workflow,"workflow_run_id":next,"continued_from_run_id":old,"workflow_link_id":link,
            "child_call_id":parent["child_call_id"],"parent_workflow_instance_id":parent["parent_workflow_instance_id"],
            "parent_workflow_run_id":parent["parent_workflow_run_id"],"parent_sequence":parent["parent_sequence"]}),None,None).await?;
        Self::transfer_messages(tx, &old, &next, &workflow).await?;
        Self::query("UPDATE workflow_runs SET status='completed',closed_reason='continued',closed_at=$1,last_progress_at=$2 WHERE id=$3")
            .bind(DB::bind_time(started)).bind(DB::bind_time(started)).bind(&old).execute(&mut **tx).await?;
        let task_id = DB::string(task, "id")?;
        Self::append(tx,&old,"WorkflowContinuedAsNew",json!({"sequence":sequence,"continued_to_run_id":next,
            "continued_to_run_number":number,"workflow_link_id":link,"closed_reason":"continued","task":Self::task_snapshot(task)?}),Some(&task_id),None).await?;
        // `PendingUpdateCloser::closeForTerminalRun`: updates already applied
        // but not completed stay with the predecessor and close there.
        Self::close_pending_updates(tx, &old, Some(task)).await?;
        Self::create_task(tx, &next, &queue, "workflow", json!({})).await
    }

    /// `applyContinueAsNew` for a child run: the continued run stays its
    /// parent's child. Each parent link gets a link to the continued run. An
    /// open parent's child call follows it (`markChildCallContinued`), and
    /// the parent records `ChildRunStarted` for it. Returns the parent
    /// reference for the continued run's `WorkflowStarted`, all null for a
    /// run without a parent.
    #[allow(clippy::too_many_arguments)]
    async fn continue_child_links(
        tx: &mut Transaction<'_, DB>,
        old: &str,
        next: &str,
        number: i64,
        class: &str,
        kind: &str,
        started: chrono::DateTime<chrono::Utc>,
    ) -> Result<Value> {
        let original = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(old).fetch_optional(&mut **tx).await?
            .map(|row| DB::document_row(&row, "payload"))
            .transpose()?
            .unwrap_or(Value::Null);
        let call_id = original["child_call_id"].as_str().filter(|id| !id.is_empty()).map(str::to_owned);
        let reference = match original["parent_workflow_run_id"].as_str().filter(|id| !id.is_empty()) {
            None => json!({"child_call_id":call_id,"parent_workflow_instance_id":null,"parent_workflow_run_id":null,"parent_sequence":null}),
            Some(parent_run) => json!({"child_call_id":call_id,
                "parent_workflow_instance_id":original["parent_workflow_instance_id"].as_str().unwrap_or(&original["workflow_instance_id"].as_str().unwrap_or_default()),
                "parent_workflow_run_id":parent_run,"parent_sequence":original["parent_sequence"].as_i64()}),
        };
        let links = Self::query("SELECT id,sequence,parent_workflow_run_id,child_workflow_instance_id,parent_close_policy FROM workflow_links WHERE child_workflow_run_id=$1 AND link_type='child_workflow' ORDER BY id")
            .bind(old).fetch_all(&mut **tx).await?;
        for parent_link in links {
            let link = id();
            Self::query("INSERT INTO workflow_links(id,link_type,sequence,parallel_group_path,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,child_workflow_run_id,is_primary_parent,parent_close_policy,created_at,updated_at) SELECT $1,link_type,sequence,parallel_group_path,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,$2,is_primary_parent,parent_close_policy,$3,$4 FROM workflow_links WHERE id=$5")
                .bind(&link).bind(next).bind(DB::bind_time(started)).bind(DB::bind_time(started))
                .bind(DB::string(&parent_link, "id")?).execute(&mut **tx).await?;
            let parent_run = DB::optional_string(&parent_link, "parent_workflow_run_id")?.filter(|id| !id.is_empty());
            let (Some(parent_run), Some(sequence)) = (parent_run, DB::optional_number(&parent_link, "sequence")?) else {
                continue;
            };
            let open = Self::query("SELECT status FROM workflow_runs WHERE id=$1")
                .bind(&parent_run).fetch_optional(&mut **tx).await?
                .map(|row| DB::string(&row, "status"))
                .transpose()?
                .is_some_and(|status| matches!(status.as_str(), "pending" | "running" | "waiting"));
            if !open {
                continue;
            }
            let child_instance = DB::string(&parent_link, "child_workflow_instance_id")?;
            if let Some(call) = Self::query("SELECT id,metadata FROM workflow_child_calls WHERE parent_workflow_run_id=$1 AND sequence=$2 AND status NOT IN ('completed','cancelled','terminated','failed') ORDER BY id DESC LIMIT 1")
                .bind(&parent_run).bind(sequence).fetch_optional(&mut **tx).await?
            {
                let mut metadata = DB::optional_document_row(&call, "metadata")?
                    .filter(Value::is_object)
                    .unwrap_or_else(|| json!({}));
                let continued = metadata["continuation_count"].as_i64().unwrap_or(0) + 1;
                if let Some(call_id) = &call_id {
                    metadata["child_call_id"] = json!(call_id);
                }
                metadata["continuation_count"] = json!(continued);
                metadata["last_continued_from_child_workflow_run_id"] = json!(old);
                Self::query("UPDATE workflow_child_calls SET resolved_child_instance_id=$1,resolved_child_run_id=$2,status='started',started_at=$3,metadata=$4,updated_at=$5 WHERE id=$6")
                    .bind(&child_instance).bind(next).bind(DB::bind_time(started)).bind(DB::document(&metadata))
                    .bind(DB::bind_time(started)).bind(DB::number(&call, "id")?).execute(&mut **tx).await?;
            }
            let mut payload = json!({"sequence":sequence,"workflow_link_id":link,"child_call_id":call_id,
                "child_workflow_instance_id":child_instance,"child_workflow_run_id":next,
                "child_workflow_class":class,"child_workflow_type":kind,"child_run_number":number,
                "parent_close_policy":DB::string(&parent_link, "parent_close_policy")?});
            if call_id.is_none() {
                payload.as_object_mut().expect("an object").remove("child_call_id");
            }
            // PHP projects the parent again (`projectRun`).
            tx.summary_changed(&parent_run);
            Self::append(tx, &parent_run, "ChildRunStarted", payload, None, None).await?;
        }
        Ok(reference)
    }

    /// `ContinuedRunMessageHandoff::transferInstanceScoped`: received signals
    /// with a declared handler and accepted updates not yet applied move to
    /// the continued run in command order, and are recorded there again.
    async fn transfer_messages(
        tx: &mut Transaction<'_, DB>,
        old: &str,
        next: &str,
        workflow: &str,
    ) -> Result<()> {
        let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(old).fetch_optional(&mut **tx).await?
            .map(|row| DB::document_row(&row, "payload"))
            .transpose()?;
        let contract = super::run_observation::command_contract(started.as_ref());
        let declared: Vec<&str> = contract["signals"]
            .as_array()
            .map(|names| names.iter().filter_map(Value::as_str).collect())
            .unwrap_or_default();
        let mut messages = Vec::new();
        for row in Self::query("SELECT * FROM workflow_signal_records WHERE workflow_run_id=$1 AND target_scope='instance' AND status='received' AND closed_at IS NULL")
            .bind(old).fetch_all(&mut **tx).await?
        {
            if declared.contains(&DB::string(&row, "signal_name")?.as_str()) {
                messages.push((true, row));
            }
        }
        for row in Self::query("SELECT * FROM workflow_updates WHERE workflow_run_id=$1 AND target_scope='instance' AND status='accepted' AND workflow_sequence IS NULL")
            .bind(old).fetch_all(&mut **tx).await?
        {
            messages.push((false, row));
        }
        // Command sequence (unsequenced last), then creation time, then id.
        let mut keyed = Vec::with_capacity(messages.len());
        for (signal, row) in messages {
            let key = (
                DB::optional_number(&row, "command_sequence")?.unwrap_or(i64::MAX),
                DB::optional_instant(&row, "created_at")?,
                DB::string(&row, "id")?,
            );
            keyed.push((key, signal, row));
        }
        keyed.sort_by(|left, right| left.0.cmp(&right.0));
        for (_, signal, row) in keyed {
            if signal {
                Self::transfer_signal(tx, &row, next, workflow).await?;
            } else {
                Self::transfer_update(tx, &row, next, workflow).await?;
            }
        }
        Ok(())
    }

    async fn transfer_signal(
        tx: &mut Transaction<'_, DB>,
        signal: &DB::Row,
        next: &str,
        workflow: &str,
    ) -> Result<()> {
        let Some(command_id) = DB::optional_string(signal, "workflow_command_id")? else {
            return Ok(());
        };
        let Some(command) = Self::query("SELECT command_type,status,target_scope,workflow_run_id,workflow_instance_id FROM workflow_commands WHERE id=$1")
            .bind(&command_id).fetch_optional(&mut **tx).await?
        else {
            return Ok(());
        };
        // Only the signal's own accepted instance command moves; anything
        // else stays and is rejected when the predecessor closes.
        if DB::string(&command, "command_type")? != "signal"
            || DB::string(&command, "status")? != "accepted"
            || DB::string(&command, "target_scope")? != "instance"
            || DB::optional_string(&command, "workflow_run_id")? != DB::optional_string(signal, "workflow_run_id")?
            || DB::optional_string(&command, "workflow_instance_id")? != DB::optional_string(signal, "workflow_instance_id")?
        {
            return Ok(());
        }
        let signal_id = DB::string(signal, "id")?;
        let wait_id = format!("signal-command:{command_id}");
        Self::query("UPDATE workflow_signal_records SET workflow_run_id=$1,resolved_workflow_run_id=$2,signal_wait_id=$3 WHERE id=$4")
            .bind(next).bind(next).bind(&wait_id).bind(&signal_id).execute(&mut **tx).await?;
        Self::query("UPDATE workflow_commands SET workflow_run_id=$1,resolved_workflow_run_id=$2 WHERE id=$3")
            .bind(next).bind(next).bind(&command_id).execute(&mut **tx).await?;
        let mut payload = json!({"workflow_command_id":command_id,"signal_id":signal_id,
            "workflow_instance_id":workflow,"workflow_run_id":next,
            "signal_name":DB::string(signal, "signal_name")?,"signal_wait_id":wait_id});
        if let Some(arguments) = DB::optional_string(signal, "arguments")? {
            payload["arguments"] = wire(&arguments);
        }
        if let Some(codec) = DB::optional_string(signal, "payload_codec")? {
            payload["payload_codec"] = json!(codec);
        }
        Self::append(tx, next, "SignalReceived", payload, None, Some(&command_id)).await
    }

    async fn transfer_update(
        tx: &mut Transaction<'_, DB>,
        update: &DB::Row,
        next: &str,
        workflow: &str,
    ) -> Result<()> {
        let update_id = DB::string(update, "id")?;
        Self::query("UPDATE workflow_updates SET workflow_run_id=$1,resolved_workflow_run_id=$2 WHERE id=$3")
            .bind(next).bind(next).bind(&update_id).execute(&mut **tx).await?;
        let command_id = DB::optional_string(update, "workflow_command_id")?;
        let command = match &command_id {
            Some(command_id) => Self::query("SELECT command_type,status FROM workflow_commands WHERE id=$1")
                .bind(command_id).fetch_optional(&mut **tx).await?,
            None => None,
        };
        let command_id = match command {
            Some(command) => {
                let id = command_id.expect("a found command has an id");
                if DB::string(&command, "command_type")? == "update"
                    && DB::string(&command, "status")? == "accepted"
                {
                    Self::query("UPDATE workflow_commands SET workflow_run_id=$1,resolved_workflow_run_id=$2 WHERE id=$3")
                        .bind(next).bind(next).bind(&id).execute(&mut **tx).await?;
                }
                Some(id)
            }
            None => None,
        };
        let payload = json!({"workflow_command_id":command_id,"update_id":update_id,
            "workflow_instance_id":workflow,"workflow_run_id":next,
            "update_name":DB::string(update, "update_name")?,
            "arguments":DB::optional_string(update, "arguments")?.as_deref().map(wire)});
        Self::append(tx, next, "UpdateAccepted", payload, None, command_id.as_deref()).await
    }
}
