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
//! Each transfer is a `dw_child_supervisions` row with the columns of Core's
//! `workflow_child_supervisions`, so no reader of `workflow_links` ever sees
//! it, including earlier native releases sharing the database.
use super::transaction::Transaction;
use super::{
    Result,
    backend::Backend,
    store::{Store, id},
};
use chrono::{DateTime, Utc};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

fn open(status: &str) -> bool {
    matches!(status, "pending" | "running" | "waiting")
}

/// A child an earlier run of the chain handed to the closing run, as the
/// enforcer applies its policy (`ParentClosePolicyEnforcer::targets`).
pub(super) struct HandedOverChild {
    /// The original child link (`workflow_link_id`).
    pub(super) link_id: String,
    /// The run that started the child, whose child call keeps the call.
    pub(super) origin_run: String,
    pub(super) sequence: Option<i64>,
    pub(super) policy: String,
    pub(super) child_instance: String,
    /// The child run the supervision names; the enforcer follows it to its
    /// current run.
    pub(super) child_run: String,
}

/// One child a run supervises, as `ChildSupervision::supervisedChildren`
/// lists it.
struct Supervised {
    link_id: String,
    parent_instance: String,
    parent_run: String,
    sequence: Option<i64>,
    child_instance: String,
    child_run: String,
    policy: String,
    status: Option<String>,
    closed_reason: Option<String>,
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
        namespace: &str,
        closing: &str,
        continued: &str,
        at: DateTime<Utc>,
    ) -> Result<()> {
        let mut children: Vec<Supervised> = Vec::new();
        // A child that continued as new while this run was open has one link
        // per child run; the first keeps the call, the latest names the run.
        let started = Self::query("SELECT l.id,l.sequence,l.parent_workflow_instance_id,l.parent_workflow_run_id,l.child_workflow_instance_id,l.child_workflow_run_id,l.parent_close_policy,r.status,r.closed_reason FROM workflow_links l LEFT JOIN workflow_runs r ON r.id=l.child_workflow_run_id WHERE l.parent_workflow_run_id=$1 AND l.link_type='child_workflow' ORDER BY l.created_at,l.id")
            .bind(closing).fetch_all(&mut **tx).await?;
        for link in &started {
            let sequence = DB::optional_number(link, "sequence")?;
            let child_instance = DB::string(link, "child_workflow_instance_id")?;
            let child_run = DB::string(link, "child_workflow_run_id")?;
            let status = DB::optional_string(link, "status")?;
            let closed_reason = DB::optional_string(link, "closed_reason")?;
            match children.iter_mut().find(|child| child.sequence == sequence && child.child_instance == child_instance) {
                Some(child) => {
                    child.child_run = child_run;
                    child.status = status;
                    child.closed_reason = closed_reason;
                }
                None => children.push(Supervised {
                    link_id: DB::string(link, "id")?,
                    parent_instance: DB::string(link, "parent_workflow_instance_id")?,
                    parent_run: DB::string(link, "parent_workflow_run_id")?,
                    sequence,
                    child_instance,
                    child_run,
                    policy: DB::string(link, "parent_close_policy")?,
                    status,
                    closed_reason,
                }),
            }
        }
        let handed = Self::query("SELECT s.workflow_link_id,s.parent_workflow_instance_id,s.parent_workflow_run_id,s.sequence,s.child_workflow_instance_id,s.child_workflow_run_id,s.parent_close_policy,r.status,r.closed_reason FROM dw_child_supervisions s LEFT JOIN workflow_runs r ON r.id=s.child_workflow_run_id WHERE s.supervising_workflow_run_id=$1 ORDER BY s.created_at,s.id")
            .bind(closing).fetch_all(&mut **tx).await?;
        for row in &handed {
            children.push(Supervised {
                link_id: DB::string(row, "workflow_link_id")?,
                parent_instance: DB::string(row, "parent_workflow_instance_id")?,
                parent_run: DB::string(row, "parent_workflow_run_id")?,
                sequence: DB::optional_number(row, "sequence")?,
                child_instance: DB::string(row, "child_workflow_instance_id")?,
                child_run: DB::string(row, "child_workflow_run_id")?,
                policy: DB::string(row, "parent_close_policy")?,
                status: DB::optional_string(row, "status")?,
                closed_reason: DB::optional_string(row, "closed_reason")?,
            });
        }
        for child in children {
            let current = match (child.status.as_deref(), child.closed_reason.as_deref()) {
                (None, _) => continue,
                (Some(_), Some("continued")) => match Self::current_child_run(tx, &child.child_run, &child.child_instance).await? {
                    Some(current) if open(&DB::string(&current, "status")?) => DB::string(&current, "id")?,
                    _ => continue,
                },
                (Some(status), _) if open(status) => child.child_run,
                _ => continue,
            };
            Self::query("INSERT INTO dw_child_supervisions(id,namespace,supervising_workflow_run_id,transferred_from_workflow_run_id,workflow_link_id,parent_workflow_instance_id,parent_workflow_run_id,sequence,child_workflow_instance_id,child_workflow_run_id,parent_close_policy,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13)")
                .bind(id()).bind(namespace).bind(continued).bind(closing).bind(&child.link_id)
                .bind(&child.parent_instance).bind(&child.parent_run).bind(DB::optional_integer(child.sequence))
                .bind(&child.child_instance).bind(&current).bind(&child.policy)
                .bind(DB::bind_time(at)).bind(DB::bind_time(at))
                .execute(&mut **tx).await?;
        }
        Ok(())
    }

    /// The children an earlier run of the chain handed to `run`, in transfer
    /// order, whose policy is not `abandon` (`ChildSupervision::transferredTo`).
    pub(super) async fn handed_over_children(tx: &mut Transaction<'_, DB>, run: &str) -> Result<Vec<HandedOverChild>> {
        let rows = Self::query("SELECT workflow_link_id,parent_workflow_run_id,sequence,child_workflow_instance_id,child_workflow_run_id,parent_close_policy FROM dw_child_supervisions WHERE supervising_workflow_run_id=$1 AND parent_close_policy IN ('request_cancel','terminate','request_cancellation') ORDER BY created_at,id LIMIT 1001")
            .bind(run).fetch_all(&mut **tx).await?;
        let mut children = Vec::with_capacity(rows.len());
        for row in rows {
            children.push(HandedOverChild {
                link_id: DB::string(&row, "workflow_link_id")?,
                origin_run: DB::string(&row, "parent_workflow_run_id")?,
                sequence: DB::optional_number(&row, "sequence")?,
                policy: DB::string(&row, "parent_close_policy")?,
                child_instance: DB::string(&row, "child_workflow_instance_id")?,
                child_run: DB::string(&row, "child_workflow_run_id")?,
            });
        }
        Ok(children)
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
        let open_supervisors = Self::scalar("SELECT COUNT(*) FROM dw_child_supervisions s JOIN workflow_runs r ON r.id=s.supervising_workflow_run_id WHERE s.parent_workflow_run_id=$1 AND s.sequence=$2 AND s.child_workflow_instance_id=$3 AND r.status IN ('pending','running','waiting')")
            .bind(DB::string(parent, "id")?).bind(sequence).bind(child_instance)
            .fetch_one(&mut **tx).await?;
        Ok(open_supervisors > 0)
    }

    /// `ChildSupervision::followRetry`: every supervision of a handed-over
    /// child now names its retry run, so the chain applies the policy to the
    /// run that is open.
    pub(super) async fn follow_child_retry(
        tx: &mut Transaction<'_, DB>,
        parent_run: &str,
        sequence: i64,
        child_instance: &str,
        retry_run: &str,
        at: DateTime<Utc>,
    ) -> Result<()> {
        Self::query("UPDATE dw_child_supervisions SET child_workflow_run_id=$1,updated_at=$2 WHERE parent_workflow_run_id=$3 AND sequence=$4 AND child_workflow_instance_id=$5")
            .bind(retry_run).bind(DB::bind_time(at)).bind(parent_run).bind(sequence).bind(child_instance)
            .execute(&mut **tx).await?;
        Ok(())
    }
}
