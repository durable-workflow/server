//! External wait timeouts retain their authored occurrence and transaction.
use super::{
    Result,
    backend::Backend,
    refuse,
    store::{Store, id, now},
    transaction::Transaction,
};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::BTreeSet;

pub(super) const CONDITION_INPUT_ORDER_FIELD: &str = "condition_wait_input_order";
const DURABLE_RECEIPT_ORDER: &str = "durable_receipt_order";
pub(super) const RESOLVE_CONDITION_WAIT: &str = "resolve_condition_wait";

pub(super) struct ConditionDecision<'a> {
    wait_id: &'a str,
    satisfied: bool,
    receipt: Option<i64>,
}

impl<'a> ConditionDecision<'a> {
    pub(super) fn parse(command: &'a Value) -> Result<Self> {
        let invalid = || refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_condition_wait_outcome");
        let wait_id = command["condition_wait_id"].as_str()
            .filter(|id| !id.is_empty() && id.len() <= 191)
            .ok_or_else(invalid)?;
        let satisfied = command["satisfied"].as_bool().ok_or_else(invalid)?;
        let receipt = command.get("input_receipt_sequence")
            .map(|value| value.as_i64().filter(|value| *value > 0).ok_or_else(invalid))
            .transpose()?;
        if !satisfied && receipt.is_some() {
            return Err(invalid());
        }
        Ok(Self { wait_id, satisfied, receipt })
    }
}

pub(super) fn uses_condition_input_order(payload: &Value) -> bool {
    payload[CONDITION_INPUT_ORDER_FIELD] == DURABLE_RECEIPT_ORDER
}

pub(super) fn supports_condition_input_order(capabilities: &Value) -> bool {
    capabilities.as_array().is_some_and(|flags| flags.iter().any(|flag| flag == CONDITION_INPUT_ORDER_FIELD))
}

pub(super) fn validate_condition_input_order(command: &Value) -> Result<()> {
    if command.get(CONDITION_INPUT_ORDER_FIELD).is_none() {
        return Ok(());
    }
    if uses_condition_input_order(command) && command["deadline_policy"] == "occurrence" {
        return Ok(());
    }
    Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_condition_wait_input_order"))
}

pub(super) fn timeout(command: &Value) -> Result<Option<(i64, DateTime<Utc>)>> {
    timeout_for(command, "invalid_signal_timeout")
}

pub(super) fn timeout_for(
    command: &Value,
    reason: &'static str,
) -> Result<Option<(i64, DateTime<Utc>)>> {
    let Some(value) = command
        .get("timeout_seconds")
        .filter(|value| !value.is_null())
    else {
        return Ok(None);
    };
    value
        .as_i64()
        .filter(|seconds| *seconds >= 0)
        .and_then(|seconds| chrono::Duration::try_seconds(seconds).map(|delay| (seconds, delay)))
        .and_then(|(seconds, delay)| now().checked_add_signed(delay).map(|at| (seconds, at)))
        .map(Some)
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, reason))
}

/// Server #269: `deadline_policy: "occurrence"` keeps one authored condition
/// wait's first-open wall deadline across false-wake reopens. It carries the
/// original authored budget, `condition_wait_timeout_budget_seconds`, which is
/// distinct from `timeout_seconds`, the worker's remaining replay budget. A
/// reopen's remaining budget may be smaller than the original; never larger.
/// Returns the original budget when the policy is present.
pub(super) fn occurrence_deadline(command: &Value) -> Result<Option<i64>> {
    let present = |field: &str| command.get(field).is_some_and(|value| !value.is_null());
    if !present("deadline_policy") && !present("condition_wait_timeout_budget_seconds") {
        return Ok(None);
    }
    let invalid = || refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_condition_deadline_policy");
    let budget = command["condition_wait_timeout_budget_seconds"].as_i64().filter(|seconds| *seconds >= 0)
        .filter(|seconds| chrono::Duration::try_seconds(*seconds).is_some());
    let timeout = command["timeout_seconds"].as_i64().filter(|seconds| *seconds >= 0);
    match (command["deadline_policy"].as_str(), budget, timeout) {
        (Some("occurrence"), Some(budget), Some(timeout))
            if timeout <= budget
                && command["condition_wait_occurrence_id"].as_str().is_some_and(|id| !id.is_empty()) => Ok(Some(budget)),
        _ => Err(invalid()),
    }
}

