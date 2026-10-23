//! Redriving a failed run, as published PHP's `DefaultWorkflowControlPlane::
//! redrive` and `FailedRunRedrivePlan` do: a run that failed right after an
//! activity failed continues in a new run whose history reuses the completed
//! activities before that step, so its workflow resumes at the failed step.
use super::laravel_rules::{self, Rule};
use super::{Result, RuntimeError, backend::Backend, refuse, store::{Store, id, now}};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::BTreeMap;

/// `WorkflowController::redriveRun`'s rules.
const RULES: &laravel_rules::Rules = &[("request_id", &[Rule::Nullable, Rule::String, Rule::Max(191)])];

pub(super) fn request_id(body: &Value) -> Result<Option<String>> {
    let body = laravel_rules::normalize(body);
    laravel_rules::validate(&body, RULES)?;
    Ok(body["request_id"].as_str().map(str::to_owned))
}

/// Carbon's `toIso8601String`: whole seconds with an explicit offset.
fn iso(at: DateTime<Utc>) -> String {
    at.format("%Y-%m-%dT%H:%M:%S+00:00").to_string()
}

/// One step the new run reuses.
struct Reused {
    payload: Value,
    recorded_at: DateTime<Utc>,
}

/// `FailedRunRedrivePlan::forRun` over the source run's history.
fn plan(status: &str, events: &[(String, Value, DateTime<Utc>)]) -> std::result::Result<(i64, Vec<Reused>), &'static str> {
    if status != "failed" {
        return Err("run_not_failed");
    }
    let mut started = false;
    let mut failure: Option<&Value> = None;
    let mut steps: BTreeMap<i64, Vec<(&str, &Value, DateTime<Utc>)>> = BTreeMap::new();
    let mut last_activity: Option<(&str, &Value)> = None;
    for (kind, payload, recorded_at) in events {
        if failure.is_some() {
            return Err("events_after_failure");
        }
        match kind.as_str() {
            "WorkflowStarted" if started => return Err("duplicate_start"),
            "WorkflowStarted" => started = true,
            "WorkflowFailed" if !started => return Err("missing_start"),
            "WorkflowFailed" => failure = Some(payload),
            "StartAccepted" if started => return Err("unexpected_start"),
            "StartAccepted" => {}
            "ActivityScheduled" | "ActivityStarted" | "ActivityHeartbeatRecorded" | "ActivityCompleted" | "ActivityFailed" => {
                if payload["execution_mode"] == "local" || payload["local_activity"] == true {
                    return Err("unsupported_local_activity");
                }
                let Some(sequence) = payload["sequence"].as_i64().filter(|sequence| started && *sequence >= 1) else {
                    return Err("invalid_activity_sequence");
                };
                steps.entry(sequence).or_default().push((kind, payload, *recorded_at));
                last_activity = Some((kind, payload));
            }
            _ => return Err("unsupported_history"),
        }
    }
    let Some(failure) = failure.filter(|_| started) else {
        return Err("missing_terminal_history");
    };
    let Some(boundary) = failure["failed_step_sequence"].as_i64().filter(|boundary| *boundary >= 1) else {
        return Err("unrecorded_failure_boundary");
    };
    if failure["failed_step_kind"] != "activity" {
        return Err("unrecorded_failure_boundary");
    }
    if steps.len() as i64 != boundary {
        return Err("activity_sequence_gap");
    }
    let mut reused = Vec::new();
    for sequence in 1..=boundary {
        let Some(events) = steps.get(&sequence) else {
            return Err("activity_sequence_gap");
        };
        let mut activity_type: Option<&str> = None;
        let mut completion = None;
        let mut failures = 0;
        for (kind, payload, recorded_at) in events {
            let Some(recorded) = payload["activity_type"].as_str().filter(|value| !value.is_empty()) else {
                return Err("missing_activity_type");
            };
            if activity_type.is_some_and(|known| known != recorded) {
                return Err("activity_type_mismatch");
            }
            activity_type = Some(recorded);
            if *kind == "ActivityCompleted" {
                if completion.is_some() {
                    return Err("duplicate_activity_completion");
                }
                completion = Some((*payload, *recorded_at));
            }
            if *kind == "ActivityFailed" {
                failures += 1;
            }
        }
        if sequence == boundary {
            if completion.is_some() || failures != 1 {
                return Err("invalid_failed_step");
            }
            continue;
        }
        let Some((payload, recorded_at)) = completion.filter(|_| failures == 0) else {
            return Err("incomplete_activity_prefix");
        };
        if payload.get("result").is_none() {
            return Err("missing_activity_result");
        }
        if payload["result"].get("external_payload").is_some() || payload["result"].get("external_storage").is_some() {
            return Err("external_activity_result_not_supported");
        }
        if !payload["result"]["blob"].is_string() {
            return Err("missing_activity_result");
        }
        reused.push(Reused { payload: payload.clone(), recorded_at });
    }
    match last_activity {
        Some(("ActivityFailed", payload)) if payload["sequence"].as_i64() == Some(boundary) => Ok((boundary, reused)),
        _ => Err("unrecorded_failure_boundary"),
    }
}

