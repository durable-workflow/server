//! Optional immediate polls carried by an already fenced task completion.
use super::{Result, RuntimeError, worker_poll_fence::{PollAdmission, WorkerPollFence}};
use axum::http::StatusCode;
use serde_json::{Value, json};

pub(super) struct ActivityPoll {
    body: Value,
    fence: WorkerPollFence,
    protocol: String,
}

#[derive(Clone, Copy)]
pub(super) struct Config<'a> {
    pub(super) protocol: Option<&'a str>,
    pub(super) pages: super::history_pages::HistoryPages,
    pub(super) default_wait: u64,
}

pub(super) struct WorkflowPoll {
    body: Value,
    fence: WorkerPollFence,
    protocol: String,
    page: Option<i64>,
    encoding: Option<&'static str>,
    request: super::workflow_poll_requests::WorkflowPollRequest,
}

pub(super) enum Outcome {
    Declined,
    Empty,
    Leased(Value),
}

impl Outcome {
    pub(super) fn activity_response(self) -> Result<Option<Value>> {
        self.response(false, None)
    }

    pub(super) fn workflow_response(self, poll: Option<&WorkflowPoll>) -> Result<Option<Value>> {
        self.response(true, poll.and_then(|poll| poll.encoding))
    }

    // Called only after the completion commits. The receipt retains plain
    // original data; presentation and this response's clock are fresh.
    fn response(self, workflow: bool, encoding: Option<&'static str>) -> Result<Option<Value>> {
        Ok(match self {
            Self::Declined => None,
            Self::Empty => Some(json!({"leased":false})),
            Self::Leased(mut task) => {
                if workflow { super::history_pages::compress(&mut task, encoding)?; }
                let answer = json!({"task":task,"poll_status":"leased",
                    "server_capabilities":super::store::capabilities(),"protocol_version":super::WORKER_PROTOCOL_VERSION});
                let answer = if workflow { workflow_answer(answer) } else { super::store::activity_server_time("activity", answer) };
                Some(json!({"leased":true,"answer":answer}))
            }
        })
    }
}

/// The normal workflow poll and a committed carrier share presentation and
/// the response clock. Original lease, history and deadline fields stay put.
pub(super) fn workflow_answer(mut answer: Value) -> Value {
    if let Some(task) = answer["task"].as_object_mut() {
        task.insert("task_kind".into(), json!("workflow"));
        answer["server_time"] = json!(super::activity_lease_renewal::micros(super::store::now()));
    }
    answer
}

// Request validation happens before claim_in. In particular, invalid_request
// from inside a claim can describe corrupt stored data, so it is not soft.
fn soft_refusal(error: &RuntimeError) -> bool {
    match error {
        RuntimeError::Refused { status: StatusCode::CONFLICT,
            reason: "worker_not_registered" | "task_queue_mismatch" | "poll_cached_task_expired", .. } => true,
        RuntimeError::Protocol { status: StatusCode::CONFLICT, response } =>
            matches!(response["reason"].as_str(), Some("worker_draining" | "poll_request_task_kinds_conflict")),
        _ => false,
    }
}

fn activity_shape(poll: &Value, owner: &str) -> bool {
    let normalized = super::laravel_rules::normalize(poll);
    poll.as_object().is_some_and(|fields| fields.keys().all(|key|
        matches!(key.as_str(), "worker_id" | "task_queue" | "build_id" | "poll_request_id" | "timeout_seconds")))
        && poll["worker_id"].as_str() == Some(owner)
        && super::text(poll, "task_queue").is_ok()
        && super::text(poll, "poll_request_id").is_ok()
        && poll["poll_request_id"].as_str().is_some_and(|id| id.chars().count() <= 255)
        && normalized["poll_request_id"].as_str().is_some_and(|id| !id.is_empty())
        && super::laravel_rules::errors(&normalized, super::http::ACTIVITY_POLL_RULES).is_empty()
}