/// What an `open_condition_wait` does with its occurrence chain.
#[derive(Clone, Copy)]
pub(super) enum OccurrenceAnchor {
    /// No policy, on a chain that never had one: per-open behavior. `open`
    /// says whether the occurrence is still open (#1015 keeps that wait).
    Legacy { open: bool },
    /// An opted-in repeat of the chain's still-open wait: keep it.
    StillOpen,
    /// The chain's first open: A = its commit + T.
    First,
    /// A same-chain reopen: reuse the first wait's durable fire_at.
    Reopen(DateTime<Utc>),
}

pub(super) fn wait_fields(wait: &Value) -> Value {
    let mut fields = serde_json::Map::new();
    for key in [
        "condition_wait_id",
        "condition_wait_occurrence_id",
        "condition_key",
        "condition_definition_fingerprint",
        CONDITION_INPUT_ORDER_FIELD,
        "signal_wait_id",
        "signal_name",
    ] {
        if let Some(value) = wait.get(key).filter(|value| !value.is_null()) {
            fields.insert(key.into(), value.clone());
        }
    }
    Value::Object(fields)
}

pub(super) fn resolution(payload: &Value) -> Value {
    let condition = payload["timer_kind"] == "condition_timeout";
    let mut value = json!({"workflow_wait_kind":if condition {"condition"} else {"signal"},"open_wait_id":payload[if condition {"condition_wait_id"} else {"signal_wait_id"}],
        "resume_source_kind":"timer","resume_source_id":payload["timer_id"],
        "timer_id":payload["timer_id"],"timer_kind":payload["timer_kind"],
        "workflow_sequence":payload["sequence"],"workflow_event_type":"TimerFired"});
    value
        .as_object_mut()
        .unwrap()
        .extend(wait_fields(payload).as_object().unwrap().clone());
    value
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
    pub(super) async fn record_ordered_condition_decision(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        task_id: &str,
        decision: ConditionDecision<'_>,
    ) -> Result<()> {
        let mismatch = || refuse(StatusCode::CONFLICT, "condition_wait_outcome_mismatch");
        let waits = Self::waits(tx, run_id).await?;
        let wait = waits.conditions.iter().find(|wait| wait.id == decision.wait_id && wait.is_open())
            .ok_or_else(mismatch)?;
        let opening = waits.condition_opening(&wait.id)
            .filter(|opening| uses_condition_input_order(&opening.payload))
            .ok_or_else(mismatch)?;
        let timeout = wait.fired_at;
        if (!decision.satisfied && timeout.is_none())
            || (decision.satisfied && timeout.is_some() && decision.receipt.is_none())
        {
            return Err(mismatch());
        }
        if let Some(receipt) = decision.receipt {
            if timeout.is_some_and(|boundary| receipt >= boundary) {
                return Err(mismatch());
            }
            // The received position is canonical even if the same task applied
            // the signal or ran the update after the timer. Failed updates have
            // no UpdateApplied event.
            let evidence = Self::query(
                "SELECT 1 FROM workflow_history_events received
                 JOIN workflow_history_events applied
                   ON applied.workflow_run_id=received.workflow_run_id
                   AND applied.workflow_command_id=received.workflow_command_id
                 WHERE received.workflow_run_id=$1 AND received.sequence=$2
                   AND ((received.event_type='SignalReceived' AND applied.event_type='SignalApplied')
                     OR (received.event_type='UpdateAccepted' AND applied.event_type='UpdateApplied')) LIMIT 1",
            ).bind(run_id).bind(receipt).fetch_optional(&mut **tx).await?;
            if evidence.is_none() {
                return Err(mismatch());
            }
        }
        let prefix_end = if decision.satisfied { decision.receipt } else { timeout };
        if let Some(boundary) = prefix_end {
            let pending = Self::query(
                "SELECT 1 FROM workflow_history_events received
                 JOIN workflow_commands command ON command.id=received.workflow_command_id
                   AND command.resolved_workflow_run_id=received.workflow_run_id
                 WHERE received.workflow_run_id=$1 AND received.sequence<$2
                   AND received.event_type IN ('SignalReceived','UpdateAccepted')
                   AND command.status='accepted' AND command.applied_at IS NULL LIMIT 1",
            ).bind(run_id).bind(boundary).fetch_optional(&mut **tx).await?;
            if pending.is_some() {
                return Err(refuse(StatusCode::CONFLICT, "condition_wait_input_pending"));
            }
        }
        let group = super::activity_metadata::payload_for_path(&opening.payload);
        let mut payload = wait_fields(&opening.payload);
        payload.as_object_mut().unwrap().extend(group.clone());
        let sequence = wait.sequence.ok_or_else(mismatch)?;
        payload["sequence"] = json!(sequence);
        if let Some(receipt) = decision.receipt {
            payload["input_receipt_sequence"] = json!(receipt);
        }
        if let Some(fire) = timeout {
            payload["timer_fired_sequence"] = json!(fire);
            payload["timer_id"] = json!(wait.timer_id().ok_or_else(mismatch)?);
        }
        let event = if decision.satisfied { "ConditionWaitSatisfied" } else { "ConditionWaitTimedOut" };
        Self::append(tx, run_id, event, payload, Some(task_id), None).await?;
        Self::claim_wait_selection(tx, run_id, &group, "condition", &wait.id, event).await?;
        if timeout.is_none() {
            Self::cancel_wait_timeout(tx, run_id,
                &json!({"sequence":sequence,"condition_wait_id":wait.id}), task_id, now()).await?;
        }
        Ok(())
    }

    /// A terminal run cannot fire its remaining wait timeouts. Settle their
    /// physical rows without adding the cancellation events that published PHP
    /// omits for ordinary completion, failure and continuation. Authored timers
    /// retain their existing close behavior.
    pub(super) async fn settle_closed_wait_timeouts(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        at: DateTime<Utc>,
    ) -> Result<()> {
        let pending = Self::query(
            "SELECT id FROM workflow_run_timers WHERE workflow_run_id=$1 AND status='pending'",
        )
        .bind(run_id)
        .fetch_all(&mut **tx)
        .await?;
        if pending.is_empty() {
            return Ok(());
        }
        let pending = pending
            .iter()
            .map(|row| DB::string(row, "id"))
            .collect::<Result<BTreeSet<_>>>()?;
        // Use canonical scheduling history, including when a retained wait
        // projection or its physical timer task is absent. Read it once for the
        // run rather than once for each pending timer.
        let scheduled = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='TimerScheduled'")
            .bind(run_id).fetch_all(&mut **tx).await?;
        let mut cancelled = BTreeSet::new();
        let mut closed_waits = BTreeSet::new();
        for event in scheduled {
            let payload = DB::document_row(&event, "payload")?;
            if matches!(
                payload["timer_kind"].as_str(),
                Some("condition_timeout" | "signal_timeout")
            ) && let Some(timer_id) = payload["timer_id"]
                .as_str()
                .filter(|id| pending.contains(*id))
            {
                cancelled.insert(timer_id.to_owned());
                let (kind, field) = if payload["timer_kind"] == "condition_timeout" {
                    ("condition", "condition_wait_id")
                } else {
                    ("signal", "signal_wait_id")
                };
                if let Some(wait_id) = payload[field].as_str().filter(|id| !id.is_empty()) {
                    closed_waits.insert((kind, wait_id.to_owned(), timer_id.to_owned()));
                }
            }
        }
        if cancelled.is_empty() {
            return Ok(());
        }
        for timer_id in &cancelled {
            Self::query("UPDATE workflow_run_timers SET status='cancelled' WHERE workflow_run_id=$1 AND id=$2 AND status='pending'")
                .bind(run_id).bind(timer_id).execute(&mut **tx).await?;
        }
        // A retained wait may outlive its physical timer task. Match the
        // canonical wait and timer identities directly; do not recreate a
        // missing projection or depend on a task inventory row to clear it.
        for (kind, wait_id, timer_id) in closed_waits {
            Self::query("UPDATE workflow_run_waits SET task_backed=FALSE,task_status='cancelled',updated_at=$1 WHERE workflow_run_id=$2 AND kind=$3 AND wait_id=$4 AND resume_source_kind='timer' AND resume_source_id=$5")
                .bind(DB::bind_time(at)).bind(run_id).bind(kind).bind(wait_id).bind(timer_id).execute(&mut **tx).await?;
        }
        let tasks = Self::query("SELECT id,payload FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='timer' AND status IN ('ready','leased')")
            .bind(run_id).fetch_all(&mut **tx).await?;
        for task in tasks {
            let payload = DB::document_row(&task, "payload")?;
            if payload["timer_id"]
                .as_str()
                .is_some_and(|id| cancelled.contains(id))
            {
                let task_id = DB::string(&task, "id")?;
                Self::query("UPDATE workflow_tasks SET status='cancelled',lease_expires_at=NULL WHERE workflow_run_id=$1 AND id=$2")
                    .bind(run_id).bind(&task_id).execute(&mut **tx).await?;
            }
        }
        Ok(())
    }

    /// Server #269: what an `open_condition_wait` does with its occurrence,
    /// decided before #1015's still-open shortcut so an opted-in repeat is
    /// checked too. The chain is this run's physical waits of this
    /// occurrence ID; every one must carry the same policy, original budget,
    /// key and definition fingerprint. Once a chain is anchored, an open
    /// without the policy is a mismatch, never a legacy fresh budget. A is
    /// the first wait's durable timer `fire_at`, read by its timer ID, so it
    /// is never recomputed from a later clock or a rounded history timestamp.
    /// Each refusal is a nothing-written conflict inside the completion's
    /// existing transaction and ownership guard.
    pub(super) async fn occurrence_anchor(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        command: &Value,
    ) -> Result<OccurrenceAnchor> {
        let budget = occurrence_deadline(command)?;
        let Some(occurrence) = command["condition_wait_occurrence_id"].as_str().filter(|value| !value.is_empty()) else {
            return Ok(OccurrenceAnchor::Legacy { open: false });
        };
        // The opening payload keeps these trimmed, and drops them when empty.
        let normalized = |field: &str| {
            command[field].as_str().map(str::trim).filter(|value| !value.is_empty()).map(str::to_owned)
        };
        let (key, fingerprint) = (normalized("condition_key"), normalized("condition_definition_fingerprint"));
        let waits = Self::waits(tx, run_id).await?;
        let mut chain: Vec<_> = waits.conditions.iter().filter(|wait| wait.occurrence() == Some(occurrence)).collect();
        chain.sort_by_key(|wait| wait.sequence.unwrap_or(i64::MAX));
        if let Some(first) = chain.first()
            && let Some(opening) = waits.condition_opening(&first.id)
            && super::selections::is_selection(&opening.payload)
            && opening.payload["parallel_group_path"] != command["parallel_group_path"]
        {
            return Err(super::all_groups::invalid());
        }
        let mismatch = || refuse(StatusCode::CONFLICT, "condition_wait_occurrence_mismatch");
        if chain.iter().any(|wait| {
            waits.condition_opening(&wait.id).is_some_and(|opening| {
                opening.payload[CONDITION_INPUT_ORDER_FIELD] != command[CONDITION_INPUT_ORDER_FIELD]
            })
        }) {
            return Err(mismatch());
        }
        let unavailable = || refuse(StatusCode::CONFLICT, "condition_wait_anchor_unavailable");
        let Some(budget) = budget else {
            // Omitting the policy on an anchored chain is a mismatch.
            return if chain.iter().any(|wait| wait.policy().is_some() || wait.budget().is_some()) {
                Err(mismatch())
            } else {
                Ok(OccurrenceAnchor::Legacy { open: chain.iter().any(|wait| wait.advanceable()) })
            };
        };
        let Some(first) = chain.first() else {
            // The first open sets A from its own commit, so the remaining
            // budget it authors is the whole original budget (A = O + T).
            return if command["timeout_seconds"].as_i64() == Some(budget) {
                Ok(OccurrenceAnchor::First)
            } else {
                Err(mismatch())
            };
        };
        // A first open without the policy has no provable original budget.
        if first.policy() != Some("occurrence") || first.budget().is_none() {
            return Err(unavailable());
        }
        for wait in &chain {
            if wait.policy() != Some("occurrence")
                || wait.budget() != Some(budget)
                || wait.key() != key.as_deref()
                || wait.fingerprint() != fingerprint.as_deref()
            {
                return Err(mismatch());
            }
            if wait.fired_at.is_some() {
                return Err(refuse(StatusCode::CONFLICT, "condition_wait_occurrence_timed_out"));
            }
        }
        // A checked repeat of the still-open wait keeps it and its deadline.
        if chain.iter().any(|wait| wait.advanceable()) {
            return Ok(OccurrenceAnchor::StillOpen);
        }
        let timer = match first.timer_id() {
            Some(timer) => Self::query("SELECT fire_at,sequence FROM workflow_run_timers WHERE id=$1 AND workflow_run_id=$2")
                .bind(timer).bind(run_id).fetch_optional(&mut **tx).await?,
            None => None,
        };
        let Some(timer) = timer.filter(|row| DB::number(row, "sequence").ok() == first.sequence) else {
            return Err(unavailable());
        };
        Ok(OccurrenceAnchor::Reopen(DB::instant(&timer, "fire_at")?))
    }

    /// `anchor` is an occurrence deadline (Server #269): the timer then fires
    /// at exactly that instant, already due if it has passed, and is never
    /// fired inline; `delay_seconds` keeps the authored remaining budget.
    pub(super) async fn schedule_wait_timeout(
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        wait: &Value,
        task_id: &str,
        anchor: Option<DateTime<Utc>>,
    ) -> Result<Option<String>> {
        let direct = wait.get("signal_name").is_some();
        let Some((seconds, computed)) = timeout_for(
            wait,
            if direct {
                "invalid_signal_timeout"
            } else {
                "invalid_condition_timeout"
            },
        )?
        else {
            return Ok(None);
        };
        let fire_at = anchor.unwrap_or(computed);
        let inline = anchor.is_none() && seconds == 0;
        let run_id = DB::string(run, "id")?;
        let timer_id = id();
        let sequence = wait["sequence"]
            .as_i64()
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "invalid_stored_wait"))?;
        Self::query("INSERT INTO workflow_run_timers(id,workflow_run_id,sequence,status,delay_seconds,fire_at,fired_at) VALUES ($1,$2,$3,$4,$5,$6,$7)")
            .bind(&timer_id).bind(&run_id).bind(sequence).bind(if inline { "fired" } else { "pending" })
            .bind(seconds).bind(DB::bind_time(fire_at)).bind(DB::optional_time(inline.then_some(fire_at)))
            .execute(&mut **tx).await?;
        let mut payload = json!({"timer_id":timer_id,"sequence":sequence,"delay_seconds":seconds,
            "fire_at":super::cancellation_scopes::iso(fire_at),"timer_kind":if direct {"signal_timeout"} else {"condition_timeout"}});
        if anchor.is_some() {
            payload["deadline_policy"] = json!("occurrence");
            payload["occurrence_deadline_at"] = json!(super::cancellation_scopes::iso(fire_at));
        }
        payload
            .as_object_mut()
            .unwrap()
            .extend(wait_fields(wait).as_object().unwrap().clone());
        // A grouped condition's timeout carries its group (Server #1142).
        let group = super::activity_metadata::payload_for_path(wait);
        payload.as_object_mut().unwrap().extend(group.clone());
        Self::append(
            tx,
            &run_id,
            "TimerScheduled",
            payload.clone(),
            Some(task_id),
            None,
        )
        .await?;
        if inline {
            payload.as_object_mut().unwrap().remove("fire_at");
            payload["fired_at"] = json!(fire_at);
            Self::append(
                tx,
                &run_id,
                "TimerFired",
                payload.clone(),
                Some(task_id),
                None,
            )
            .await?;
            let resume = Self::create_task(
                tx,
                &run_id,
                &DB::string(run, "queue")?,
                "workflow",
                resolution(&payload),
            )
            .await?;
            // The completion reports the task it created, as
            // `applyOpenSignalWait` and `applyOpenConditionWait` do.
            Ok(Some(resume))
        } else {
            let mut task_payload = wait_fields(wait);
            task_payload["timer_id"] = json!(timer_id);
            task_payload.as_object_mut().unwrap().extend(group);
            let timer_task = Self::create_task(
                tx,
                &run_id,
                &DB::string(run, "queue")?,
                "timer",
                task_payload,
            )
            .await?;
            Self::query("UPDATE workflow_tasks SET available_at=$1 WHERE id=$2")
                .bind(DB::bind_time(fire_at))
                .bind(&timer_task)
                .execute(&mut **tx)
                .await?;
            Self::query("UPDATE workflow_run_waits SET task_id=$1,task_type='timer',task_status='ready',task_backed=TRUE WHERE workflow_run_id=$2 AND wait_id=$3 AND kind=$4")
                .bind(&timer_task).bind(&run_id).bind(super::text(wait,if direct {"signal_wait_id"} else {"condition_wait_id"})?)
                .bind(if direct {"signal"} else {"condition"}).execute(&mut **tx).await?;
            Ok(Some(timer_task))
        }
    }

    pub(super) async fn cancel_wait_timeout(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        wait: &Value,
        task_id: &str,
        at: DateTime<Utc>,
    ) -> Result<()> {
        let Some(timer) = Self::query("SELECT * FROM workflow_run_timers WHERE workflow_run_id=$1 AND sequence=$2 AND status='pending'")
            .bind(run_id).bind(wait["sequence"].as_i64().unwrap_or(0)).fetch_optional(&mut **tx).await? else {return Ok(());};
        let timer_id = DB::string(&timer, "id")?;
        let payload = Self::timer_cancellation_payload(tx, run_id, &timer, at).await?;
        let (kind, key) = if wait.get("condition_wait_id").is_some() {
            ("condition_timeout", "condition_wait_id")
        } else {
            ("signal_timeout", "signal_wait_id")
        };
        if payload["timer_kind"] != kind || payload[key] != wait[key] {
            return Ok(());
        }
        Self::query("UPDATE workflow_run_timers SET status='cancelled' WHERE id=$1")
            .bind(&timer_id)
            .execute(&mut **tx)
            .await?;
        let tasks = Self::query("SELECT id,payload FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='timer' AND status IN ('ready','leased')")
            .bind(run_id).fetch_all(&mut **tx).await?;
        for task in tasks {
            if DB::document_row(&task, "payload")?["timer_id"] == timer_id {
                Self::query("UPDATE workflow_tasks SET status='cancelled',lease_expires_at=NULL WHERE id=$1")
                    .bind(DB::string(&task,"id")?).execute(&mut **tx).await?;
            }
        }
        Self::append(tx, run_id, "TimerCancelled", payload, Some(task_id), None).await
    }
}
