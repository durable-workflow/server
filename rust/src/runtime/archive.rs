//! Archiving a closed run, as published PHP's `WorkflowStub::attemptArchive`
//! does: an archive command, `ArchiveRequested`, and on the first archive the
//! run's archive columns and `WorkflowArchived`. Nothing is deleted.
use super::laravel_rules::{self, Rule};
use super::{Result, RuntimeError, backend::Backend, refuse, store::{Store, id, now}};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

/// `WorkflowController::archive`'s rules.
const RULES: &laravel_rules::Rules = &[
    ("reason", &[Rule::Nullable, Rule::String, Rule::Max(1000)]),
    ("request_id", &[Rule::Nullable, Rule::String, Rule::Max(255)]),
];

/// The validated reason, if any. Laravel trims it and turns an empty one
/// into null first.
pub(super) fn reason(body: &Value, workflow_id: &str) -> Result<Option<String>> {
    let body = laravel_rules::normalize(body);
    if let Err(RuntimeError::Protocol { status, mut response }) = laravel_rules::validate(&body, RULES) {
        // PHP names the workflow on this validation failure.
        response["workflow_id"] = json!(workflow_id);
        return Err(RuntimeError::Protocol { status, response });
    }
    Ok(body["reason"].as_str().map(str::to_owned))
}

/// `archiveCommandPayloadAttributes`: the command payload `{reason}`.
pub(super) fn payload(reason: Option<&str>) -> Result<String> {
    use crate::codec::{Value as Datum, ValueCodec};
    let reason = reason.map_or(Datum::Null, |reason| Datum::String(reason.to_owned()));
    ValueCodec::new()
        .and_then(|codec| codec.encode(&Datum::Map(std::collections::BTreeMap::from([("reason".into(), reason)]))))
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "archive_codec_unavailable"))
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
    pub(crate) async fn archive_workflow(
        &self,
        workflow_id: &str,
        selected_run: Option<&str>,
        reason: Option<String>,
        blob: String,
        context: Value,
    ) -> Result<(StatusCode, Value)> {
        let mut tx = self.begin().await?;
        let run = Self::query("SELECT r.* FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1 AND i.namespace=$2")
            .bind(workflow_id).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        let run_id = DB::string(&run, "id")?;
        if selected_run.is_some_and(|id| id != run_id) {
            return Err(RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: json!({"message":"Commands cannot target historical runs. Use the instance-level endpoint to command the current run, or omit the run ID.",
                    "workflow_id":workflow_id,"run_id":selected_run,"reason":"historical_run_command_rejected","target_scope":"run"}),
            });
        }
        let open = matches!(DB::string(&run, "status")?.as_str(), "pending" | "running" | "waiting");
        let archived = DB::optional_string(&run, "archive_command_id")?.is_some()
            || DB::optional_instant(&run, "archived_at")?.is_some();
        let command_id = id();
        let sequence = DB::number(&run, "last_command_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let at = now();
        let (status, outcome, rejection) = if open {
            ("rejected", "rejected_run_not_closed", Some("run_not_closed"))
        } else if archived {
            ("accepted", "archive_not_needed", None)
        } else {
            ("accepted", "archived", None)
        };
        let decided = |when: bool| DB::optional_time(when.then_some(at));
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,target_scope,source,status,outcome,rejection_reason,workflow_type,payload_codec,payload,command_sequence,accepted_at,applied_at,rejected_at,context) VALUES ($1,$2,$3,$4,'archive','instance','control_plane',$5,$6,$7,$8,'avro',$9,$10,$11,$12,$13,$14)")
            .bind(&command_id).bind(workflow_id).bind(&run_id).bind(&run_id).bind(status).bind(outcome).bind(rejection)
            .bind(DB::string(&run, "workflow_type")?).bind(&blob).bind(sequence)
            .bind(decided(!open)).bind(decided(!open)).bind(decided(open)).bind(DB::document(&context))
            .execute(&mut *tx).await?;
        Self::query("UPDATE workflow_runs SET last_command_sequence=$1 WHERE id=$2")
            .bind(sequence)
            .bind(&run_id)
            .execute(&mut *tx)
            .await?;
        let mut response = json!({"outcome":outcome,"workflow_id":workflow_id,"run_id":run_id,"requested_run_id":null,
            "resolved_run_id":run_id,"command_id":command_id,"command_sequence":sequence,"target_scope":"instance",
            "workflow_type":DB::string(&run, "workflow_type")?,"command_status":status,"command_source":"control_plane",
            "reason":if open { json!(rejection) } else { json!(reason) },"rejection_reason":rejection,"validation_errors":[]});
        if open {
            response["accepted"] = json!(false);
            tx.commit().await?;
            return Ok((StatusCode::CONFLICT, response));
        }
        let mut requested = json!({"workflow_command_id":command_id,"workflow_instance_id":workflow_id,
            "workflow_run_id":run_id,"command_type":"archive","outcome":outcome});
        if let Some(reason) = &reason {
            requested["reason"] = json!(reason);
        }
        Self::append(&mut tx, &run_id, "ArchiveRequested", requested, None, Some(&command_id)).await?;
        if !archived {
            Self::query("UPDATE workflow_runs SET archived_at=$1,archive_command_id=$2,archive_reason=$3,last_progress_at=$4 WHERE id=$5")
                .bind(DB::bind_time(at)).bind(&command_id).bind(reason.as_deref()).bind(DB::bind_time(at)).bind(&run_id)
                .execute(&mut *tx).await?;
            let mut event = json!({"workflow_command_id":command_id,"workflow_instance_id":workflow_id,
                "workflow_run_id":run_id,"archive_command_id":command_id});
            if let Some(reason) = &reason {
                event["reason"] = json!(reason);
            }
            Self::append(&mut tx, &run_id, "WorkflowArchived", event, None, Some(&command_id)).await?;
        }
        tx.summary_changed(&run_id);
        tx.commit().await?;
        Ok((StatusCode::OK, response))
    }
}
