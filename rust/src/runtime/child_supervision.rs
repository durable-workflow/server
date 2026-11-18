//! Supervision of open children across continue-as-new (Core workflow#868,
//! Workflow 2.26.0 `ChildSupervision`, with the workflow#1107 retry).
//!
//! A run that continues as new hands every child it still supervises to the
//! continued run: the children it started and those an earlier run of its
//! chain handed to it. The child link, child call, child identity, deadlines
//! and parent-close policy stay as recorded; only the run that applies the
//! policy changes. The chain's final run applies it when it closes, and
//! `abandon` stays `abandon`. Nothing is appended to a closed predecessor.
//!
//! Core keeps each transfer in `workflow_child_supervisions`. Native keeps
//! the same facts in `workflow_links`, as a `child_supervision` link from the
//! supervising run to the child's current run, with the original sequence
//! and policy. The child's original link and the run that started it are the
//! latest earlier run of the chain that holds a `child_workflow` link for
//! that sequence and child instance: each transfer copies a child the
//! predecessor started or was handed, so the rows of one child form an
//! unbroken run of the chain back to that run. Every reader of links that
//! lists a run's parents or children leaves this link type out, as Core's
//! separate table is never listed.
use super::transaction::Transaction;
use super::{
    Result,
    backend::Backend,
    store::{Store, id},
};
use chrono::{DateTime, Utc};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::HashSet;

fn open(status: &str) -> bool {
    matches!(status, "pending" | "running" | "waiting")
}

/// A child an earlier run of the chain handed to the closing run, as the
/// enforcer applies its policy (`ParentClosePolicyEnforcer::targets`).
pub(super) struct HandedOverChild {
    /// The original child link (`workflow_link_id`), when it is retained.
    pub(super) link_id: Option<String>,
    /// The run that started the child, whose child call keeps the call.
    pub(super) origin_run: Option<String>,
    pub(super) sequence: i64,
    pub(super) policy: String,
    pub(super) child_instance: String,
    /// The child run the supervision names; the enforcer follows it to its
    /// current run.
    pub(super) child_run: String,
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
    /// `ChildSupervision::currentChildRun`: a child that continued as new is
    /// still the same child, so a run it continued from resolves to its
    /// instance's current run in the same namespace. Returns nothing for a
    /// run no longer retained.
    pub(super) async fn current_child_run(
        tx: &mut Transaction<'_, DB>,
        child_run: &str,
        child_instance: &str,
    ) -> Result<Option<DB::Row>> {
        let Some(run) = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(child_run).fetch_optional(&mut **tx).await?
        else {
            return Ok(None);
        };
        if DB::optional_string(&run, "closed_reason")?.as_deref() != Some("continued") {
            return Ok(Some(run));
        }
        let current = Self::query("SELECT r.* FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1")
            .bind(child_instance).fetch_optional(&mut **tx).await?;
        Ok(Some(match current {
            Some(current)
                if DB::string(&current, "workflow_instance_id")? == child_instance
                    && DB::string(&current, "namespace")? == DB::string(&run, "namespace")? =>
            {
                current
            }
            _ => run,
        }))
    }