fn workflow_shape(poll: &Value, owner: &str) -> bool {
    let normalized = super::laravel_rules::normalize(poll);
    poll.as_object().is_some_and(|fields| fields.keys().all(|key|
        matches!(key.as_str(), "worker_id" | "task_queue" | "build_id" | "poll_request_id" | "timeout_seconds"
            | "history_page_size" | "accept_history_encoding" | "task_kinds")))
        && poll["worker_id"].as_str() == Some(owner)
        && super::text(poll, "task_queue").is_ok()
        && poll["poll_request_id"].as_str().is_some_and(|id| id.chars().count() <= 255)
        && normalized["poll_request_id"].as_str().is_some_and(|id| !id.is_empty())
        && super::laravel_rules::errors(&normalized, super::http::WORKFLOW_POLL_RULES).is_empty()
        && (poll["task_kinds"].is_null() || poll["task_kinds"].as_array().is_some_and(|kinds| kinds.len() == 1 && kinds[0] == "workflow"))
        && super::history_pages::requested_size(poll).is_ok()
        && super::history_pages::requested(poll).is_ok()
}

pub(super) fn paired_owners_match(activity: Option<&Value>, workflow: Option<&Value>, owner: &str) -> bool {
    activity.into_iter().chain(workflow).all(|poll| poll["worker_id"].as_str() == Some(owner))
}

