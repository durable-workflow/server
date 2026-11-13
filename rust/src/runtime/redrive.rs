//! Redriving a failed run, as published PHP's `DefaultWorkflowControlPlane::
//! redrive` and `FailedRunRedrivePlan` do: a run that failed right after an
//! activity failed continues in a new run whose history reuses the completed
//! activities, side effects and version markers before that step, so its
//! workflow resumes at the failed step.
use super::laravel_rules::{self, Rule};
use super::{Result, RuntimeError, backend::Backend, refuse, store::{Store, id, now}, transaction::Transaction};
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

/// One step the new run reuses: an `ActivityCompleted`, or a marker step
/// (`SideEffectRecorded`, `VersionMarkerRecorded`, `MemoUpserted`,
/// `SearchAttributesUpserted`) without its source task and command.
struct Reused {
    kind: String,
    payload: Value,
    recorded_at: DateTime<Utc>,
}

/// The marker steps `FailedRunRedrivePlan::forRun` reuses as recorded.
const MARKERS: [&str; 4] = ["SideEffectRecorded", "VersionMarkerRecorded", "MemoUpserted", "SearchAttributesUpserted"];

/// `FailedRunRedrivePlan::isExternalResult`, in native's stored forms too.
fn external(result: &Value) -> bool {
    ["external_payload", "external_storage"].iter().any(|key| result.get(key).is_some_and(|value| !value.is_null()))
        || result.as_str().is_some_and(|blob| blob.starts_with(super::external_resolution::STORED_PREFIX))
}

/// What an accepted redrive continues from.
struct Decision {
    resume: i64,
    reused: Vec<Reused>,
    started: Value,
}

