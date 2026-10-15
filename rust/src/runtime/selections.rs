//! Bounded mixed activity/timer selection, with a durable first committed winner.
use super::{Result, backend::Backend, refuse, store::Store};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use super::transaction::Transaction;

const FIELDS: [&str; 11] = [
    "parallel_group_id",
    "parallel_group_kind",
    "parallel_group_mode",
    "parallel_group_base_sequence",
    "parallel_group_size",
    "parallel_group_index",
    "selection_member_key",
    "selection_member_index",
    "selection_member_base_sequence",
    "selection_member_size",
    "selection_member_kind",
];

fn unqualified() -> super::RuntimeError {
    refuse(
        StatusCode::UNPROCESSABLE_ENTITY,
        "development_selection_shape_unqualified",
    )
}

fn present(value: &Value) -> bool {
    value.get("parallel_group_path").is_some()
        || FIELDS.iter().any(|field| value.get(*field).is_some())
}

pub(super) fn metadata(value: &Value, kind: &str) -> Result<Value> {
    if !present(value) {
        return Ok(json!({}));
    }
    let base = value["parallel_group_base_sequence"]
        .as_i64()
        .filter(|base| *base > 0)
        .ok_or_else(unqualified)?;
    let index = value["parallel_group_index"]
        .as_i64()
        .filter(|index| (0..2).contains(index))
        .ok_or_else(unqualified)?;
    let member_base = base.checked_add(index).ok_or_else(unqualified)?;
    let key = &value["selection_member_key"];
    if !matches!(kind, "activity" | "timer")
        || value["parallel_group_id"] != format!("select-calls:{base}:2")
        || value["parallel_group_kind"] != "mixed"
        || value["parallel_group_mode"] != "select"
        || value["parallel_group_size"] != 2
        || value["selection_member_index"] != index
        || value["selection_member_base_sequence"] != member_base
        || value["selection_member_size"] != 1
        || value["selection_member_kind"] != kind
        || !(key.as_str().is_some_and(|key| !key.is_empty())
            || key.as_i64().is_some_and(|key| key >= 0))
    {
        return Err(unqualified());
    }
    let mut leaf = serde_json::Map::new();
    for field in FIELDS {
        leaf.insert(
            field.to_owned(),
            value.get(field).cloned().ok_or_else(unqualified)?,
        );
    }
    let path = value["parallel_group_path"]
        .as_array()
        .filter(|path| path.len() == 1)
        .ok_or_else(unqualified)?;
    if path[0] != Value::Object(leaf.clone()) {
        return Err(unqualified());
    }
    leaf.insert("parallel_group_path".into(), Value::Array(path.clone()));
    Ok(Value::Object(leaf))
}

pub(super) fn fields(command: &Value, ordinary: &[&str]) -> Result<()> {
    let mut allowed = ordinary.to_vec();
    allowed.extend(FIELDS);
    allowed.push("parallel_group_path");
    super::store::reject_fields(command, &allowed)
}

/// A complete group must arrive in one atomic authored batch. Other shapes
/// remain refused rather than treating parallel metadata as ignorable options.
pub(super) fn validate_batch(commands: &[Value]) -> Result<()> {
    let mut members = Vec::new();
    for (offset, command) in commands.iter().enumerate() {
        if !present(command) {
            continue;
        }
        let kind = match command["type"].as_str() {
            Some("schedule_activity") => "activity",
            Some("start_timer")
                if command["delay_seconds"]
                    .as_i64()
                    .is_some_and(|delay| delay > 0) =>
            {
                "timer"
            }
            _ => return Err(unqualified()),
        };
        members.push((offset, kind, metadata(command, kind)?));
    }
    if members.is_empty() {
        return Ok(());
    }
    if members.len() != 2 {
        return Err(unqualified());
    }
    let (first_offset, first_kind, first) = &members[0];
    let (second_offset, second_kind, second) = &members[1];
    if *second_offset != *first_offset + 1
        || first_kind == second_kind
        || first["parallel_group_index"] != 0
        || second["parallel_group_index"] != 1
        || first["parallel_group_id"] != second["parallel_group_id"]
        || first["selection_member_key"] == second["selection_member_key"]
    {
        return Err(unqualified());
    }
    Ok(())
}

