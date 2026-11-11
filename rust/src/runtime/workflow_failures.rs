//! Atomic worker-authored terminal failure, distinct from retryable task failure.
use super::{
    Result,
    backend::Backend,
    children::ChildResolution,
    refuse,
    store::{Store, id, now},
    transaction::Transaction,
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

/// The most bytes a MySQL/MariaDB `TEXT` column holds, which bounds
/// `workflow_failures.message`.
const MESSAGE_COLUMN_BYTES: usize = 65_535;
/// Core's marker for a message whose full bytes are kept elsewhere.
const OVERFLOW_MARKER: &str = "\n[full message stored separately]";

/// The value `workflow_failures.message` stores (Server #1135). A message over
/// the TEXT capacity, or holding a NUL, would fail the write on MySQL/MariaDB
/// or PostgreSQL, so it becomes Core's labelled compatibility preview: NULs
/// escaped, cut on a character boundary, then the marker. Native keeps no
/// second column; retained history carries the full failure, as it does for
/// a preview a PHP takeover carried over.
pub(super) fn stored_message(message: &str) -> std::borrow::Cow<'_, str> {
    if message.len() <= MESSAGE_COLUMN_BYTES && !message.contains('\0') {
        return std::borrow::Cow::Borrowed(message);
    }
    let escaped = message.replace('\0', "\\0");
    let mut end = (MESSAGE_COLUMN_BYTES - OVERFLOW_MARKER.len()).min(escaped.len());
    while !escaped.is_char_boundary(end) {
        end -= 1;
    }
    std::borrow::Cow::Owned(format!("{}{OVERFLOW_MARKER}", &escaped[..end]))
}

#[cfg(test)]
mod stored_message_tests {
    use super::*;

    #[test]
    fn long_or_nul_messages_store_cores_labelled_preview() {
        assert_eq!(stored_message("boom"), "boom");
        let fits = "x".repeat(MESSAGE_COLUMN_BYTES);
        assert_eq!(stored_message(&fits), fits.as_str());
        // A multi-byte character straddling the cut is not split.
        let long = format!("{}λ{}", "x".repeat(MESSAGE_COLUMN_BYTES - OVERFLOW_MARKER.len() - 1), "y".repeat(100));
        let preview = stored_message(&long);
        assert!(preview.len() <= MESSAGE_COLUMN_BYTES);
        assert!(preview.ends_with(OVERFLOW_MARKER), "{}", &preview[preview.len() - 40..]);
        assert!(!preview.contains('λ'));
        assert_eq!(stored_message("a\0b"), format!("a\\0b{OVERFLOW_MARKER}"));
    }
}

pub(super) struct WorkflowFailure {
    message: String,
    class: String,
    exception_type: Option<String>,
    exception: Value,
    category: &'static str,
    non_retryable: bool,
    activity_boundary: Option<(i64, String)>,
}

fn string(value: &Value) -> Option<&str> {
    value
        .as_str()
        .map(super::task_failures::php_trim)
        .filter(|v| !v.is_empty())
}

pub(super) fn validate_batch(commands: &[Value]) -> Result<()> {
    // A second terminal command is the bridge's 409, after the ownership
    // checks (`Store::complete_workflow`).
    // PHP parseCommands removes terminal commands before group validation.
    let members: std::borrow::Cow<'_, [Value]> = if commands
        .iter()
        .any(|command| command["type"] == "fail_workflow")
    {
        std::borrow::Cow::Owned(
            commands
                .iter()
                .filter(|command| command["type"] != "fail_workflow")
                .cloned()
                .collect(),
        )
    } else {
        std::borrow::Cow::Borrowed(commands)
    };
    super::selections::validate_batch(&members)
}