/// `FailedRunRedrivePlan::forRun` over the source run's history.
fn plan(status: &str, events: &[(String, Value, DateTime<Utc>)]) -> std::result::Result<(i64, Vec<Reused>), &'static str> {
    if status != "failed" {
        return Err("run_not_failed");
    }
    let mut started = false;
    let mut failure: Option<&Value> = None;
    let mut steps: BTreeMap<i64, Vec<(&str, &Value, DateTime<Utc>)>> = BTreeMap::new();
    let mut markers: BTreeMap<i64, (&str, &Value, DateTime<Utc>)> = BTreeMap::new();
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
            kind if MARKERS.contains(&kind) => {
                let Some(sequence) = payload["sequence"].as_i64().filter(|sequence| started && *sequence >= 1) else {
                    return Err("invalid_step_sequence");
                };
                if markers.contains_key(&sequence) {
                    return Err("duplicate_step_sequence");
                }
                if kind == "SideEffectRecorded" && external(&payload["result"]) {
                    return Err("external_side_effect_result_not_supported");
                }
                markers.insert(sequence, (kind, payload, *recorded_at));
            }
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
    // A marker takes its own step before the failed one.
    if markers.keys().any(|sequence| *sequence >= boundary) {
        return Err("invalid_failed_step");
    }
    if markers.keys().any(|sequence| steps.contains_key(sequence)) {
        return Err("duplicate_step_sequence");
    }
    if (steps.len() + markers.len()) as i64 != boundary {
        return Err("activity_sequence_gap");
    }
    let mut reused = Vec::new();
    for sequence in 1..=boundary {
        if let Some((kind, payload, recorded_at)) = markers.get(&sequence) {
            // The task and command are the source run's recording context,
            // not part of the reused step.
            let mut payload = (*payload).clone();
            if let Some(fields) = payload.as_object_mut() {
                fields.remove("task");
                fields.remove("command");
            }
            reused.push(Reused { kind: (*kind).to_owned(), payload, recorded_at: *recorded_at });
            continue;
        }
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
        reused.push(Reused { kind: "ActivityCompleted".to_owned(), payload: payload.clone(), recorded_at });
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
    /// `FailedRunRedriveEligibility::evaluate`: whether redrive accepts this
    /// source run, and if not, why. Redrive applies it inside its transaction
    /// (`presented`: compare the worker cohort's definition); describe reads
    /// the same decision.
    async fn redrive_decision(&self, tx: &mut Transaction<'_, DB>, source: &DB::Row, current: bool, presented: bool)
        -> Result<std::result::Result<Decision, &'static str>> {
        if !current {
            return Ok(Err("source_run_not_current"));
        }
        let source_id = DB::string(source, "id")?;
        let source_id = source_id.as_str();
        let mut events = Vec::new();
        for row in Self::query("SELECT event_type,payload,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence")
            .bind(source_id).fetch_all(&mut **tx).await?
        {
            events.push((DB::string(&row, "event_type")?, DB::document_row(&row, "payload")?, DB::instant(&row, "recorded_at")?));
        }
        let (resume, reused) = match plan(&DB::string(source, "status")?, &events) {
            Ok(plan) => plan,
            Err(reason) => return Ok(Err(reason)),
        };
        // Start-time and inherited memos and search attributes carry to the
        // successor as they are (server#980). A value workflow code upserted
        // must come from an upsert step the plan reuses
        // (`FailedRunRedriveEligibility::rowsMatchReusedUpserts`).
        for (sql, kind) in [
            ("SELECT upserted_at_sequence FROM workflow_memos WHERE workflow_run_id=$1 AND upserted_at_sequence>0 AND inherited_from_parent=false", "MemoUpserted"),
            ("SELECT upserted_at_sequence FROM workflow_search_attributes WHERE workflow_run_id=$1 AND upserted_at_sequence>0 AND inherited_from_parent=false", "SearchAttributesUpserted"),
        ] {
            for row in Self::query(sql).bind(source_id).fetch_all(&mut **tx).await? {
                let sequence = DB::number(&row, "upserted_at_sequence")?;
                if !reused.iter().any(|step| step.kind == kind && step.payload["sequence"].as_i64() == Some(sequence)) {
                    return Ok(Err("unsupported_visibility_metadata"));
                }
            }
        }
        let child = Self::scalar("SELECT COUNT(*) FROM workflow_links WHERE child_workflow_run_id=$1 AND link_type='child_workflow'")
            .bind(source_id).fetch_one(&mut **tx).await?;
        let arguments = DB::optional_string(source, "arguments")?;
        if child > 0 || arguments.as_deref().is_some_and(|blob| blob.starts_with(super::external_resolution::STORED_PREFIX)) {
            return Ok(Err("unsupported_source_link_or_payload"));
        }
        let at = now();
        let execution_deadline = DB::optional_instant(source, "execution_deadline_at")?;
        if execution_deadline.is_some_and(|deadline| at >= deadline) {
            return Ok(Err("execution_deadline_elapsed"));
        }
        let mut started = events.iter().find(|(kind, _, _)| kind == "WorkflowStarted").map(|(_, payload, _)| payload.clone()).unwrap_or(Value::Null);
        if let Some(fields) = started.as_object_mut() {
            fields.remove("command");
            fields.remove("task");
        }
        let filled = |value: &Value| !(value.is_null() || value == &json!([]) || value == &json!({}) || value == "" || value == &json!(false));
        if filled(&started["parent_workflow_run_id"]) {
            return Ok(Err("unsupported_source_metadata"));
        }
        // Service-mode SDK workers are qualified to replay reused activities,
        // side effects and version markers. A reused memo or search-attribute
        // upsert is not qualified yet, so a run a worker started refuses it
        // before anything is written.
        let upserts = reused.iter().any(|step| step.kind == "MemoUpserted" || step.kind == "SearchAttributesUpserted");
        if started["workflow_definition_fingerprint_source"] == "worker" && upserts {
            return Ok(Err("service_redrive_history_not_qualified"));
        }
        // An external workflow's definition must still match its worker cohort.
        let workflow_type = DB::string(source, "workflow_type")?;
        let queue = DB::string(source, "queue")?;
        let recorded = started["workflow_definition_fingerprint"].as_str().filter(|value| !value.is_empty());
        if DB::string(source, "workflow_class")? != workflow_type || recorded.is_none() {
            return Ok(Err("workflow_definition_unavailable_or_changed"));
        }
        // Redrive compares the cohort's fingerprint (`ServerWorkflowControlPlane::
        // redrive`: the source run's own cohort, its build or the unversioned
        // workers). Describe cannot know which worker will take the run, so it
        // needs only a recorded one.
        if presented {
            let workers = Self::start_workers(tx, &self.namespace, &queue, self.worker_stale_after_seconds).await?;
            let source_cohort = super::start_definition::Cohort::of_run(DB::optional_string(source, "compatibility")?.as_deref());
            let cohort = super::start_definition::fingerprint_for(&workers, &workflow_type, &source_cohort);
            if recorded != cohort.as_deref() {
                return Ok(Err("workflow_definition_unavailable_or_changed"));
            }
        }
        // Only an embedded workflow class replays a reused upsert in Core, and
        // native runs every workflow on a service worker.
        if upserts {
            return Ok(Err("service_redrive_history_not_qualified"));
        }
        Ok(Ok(Decision { resume, reused, started }))
    }

    /// `FailedRunRedriveEligibility::blockedReason` for describe: the reason
    /// redrive would refuse this run, or `None` when it would accept it.
    pub(super) async fn redrive_blocked_reason(&self, tx: &mut Transaction<'_, DB>, run: &DB::Row, current: bool)
        -> Result<Option<&'static str>> {
        if DB::string(run, "status")? != "failed" {
            return Ok(Some("run_not_failed"));
        }
        let redriven = Self::scalar("SELECT COUNT(*) FROM workflow_links WHERE parent_workflow_run_id=$1 AND link_type='redrive'")
            .bind(DB::string(run, "id")?).fetch_one(&mut **tx).await?;
        if redriven > 0 {
            return Ok(Some("already_redriven"));
        }
        Ok(self.redrive_decision(tx, run, current, false).await?.err())
    }

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
        let current = DB::optional_string(&instance, "current_run_id")?.as_deref() == Some(source_id);
        let Decision { resume, reused, started } = match self.redrive_decision(&mut tx, &source, current, true).await? {
            Ok(decision) => decision,
            Err(reason) => return conflict(reason),
        };
        let at = now();
        let execution_deadline = DB::optional_instant(&source, "execution_deadline_at")?;
        let run_timeout = DB::optional_number(&source, "run_timeout_seconds")?.filter(|seconds| *seconds > 0);
        let run_deadline = run_timeout.map(|seconds| at + chrono::Duration::seconds(seconds));
        let mut started = started;
        let workflow_type = DB::string(&source, "workflow_type")?;
        let queue = DB::string(&source, "queue")?;
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
        for sql in [DB::MEMO_REDRIVE_COPY, DB::SEARCH_ATTRIBUTE_REDRIVE_COPY] {
            Self::query(sql).bind(&run_id).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(source_id).execute(&mut *tx).await?;
        }
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
        // `FailedRunRedrivePlan::successorEvents`: each reused step in step
        // order. A step reused more than once keeps the time it first ran.
        for step in reused {
            let payload = &step.payload;
            let recorded_at = payload["reused_recorded_at"].as_str().map(str::to_owned).unwrap_or_else(|| iso(step.recorded_at));
            if step.kind != "ActivityCompleted" {
                let mut marker = step.payload.clone();
                marker["reused_from_run_id"] = json!(source_id);
                marker["reused_recorded_at"] = json!(recorded_at);
                Self::append(&mut tx, &run_id, &step.kind, marker, None, None).await?;
                continue;
            }
            Self::append(&mut tx, &run_id, "ActivityCompleted", json!({"activity_execution_id":id(),
                "activity_class":payload["activity_class"],"activity_type":payload["activity_type"],"sequence":payload["sequence"],
                "result":payload["result"],"payload_codec":payload["payload_codec"].as_str().unwrap_or("avro"),
                "reused_from_run_id":source_id,"reused_activity_execution_id":payload["activity_execution_id"],
                "reused_recorded_at":recorded_at}), None, None).await?;
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

    // Core workflow#991: side effects, version markers and upserts before the
    // failed step are reused in step order, without their task and command.
    #[test]
    fn plan_reuses_marker_steps_like_core() {
        let done = json!({"sequence":3,"activity_type":"a","result":{"codec":"avro","blob":"x"},"payload_codec":"avro"});
        let history = vec![event("StartAccepted", json!({})), event("WorkflowStarted", json!({})),
            event("SideEffectRecorded", json!({"sequence":1,"result":{"codec":"avro","blob":"s"},"task":{"id":"t"}})),
            event("VersionMarkerRecorded", json!({"sequence":2,"change_id":"c","version":1,"command":{"id":"c"}})),
            event("ActivityScheduled", json!({"sequence":3,"activity_type":"a"})), event("ActivityCompleted", done),
            event("ActivityScheduled", json!({"sequence":4,"activity_type":"b"})), event("ActivityFailed", json!({"sequence":4,"activity_type":"b"})),
            event("WorkflowFailed", json!({"failed_step_sequence":4,"failed_step_kind":"activity"}))];
        let (resume, reused) = plan("failed", &history).unwrap();
        assert_eq!(resume, 4);
        let steps: Vec<(&str, Value)> = reused.iter().map(|step| (step.kind.as_str(), step.payload["sequence"].clone())).collect();
        assert_eq!(steps, [("SideEffectRecorded", json!(1)), ("VersionMarkerRecorded", json!(2)), ("ActivityCompleted", json!(3))]);
        assert!(reused[0].payload.get("task").is_none() && reused[1].payload.get("command").is_none());
        let refused = |at: usize, kind: &str, payload: Value| {
            let mut changed = history.clone();
            changed[at] = event(kind, payload);
            plan("failed", &changed).err()
        };
        assert_eq!(refused(2, "SideEffectRecorded", json!({"sequence":0})), Some("invalid_step_sequence"));
        assert_eq!(refused(3, "VersionMarkerRecorded", json!({"sequence":1})), Some("duplicate_step_sequence"));
        assert_eq!(refused(2, "SideEffectRecorded", json!({"sequence":1,"result":{"codec":"avro","external_payload":{}}})),
            Some("external_side_effect_result_not_supported"));
        assert_eq!(refused(2, "MemoUpserted", json!({"sequence":4})), Some("invalid_failed_step"));
        assert_eq!(refused(2, "SearchAttributesUpserted", json!({"sequence":3})), Some("duplicate_step_sequence"));
        assert_eq!(refused(2, "SideEffectRecorded", json!({"sequence":5})), Some("invalid_failed_step"));
        let mut missing = history.clone();
        missing.remove(3);
        assert_eq!(plan("failed", &missing).err(), Some("activity_sequence_gap"));
    }

    fn fired_timer_history() -> Vec<(String, Value, DateTime<Utc>)> {
        vec![event("WorkflowStarted", json!({})),
            event("ActivityCompleted", json!({"sequence":1,"activity_type":"a","result":{"blob":"x"}})),
            event("TimerScheduled", json!({"sequence":2,"timer_id":"source-timer","delay_seconds":5,
                "task":{"id":"scheduled-task"},"command":{"id":"scheduled-command"}})),
            event("TimerFired", json!({"sequence":2,"timer_id":"source-timer","delay_seconds":5,
                "task":{"id":"fired-task"},"command":{"id":"fired-command"}})),
            event("ActivityFailed", json!({"sequence":3,"activity_type":"b"})),
            event("WorkflowFailed", json!({"failed_step_sequence":3,"failed_step_kind":"activity"}))]
    }

    #[test]
    fn plan_reuses_a_complete_plain_timer_in_step_order() {
        let history = fired_timer_history();
        let original = history.clone();
        let (resume, reused) = plan("failed", &history).unwrap();
        assert_eq!((resume, reused.len()), (3, 2));
        assert_eq!(reused[0].kind, "ActivityCompleted");
        assert_eq!(reused[1].kind, "TimerFired");
        assert_eq!(reused[1].payload, json!({"sequence":2,"delay_seconds":5}));
        assert_eq!(reused[1].recorded_at, history[3].2);
        assert_eq!(history, original);
    }

    #[test]
    fn plan_refuses_non_plain_or_incomplete_timers_like_core() {
        let history = fired_timer_history();
        for index in [2, 3] {
            for field in ["timer_kind", "cancellation_cleanup", "cancellation_scope_id", "parallel_group_id",
                "condition_wait_id", "signal_wait_id"] {
                for value in [json!(false), json!(""), json!("condition")] {
                    let mut changed = history.clone();
                    changed[index].1[field] = value;
                    assert_eq!(plan("failed", &changed).err(), Some("unsupported_timer"), "{field}");
                }
                let mut changed = history.clone();
                changed[index].1[field] = Value::Null;
                assert!(plan("failed", &changed).is_ok(), "null {field}");
            }
            let mut missing = history.clone();
            missing.remove(index);
            assert_eq!(plan("failed", &missing).err(), Some("incomplete_timer_step"));
            let mut duplicate = history.clone();
            duplicate.insert(index, history[index].clone());
            assert_eq!(plan("failed", &duplicate).err(), Some("incomplete_timer_step"));
            for value in [Value::Null, json!(5), json!("another-timer")] {
                let mut changed = history.clone();
                changed[index].1["timer_id"] = value;
                assert_eq!(plan("failed", &changed).err(), Some("incomplete_timer_step"));
            }
            for value in [Value::Null, json!(0), json!("2")] {
                let mut changed = history.clone();
                changed[index].1["sequence"] = value;
                assert_eq!(plan("failed", &changed).err(), Some("invalid_step_sequence"));
            }
        }
        let mut reversed = history.clone();
        reversed.swap(2, 3);
        assert_eq!(plan("failed", &reversed).err(), Some("incomplete_timer_step"));
        for (sequence, expected) in [(1, "duplicate_step_sequence"), (3, "invalid_failed_step")] {
            let mut changed = history.clone();
            changed[2].1["sequence"] = json!(sequence);
            changed[3].1["sequence"] = json!(sequence);
            assert_eq!(plan("failed", &changed).err(), Some(expected));
        }
        let mut collision = history.clone();
        collision.insert(2, event("VersionMarkerRecorded", json!({"sequence":2})));
        assert_eq!(plan("failed", &collision).err(), Some("duplicate_step_sequence"));
        let mut gap = history.clone();
        gap.remove(1);
        assert_eq!(plan("failed", &gap).err(), Some("activity_sequence_gap"));
    }
}