pub(super) fn check_sequence(metadata: &Value, sequence: i64) -> Result<()> {
    if metadata.get("parallel_group_id").is_some()
        && metadata["selection_member_base_sequence"] != sequence
    {
        return Err(refuse(StatusCode::CONFLICT, "selection_sequence_mismatch"));
    }
    Ok(())
}

pub(super) fn merge(payload: &mut Value, metadata: &Value) {
    payload
        .as_object_mut()
        .expect("event object")
        .extend(metadata.as_object().expect("metadata object").clone());
}

pub(super) fn activity_metadata(path: Option<Value>) -> Result<Value> {
    let Some(path) = path else {
        return Ok(json!({}));
    };
    let leaf = path
        .as_array()
        .filter(|path| path.len() == 1)
        .and_then(|path| path.first())
        .ok_or_else(unqualified)?;
    let mut value = leaf.clone();
    value["parallel_group_path"] = path.clone();
    metadata(&value, "activity")
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
    pub(super) async fn resolve_selection(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        metadata: &Value,
        kind: &str,
        identity: &str,
        event_type: &str,
    ) -> Result<()> {
        let Some(group_id) = metadata["parallel_group_id"].as_str() else {
            return Ok(());
        };
        if Self::query(DB::SELECTION_RESOLUTION_SQL)
            .bind(run_id)
            .bind(group_id)
            .fetch_optional(&mut **tx)
            .await?
            .is_some()
        {
            return Ok(());
        }
        // Called immediately after the member's actual terminal event under
        // the same transition lock; bind its physical durable history ID.
        let event = Self::query("SELECT id,event_type FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1")
            .bind(run_id).fetch_one(&mut **tx).await?;
        if DB::string(&event, "event_type")? != event_type {
            return Err(refuse(
                StatusCode::CONFLICT,
                "selection_resolution_mismatch",
            ));
        }
        Self::append(tx, run_id, "SelectionResolved", json!({
            "selection_group_id":group_id,"selection_group_base_sequence":metadata["parallel_group_base_sequence"],
            "selection_group_size":metadata["parallel_group_size"],"member_key":metadata["selection_member_key"],
            "member_index":metadata["selection_member_index"],"member_base_sequence":metadata["selection_member_base_sequence"],
            "member_size":1,"operation_kind":kind,"operation_identity":identity,"outcome":if event_type == "ActivityTimedOut" {"failed"} else {"completed"},
            "resolution_event_id":DB::string(&event,"id")?,"resolution_event_type":event_type,
        }), None, None).await
    }

    pub(super) async fn selection_resume(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        queue: &str,
        payload: Value,
        metadata: &Value,
    ) -> Result<String> {
        if metadata.get("parallel_group_id").is_some() {
            // A leased worker may be replaying an older history snapshot.
            // Coalesce only a ready wakeup, otherwise preserve a later task
            // even if the active lease subsequently acknowledges waiting.
            let open = Self::query("SELECT id FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status='ready' ORDER BY available_at,id LIMIT 1")
                .bind(run_id).fetch_optional(&mut **tx).await?;
            if let Some(task) = open {
                return DB::string(&task, "id");
            }
        }
        Self::create_task(tx, run_id, queue, "workflow", payload).await
    }

    /// Only the proven timer-won, unclaimed losing activity checkpoint widens
    /// explicit range repair. Running activities and unrelated open work remain
    /// refused; none of their rows or attempts are changed by this inspection.
    pub(super) async fn range_repair_wait(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
    ) -> Result<Option<&'static str>> {
        let open = Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND status IN ('ready','leased') ORDER BY id LIMIT 3")
            .bind(run_id).fetch_all(&mut **tx).await?;
        let pending = Self::scalar("SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=$1 AND status IN ('pending','running')")
            .bind(run_id).fetch_one(&mut **tx).await?;
        if open.is_empty() && pending == 0 {
            return Ok(None);
        }
        let bad = || {
            refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_workflow_task_repair_active_work_unqualified",
            )
        };
        if open.len() != 1 || pending != 1 {
            return Err(bad());
        }
        let task = &open[0];
        if DB::string(task, "task_type")? != "activity"
            || DB::string(task, "status")? != "ready"
            || DB::number(task, "attempt_count")? != 0
            || DB::optional_string(task, "lease_owner")?.is_some()
        {
            return Err(bad());
        }
        let payload = DB::document_row(task, "payload")?;
        let activity_id = payload["activity_execution_id"].as_str().ok_or_else(bad)?;
        let activity =
            Self::query("SELECT * FROM activity_executions WHERE id=$1 AND workflow_run_id=$2")
                .bind(activity_id)
                .bind(run_id)
                .fetch_optional(&mut **tx)
                .await?
                .ok_or_else(bad)?;
        if DB::string(&activity, "status")? != "pending"
            || DB::number(&activity, "attempt_count")? != 0
            || DB::optional_string(&activity, "current_attempt_id")?.is_some()
            || DB::optional_instant(&activity, "started_at")?.is_some()
        {
            return Err(bad());
        }
        let metadata =
            activity_metadata(DB::optional_document_row(&activity, "parallel_group_path")?)?;
        let group = metadata["parallel_group_id"].as_str().ok_or_else(bad)?;
        let resolution = Self::query(DB::SELECTION_RESOLUTION_SQL)
            .bind(run_id)
            .bind(group)
            .fetch_optional(&mut **tx)
            .await?
            .ok_or_else(bad)?;
        let winner = DB::document_row(&resolution, "payload")?;
        if winner["operation_kind"] != "timer"
            || winner["outcome"] != "completed"
            || winner["selection_group_base_sequence"] != metadata["parallel_group_base_sequence"]
            || winner["selection_group_size"] != 2
        {
            return Err(bad());
        }
        let timers = Self::scalar("SELECT COUNT(*) FROM workflow_run_timers WHERE workflow_run_id=$1 AND status='pending'")
            .bind(run_id).fetch_one(&mut **tx).await?;
        if timers != 0 {
            return Err(bad());
        }
        Ok(Some("activity"))
    }

    pub(super) async fn range_selection_history(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
    ) -> Result<bool> {
        let events = Self::query("SELECT id,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('TimerScheduled','TimerFired','SelectionResolved') ORDER BY sequence LIMIT 4")
            .bind(run_id).fetch_all(&mut **tx).await?;
        if events.is_empty() {
            return Ok(false);
        }
        if !matches!(events.len(), 1 | 3)
            || DB::string(&events[0], "event_type")? != "TimerScheduled"
        {
            return Err(unqualified());
        }
        let opening = DB::document_row(&events[0], "payload")?;
        let group = metadata(&opening, "timer")?;
        if group.get("parallel_group_id").is_none() {
            return Err(unqualified());
        }
        if events.len() == 3 {
            let fired = DB::document_row(&events[1], "payload")?;
            let winner = DB::document_row(&events[2], "payload")?;
            if DB::string(&events[1], "event_type")? != "TimerFired"
                || DB::string(&events[2], "event_type")? != "SelectionResolved"
                || metadata(&fired, "timer")? != group
                || fired["timer_id"] != opening["timer_id"]
                || winner["selection_group_id"] != group["parallel_group_id"]
                || winner["operation_kind"] != "timer"
                || winner["operation_identity"] != opening["timer_id"]
                || winner["resolution_event_id"] != DB::string(&events[1], "id")?
            {
                return Err(unqualified());
            }
        }
        Ok(true)
    }
}
