//! An activity worker's stop acknowledgement after a cooperative
//! cancellation reached its attempt, as published Server 2.11 serves it
//! (`ActivityTaskController::acknowledgeCancellation` over Workflow 2.10's
//! `ActivityCancellationAcknowledgement::recordStopped`), and the receipt
//! the status route shows for it. An acknowledgement records one event with
//! the stop's evidence; it never changes the attempt, its lease or the
//! cleanup deadline, and a repeat returns the original event.
use super::activity_lease_renewal::{micros, worker};
use super::laravel_rules::{self, Rule};
use super::transaction::Transaction;
use super::{
    Result, RuntimeError,
    store::{capabilities, now},
};
use axum::http::StatusCode;
use serde_json::{Value, json};

/// Published PHP's validation rules for the request, in their order.
const RULES: &laravel_rules::Rules = &[
    ("activity_attempt_id", &[Rule::Required, Rule::String, Rule::Max(255)]),
    ("lease_owner", &[Rule::Required, Rule::String, Rule::Max(255)]),
    ("request_id", &[Rule::Required, Rule::String, Rule::Max(255)]),
];

const REMEDIATION: &str = "Use a runtime with activity stop acknowledgements and an original claim from a cooperative worker on protocol 1.20 or newer.";

/// Whether a request's worker protocol is 1.20 or newer.
fn protocol_supported(protocol: &str) -> bool {
    super::cooperative::supports(&json!(["cooperative_cancellation"]), protocol)
}

