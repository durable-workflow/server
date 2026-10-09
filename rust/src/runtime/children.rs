//! Sequential successful children, committed with their original parent call.
//! Retry, failure, cancellation, parallel groups and parent-close actions remain
//! unqualified and are refused at admission rather than silently discarded.
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
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Transaction, Type};

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
        ],
    )?;
    if text(command, "workflow_type")?.len() > 191
        || command.get("queue").is_some_and(|queue| {
            queue
                .as_str()
                .is_none_or(|queue| queue.trim().is_empty() || queue.len() > 191)
        })
        || ["parent_close_policy", "cancellation_policy"]
            .iter()
            .any(|field| command.get(*field).is_some_and(|value| value != "abandon"))
    {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_child_options",
        ));
    }
    envelope(command, "arguments")?;
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
    pub(super) async fn attach_workflow_contract(
        tx: &mut Transaction<'_, DB>,
        queue: &str,
        workflow_type: &str,
        started: &mut Value,
    ) -> Result<()> {
        let workers = Self::query("SELECT supported_workflow_types,workflow_command_contracts FROM workflow_worker_registrations WHERE namespace='default' AND task_queue=$1 AND status='active' AND workflow_command_contracts IS NOT NULL AND last_heartbeat_at>$2 ORDER BY id DESC LIMIT 100")
            .bind(queue).bind(DB::bind_time(now() - chrono::Duration::seconds(60))).fetch_all(&mut **tx).await?;
        for worker in workers {
            if DB::document_row(&worker, "supported_workflow_types")?
                .as_array()
                .is_some_and(|types| types.iter().any(|value| value == workflow_type))
            {
                let contracts = DB::document_row(&worker, "workflow_command_contracts")?;
                if let Some(contract) = contracts.get(workflow_type) {
                    for (source, target) in [
                        ("signals", "declared_signals"),
                        ("signal_contracts", "declared_signal_contracts"),
                        ("queries", "declared_queries"),
                        ("query_contracts", "declared_query_contracts"),
                        ("updates", "declared_updates"),
                        ("update_contracts", "declared_update_contracts"),
                        ("update_validators", "declared_update_validators"),
                    ] {
                        started[target] = contract[source].clone();
                    }
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
    ) -> Result<()> {
        let parent_run = DB::string(parent, "id")?;
        let parent_instance = DB::string(parent, "workflow_instance_id")?;
        // This first slice permits one outstanding authored child. A replayed
        // completion is handled by the enclosing immutable task receipt.
        for sql in [
            "SELECT COUNT(*) FROM workflow_child_calls WHERE parent_workflow_run_id=$1 AND status!='completed'",
            "SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=$1 AND status!='completed'",
            "SELECT COUNT(*) FROM workflow_run_timers WHERE workflow_run_id=$1 AND status='pending'",
        ] {
            if Self::scalar(sql)
                .bind(&parent_run)
                .fetch_one(&mut **tx)
                .await?
                != 0
            {
                return Err(refuse(StatusCode::CONFLICT, "pending_durable_operations"));
            }
        }
        if Self::open_wait(tx, &parent_run).await?.is_some() {
            return Err(refuse(StatusCode::CONFLICT, "pending_durable_operations"));
        }
        let workflow_type = text(command, "workflow_type")?;
        let queue = command
            .get("queue")
            .map_or(Ok(DB::string(parent, "queue")?), |_| {
                text(command, "queue").map(str::to_owned)
            })?;
        let arguments = envelope(command, "arguments")?;
        let child_instance = id();
        let child_run = id();
        let call = id();
        let started_at = now();
        // PHP children without explicit timeout options have nullable budgets
        // and deadlines. Do not invent parent defaults for these runs.
        Self::query("INSERT INTO workflow_instances(id,namespace,workflow_type,workflow_class,current_run_id,run_count,started_at,created_at,updated_at) VALUES ($1,'default',$2,$3,$4,1,$5,$6,$7)")
            .bind(&child_instance).bind(workflow_type).bind(workflow_type).bind(&child_run).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).execute(&mut **tx).await?;
        Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,namespace,workflow_type,workflow_class,run_number,status,payload_codec,arguments,queue,started_at,created_at,updated_at) VALUES ($1,$2,'default',$3,$4,1,'running','avro',$5,$6,$7,$8,$9)")
            .bind(&child_run).bind(&child_instance).bind(workflow_type).bind(workflow_type).bind(&arguments).bind(&queue).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).execute(&mut **tx).await?;
        Self::query("INSERT INTO workflow_child_calls(parent_workflow_run_id,parent_workflow_instance_id,sequence,child_workflow_type,child_workflow_class,resolved_child_instance_id,resolved_child_run_id,parent_close_policy,queue,status,scheduled_at,started_at,arguments,metadata,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,'abandon',$8,'started',$9,$10,$11,$12,$13,$14)")
            .bind(&parent_run).bind(&parent_instance).bind(sequence).bind(workflow_type).bind(workflow_type).bind(&child_instance).bind(&child_run).bind(&queue).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at))
            .bind(DB::document(&json!({"payload":arguments}))).bind(DB::document(&json!({"child_call_id":call,"attempt_count":1,"cancellation_policy":"abandon"}))).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).execute(&mut **tx).await?;
        Self::query("INSERT INTO workflow_links(id,link_type,sequence,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,child_workflow_run_id,is_primary_parent,parent_close_policy,created_at,updated_at) VALUES ($1,'child_workflow',$2,$3,$4,$5,$6,TRUE,'abandon',$7,$8)")
            .bind(&call).bind(sequence).bind(&parent_instance).bind(&parent_run).bind(&child_instance).bind(&child_run).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).execute(&mut **tx).await?;
        let scheduled = json!({"sequence":sequence,"workflow_link_id":call,"child_call_id":call,"child_workflow_instance_id":child_instance,"child_workflow_run_id":child_run,"child_workflow_class":workflow_type,"child_workflow_type":workflow_type,"parent_close_policy":"abandon","cancellation_policy":"abandon","retry_policy":null,"timeout_policy":null});
        Self::append(
            tx,
            &parent_run,
            "ChildWorkflowScheduled",
            scheduled.clone(),
            Some(task_id),
            None,
        )
        .await?;
        let mut started = scheduled;
        started["child_run_number"] = json!(1);
        for field in [
            "execution_timeout_seconds",
            "run_timeout_seconds",
            "execution_deadline_at",
            "run_deadline_at",
        ] {
            started[field] = Value::Null;
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
        let mut child_started = json!({"workflow_class":workflow_type,"workflow_type":workflow_type,"workflow_instance_id":child_instance,"workflow_run_id":child_run,"parent_workflow_instance_id":parent_instance,"parent_workflow_run_id":parent_run,"parent_sequence":sequence,"workflow_link_id":call,"child_call_id":call,"retry_policy":null,"timeout_policy":null,"execution_timeout_seconds":null,"run_timeout_seconds":null,"execution_deadline_at":null,"run_deadline_at":null});
        Self::attach_workflow_contract(tx, &queue, workflow_type, &mut child_started).await?;
        Self::append(tx, &child_run, "WorkflowStarted", child_started, None, None).await?;
        Self::create_task(tx, &child_run, &queue, "workflow", json!({})).await?;
        Self::query("UPDATE workflow_runs SET status='waiting' WHERE id=$1")
            .bind(&parent_run)
            .execute(&mut **tx)
            .await?;
        Ok(())
    }

    pub(super) async fn complete_child(
        tx: &mut Transaction<'_, DB>,
        child: &DB::Row,
        output: &str,
        closed_at: DateTime<Utc>,
    ) -> Result<()> {
        let child_run = DB::string(child, "id")?;
        let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(&child_run).fetch_one(&mut **tx).await?;
        let original = DB::document_row(&started, "payload")?;
        let links = Self::query("SELECT * FROM workflow_links WHERE child_workflow_run_id=$1 AND link_type='child_workflow' LIMIT 2").bind(&child_run).fetch_all(&mut **tx).await?;
        if links.is_empty() && original.get("parent_workflow_run_id").is_none() {
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
        let call_id = DB::string(link, "id")?;
        if original["parent_workflow_run_id"] != parent_run
            || original["parent_workflow_instance_id"]
                != DB::string(link, "parent_workflow_instance_id")?
            || original["parent_sequence"] != sequence
            || original["workflow_link_id"] != call_id
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
            || DB::string(call, "child_workflow_type")? != DB::string(child, "workflow_type")?
            || DB::string(link, "child_workflow_instance_id")?
                != DB::string(child, "workflow_instance_id")?
            || DB::string(call, "parent_workflow_instance_id")?
                != DB::string(link, "parent_workflow_instance_id")?
        {
            return Err(refuse(StatusCode::CONFLICT, "child_call_mismatch"));
        }
        Self::query("UPDATE workflow_child_calls SET status='completed',closed_reason='completed',closed_at=$1,updated_at=$2 WHERE id=$3")
            .bind(DB::bind_time(closed_at)).bind(DB::bind_time(closed_at)).bind(DB::number(call, "id")?).execute(&mut **tx).await?;
        let parent = Self::query("SELECT r.*,i.current_run_id FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE r.id=$1").bind(&parent_run).fetch_one(&mut **tx).await?;
        if DB::string(&parent, "workflow_instance_id")?
            != DB::string(link, "parent_workflow_instance_id")?
        {
            return Err(refuse(StatusCode::CONFLICT, "child_call_mismatch"));
        }
        if !matches!(
            DB::string(&parent, "status")?.as_str(),
            "running" | "waiting"
        ) || DB::string(&parent, "current_run_id")? != parent_run
        {
            // Abandoned children may finish after their original parent closes.
            // Never enqueue a task for a closed or replacement parent run.
            return Ok(());
        }
        if Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')").bind(&parent_run).fetch_one(&mut **tx).await? != 0 {
            return Err(refuse(StatusCode::CONFLICT, "child_parent_task_conflict"));
        }
        Self::append(tx, &parent_run, "ChildRunCompleted", json!({"sequence":sequence,"workflow_link_id":call_id,"child_call_id":call_id,"child_workflow_instance_id":DB::string(child,"workflow_instance_id")?,"child_workflow_run_id":child_run,"child_workflow_class":DB::string(child,"workflow_class")?,"child_workflow_type":DB::string(child,"workflow_type")?,"child_run_number":DB::number(child,"run_number")?,"child_status":"completed","closed_reason":"completed","closed_at":closed_at,"output":wire(output),"result":wire(output),"payload_codec":"avro"}), None, None).await?;
        Self::create_task(tx, &parent_run, &DB::string(&parent,"queue")?, "workflow", json!({"workflow_wait_kind":"child","child_call_id":call_id,"child_workflow_run_id":child_run,"resume_source_kind":"child_workflow_run","resume_source_id":child_run,"workflow_sequence":sequence,"workflow_event_type":"ChildRunCompleted","open_wait_id":format!("child:{call_id}")})).await?;
        Ok(())
    }
}