pub(super) fn validate(command: &Value) -> Result<WorkflowFailure> {
    let exception = &command["exception"];
    let message = string(&exception["message"])
        .or_else(|| string(&command["message"]))
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_workflow_failure"))?;
    let class = string(&exception["class"])
        .or_else(|| string(&command["exception_class"]))
        .unwrap_or("RuntimeException");
    let exception_type = string(&exception["type"]).or_else(|| string(&command["exception_type"]));
    if command
        .get("non_retryable")
        .is_some_and(|v| !v.is_null() && !v.is_boolean())
        || (!exception.is_null() && !exception.is_object() && !exception.is_array())
    {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "invalid_workflow_failure",
        ));
    }
    let sequence = command.get("failed_step_sequence").filter(|v| !v.is_null());
    let activity = command
        .get("failed_activity_execution_id")
        .filter(|v| !v.is_null());
    let activity_boundary = match (sequence, activity) {
        (None, None) => None,
        (Some(sequence), Some(activity))
            if sequence.as_i64().is_some_and(|n| n > 0) && string(activity).is_some() =>
        {
            Some((sequence.as_i64().unwrap(), string(activity).unwrap().into()))
        }
        _ => {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_activity_failure_identity",
            ));
        }
    };
    let mut structured = json!({"class":class,"type":exception_type,
        "message":string(&exception["message"]).unwrap_or(message),"code":0,"file":"","line":0,"trace":[],"properties":[]});
    if let Some(fields) = exception.as_object() {
        structured.as_object_mut().unwrap().extend(fields.clone());
    } else if let Some(fields) = exception.as_array() {
        structured.as_object_mut().unwrap().extend(
            fields
                .iter()
                .enumerate()
                .map(|(i, v)| (i.to_string(), v.clone())),
        );
    }
    Ok(WorkflowFailure {
        message: message.into(),
        class: class.into(),
        exception_type: exception_type.map(str::to_owned),
        exception: structured,
        category: super::failure_commands::category(class, message),
        non_retryable: command["non_retryable"]
            .as_bool()
            .unwrap_or_else(|| super::failure_commands::default_non_retryable(class)),
        activity_boundary,
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
    pub(super) async fn apply_workflow_failure(
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        task: &DB::Row,
        command: &Value,
    ) -> Result<()> {
        let task_id = DB::string(task, "id")?;
        if Self::cancellation_request(run)?.is_some() {
            return Self::finish_root_cancellation(tx, run, Some(task)).await;
        }
        let failure = validate(command)?;
        let run_id = DB::string(run, "id")?;
        let failure_id = id();
        let at = now();
        let attributed = if let Some((sequence, activity)) = &failure.activity_boundary {
            let event=Self::query("SELECT event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1")
                .bind(&run_id).fetch_optional(&mut **tx).await?;
            event
                .map(|event| -> Result<bool> {
                    let payload = DB::document_row(&event, "payload")?;
                    Ok(DB::string(&event, "event_type")? == "ActivityFailed"
                        && payload["sequence"] == *sequence
                        && payload["activity_execution_id"] == *activity)
                })
                .transpose()?
                .unwrap_or(false)
        } else {
            false
        };
        Self::query("INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,exception_class,message,non_retryable,handled,file,line,trace_preview,created_at,updated_at) VALUES ($1,$2,'workflow_run',$3,'terminal',$4,$5,$6,CASE WHEN $7='true' THEN TRUE ELSE FALSE END,FALSE,'',0,'',$8,$9)")
            .bind(&failure_id).bind(&run_id).bind(&run_id).bind(failure.category).bind(&failure.class).bind(stored_message(&failure.message).as_ref())
            .bind(if failure.non_retryable {"true"} else {"false"}).bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut **tx).await?;
        let mut payload = json!({"failure_id":failure_id,"source_kind":"workflow_run","source_id":run_id,
            "failure_category":failure.category,"non_retryable":failure.non_retryable,"exception_type":failure.exception_type,
            "exception_class":failure.class,"message":failure.message,"exception":failure.exception,"task":Self::task_snapshot(task)?});
        if attributed {
            payload["failed_step_sequence"] = json!(failure.activity_boundary.as_ref().unwrap().0);
            payload["failed_step_kind"] = json!("activity");
        }
        Self::query("UPDATE workflow_runs SET status='failed',closed_reason='failed',closed_at=$1,last_progress_at=$2 WHERE id=$3")
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(&run_id).execute(&mut **tx).await?;
        Self::append(
            tx,
            &run_id,
            "WorkflowFailed",
            payload.clone(),
            Some(&task_id),
            None,
        )
        .await?;
        Self::close_pending_updates(tx, &run_id, Some(task)).await?;
        Self::enforce_parent_close_policies(tx, run, "failed", 0).await?;
        Self::query("UPDATE workflow_tasks SET status='failed',lease_expires_at=NULL WHERE id=$1")
            .bind(&task_id)
            .execute(&mut **tx)
            .await?;
        Self::resolve_child(tx, run, ChildResolution::Failed(&payload), at).await?;
        Ok(())
    }
}