/// PHP's answer for a redrive that was not accepted.
fn rejected(workflow_id: &str, source: &str, reason: &str, status: StatusCode) -> (StatusCode, Value) {
    (status, json!({"workflow_run_id":null,"continued_from_run_id":source,"resume_step_sequence":null,"task_id":null,
        "reason":reason,"run_id":source,"command_status":"rejected","outcome":"rejected","accepted":false,"workflow_id":workflow_id}))
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
    pub(crate) async fn redrive_run(
        &self,
        workflow_id: &str,
        source_id: &str,
        request_id: Option<String>,
        context: Value,
    ) -> Result<(StatusCode, Value)> {
        let mut tx = self.begin().await?;
        let instance = Self::query("SELECT current_run_id FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(workflow_id).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "run_not_found"))?;
        let source = Self::query("SELECT * FROM workflow_runs WHERE id=$1 AND workflow_instance_id=$2")
            .bind(source_id).bind(workflow_id).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "run_not_found"))?;
        // A source already redriven answers with its continuation.
        if let Some(link) = Self::query("SELECT child_workflow_run_id,sequence FROM workflow_links WHERE parent_workflow_run_id=$1 AND link_type='redrive'")
            .bind(source_id).fetch_optional(&mut *tx).await?
        {
            let continued = DB::string(&link, "child_workflow_run_id")?;
            return Ok((StatusCode::OK, json!({"workflow_run_id":continued,"continued_from_run_id":source_id,
                "resume_step_sequence":DB::optional_number(&link, "sequence")?,"task_id":null,"reason":null,
                "run_id":continued,"command_status":"accepted","outcome":"redriven","workflow_id":workflow_id})));
        }
        let conflict = |reason: &str| Ok(rejected(workflow_id, source_id, reason, StatusCode::CONFLICT));
        if DB::optional_string(&instance, "current_run_id")?.as_deref() != Some(source_id) {
            return conflict("source_run_not_current");
        }
        let mut events = Vec::new();
        for row in Self::query("SELECT event_type,payload,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence")
            .bind(source_id).fetch_all(&mut *tx).await?
        {
            events.push((DB::string(&row, "event_type")?, DB::document_row(&row, "payload")?, DB::instant(&row, "recorded_at")?));
        }
        let (resume, reused) = match plan(&DB::string(&source, "status")?, &events) {
            Ok(plan) => plan,
            Err(reason) => return conflict(reason),
        };
        let memos = Self::scalar("SELECT COUNT(*) FROM workflow_memos WHERE workflow_run_id=$1").bind(source_id).fetch_one(&mut *tx).await?;
        let attributes = Self::scalar("SELECT COUNT(*) FROM workflow_search_attributes WHERE workflow_run_id=$1").bind(source_id).fetch_one(&mut *tx).await?;
        if memos + attributes > 0 {
            return conflict("unsupported_visibility_metadata");
        }
        let child = Self::scalar("SELECT COUNT(*) FROM workflow_links WHERE child_workflow_run_id=$1 AND link_type='child_workflow'")
            .bind(source_id).fetch_one(&mut *tx).await?;
        let arguments = DB::optional_string(&source, "arguments")?;
        if child > 0 || arguments.as_deref().is_some_and(|blob| blob.starts_with(super::external_resolution::STORED_PREFIX)) {
            return conflict("unsupported_source_link_or_payload");
        }
        let at = now();
        let execution_deadline = DB::optional_instant(&source, "execution_deadline_at")?;
        if execution_deadline.is_some_and(|deadline| at >= deadline) {
            return conflict("execution_deadline_elapsed");
        }
        let run_timeout = DB::optional_number(&source, "run_timeout_seconds")?.filter(|seconds| *seconds > 0);
        let run_deadline = run_timeout.map(|seconds| at + chrono::Duration::seconds(seconds));
        let mut started = events.iter().find(|(kind, _, _)| kind == "WorkflowStarted").map(|(_, payload, _)| payload.clone()).unwrap_or(Value::Null);
        if let Some(fields) = started.as_object_mut() {
            fields.remove("command");
            fields.remove("task");
        }
        let filled = |value: &Value| !(value.is_null() || value == &json!([]) || value == &json!({}) || value == "" || value == &json!(false));
        if filled(&started["memo"]) || filled(&started["search_attributes"]) || filled(&started["parent_workflow_run_id"]) {
            return conflict("unsupported_source_metadata");
        }
        // An external workflow's definition must still match its worker cohort.
        let workflow_type = DB::string(&source, "workflow_type")?;
        let queue = DB::string(&source, "queue")?;
        // `ServerWorkflowControlPlane::redrive`: the source run's own cohort,
        // its build or the unversioned workers.
        let workers = Self::start_workers(&mut tx, &self.namespace, &queue, self.worker_stale_after_seconds).await?;
        let source_cohort = super::start_definition::Cohort::of_run(DB::optional_string(&source, "compatibility")?.as_deref());
        let cohort = super::start_definition::fingerprint_for(&workers, &workflow_type, &source_cohort);
        let recorded = started["workflow_definition_fingerprint"].as_str().filter(|value| !value.is_empty());
        if DB::string(&source, "workflow_class")? != workflow_type || recorded.is_none() || recorded != cohort.as_deref() {
            return conflict("workflow_definition_unavailable_or_changed");
        }
        if let Some(request_id) = &request_id {
            let used = Self::scalar("SELECT COUNT(*) FROM workflow_commands WHERE workflow_instance_id=$1 AND command_type='redrive' AND request_id=$2")
                .bind(workflow_id).bind(request_id.as_str()).fetch_one(&mut *tx).await?;
            if used > 0 {
                return conflict("request_id_already_used");
            }
        }
        let run_id = id();
        let run_number = DB::number(&source, "run_number")? + 1;
        Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,workflow_type,workflow_class,run_number,status,payload_codec,arguments,queue,run_timeout_seconds,execution_deadline_at,run_deadline_at,started_at,created_at,updated_at,namespace,last_progress_at,business_key,visibility_labels,compatibility,connection,priority,fairness_key,fairness_weight,last_command_sequence) SELECT $1,workflow_instance_id,workflow_type,workflow_class,$2,'pending',payload_codec,arguments,queue,run_timeout_seconds,execution_deadline_at,$3,$4,$5,$6,namespace,$7,business_key,visibility_labels,compatibility,connection,priority,fairness_key,fairness_weight,1 FROM workflow_runs WHERE id=$8")
            .bind(&run_id).bind(run_number).bind(DB::optional_time(run_deadline)).bind(DB::bind_time(at)).bind(DB::bind_time(at))
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(source_id).execute(&mut *tx).await?;
        let command_id = id();
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,requested_workflow_run_id,resolved_workflow_run_id,command_type,target_scope,source,status,outcome,workflow_type,payload_codec,command_sequence,accepted_at,applied_at,context,request_id) VALUES ($1,$2,$3,$4,$5,'redrive','run','control_plane','accepted','redriven',$6,'avro',1,$7,$8,$9,$10)")
            .bind(&command_id).bind(workflow_id).bind(&run_id).bind(source_id).bind(&run_id).bind(&workflow_type)
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(DB::document(&context)).bind(request_id.as_deref())
            .execute(&mut *tx).await?;
        let link_id = id();
        Self::query("INSERT INTO workflow_links(id,link_type,sequence,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,child_workflow_run_id,is_primary_parent,created_at,updated_at) VALUES ($1,'redrive',$2,$3,$4,$5,$6,TRUE,$7,$8)")
            .bind(&link_id).bind(resume).bind(workflow_id).bind(source_id).bind(workflow_id).bind(&run_id)
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut *tx).await?;
        Self::query("UPDATE workflow_instances SET current_run_id=$1,run_count=$2,updated_at=$3 WHERE id=$4")
            .bind(&run_id).bind(run_number).bind(DB::bind_time(at)).bind(workflow_id).execute(&mut *tx).await?;
        Self::append(&mut tx, &run_id, "StartAccepted", json!({"workflow_command_id":command_id,"workflow_instance_id":workflow_id,
            "workflow_run_id":run_id,"workflow_class":workflow_type,"workflow_type":workflow_type,"outcome":"redriven"}), None, Some(&command_id)).await?;
        let source_started = DB::optional_instant(&source, "started_at")?;
        let replayed = started["replayed_started_at"].as_str().map(str::to_owned).or_else(|| source_started.map(iso));
        if !started.is_object() {
            started = json!({});
        }
        for (key, value) in [("workflow_instance_id", json!(workflow_id)), ("workflow_run_id", json!(run_id)),
            ("workflow_command_id", json!(command_id)), ("continued_from_run_id", json!(source_id)),
            ("resume_step_sequence", json!(resume)), ("recovery_kind", json!("redrive")),
            ("execution_deadline_at", json!(execution_deadline.map(iso))), ("run_deadline_at", json!(run_deadline.map(iso))),
            ("replayed_started_at", json!(replayed)), ("workflow_link_id", json!(link_id))] {
            started[key] = value;
        }
        Self::append(&mut tx, &run_id, "WorkflowStarted", started, None, Some(&command_id)).await?;
        for step in reused {
            let payload = &step.payload;
            Self::append(&mut tx, &run_id, "ActivityCompleted", json!({"activity_execution_id":id(),
                "activity_class":payload["activity_class"],"activity_type":payload["activity_type"],"sequence":payload["sequence"],
                "result":payload["result"],"payload_codec":payload["payload_codec"].as_str().unwrap_or("avro"),
                "reused_from_run_id":source_id,"reused_activity_execution_id":payload["activity_execution_id"],
                "reused_recorded_at":payload["reused_recorded_at"].as_str().map(str::to_owned).unwrap_or_else(|| iso(step.recorded_at))}), None, None).await?;
        }
        let task_id = Self::create_task(&mut tx, &run_id, &queue, "workflow", json!({})).await?;
        tx.summary_changed(source_id);
        tx.summary_changed(&run_id);
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok((StatusCode::ACCEPTED, json!({"workflow_run_id":run_id,"continued_from_run_id":source_id,"resume_step_sequence":resume,
            "task_id":task_id,"reason":null,"run_id":run_id,"command_status":"accepted","outcome":"redriven","workflow_id":workflow_id})))
    }
}

