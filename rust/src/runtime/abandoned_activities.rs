//! Post-run authority for a remote activity scheduled under the `abandon`
//! cancellation policy (Server #971), Core's `ActivityAbandonment::allows`.
//!
//! A run's canonical cooperative cancellation leaves such an activity open.
//! The activity keeps its own lifetime after the run closes: it can still be
//! claimed, heartbeat, renew its lease, retry, complete or fail, and its
//! original schedule-to-close deadline still times it out. Its outcomes are
//! recorded in the closed run's history and never resume the run.
//!
//! The authority is read from durable history and fails closed. It needs the
//! activity's own `ActivityScheduled` snapshot with the `abandon` policy and
//! the deadline the execution still carries, the run's cooperative request
//! and its delivery, and no `ActivityCancelled` for the activity. Once the
//! run is closed it also needs the request's own canonical `WorkflowCancelled`
//! with its `cancellation_cleanup`. Any other close (a cancel or terminate
//! command, a completion, failure, timeout or continue-as-new) cancels the
//! activity as before.
use super::store::now;
use super::transaction::Transaction;
use super::{Result, RuntimeError};
use chrono::{DateTime, Utc};
use serde_json::Value;

/// The history an abandoned activity's authority is read from.
const AUTHORITY_HISTORY: &str = "SELECT event_type,workflow_command_id,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('ActivityScheduled','ActivityCancelled','CooperativeCancellationRequested','CooperativeCancellationDelivered','WorkflowCancelled') ORDER BY sequence";

store_methods! {
    /// Whether `activity`, open on `run`, keeps its abandon authority. While
    /// the run is open, this is whether its canonical cooperative close would
    /// leave the activity open. Once the run is closed, it is whether that
    /// close did.
    pub(super) async fn abandoned_activity_allowed(
        connection: &mut DB::Connection,
        run: &DB::Row,
        activity: &DB::Row,
    ) -> Result<bool> {
        let run_id = DB::string(run, "id")?;
        let activity_id = DB::string(activity, "id")?;
        if DB::string(activity, "workflow_run_id")? != run_id
            || !matches!(DB::string(activity, "status")?.as_str(), "pending" | "running")
            || DB::optional_document_row(activity, "activity_options")?
                .is_none_or(|options| options["cancellation_policy"] != "abandon")
        {
            return Ok(false);
        }
        let closed = match DB::string(run, "status")?.as_str() {
            "pending" | "running" | "waiting" => false,
            "cancelled" if DB::optional_string(run, "closed_reason")?.as_deref() == Some("cancelled") => true,
            _ => return Ok(false),
        };
        let (Some(request), Some(deadline)) = (
            DB::optional_string(run, "cancellation_request_command_id")?,
            DB::optional_instant(activity, "schedule_to_close_deadline_at")?,
        ) else {
            return Ok(false);
        };
        let sequence = DB::number(activity, "sequence")?;
        let (mut scheduled, mut requested, mut delivered, mut cancelled, mut canonical_close) = (false, false, false, false, false);
        for event in Self::query(AUTHORITY_HISTORY).bind(&run_id).fetch_all(&mut *connection).await? {
            let payload = DB::document_row(&event, "payload")?;
            let by_request = DB::optional_string(&event, "workflow_command_id")?.as_deref() == Some(request.as_str());
            match DB::string(&event, "event_type")?.as_str() {
                "ActivityScheduled" => {
                    scheduled |= payload["sequence"].as_i64() == Some(sequence)
                        && payload["activity_execution_id"] == activity_id.as_str()
                        && payload["activity"]["id"] == activity_id.as_str()
                        && payload["activity"]["cancellation_policy"] == "abandon"
                        && snapshot_deadline(&payload) == Some(deadline);
                }
                "ActivityCancelled" => cancelled |= payload["activity_execution_id"] == activity_id.as_str(),
                "CooperativeCancellationRequested" => requested |= by_request,
                "CooperativeCancellationDelivered" => delivered |= by_request,
                "WorkflowCancelled" => {
                    canonical_close |= by_request && payload["cancellation_cleanup"]["request_id"] == request.as_str();
                }
                _ => {}
            }
        }
        Ok(scheduled && requested && delivered && !cancelled && (!closed || canonical_close))
    }

    /// The run an activity's outcome, heartbeat or deadline writes to: its
    /// active run, or a run closed by its canonical cooperative cancellation
    /// while the activity keeps its abandon authority. Also returns whether
    /// that run is closed, in which case nothing resumes it. Every other
    /// refusal is `active_run`'s.
    pub(super) async fn activity_run(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        activity: &DB::Row,
    ) -> Result<(DB::Row, bool)> {
        match Self::active_run(tx, run_id).await {
            Ok(run) => Ok((run, false)),
            Err(refusal @ RuntimeError::Refused { reason: "run_closed", .. }) => {
                let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1").bind(run_id).fetch_one(&mut **tx).await?;
                if !Self::abandoned_activity_allowed(&mut **tx, &run, activity).await? {
                    return Err(refusal);
                }
                tx.remember_run_tags(run_id, &run);
                Ok((run, true))
            }
            Err(error) => Err(error),
        }
    }

    /// Core's `closesExpiredRowOfClosedRun` for a cancelled run: an expired
    /// activity it left open without abandon authority, such as a row from
    /// before an upgrade, is cancelled with its attempt and task and no
    /// history, so the deadline sweep stops selecting it. Other closed runs
    /// are left as they are.
    pub(super) async fn cancel_closed_run_activity(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        activity: &DB::Row,
    ) -> Result<()> {
        let run = Self::query("SELECT status FROM workflow_runs WHERE id=$1").bind(run_id).fetch_one(&mut **tx).await?;
        if DB::string(&run, "status")? != "cancelled" {
            return Ok(());
        }
        let activity_id = DB::string(activity, "id")?;
        let at = now();
        Self::query("UPDATE activity_attempts SET status='cancelled',lease_expires_at=NULL,closed_at=COALESCE(closed_at,$1) WHERE activity_execution_id=$2 AND status='running'")
            .bind(DB::bind_time(at)).bind(&activity_id).execute(&mut **tx).await?;
        if let Some(task) = Self::query(DB::ACTIVITY_OPEN_TASK_SQL).bind(run_id).bind(&activity_id).fetch_optional(&mut **tx).await? {
            Self::query("UPDATE workflow_tasks SET status='cancelled',lease_expires_at=NULL WHERE id=$1")
                .bind(DB::string(&task, "id")?).execute(&mut **tx).await?;
        }
        Self::query("UPDATE activity_executions SET status='cancelled',closed_at=$1 WHERE id=$2 AND status IN ('pending','running')")
            .bind(DB::bind_time(at)).bind(&activity_id).execute(&mut **tx).await?;
        tx.summary_changed(run_id);
        Ok(())
    }
}

/// The schedule-to-close deadline an `ActivityScheduled` snapshot recorded
/// for its abandoned activity.
fn snapshot_deadline(payload: &Value) -> Option<DateTime<Utc>> {
    payload["activity"]["schedule_to_close_deadline_at"]
        .as_str()
        .and_then(|at| DateTime::parse_from_rfc3339(at).ok())
        .map(|at| at.with_timezone(&Utc))
}