    /// `ChildSupervision::transferToContinuedRun`, inside the continue-as-new
    /// transaction: each child the closing run supervises and that is still
    /// open is handed to the continued run, in Core's order (the children it
    /// started, by first link, then those handed to it).
    pub(super) async fn transfer_child_supervision(
        tx: &mut Transaction<'_, DB>,
        closing: &str,
        continued: &str,
        instance: &str,
        at: DateTime<Utc>,
    ) -> Result<()> {
        // (sequence, child instance, child run, policy, child status, child closed reason)
        type Entry = (i64, String, String, String, Option<String>, Option<String>);
        let mut entries: Vec<Entry> = Vec::new();
        // A child that continued as new while this run was open has one link
        // per child run; the first keeps the call, the latest names the run.
        let started = Self::query("SELECT l.sequence,l.child_workflow_instance_id,l.child_workflow_run_id,l.parent_close_policy,r.status,r.closed_reason FROM workflow_links l LEFT JOIN workflow_runs r ON r.id=l.child_workflow_run_id WHERE l.parent_workflow_run_id=$1 AND l.link_type='child_workflow' ORDER BY l.created_at,l.id")
            .bind(closing).fetch_all(&mut **tx).await?;
        for link in &started {
            // Native and PHP record every child link with its sequence.
            let Some(sequence) = DB::optional_number(link, "sequence")? else {
                continue;
            };
            let child_instance = DB::string(link, "child_workflow_instance_id")?;
            let child_run = DB::string(link, "child_workflow_run_id")?;
            let status = DB::optional_string(link, "status")?;
            let closed_reason = DB::optional_string(link, "closed_reason")?;
            match entries.iter_mut().find(|entry| entry.0 == sequence && entry.1 == child_instance) {
                Some(entry) => {
                    entry.2 = child_run;
                    entry.4 = status;
                    entry.5 = closed_reason;
                }
                None => entries.push((sequence, child_instance, child_run, DB::string(link, "parent_close_policy")?, status, closed_reason)),
            }
        }
        let handed = Self::query("SELECT l.sequence,l.child_workflow_instance_id,l.child_workflow_run_id,l.parent_close_policy,r.status,r.closed_reason FROM workflow_links l LEFT JOIN workflow_runs r ON r.id=l.child_workflow_run_id WHERE l.parent_workflow_run_id=$1 AND l.link_type='child_supervision' ORDER BY l.created_at,l.id")
            .bind(closing).fetch_all(&mut **tx).await?;
        for row in &handed {
            let Some(sequence) = DB::optional_number(row, "sequence")? else {
                continue;
            };
            entries.push((sequence, DB::string(row, "child_workflow_instance_id")?, DB::string(row, "child_workflow_run_id")?,
                DB::string(row, "parent_close_policy")?, DB::optional_string(row, "status")?, DB::optional_string(row, "closed_reason")?));
        }
        let mut handed_runs = HashSet::new();
        for (sequence, child_instance, child_run, policy, status, closed_reason) in entries {
            let current = match (status.as_deref(), closed_reason.as_deref()) {
                (None, _) => continue,
                (Some(_), Some("continued")) => match Self::current_child_run(tx, &child_run, &child_instance).await? {
                    Some(current) if open(&DB::string(&current, "status")?) => DB::string(&current, "id")?,
                    _ => continue,
                },
                (Some(status), _) if open(status) => child_run,
                _ => continue,
            };
            // One row per child run and supervising run, as the links'
            // unique key requires; a second entry for the same run would
            // apply the same policy to a child already closed by the first.
            if !handed_runs.insert(current.clone()) {
                continue;
            }
            Self::query("INSERT INTO workflow_links(id,link_type,sequence,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,child_workflow_run_id,is_primary_parent,parent_close_policy,created_at,updated_at) VALUES ($1,'child_supervision',$2,$3,$4,$5,$6,FALSE,$7,$8,$9)")
                .bind(id()).bind(sequence).bind(instance).bind(continued).bind(&child_instance).bind(&current)
                .bind(&policy).bind(DB::bind_time(at)).bind(DB::bind_time(at))
                .execute(&mut **tx).await?;
        }
        Ok(())
    }

    /// The children an earlier run of the chain handed to `run`, in transfer
    /// order, whose policy is not `abandon` (`ChildSupervision::transferredTo`).
    pub(super) async fn handed_over_children(tx: &mut Transaction<'_, DB>, run: &DB::Row) -> Result<Vec<HandedOverChild>> {
        let run_id = DB::string(run, "id")?;
        let instance = DB::string(run, "workflow_instance_id")?;
        let number = DB::number(run, "run_number")?;
        let rows = Self::query("SELECT sequence,child_workflow_instance_id,child_workflow_run_id,parent_close_policy FROM workflow_links WHERE parent_workflow_run_id=$1 AND link_type='child_supervision' AND parent_close_policy IN ('request_cancel','terminate','request_cancellation') ORDER BY created_at,id LIMIT 1001")
            .bind(&run_id).fetch_all(&mut **tx).await?;
        let mut children = Vec::with_capacity(rows.len());
        for row in rows {
            let Some(sequence) = DB::optional_number(&row, "sequence")? else {
                continue;
            };
            let child_instance = DB::string(&row, "child_workflow_instance_id")?;
            let origin = Self::original_child_link(tx, &instance, number, sequence, &child_instance).await?;
            children.push(HandedOverChild {
                link_id: origin.as_ref().map(|(link, _)| link.clone()),
                origin_run: origin.map(|(_, run)| run),
                sequence,
                policy: DB::string(&row, "parent_close_policy")?,
                child_instance,
                child_run: DB::string(&row, "child_workflow_run_id")?,
            });
        }
        Ok(children)
    }