store_methods! {
    /// A live activity receipt is a read, not another claim. Registration,
    /// receipt and lease are observed in one snapshot; expiry and all other
    /// non-replay states return to the ordinary locked poll path.
    pub(super) async fn replay_activity_poll_read_only(
        &self, body: &Value, fence: &WorkerPollFence,
    ) -> Result<Option<Value>> {
        let owner = super::text(body, "worker_id")?;
        let request = super::text(body, "poll_request_id")?;
        let mut read = self.begin_read().await?;
        if !self.worker_poll_fence_holds_in(&mut read, fence, owner).await? { return Ok(None); }
        let receipt = Self::query("SELECT p.*,t.status,t.attempt_count,t.lease_owner,t.lease_expires_at FROM dw_poll_receipts p JOIN workflow_tasks t ON t.id=p.task_id WHERE p.worker_id=$1 AND p.kind='activity' AND p.request_id=$2 AND p.namespace=$3")
            .bind(owner).bind(request).bind(self.namespace.as_ref()).fetch_optional(&mut *read).await?;
        let Some(receipt) = receipt else { return Ok(None); };
        if DB::string(&receipt, "status")? != "leased"
            || DB::number(&receipt, "attempt")? != DB::number(&receipt, "attempt_count")?
            || DB::optional_string(&receipt, "lease_owner")?.as_deref() != Some(owner)
            || DB::optional_instant(&receipt, "lease_expires_at")?.is_none_or(|expires| expires <= super::store::now())
        { return Ok(None); }
        let response = DB::document_row(&receipt, "response")?;
        if response["task_id"].as_str() != Some(DB::string(&receipt, "task_id")?.as_str())
            || response["lease_owner"].as_str() != Some(owner)
            || response["attempt_number"].as_i64() != Some(DB::number(&receipt, "attempt")?)
        {
            return Err(super::refuse(StatusCode::INTERNAL_SERVER_ERROR, "invalid_poll_receipt"));
        }
        read.commit().await?;
        // Return original lease/deadline fields. The caller adds only its
        // fresh response clock; neither receipt replay nor caching renews it.
        Ok(Some(response))
    }

    pub(super) async fn admit_activity_carrier(
        &self, poll: Option<Value>, owner: &str, protocol: Option<&str>,
    ) -> Result<Option<ActivityPoll>> {
        let (Some(poll), Some(protocol)) = (poll, protocol) else { return Ok(None); };
        // A completion owner's role is not authority to poll as a different
        // worker. Do not even look up that other worker's admission state.
        if !activity_shape(&poll, owner) { return Ok(None); }
        let queue = super::text(&poll, "task_queue")?;
        if let Err(error) = self.refuse_draining_worker(owner, queue).await {
            return if soft_refusal(&error) { Ok(None) } else { Err(error) };
        }
        match self.admit_carried_worker_poll(owner, queue).await? {
            PollAdmission::Fenced(fence) => Ok(Some(ActivityPoll { body: poll, fence, protocol: protocol.to_owned() })),
            PollAdmission::Unregistered | PollAdmission::Stale => Ok(None),
        }
    }

    pub(super) async fn claim_carried_activity(
        &self, tx: &mut super::transaction::Transaction<'_, DB>, poll: &ActivityPoll, completion_owner: &str,
    ) -> Result<Outcome> {
        let owner = super::text(&poll.body, "worker_id")?;
        if owner != completion_owner || !self.worker_poll_fence_holds_in(tx, &poll.fence, owner).await? {
            return Ok(Outcome::Declined);
        }
        // Validation work uses the workflow slot too. An unverifiable lease
        // deadline remains protected, rather than being treated as free.
        let at = super::store::now();
        let occupied = Self::query("SELECT (SELECT COUNT(*) FROM workflow_tasks WHERE namespace=$1 AND lease_owner=$2 AND task_type='workflow' AND status='leased' AND (lease_expires_at IS NULL OR lease_expires_at>$3)) AS workflow_leases,(SELECT COUNT(*) FROM workflow_update_validation_tasks WHERE namespace=$4 AND lease_owner=$5 AND status='leased' AND (lease_expires_at IS NULL OR lease_expires_at>$6)) AS validation_leases")
            .bind(self.namespace.as_ref()).bind(owner).bind(DB::bind_time(at))
            .bind(self.namespace.as_ref()).bind(owner).bind(DB::bind_time(at))
            .fetch_one(&mut **tx).await?;
        if DB::number(&occupied, "workflow_leases")? > 0 || DB::number(&occupied, "validation_leases")? > 0 {
            return Ok(Outcome::Declined);
        }
        let saved = tx.poll_savepoint().await?;
        match self.claim_in(tx, &poll.body, "activity", &poll.protocol, None, None).await {
            Ok(task) => {
                tx.release_poll_claim().await?;
                Ok(task.map_or(Outcome::Empty, Outcome::Leased))
            }
            Err(error) => {
                tx.rollback_poll_claim(saved).await?;
                if soft_refusal(&error) { Ok(Outcome::Declined) } else { Err(error) }
            }
        }
    }

    pub(super) async fn admit_workflow_carrier(
        &self, poll: Option<Value>, owner: &str, config: Config<'_>,
    ) -> Result<Option<WorkflowPoll>> {
        let (Some(poll), Some(protocol)) = (poll, config.protocol) else { return Ok(None); };
        if !workflow_shape(&poll, owner) { return Ok(None); }
        let queue = super::text(&poll, "task_queue")?;
        if let Err(error) = self.refuse_draining_worker(owner, queue).await {
            return if soft_refusal(&error) { Ok(None) } else { Err(error) };
        }
        let PollAdmission::Fenced(fence) = self.admit_carried_worker_poll(owner, queue).await? else { return Ok(None); };
        let request = self.workflow_poll_request(owner, queue,
            super::workflow_poll_requests::request_id(&poll).expect("validated poll request identity"),
            config.default_wait, &["workflow"]).await?;
        let encoding = super::history_pages::requested(&poll)?;
        let page = config.pages.poll(super::history_pages::requested_size(&poll)?, encoding);
        Ok(Some(WorkflowPoll { body: poll, fence, protocol: protocol.to_owned(), page, encoding, request }))
    }

    pub(super) async fn claim_carried_workflow(
        &self, tx: &mut super::transaction::Transaction<'_, DB>, poll: &WorkflowPoll, completion_owner: &str,
    ) -> Result<Outcome> {
        let owner = super::text(&poll.body, "worker_id")?;
        if owner != completion_owner || !self.worker_poll_fence_holds_in(tx, &poll.fence, owner).await? {
            return Ok(Outcome::Declined);
        }
        let occupied = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE namespace=$1 AND lease_owner=$2 AND task_type='activity' AND status='leased' AND (lease_expires_at IS NULL OR lease_expires_at>$3)")
            .bind(self.namespace.as_ref()).bind(owner).bind(DB::bind_time(super::store::now())).fetch_one(&mut **tx).await?;
        if occupied > 0 { return Ok(Outcome::Declined); }
        let saved = tx.poll_savepoint().await?;
        let result: Result<Outcome> = async {
            // A preceding ordinary empty poll is already an answer for this
            // ID. A carrier must honor it instead of claiming past it.
            if let Some(answer) = self.replay_workflow_poll_in(tx, &poll.request, owner, super::text(&poll.body, "task_queue")?).await? {
                return Ok(if answer["task"].is_object() { Outcome::Leased(answer["task"].clone()) } else { Outcome::Empty });
            }
            let task = self.claim_in(tx, &poll.body, "workflow", &poll.protocol, poll.page, Some(&poll.request)).await?;
            if let Some(task) = task {
                Self::remember_workflow_poll(tx, &poll.request, &json!({"task":task,"poll_status":"leased"})).await?;
                Ok(Outcome::Leased(task))
            } else {
                // Keep the kind binding, but no new ordinary empty answer:
                // follow-up polling under this ID must see later arrivals.
                Ok(Outcome::Empty)
            }
        }.await;
        match result {
            Ok(outcome) => { tx.release_poll_claim().await?; Ok(outcome) }
            Err(error) => {
                tx.rollback_poll_claim(saved).await?;
                if soft_refusal(&error) { Ok(Outcome::Declined) } else { Err(error) }
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn request_mismatch_is_declined_and_internal_claim_errors_are_not_soft() {
        let poll = json!({"worker_id":"owner","task_queue":"queue","poll_request_id":"request","timeout_seconds":0});
        assert!(activity_shape(&poll, "owner"));
        assert!(!activity_shape(&poll, "another-worker"));
        let mut extra = poll.clone();
        extra["unknown"] = json!(true);
        assert!(!activity_shape(&extra, "owner"));
        extra = poll.clone();
        extra["poll_request_id"] = json!("\u{200b}\u{feff}");
        assert!(!activity_shape(&extra, "owner"));
        extra = poll.clone();
        extra["poll_request_id"] = json!("λ".repeat(128));
        assert!(!activity_shape(&extra, "owner"), "native opaque activity IDs retain the existing UTF-8 byte bound");
        assert!(workflow_shape(&poll, "owner"));
        assert!(!workflow_shape(&poll, "other"));
        extra = poll.clone();
        extra["task_kinds"] = json!(["workflow","update_validation"]);
        assert!(!workflow_shape(&extra, "owner"));
        extra["task_kinds"] = json!({"0":"workflow"});
        assert!(!workflow_shape(&extra, "owner"));
        assert!(!soft_refusal(&sqlx::Error::PoolTimedOut.into()));
        assert!(!soft_refusal(&serde_json::from_str::<Value>("{").unwrap_err().into()));
        for reason in ["invalid_request", "task_attempt_exhausted", "history_event_exceeds_page_budget", "unsupported_stored_timestamp"] {
            assert!(!soft_refusal(&super::super::refuse(StatusCode::CONFLICT, reason)));
        }
        assert!(soft_refusal(&super::super::refuse(StatusCode::CONFLICT, "poll_cached_task_expired")));
        assert!(!soft_refusal(&super::super::refuse(StatusCode::INTERNAL_SERVER_ERROR, "poll_cached_task_expired")));
    }
}
