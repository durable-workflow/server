//! Explicit recovery of a worker-reported version-range blockage.
use super::{
    Result, RuntimeError,
    backend::Backend,
    refuse,
    store::{Store, id, now},
    wire,
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::sync::Arc;
use tokio::sync::Semaphore;

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
    pub(crate) async fn repair_range_blocked(
        &self,
        workflow_id: &str,
        requested_run_id: Option<&str>,
        body: Value,
        context: Value,
        codec: Arc<Semaphore>,
    ) -> Result<Value> {
        if !body.is_object()
            || body.get("request_id").is_some_and(|value| {
                !value.is_null() && value.as_str().is_none_or(|id| id.chars().count() > 255)
            })
        {
            return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_request"));
        }
        if self.namespace.as_ref() != "default" {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_workflow_task_repair_namespace_unqualified",
            ));
        }
        let mut tx = self.begin().await?;
        let instance = Self::query("SELECT * FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(workflow_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&mut *tx)
            .await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        let run_id = DB::string(&instance, "current_run_id")?;
        if requested_run_id.is_some_and(|selected| selected != run_id) {
            return Err(RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: json!({
                    "workflow_id":workflow_id,"run_id":requested_run_id,"target_scope":"run",
                    "reason":"historical_run_command_rejected",
                    "message":"Commands cannot target historical runs. Use the instance-level endpoint to command the current run, or omit the run ID."
                }),
            });
        }
        let run = Self::active_run(&mut tx, &run_id).await?;
        let task = Self::query(DB::RANGE_REPAIR_TASK_SQL)
            .bind(&run_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&mut *tx)
            .await?
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "development_workflow_task_repair_unqualified",
                )
            })?;
        let open = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND status IN ('ready','leased')")
            .bind(&run_id).fetch_one(&mut *tx).await?;
        let signals = Self::scalar("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$1 AND status='received'")
            .bind(&run_id).fetch_one(&mut *tx).await?;
        let updates = Self::scalar(
            "SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$1 AND status='accepted'",
        )
        .bind(&run_id)
        .fetch_one(&mut *tx)
        .await?;
        if open != 0 || signals != 0 || updates != 0 || Self::cancellation_request(&run)?.is_some()
        {
            // Pending activity/message/cancellation repair needs its own shared
            // contract. Do not create another ready task beside active work.
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_workflow_task_repair_active_work_unqualified",
            ));
        }
        let task_id = DB::string(&task, "id")?;
        let repair_count = DB::number(&task, "repair_count")?
            .checked_add(1)
            .filter(|count| *count <= DB::REPAIR_COUNTER_MAX)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "repair_count_exhausted"))?;
        let sequence = DB::number(&run, "last_command_sequence")?
            .checked_add(1)
            .filter(|count| *count <= DB::REPAIR_COUNTER_MAX)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let command_payload = super::worker_incarnations::repair_payload(
            codec,
            task_id.clone(),
            "workflow_replay_blocked",
            None,
        )
        .await?;
        let mut task_payload = DB::document_row(&task, "payload")?;
        task_payload
            .as_object_mut()
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "invalid_workflow_task_payload"))?
            .retain(|key, _| !key.starts_with("replay_blocked"));
        let timestamp = now();
        let command_id = id();
        Self::query("UPDATE workflow_tasks SET status='ready',payload=$1,leased_at=NULL,lease_owner=NULL,lease_expires_at=NULL,repair_count=$2,repair_available_at=NULL,last_error=NULL,updated_at=$3 WHERE id=$4")
            .bind(DB::document(&task_payload)).bind(repair_count).bind(DB::bind_time(timestamp)).bind(&task_id).execute(&mut *tx).await?;
        // PHP guards a selected run then records an instance-targeted repair.
        // Preserve that command identity rather than treating the URL as scope.
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,source,target_scope,status,outcome,workflow_type,payload_codec,payload,command_sequence,accepted_at,applied_at,context) VALUES ($1,$2,$3,$4,'repair','control_plane','instance','accepted','repair_dispatched',$5,'avro',$6,$7,$8,$9,$10)")
            .bind(&command_id).bind(workflow_id).bind(&run_id).bind(&run_id).bind(DB::string(&run, "workflow_type")?)
            .bind(&command_payload).bind(sequence).bind(DB::bind_time(timestamp)).bind(DB::bind_time(timestamp))
            .bind(DB::document(&context)).execute(&mut *tx).await?;
        Self::query(
            "UPDATE workflow_runs SET last_command_sequence=$1,last_progress_at=$2 WHERE id=$3",
        )
        .bind(sequence)
        .bind(DB::bind_time(timestamp))
        .bind(&run_id)
        .execute(&mut *tx)
        .await?;
        let repaired = Self::query("SELECT * FROM workflow_tasks WHERE id=$1")
            .bind(&task_id)
            .fetch_one(&mut *tx)
            .await?;
        Self::append(&mut tx, &run_id, "RepairRequested", json!({
            "workflow_command_id":command_id,"workflow_instance_id":workflow_id,"workflow_run_id":run_id,
            "command_type":"repair","outcome":"repair_dispatched","liveness_state":"workflow_replay_blocked",
            "wait_kind":null,"task_id":task_id,"task_type":"workflow","task":Self::task_snapshot(&repaired)?,
            "command":{"id":command_id,"sequence":sequence,"type":"repair","target_scope":"instance",
                "resolved_run_id":run_id,"status":"accepted","outcome":"repair_dispatched",
                "source":"control_plane","payload_codec":"avro","payload":wire(&command_payload),
                "context":{"principal":context["principal"]},
                "caller_label":context["caller"]["label"],"auth_status":context["auth"]["status"],
                "auth_method":context["auth"]["method"],"principal_type":context["principal"]["type"],
                "principal_id":context["principal"]["id"],"principal_label":context["principal"]["label"],
                "request_method":context["request"]["method"],"request_path":context["request"]["path"],
                "request_fingerprint":context["request"]["fingerprint"],"accepted_at":timestamp,"applied_at":timestamp}
        }), Some(&task_id), Some(&command_id)).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"workflow_id":workflow_id,"run_id":run_id,"workflow_type":DB::string(&run, "workflow_type")?,
            "namespace":self.namespace.as_ref(),"command_id":command_id,"command_status":"accepted",
            "command_source":"control_plane","outcome":"repair_dispatched","requested_run_id":null,
            "resolved_run_id":run_id,"target_scope":"instance","reason":null}),
        )
    }

    /// Physical diagnostics for the range checkpoint. Broader diagnostics
    /// remain gated rather than returning invented empty message/failure data.
    pub(crate) async fn range_debug(
        &self,
        workflow_id: &str,
        selected_run: Option<&str>,
    ) -> Result<Value> {
        if self.namespace.as_ref() != "default" {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_range_diagnostics_namespace_unqualified",
            ));
        }
        let mut tx = self.begin().await?;
        let run = Self::query("SELECT r.* FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE i.id=$1 AND r.id=COALESCE($2,i.current_run_id) AND r.namespace=$3")
            .bind(workflow_id).bind(selected_run).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, if selected_run.is_some() { "run_not_found" } else { "instance_not_found" }))?;
        let run_id = DB::string(&run, "id")?;
        let unsupported = Self::scalar("SELECT COUNT(*) FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type NOT IN ('StartAccepted','WorkflowStarted','VersionMarkerRecorded','ActivityScheduled','ActivityStarted','ActivityCompleted','RepairRequested','WorkflowCompleted')")
            .bind(&run_id).fetch_one(&mut *tx).await?;
        let failures =
            Self::scalar("SELECT COUNT(*) FROM workflow_failures WHERE workflow_run_id=$1")
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
        let signals =
            Self::scalar("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$1")
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
        let updates =
            Self::scalar("SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$1")
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
        let open = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')")
            .bind(&run_id).fetch_one(&mut *tx).await?;
        if unsupported != 0
            || failures != 0
            || signals != 0
            || updates != 0
            || open != 0
            || Self::cancellation_request(&run)?.is_some()
        {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_range_diagnostics_shape_unqualified",
            ));
        }
        let failed = Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status='failed' ORDER BY updated_at DESC,id DESC LIMIT 1")
            .bind(&run_id).fetch_optional(&mut *tx).await?;
        let mut latest_failure = Value::Null;
        let mut blocked_message = None;
        if let Some(task) = failed {
            let payload = DB::document_row(&task, "payload")?;
            if payload["replay_blocked"] != true
                || payload["failure_reason"] != "version_marker_incompatible_range"
            {
                return Err(refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "development_range_diagnostics_failure_unqualified",
                ));
            }
            blocked_message = DB::optional_string(&task, "last_error")?;
            latest_failure = json!({"task_id":DB::string(&task,"id")?,"message":blocked_message,
                "type":payload["failure_type"],"reason":payload["failure_reason"],"sequence":payload["failure_sequence"],
                "replay_blocked":true,"recorded_at":DB::instant(&task,"updated_at")?});
            latest_failure
                .as_object_mut()
                .unwrap()
                .retain(|_, value| !value.is_null());
        }
        let activities = Self::query("SELECT * FROM activity_executions WHERE workflow_run_id=$1 AND status IN ('pending','running') ORDER BY started_at,sequence,id LIMIT 26")
            .bind(&run_id).fetch_all(&mut *tx).await?;
        if activities.len() > 25
            || activities
                .iter()
                .any(|row| DB::string(row, "status").is_ok_and(|status| status != "pending"))
        {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_range_diagnostics_activity_unqualified",
            ));
        }
        let mut pending = Vec::new();
        for activity in &activities {
            let mut value = json!({"activity_execution_id":DB::string(activity,"id")?,
                "activity_type":DB::string(activity,"activity_type")?,"activity_class":DB::string(activity,"activity_class")?,
                "status":DB::string(activity,"status")?,"queue":DB::string(activity,"queue")?,
                "attempt_count":DB::number(activity,"attempt_count")?});
            for field in [
                "started_at",
                "last_heartbeat_at",
                "schedule_deadline_at",
                "close_deadline_at",
                "schedule_to_close_deadline_at",
                "heartbeat_deadline_at",
            ] {
                if let Some(time) = DB::optional_instant(activity, field)? {
                    value[field] = json!(time);
                }
            }
            pending.push(value);
        }
        let status = DB::string(&run, "status")?;
        let terminal = status == "completed";
        let repaired = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND repair_count>0")
            .bind(&run_id).fetch_one(&mut *tx).await? > 0;
        let blocked = !latest_failure.is_null();
        let (liveness, reason) = if terminal {
            ("closed", "Run closed as completed.".to_owned())
        } else if blocked {
            (
                "workflow_replay_blocked",
                blocked_message.unwrap_or_else(|| "Workflow replay is blocked.".into()),
            )
        } else if !activities.is_empty() {
            let task = Self::query("SELECT id FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='activity' AND status='ready' ORDER BY available_at,id LIMIT 1")
                .bind(&run_id).fetch_optional(&mut *tx).await?
                .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY,"development_range_diagnostics_activity_unqualified"))?;
            (
                "activity_task_ready",
                format!(
                    "Activity task {} is ready to run.",
                    DB::string(&task, "id")?
                ),
            )
        } else {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_range_diagnostics_liveness_unqualified",
            ));
        };
        let mut execution = json!({"status":status,"status_bucket":super::visibility::status_bucket(&status)?,
            "is_terminal":terminal,"workflow_type":DB::string(&run,"workflow_type")?,"task_queue":DB::string(&run,"queue")?,
            "run_number":DB::number(&run,"run_number")?,"started_at":DB::instant(&run,"started_at")?,
            "closed_at":DB::optional_instant(&run,"closed_at")?,"last_progress_at":DB::optional_instant(&run,"last_progress_at")?,
            "liveness_state":liveness,"liveness_reason":reason,"repair_attention":false,"task_problem":blocked || repaired});
        execution
            .as_object_mut()
            .unwrap()
            .retain(|_, value| !value.is_null());
        if !terminal && !blocked && !activities.is_empty() {
            execution["wait_kind"] = json!("activity");
            execution["wait_reason"] = json!(format!(
                "Waiting for activity {}",
                DB::string(&activities[0], "activity_type")?
            ));
        }
        if blocked || repaired {
            execution["task_problem_badge"] = if blocked {
                json!({"code":"replay_blocked","label":"Task Failure","description":"The selected run has a workflow-task replay failure or unsupported history.","tone":"dark","badge_visible":true})
            } else {
                json!({"code":"history","label":"Task History","description":"The selected run previously needed workflow-task repair or replay recovery.","tone":"secondary","badge_visible":true})
            };
        }
        tx.commit().await?;
        Ok(
            json!({"generated_at":now(),"workflow_id":workflow_id,"run_id":run_id,"namespace":self.namespace.as_ref(),
            "diagnostic_status":if terminal {"terminal"} else if blocked {"needs_attention"} else {"pending_work"},
            "execution":execution,"pending_workflow_tasks":[],"pending_activities":pending,
            "recent_failures":[],"recent_failures_truncated":false,"latest_workflow_task_failure":latest_failure}),
        )
    }
}