    /// The original link of a child a run of `instance`'s chain supervises
    /// at `sequence`, and the run that started it: the first link of the
    /// latest earlier run (before `before`) that started that child instance
    /// at that sequence.
    async fn original_child_link(
        tx: &mut Transaction<'_, DB>,
        instance: &str,
        before: i64,
        sequence: i64,
        child_instance: &str,
    ) -> Result<Option<(String, String)>> {
        let link = Self::query("SELECT l.id,l.parent_workflow_run_id FROM workflow_links l JOIN workflow_runs r ON r.id=l.parent_workflow_run_id WHERE l.link_type='child_workflow' AND l.parent_workflow_instance_id=$1 AND l.sequence=$2 AND l.child_workflow_instance_id=$3 AND r.run_number<$4 ORDER BY r.run_number DESC,l.created_at,l.id LIMIT 1")
            .bind(instance).bind(sequence).bind(child_instance).bind(before)
            .fetch_optional(&mut **tx).await?;
        Ok(match link {
            Some(link) => Some((DB::string(&link, "id")?, DB::string(&link, "parent_workflow_run_id")?)),
            None => None,
        })
    }

    /// The supervision rows of the child the closed `parent` started at
    /// `sequence`: the rows of later runs of its chain, up to and including
    /// the next run that started that child instance at that sequence
    /// again, with whether each supervising run is still open.
    async fn supervisions_of(
        tx: &mut Transaction<'_, DB>,
        parent: &DB::Row,
        sequence: i64,
        child_instance: &str,
    ) -> Result<Vec<(String, bool)>> {
        let instance = DB::string(parent, "workflow_instance_id")?;
        let number = DB::number(parent, "run_number")?;
        let restarted = Self::query("SELECT r.run_number FROM workflow_links l JOIN workflow_runs r ON r.id=l.parent_workflow_run_id WHERE l.link_type='child_workflow' AND l.parent_workflow_instance_id=$1 AND l.sequence=$2 AND l.child_workflow_instance_id=$3 AND r.run_number>$4 ORDER BY r.run_number LIMIT 1")
            .bind(&instance).bind(sequence).bind(child_instance).bind(number)
            .fetch_optional(&mut **tx).await?;
        let restarted = match restarted {
            Some(row) => Some(DB::number(&row, "run_number")?),
            None => None,
        };
        let rows = Self::query("SELECT l.id,r.run_number,r.status FROM workflow_links l JOIN workflow_runs r ON r.id=l.parent_workflow_run_id WHERE l.link_type='child_supervision' AND l.parent_workflow_instance_id=$1 AND l.sequence=$2 AND l.child_workflow_instance_id=$3 AND r.run_number>$4 ORDER BY r.run_number,l.id")
            .bind(&instance).bind(sequence).bind(child_instance).bind(number)
            .fetch_all(&mut **tx).await?;
        let mut supervisions = Vec::with_capacity(rows.len());
        for row in rows {
            if restarted.is_some_and(|restarted| DB::number(&row, "run_number").is_ok_and(|number| number > restarted)) {
                continue;
            }
            supervisions.push((DB::string(&row, "id")?, open(&DB::string(&row, "status")?)));
        }
        Ok(supervisions)
    }

    /// `ChildSupervision::openSupervisorOfHandedOverChild`: whether a run of
    /// the closed parent's chain still supervises the child it started at
    /// `sequence`. Only then is a failed child retried after its parent
    /// continued as new; once the chain has closed, the failure is final.
    pub(super) async fn handed_over_child_supervised(
        tx: &mut Transaction<'_, DB>,
        parent: &DB::Row,
        sequence: i64,
        child_instance: &str,
    ) -> Result<bool> {
        if DB::optional_string(parent, "closed_reason")?.as_deref() != Some("continued") {
            return Ok(false);
        }
        Ok(Self::supervisions_of(tx, parent, sequence, child_instance).await?.iter().any(|(_, open)| *open))
    }

    /// `ChildSupervision::followRetry`: every supervision of a handed-over
    /// child now names its retry run, so the chain applies the policy to the
    /// run that is open.
    pub(super) async fn follow_child_retry(
        tx: &mut Transaction<'_, DB>,
        parent: &DB::Row,
        sequence: i64,
        child_instance: &str,
        retry_run: &str,
        at: DateTime<Utc>,
    ) -> Result<()> {
        for (row, _) in Self::supervisions_of(tx, parent, sequence, child_instance).await? {
            Self::query("UPDATE workflow_links SET child_workflow_run_id=$1,updated_at=$2 WHERE id=$3")
                .bind(retry_run).bind(DB::bind_time(at)).bind(&row)
                .execute(&mut **tx).await?;
        }
        Ok(())
    }
}