store_methods! {
    pub(crate) async fn acknowledge_activity_cancellation(&self, task_id: &str, body: Value, protocol: &str) -> Result<Value> {
        // The protocol gate comes before validation, as in PHP.
        if !protocol_supported(protocol) {
            return Err(worker(StatusCode::UNPROCESSABLE_ENTITY, json!({"acknowledged":false,
                "reason":"activity_cancellation_acknowledgement_not_supported","minimum_protocol_version":"1.20",
                "unavailable":["worker_protocol"]})));
        }
        let body = laravel_rules::normalize(&body);
        if let Err(RuntimeError::Protocol { status, response }) = laravel_rules::validate(&body, RULES) {
            return Err(worker(status, response));
        }
        let attempt_id = body["activity_attempt_id"].as_str().unwrap_or_default();
        let owner = body["lease_owner"].as_str().unwrap_or_default();
        let request_id = body["request_id"].as_str().unwrap_or_default();
        let refusal = |status, error: &str, reason: &str| {
            worker(status, json!({"task_id":task_id,"activity_attempt_id":attempt_id,"error":error,"reason":reason}))
        };
        let outcome = |acknowledged: bool, duplicate: bool, reason: Option<&str>, event: Option<String>| {
            json!({"task_id":task_id,"activity_attempt_id":attempt_id,"lease_owner":owner,"request_id":request_id,
                "acknowledged":acknowledged,"duplicate":duplicate,"reason":reason,"history_event_id":event,
                "heartbeat_recorded":false})
        };
        // PHP answers a refusal of the recorder with 404 only for a missing
        // attempt, otherwise 409.
        let refused = |reason: &'static str| {
            let status = if reason == "activity_attempt_not_found" { StatusCode::NOT_FOUND } else { StatusCode::CONFLICT };
            worker(status, outcome(false, false, Some(reason), None))
        };
        let mut tx = self.begin().await?;
        // PHP's ownership guard, in its order: the task in this namespace,
        // then the attempt by its identity, its task and its owner.
        let task = Self::query("SELECT id FROM workflow_tasks WHERE id=$1 AND namespace=$2")
            .bind(task_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&mut *tx)
            .await?
            .ok_or_else(|| refusal(StatusCode::NOT_FOUND, "Activity task not found.", "task_not_found"))?;
        let Some(rows) = Self::attempt_rows(&mut tx, attempt_id).await? else {
            return Err(refusal(StatusCode::NOT_FOUND, "Activity attempt not found.", "attempt_not_found"));
        };
        let observed = Self::attempt_status(attempt_id, &rows, None)?;
        if observed["workflow_task_id"].as_str() != Some(DB::string(&task, "id")?.as_str()) {
            return Err(refusal(StatusCode::CONFLICT, "Activity attempt is not leased for this task.", "task_mismatch"));
        }
        if observed["lease_owner"].as_str() != Some(owner) {
            let mut error = json!({"task_id":task_id,"activity_attempt_id":attempt_id,
                "error":"Activity attempt lease is owned by another worker.","reason":"lease_owner_mismatch"});
            error["lease_owner"] = observed["lease_owner"].clone();
            return Err(worker(StatusCode::CONFLICT, error));
        }
        let (Some(activity), Some(run), Some(task)) = (&rows.activity, &rows.run, &rows.task) else {
            return Err(refused("activity_attempt_not_found"));
        };
        // The original claim must come from a cooperative worker on 1.20,
        // checked before the request or its delivery.
        if !Self::claim_supports_cancellation(task)? {
            return Err(worker(StatusCode::CONFLICT, json!({"acknowledged":false,
                "reason":"activity_cancellation_acknowledgement_not_supported","required_capability":"cooperative_cancellation",
                "minimum_protocol_version":"1.20","worker_id":owner,"unavailable":["worker_claim_capability"],
                "remediation":REMEDIATION})));
        }
        let run_id = DB::string(run, "id")?;
        let execution_id = DB::string(activity, "id")?;
        // `recordStopped`'s fence: the execution's current attempt, on this
        // run, leased to this owner on both the attempt and its task.
        if DB::optional_string(activity, "current_attempt_id")?.as_deref() != Some(attempt_id)
            || DB::string(activity, "workflow_run_id")? != run_id
            || DB::string(task, "workflow_run_id")? != run_id
            || DB::optional_string(&rows.attempt, "lease_owner")?.as_deref() != Some(owner)
            || DB::optional_string(task, "lease_owner")?.as_deref() != Some(owner)
        {
            return Err(refused("activity_cancellation_acknowledgement_fence_mismatch"));
        }
        if DB::optional_string(run, "cancellation_request_command_id")?.as_deref() != Some(request_id) {
            return Err(refused("cancellation_request_mismatch"));
        }
        let Some((cancellation_event, cancellation)) =
            Self::activity_cancellation_event(&mut tx, &run_id, request_id, &execution_id, attempt_id).await?
        else {
            return Err(refused("activity_cancellation_not_recorded"));
        };
        let snapshot = &cancellation["activity_attempt"];
        if snapshot["id"] != attempt_id || snapshot["status"] != "cancelled" || snapshot["lease_owner"] != owner
            || snapshot["task_id"] != task_id || snapshot["activity_execution_id"] != execution_id.as_str()
        {
            return Err(refused("activity_cancellation_snapshot_mismatch"));
        }
        let Some(context) = Self::cooperative_context(&mut tx, &run_id, request_id).await? else {
            return Err(refused("cancellation_context_not_recorded"));
        };
        if let Some((event, _)) = Self::acknowledgement_event(&mut tx, &run_id, &cancellation_event, attempt_id).await? {
            tx.commit().await?;
            return Ok(with_protocol(outcome(true, true, None, Some(event))));
        }
        if DB::string(&rows.attempt, "status")? != "cancelled" || DB::string(activity, "status")? != "cancelled"
            || DB::string(task, "status")? != "cancelled"
        {
            return Err(refused("activity_cancellation_not_fenced"));
        }
        let at = now();
        let acknowledged_at = micros(at);
        let mut payload = json!({"sequence":DB::number(activity,"sequence")?,"activity_execution_id":execution_id,
            "activity_attempt_id":attempt_id,"lease_owner":owner,"cancellation_history_event_id":cancellation_event,
            "request_id":request_id,"root_request_id":context.root_request_id,"cleanup_deadline_at":micros(context.deadline),
            "callback_state":"stopped","evidence_source":"activity_worker","acknowledged_at":acknowledged_at,
            "received_after_deadline":at >= context.deadline});
        payload["task"] = Self::task_snapshot(task)?;
        Self::append_by_command_id(&mut tx, &run_id, "ActivityCancellationAcknowledged", payload, Some(task_id), request_id).await?;
        Self::query("UPDATE workflow_runs SET last_progress_at=$1 WHERE id=$2")
            .bind(DB::bind_time(at)).bind(&run_id).execute(&mut *tx).await?;
        let event = Self::query("SELECT h.id FROM workflow_history_events h JOIN workflow_runs r ON r.id=h.workflow_run_id AND r.last_history_sequence=h.sequence WHERE h.workflow_run_id=$1")
            .bind(&run_id).fetch_one(&mut *tx).await?;
        let event = DB::string(&event, "id")?;
        tx.summary_changed(&run_id);
        tx.commit().await?;
        Ok(with_protocol(outcome(true, false, None, Some(event))))
    }

    /// The status route's `cancellation_acknowledgement` receipt: present once
    /// the run's cooperative request cancelled this attempt.
    pub(super) async fn activity_cancellation_receipt(
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        execution_id: &str,
        attempt_id: &str,
    ) -> Result<Option<Value>> {
        let Some(request_id) = DB::optional_string(run, "cancellation_request_command_id")? else {
            return Ok(None);
        };
        let run_id = DB::string(run, "id")?;
        let Some((cancellation_event, _)) =
            Self::activity_cancellation_event(tx, &run_id, &request_id, execution_id, attempt_id).await?
        else {
            return Ok(None);
        };
        let Some(context) = Self::cooperative_context(tx, &run_id, &request_id).await? else {
            return Ok(None);
        };
        let mut receipt = json!({"request_id":request_id,"root_request_id":context.root_request_id,
            "cleanup_deadline_at":micros(context.deadline),"cancellation_history_event_id":cancellation_event,
            "callback_state":"unknown","history_event_id":null,"acknowledged_at":null,"received_after_deadline":null});
        if let Some((event, payload)) = Self::acknowledgement_event(tx, &run_id, &cancellation_event, attempt_id).await? {
            receipt["callback_state"] = payload["callback_state"].clone();
            receipt["history_event_id"] = json!(event);
            receipt["acknowledged_at"] = payload["acknowledged_at"].clone();
            receipt["received_after_deadline"] = payload["received_after_deadline"].clone();
        }
        Ok(Some(receipt))
    }

    /// The `ActivityCancelled` event the request recorded for this attempt.
    async fn activity_cancellation_event(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        request_id: &str,
        execution_id: &str,
        attempt_id: &str,
    ) -> Result<Option<(String, Value)>> {
        let events = Self::query("SELECT id,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND workflow_command_id=$2 AND event_type='ActivityCancelled' ORDER BY sequence")
            .bind(run_id).bind(request_id).fetch_all(&mut **tx).await?;
        for event in events {
            let payload = DB::document_row(&event, "payload")?;
            if payload["activity_execution_id"] == execution_id && payload["activity_attempt_id"] == attempt_id {
                return Ok(Some((DB::string(&event, "id")?, payload)));
            }
        }
        Ok(None)
    }

    /// An earlier acknowledgement of the same cancellation by this attempt.
    async fn acknowledgement_event(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        cancellation_event: &str,
        attempt_id: &str,
    ) -> Result<Option<(String, Value)>> {
        let events = Self::query("SELECT id,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='ActivityCancellationAcknowledged' ORDER BY sequence")
            .bind(run_id).fetch_all(&mut **tx).await?;
        for event in events {
            let payload = DB::document_row(&event, "payload")?;
            if payload["cancellation_history_event_id"] == cancellation_event && payload["activity_attempt_id"] == attempt_id {
                return Ok(Some((DB::string(&event, "id")?, payload)));
            }
        }
        Ok(None)
    }

    /// The request's root and original cleanup deadline, from its
    /// `CooperativeCancellationRequested` event.
    async fn cooperative_context(tx: &mut Transaction<'_, DB>, run_id: &str, request_id: &str) -> Result<Option<CooperativeContext>> {
        let Some(requested) = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND workflow_command_id=$2 AND event_type='CooperativeCancellationRequested' ORDER BY sequence LIMIT 1")
            .bind(run_id).bind(request_id).fetch_optional(&mut **tx).await?
        else {
            return Ok(None);
        };
        let cancellation = &DB::document_row(&requested, "payload")?["cancellation"];
        let Some(deadline) = cancellation["cleanup_deadline_at"].as_str()
            .and_then(|value| chrono::DateTime::parse_from_rfc3339(value).ok())
            .map(|value| value.with_timezone(&chrono::Utc))
        else {
            return Ok(None);
        };
        let root_request_id = cancellation["lineage"].as_array().and_then(|lineage| lineage.first())
            .and_then(|root| root["request_id"].as_str()).unwrap_or(request_id).to_owned();
        Ok(Some(CooperativeContext { root_request_id, deadline }))
    }
}

struct CooperativeContext {
    root_request_id: String,
    deadline: chrono::DateTime<chrono::Utc>,
}

fn with_protocol(mut response: Value) -> Value {
    response["protocol_version"] = json!(super::WORKER_PROTOCOL_VERSION);
    response["server_capabilities"] = capabilities();
    response
}
