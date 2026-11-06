//! Optional immediate polls carried by an already fenced task completion.
use super::{Result, RuntimeError, worker_poll_fence::{PollAdmission, WorkerPollFence}};
use axum::http::StatusCode;
use serde_json::{Value, json};

pub(super) struct ActivityPoll {
    body: Value,
    fence: WorkerPollFence,
    protocol: String,
}

pub(super) enum Outcome {
    Declined,
    Empty,
    Leased(Value),
}

impl Outcome {
    pub(super) fn response(self) -> Option<Value> {
        match self {
            Self::Declined => None,
            Self::Empty => Some(json!({"leased":false})),
            Self::Leased(_task) => Some(json!({"leased":true})),
        }
    }
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
    poll.is_object()
        && poll["worker_id"].as_str() == Some(owner)
        && poll["poll_request_id"].as_str().is_some_and(|id| !id.trim().is_empty())
        && super::laravel_rules::errors(&super::laravel_rules::normalize(poll), super::http::ACTIVITY_POLL_RULES).is_empty()
        && super::history_pages::requested_size(poll).is_ok()
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
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn request_mismatch_is_declined_and_internal_claim_errors_are_not_soft() {
        let poll = json!({"worker_id":"owner","task_queue":"queue","poll_request_id":"request","timeout_seconds":0});
        assert!(activity_shape(&poll, "owner"));
        assert!(!activity_shape(&poll, "another-worker"));
        for reason in ["invalid_request", "task_attempt_exhausted", "history_event_exceeds_page_budget", "unsupported_stored_timestamp"] {
            assert!(!soft_refusal(&super::super::refuse(StatusCode::CONFLICT, reason)));
        }
        assert!(soft_refusal(&super::super::refuse(StatusCode::CONFLICT, "poll_cached_task_expired")));
        assert!(!soft_refusal(&super::super::refuse(StatusCode::INTERNAL_SERVER_ERROR, "poll_cached_task_expired")));
    }
}
