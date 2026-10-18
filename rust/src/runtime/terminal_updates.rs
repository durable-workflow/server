//! Resolve accepted updates in the same transaction as their original run.
use super::{Result, backend::Backend, refuse, store::{Store, id, now}, transaction::Transaction};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

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
    pub(super) async fn close_pending_updates(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        task: Option<&DB::Row>,
    ) -> Result<()> {
        // Read the newly closed row, not the caller's pre-transition snapshot.
        let run=Self::query("SELECT status,closed_reason,workflow_instance_id,message_cursor_position FROM workflow_runs WHERE id=$1")
            .bind(run_id).fetch_one(&mut **tx).await?;
        let status=DB::string(&run,"status")?;
        if !matches!(status.as_str(),"completed"|"failed"|"cancelled"|"terminated") {
            return Err(refuse(StatusCode::CONFLICT,"update_closure_requires_terminal_run"));
        }
        let reason=DB::optional_string(&run,"closed_reason")?.unwrap_or(status);
        let workflow=DB::string(&run,"workflow_instance_id")?;
        let mut cursor=DB::number(&run,"message_cursor_position")?;
        let task_id=task.map(|task|DB::string(task,"id")).transpose()?;
        let snapshot=task.map(Self::task_snapshot).transpose()?;
        let updates=Self::query("SELECT * FROM workflow_updates WHERE workflow_run_id=$1 AND status='accepted' ORDER BY CASE WHEN command_sequence IS NULL THEN 1 ELSE 0 END,command_sequence,accepted_at,id")
            .bind(run_id).fetch_all(&mut **tx).await?;
        for update in updates {
            let update_id=DB::string(&update,"id")?;
            let command=if let Some(command_id)=DB::optional_string(&update,"workflow_command_id")? {
                Self::query("SELECT * FROM workflow_commands WHERE id=$1").bind(command_id).fetch_optional(&mut **tx).await?
            } else {None};
            let command_id=command.as_ref().map(|command|DB::string(command,"id")).transpose()?;
            let name=DB::string(&update,"update_name")?;
            let message=format!("Workflow run closed as {reason} before accepted update {name} could be applied.");
            let failure_id=id();
            let closed_at=now();
            Self::query("INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,exception_class,message,non_retryable,handled,file,line,trace_preview,created_at,updated_at) VALUES ($1,$2,$3,$4,'update','application','RuntimeException',$5,TRUE,FALSE,'',0,'',$6,$7)")
                .bind(&failure_id).bind(run_id).bind(if command.is_some(){"workflow_command"}else{"workflow_update"})
                .bind(command_id.as_deref().unwrap_or(&update_id)).bind(&message)
                .bind(DB::bind_time(closed_at)).bind(DB::bind_time(closed_at)).execute(&mut **tx).await?;
            let mut payload=json!({"workflow_command_id":command_id,"update_id":update_id,
                "workflow_instance_id":workflow,"workflow_run_id":run_id,"update_name":name,
                "failure_id":failure_id,"failure_category":"application","non_retryable":true,
                "exception_class":"RuntimeException","message":message,"code":0,
                "exception":{"class":"RuntimeException","message":message,"code":0,"file":"","line":0,"trace":[],"properties":[]},
                "terminal_reason":reason});
            if let Some(snapshot)=&snapshot {payload["task"]=snapshot.clone();}
            if let Some(command)=&command {payload["command"]=Self::terminal_update_command_snapshot(command,&name)?;}
            Self::append(tx,run_id,"UpdateCompleted",payload,task_id.as_deref(),command_id.as_deref()).await?;
            Self::query("UPDATE workflow_updates SET status='failed',outcome='update_failed',failure_id=$1,failure_message=$2,closed_at=$3 WHERE id=$4")
                .bind(&failure_id).bind(&message).bind(DB::bind_time(closed_at)).bind(&update_id).execute(&mut **tx).await?;
            if let Some(command)=command {
                Self::query("UPDATE workflow_commands SET outcome='update_failed',applied_at=$1 WHERE id=$2")
                    .bind(DB::bind_time(closed_at)).bind(command_id.as_deref()).execute(&mut **tx).await?;
                if let Some(position)=DB::optional_number(&command,"message_sequence")?.filter(|position|*position>cursor) {
                    Self::query("UPDATE workflow_runs SET message_cursor_position=$1 WHERE id=$2")
                        .bind(position).bind(run_id).execute(&mut **tx).await?;
                    let mut advanced=json!({"stream_key":format!("instance:{workflow}"),"previous_position":cursor,"new_position":position});
                    if let Some(snapshot)=&snapshot {advanced["task"]=snapshot.clone();}
                    Self::append(tx,run_id,"MessageCursorAdvanced",advanced,task_id.as_deref(),None).await?;
                    cursor=position;
                }
            }
        }
        Ok(())
    }

    fn terminal_update_command_snapshot(command:&DB::Row,name:&str)->Result<Value> {
        // Current native update admission stores the control-plane request ID,
        // with no provider/principal or workflow caller context.
        let mut snapshot=json!({"id":DB::string(command,"id")?,
            "sequence":DB::optional_number(command,"command_sequence")?,
            "message_sequence":DB::optional_number(command,"message_sequence")?,
            "type":DB::string(command,"command_type")?,"target_scope":DB::string(command,"target_scope")?,
            "resolved_run_id":DB::optional_string(command,"resolved_workflow_run_id")?,
            "target_name":name,"payload_codec":DB::optional_string(command,"payload_codec")?,
            "payload":DB::optional_string(command,"payload")?,"source":DB::string(command,"source")?,
            "request_id":DB::optional_string(command,"request_id")?,"status":DB::string(command,"status")?,
            "outcome":DB::optional_string(command,"outcome")?});
        for field in ["accepted_at","applied_at","rejected_at"] {
            if let Some(at)=DB::optional_instant(command,field)? {
                snapshot[field]=json!(at.to_rfc3339_opts(chrono::SecondsFormat::Micros,true));
            }
        }
        snapshot.as_object_mut().unwrap().retain(|_,v|!v.is_null());
        Ok(snapshot)
    }
}