/// A rejected or unknown redrive for a run outside this namespace.
pub(super) fn run_not_found(workflow_id: &str, run_id: &str) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::NOT_FOUND,
        response: json!({"message":"Workflow run not found.","reason":"run_not_found","workflow_id":workflow_id,"run_id":run_id}),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn event(kind: &str, payload: Value) -> (String, Value, DateTime<Utc>) {
        (kind.into(), payload, Utc::now())
    }

    #[test]
    fn plan_follows_php_s_failed_run_rules() {
        let done = json!({"sequence":1,"activity_type":"a","result":{"codec":"avro","blob":"x"},"payload_codec":"avro"});
        let failed = json!({"sequence":2,"activity_type":"b"});
        let history = vec![event("StartAccepted", json!({})), event("WorkflowStarted", json!({})),
            event("ActivityScheduled", json!({"sequence":1,"activity_type":"a"})), event("ActivityCompleted", done.clone()),
            event("ActivityScheduled", json!({"sequence":2,"activity_type":"b"})), event("ActivityFailed", failed.clone()),
            event("WorkflowFailed", json!({"failed_step_sequence":2,"failed_step_kind":"activity"}))];
        let (resume, reused) = plan("failed", &history).unwrap();
        assert_eq!((resume, reused.len()), (2, 1));
        assert_eq!(plan("completed", &history).err(), Some("run_not_failed"));
        let mut unbounded = history.clone();
        unbounded[6] = event("WorkflowFailed", json!({}));
        assert_eq!(plan("failed", &unbounded).err(), Some("unrecorded_failure_boundary"));
        let mut timer = history.clone();
        timer.insert(2, event("TimerScheduled", json!({"sequence":1})));
        assert_eq!(plan("failed", &timer).err(), Some("unsupported_history"));
        let mut external = history.clone();
        external[3] = event("ActivityCompleted", json!({"sequence":1,"activity_type":"a","result":{"codec":"avro","external_payload":{}}}));
        assert_eq!(plan("failed", &external).err(), Some("external_activity_result_not_supported"));
        let mut gap = history.clone();
        gap[6] = event("WorkflowFailed", json!({"failed_step_sequence":3,"failed_step_kind":"activity"}));
        assert_eq!(plan("failed", &gap).err(), Some("activity_sequence_gap"));
    }
}
