//! Atomic first continuation slice. Related children, pending messages/updates,
//! typed visibility and streams require separate transfer qualification.
use super::{
    Result,
    backend::Backend,
    envelope, refuse,
    store::{Store, id, now, reject_fields},
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Transaction, Type};

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
    pub(super) async fn list_runs(&self, workflow: &str) -> Result<Value> {
        let rows=Self::query("SELECT r.id,r.workflow_type,r.status,r.run_number,r.closed_reason,r.started_at,r.closed_at,i.current_run_id FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE i.id=$1 AND i.namespace=$2 ORDER BY r.run_number LIMIT 1025")
            .bind(workflow).bind(self.namespace.as_ref()).fetch_all(&self.pool).await?;
        if rows.is_empty() {
            return Err(refuse(StatusCode::NOT_FOUND, "instance_not_found"));
        }
        if rows.len() > 1024 {
            return Err(refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "workflow_runs_exceed_read_budget",
            ));
        }
        let mut runs = Vec::with_capacity(rows.len());
        for row in rows {
            let run = DB::string(&row, "id")?;
            runs.push(json!({"workflow_id":workflow,"run_id":run,"workflow_type":DB::string(&row,"workflow_type")?,
                "status":DB::string(&row,"status")?,"run_number":DB::number(&row,"run_number")?,
                "closed_reason":DB::optional_string(&row,"closed_reason")?,"is_current_run":run==DB::string(&row,"current_run_id")?,
                "started_at":DB::instant(&row,"started_at")?,"closed_at":DB::optional_instant(&row,"closed_at")?}));
        }
        Ok(json!({"workflow_id":workflow,"namespace":self.namespace.as_ref(),"runs":runs}))
    }

    pub(super) async fn continue_run(
        &self,
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        task: &DB::Row,
        command: &Value,
        sequence: i64,
    ) -> Result<()> {
        let old = DB::string(run, "id")?;
        let workflow = DB::string(run, "workflow_instance_id")?;
        for field in ["workflow_type", "queue"] {
            if command.get(field).is_some_and(|value| !value.is_null())
                && super::text(command, field)? != DB::string(run, field)?
            {
                return Err(refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "continue_as_new_routing_unqualified",
                ));
            }
        }
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
        for (sql, selected) in [
            (
                "SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=$1 AND status NOT IN ('completed','failed','cancelled')",
                old.as_str(),
            ),
            (
                "SELECT COUNT(*) FROM workflow_run_timers WHERE workflow_run_id=$1 AND status='pending'",
                old.as_str(),
            ),
            (
                "SELECT COUNT(*) FROM workflow_child_calls WHERE parent_workflow_run_id=$1",
                old.as_str(),
            ),
            (
                "SELECT COUNT(*) FROM workflow_links WHERE child_workflow_run_id=$1 AND link_type='child_workflow'",
                old.as_str(),
            ),
            (
                "SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$1 AND status NOT IN ('completed','failed','rejected','cancelled')",
                old.as_str(),
            ),
            (
                "SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$1 AND status='received'",
                old.as_str(),
            ),
            (
                "SELECT COUNT(*) FROM workflow_search_attributes WHERE workflow_run_id=$1",
                old.as_str(),
            ),
            (
                "SELECT COUNT(*) FROM workflow_durable_streams WHERE workflow_instance_id=$1",
                workflow.as_str(),
            ),
            (
                "SELECT COUNT(*) FROM workflow_inbound_streams WHERE workflow_instance_id=$1",
                workflow.as_str(),
            ),
            (
                "SELECT COUNT(*) FROM workflow_service_calls WHERE caller_workflow_run_id=$1",
                old.as_str(),
            ),
            (
                "SELECT COUNT(*) FROM workflow_service_calls WHERE linked_workflow_run_id=$1",
                old.as_str(),
            ),
        ] {
            if Self::scalar(sql)
                .bind(selected)
                .fetch_one(&mut **tx)
                .await?
                != 0
            {
                return Err(refuse(
                    StatusCode::CONFLICT,
                    "continue_as_new_transfer_unqualified",
                ));
            }
        }
        if Self::open_wait(tx, &old).await?.is_some() {
            return Err(refuse(
                StatusCode::CONFLICT,
                "continue_as_new_transfer_unqualified",
            ));
        }
        // Select this run before applying the bound. Receipt retention does
        // not extend original query execution, and unrelated cache prefixes
        // cannot hide a live claim. Read timing only, never history snapshots.
        let queries = Self::query(DB::QUERY_CACHE_PENDING_FOR_RUN)
            .bind(&old)
            .bind(now().timestamp())
            .fetch_all(&mut **tx)
            .await?;
        if queries.len() > super::queries::MAX_ENTRIES {
            return Err(refuse(
                StatusCode::CONFLICT,
                "continue_as_new_transfer_unqualified",
            ));
        }
        let observed = super::query_authority::now();
        for query in queries {
            let timing = json!({
                "query_admitted_at":DB::optional_string(&query,"query_admitted_at")?,
                "query_deadline_at":DB::optional_string(&query,"query_deadline_at")?,
                "receipt_expires_at":DB::optional_string(&query,"receipt_expires_at")?,
            });
            if super::query_authority::deadline(&timing).is_none_or(|deadline| observed < deadline)
            {
                return Err(refuse(
                    StatusCode::CONFLICT,
                    "continue_as_new_transfer_unqualified",
                ));
            }
        }
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
        let kind = DB::string(run, "workflow_type")?;
        let class = DB::string(run, "workflow_class")?;
        let queue = DB::string(run, "queue")?;
        Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,workflow_type,workflow_class,run_number,status,payload_codec,arguments,connection,queue,business_key,visibility_labels,compatibility,run_timeout_seconds,execution_deadline_at,run_deadline_at,started_at,created_at,updated_at,namespace,last_progress_at) SELECT $1,workflow_instance_id,workflow_type,workflow_class,$2,'pending',payload_codec,$3,connection,queue,business_key,visibility_labels,compatibility,run_timeout_seconds,execution_deadline_at,$4,$5,$6,$7,namespace,$8 FROM workflow_runs WHERE id=$9")
            .bind(&next).bind(number).bind(&arguments).bind(DB::optional_time(deadline))
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
        Self::query("UPDATE workflow_instances SET current_run_id=$1,run_count=$2,updated_at=$3 WHERE id=$4")
            .bind(&next).bind(number).bind(DB::bind_time(started)).bind(&workflow).execute(&mut **tx).await?;
        Self::query("INSERT INTO workflow_links(id,link_type,sequence,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,child_workflow_run_id,is_primary_parent,parent_close_policy,created_at,updated_at) VALUES ($1,'continue_as_new',$2,$3,$4,$5,$6,TRUE,'abandon',$7,$8)")
            .bind(&link).bind(sequence).bind(&workflow).bind(&old).bind(&workflow).bind(&next)
            .bind(DB::bind_time(started)).bind(DB::bind_time(started)).execute(&mut **tx).await?;
        Self::query("UPDATE workflow_runs SET status='completed',closed_reason='continued',closed_at=$1,last_progress_at=$2 WHERE id=$3")
            .bind(DB::bind_time(started)).bind(DB::bind_time(started)).bind(&old).execute(&mut **tx).await?;
        let task_id = DB::string(task, "id")?;
        Self::append(tx,&old,"WorkflowContinuedAsNew",json!({"sequence":sequence,"continued_to_run_id":next,
            "continued_to_run_number":number,"workflow_link_id":link,"closed_reason":"continued","task":Self::task_snapshot(task)?}),Some(&task_id),None).await?;
        Self::append(tx,&next,"WorkflowStarted",json!({"workflow_class":class,"workflow_type":kind,
            "workflow_instance_id":workflow,"workflow_run_id":next,"continued_from_run_id":old,"workflow_link_id":link}),None,None).await?;
        Self::create_task(tx, &next, &queue, "workflow", json!({})).await?;
        Ok(())
    }
}
