//! Failed-run redrive follows Core's `DefaultWorkflowControlPlane::redrive`
//! and `FailedRunRedriveEligibility` (Workflow 2.33.0; the plan itself is
//! `redrive_plan`): a failed run continues in a new run whose history reuses
//! completed activities, side effects, version markers and fired plain
//! timers before the failed step. The workflow resumes at that step.
use super::laravel_rules::{self, Rule};
use super::redrive_plan::{self, Event, Plan, SourceRun};
use super::{Result, RuntimeError, backend::Backend, refuse, store::{Store, id, now}, transaction::Transaction};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

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

/// Replay clocks retain the persisted instant's precision. Deadline formatting
/// remains separate; rounding a clock changes workflow decisions on replay.
fn replay_time(at: DateTime<Utc>) -> String {
    at.to_rfc3339_opts(chrono::SecondsFormat::AutoSi, false)
}

/// What an accepted redrive continues from.
struct Decision {
    resume: i64,
    reused: Vec<redrive_plan::Reused>,
    started: Value,
}

/// `Serializer::unserializeWithCodec` for the codec native writes. Another
/// codec's payload is not decoded here; the SDK that wrote it replays it.
fn decodes(codec: &str, blob: &str) -> bool {
    if codec != "avro" {
        return true;
    }
    crate::codec::ValueCodec::new().is_ok_and(|codec| codec.decode(blob).is_ok())
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
        for row in Self::query("SELECT sequence,event_type,payload,recorded_at,workflow_task_id,workflow_command_id FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence")
            .bind(source_id).fetch_all(&mut **tx).await?
        {
            events.push(Event { sequence: DB::number(&row, "sequence")?, kind: DB::string(&row, "event_type")?,
                payload: DB::document_row(&row, "payload")?, recorded_at: DB::instant(&row, "recorded_at")?,
                task_id: DB::optional_string(&row, "workflow_task_id")?, command_id: DB::optional_string(&row, "workflow_command_id")? });
        }
        let instance_id = DB::string(source, "workflow_instance_id")?;
        // A redrive successor's reused messages may name an earlier run of
        // the instance (`FailedRunRedrivePlan::provenance`).
        let mut earlier_runs = std::collections::BTreeSet::new();
        let successor = events.iter().find(|event| event.kind == "WorkflowStarted").is_some_and(|event| event.payload["recovery_kind"] == "redrive");
        if successor {
            for row in Self::query("SELECT id FROM workflow_runs WHERE workflow_instance_id=$1 AND run_number<$2")
                .bind(instance_id.as_str()).bind(DB::number(source, "run_number")?).fetch_all(&mut **tx).await?
            {
                earlier_runs.insert(DB::string(&row, "id")?);
            }
        }
        let (status, workflow_class, workflow_type) = (DB::string(source, "status")?, DB::string(source, "workflow_class")?, DB::string(source, "workflow_type")?);
        let payload_codec = DB::optional_string(source, "payload_codec")?;
        let run = SourceRun { id: source_id, instance_id: &instance_id, workflow_class: &workflow_class, workflow_type: &workflow_type,
            status: &status, payload_codec: payload_codec.as_deref(), earlier_runs: &earlier_runs };
        let Plan { resume, failed_step_kind, reused, service_unqualified } = match redrive_plan::plan(&run, &events, &decodes) {
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
        let mut started = events.iter().find(|event| event.kind == "WorkflowStarted").map(|event| event.payload.clone()).unwrap_or(Value::Null);
        if let Some(fields) = started.as_object_mut() {
            fields.remove("command");
            fields.remove("task");
        }
        let filled = |value: &Value| !(value.is_null() || value == &json!([]) || value == &json!({}) || value == "" || value == &json!(false));
        if filled(&started["parent_workflow_run_id"]) {
            return Ok(Err("unsupported_source_metadata"));
        }
        // Service-mode SDK workers are qualified to replay reused activities,
        // side effects, version markers and timers. Reused signal, memo,
        // search-attribute, update and child workflow steps are not qualified
        // yet, so a run a worker started refuses them before anything is
        // written.
        if started["workflow_definition_fingerprint_source"] == "worker" && service_unqualified {
            return Ok(Err("service_redrive_history_not_qualified"));
        }
        // An external workflow's definition must still match its worker cohort.
        let queue = DB::string(source, "queue")?;
        let recorded = started["workflow_definition_fingerprint"].as_str().filter(|value| !value.is_empty());
        if workflow_class != workflow_type || recorded.is_none() {
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
        // The same code would throw at the same step again. Native has no
        // local class to change the definition to (Core's
        // `allow_definition_change` applies only to an embedded class).
        if failed_step_kind == "workflow_code" {
            return Ok(Err("workflow_code_failure_requires_corrected_definition"));
        }
        // Only an embedded workflow class replays these steps in Core, and
        // native runs every workflow on a service worker.
        if service_unqualified {
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
        let replayed = started["replayed_started_at"].as_str().map(str::to_owned).or_else(|| source_started.map(replay_time));
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
        // Server #1201: the successor keeps the source's allowance, with its
        // work deadlines derived from its own clocks.
        if let Some(allowance) = super::timeout_cleanup::allowance(&started) {
            started[super::timeout_cleanup::STARTED_KEY] = super::timeout_cleanup::started_payload(allowance, execution_deadline, run_deadline);
        }
        Self::append(&mut tx, &run_id, "WorkflowStarted", started, None, Some(&command_id)).await?;
        // Core's redrive writer copies each reused step in step
        // order. A step reused more than once keeps the time it first ran.
        for step in reused {
            let payload = &step.payload;
            let recorded_at = payload["reused_recorded_at"].as_str().map(str::to_owned).unwrap_or_else(|| replay_time(step.recorded_at));
            if let Some(mut scheduled) = step.scheduled_payload {
                // This timer already fired. Reuse its two events with one new
                // identity, without inserting a timer row or runnable task.
                let timer_id = id();
                scheduled["timer_id"] = json!(timer_id);
                Self::append(&mut tx, &run_id, "TimerScheduled", scheduled, None, None).await?;
                let mut fired = step.payload;
                fired["timer_id"] = json!(timer_id);
                fired["reused_from_run_id"] = json!(source_id);
                fired["reused_recorded_at"] = json!(recorded_at);
                Self::append(&mut tx, &run_id, "TimerFired", fired, None, None).await?;
                continue;
            }
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
