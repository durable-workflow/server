//! A read-only plan of a proposed namespace retention policy (Server #522).
//!
//! `POST /api/namespaces/{namespace}/retention-plan` compares the closed runs
//! the namespace's current policy makes eligible for pruning with those a
//! proposed policy would, before anyone applies it. Updating the namespace
//! still activates its policy at once; the plan changes nothing about that.
//!
//! The plan reads one snapshot and makes no write: no policy change, no row
//! marked, no payload object fetched, nothing pruned. On PostgreSQL and MySQL
//! the snapshot is a `READ ONLY` transaction, and it is always rolled back.
//!
//! It is a point-in-time scan, not an immutable deletion set. Between the
//! plan and any prune, runs close and age past the cutoff, are archived, gain
//! or lose holds, and the policy itself can change. `policy_revision` binds
//! the plan to the exact current policy, and `plan_revision` to that policy
//! and the exact proposal.
//!
//! `POST /api/namespaces/{namespace}/retention-plan/apply` is the guarded
//! apply. It takes the proposal with both revisions, and in one write
//! transaction refuses a stale policy revision, a proposal that differs from
//! the planned one, and a retroactive proposal (one that makes closed runs
//! eligible that the current policy keeps) without
//! `confirm_retroactive_pruning`. `PUT /api/namespaces/{namespace}` keeps its
//! immediate activation contract unchanged.
//!
//! Every count is bounded. A count that reaches its bound is reported with
//! `count: null`, its `lower_bound` and `complete: false`, never as exact.
use super::backend::Backend;
use super::laravel_validation::{Rule, failure_messages, rule_errors};
use super::namespaces::{requested_retention, resolved_retention};
use super::operator_metrics::{ABANDONED_ACTIVITY_EVENTS, abandoned_activity_held, time};
use super::retention::{self, Policy};
use super::retention_payloads::native_reference;
use super::store::now;
use super::transaction::Transaction;
use super::{Result, RuntimeError};
use axum::http::StatusCode;
use chrono::{DateTime, SecondsFormat, Utc};
use serde_json::{Map, Value, json};
use sha2::{Digest, Sha256};

pub(super) const SCHEMA: &str = "durable-workflow.v2.retention-plan.v1";
pub(super) const APPLY_SCHEMA: &str = "durable-workflow.v2.retention-apply.v1";
/// The first line of the material `policy_revision` digests.
const REVISION_SCHEMA: &str = "durable-workflow.v2.retention-policy-revision.v1";
/// The first line of the material `plan_revision` digests.
const PLAN_REVISION_SCHEMA: &str = "durable-workflow.v2.retention-plan-revision.v1";
const DEFAULT_SCAN_LIMIT: i64 = 1000;
const MAX_SCAN_LIMIT: i64 = 10_000;
const DEFAULT_HOLD_SCAN_LIMIT: i64 = 100;
const MAX_HOLD_SCAN_LIMIT: i64 = 1000;
/// The most example runs one category lists.
pub(super) const EXAMPLE_LIMIT: usize = 5;
const SCOPE: &str = "A point-in-time scan of one read snapshot, not an immutable deletion set. Runs can close, age past the cutoff, be archived or gain and lose holds, and the policy can change, before any prune. Holds are checked again when a run is pruned.";
const APPLY_SCOPE: &str = "The policy is active from applied_at. Each later retention pass prunes the closed, unarchived runs past the cutoff as it stands at that pass, which can include runs that closed or aged after the plan, and skips runs archived or held at that time. The plan's counts were a point-in-time scan, not the set that will be deleted.";

/// Closed runs ordinary retention can prune: the `RETENTION_CANDIDATES`
/// predicate without its cutoff.
macro_rules! unarchived {
    () => {
        "namespace=$1 AND status_bucket IN ('completed','failed') AND closed_at IS NOT NULL AND archived_at IS NULL"
    };
}
macro_rules! archived {
    () => {
        "namespace=$1 AND status_bucket IN ('completed','failed') AND closed_at IS NOT NULL AND archived_at IS NOT NULL"
    };
}

/// One predicate's statements for each closed-at window, the limit bound
/// last.
struct Windowed {
    all: &'static str,
    before: &'static str,
    from: &'static str,
    between: &'static str,
}

macro_rules! windowed {
    ($head:expr, $predicate:expr, $order:expr, $end:expr) => {
        Windowed {
            all: concat!($head, $predicate, $order, " LIMIT $2", $end),
            before: concat!($head, $predicate, " AND closed_at<$2", $order, " LIMIT $3", $end),
            from: concat!($head, $predicate, " AND closed_at>=$2", $order, " LIMIT $3", $end),
            between: concat!($head, $predicate, " AND closed_at>=$2 AND closed_at<$3", $order, " LIMIT $4", $end),
        }
    };
}

macro_rules! counted {
    ($predicate:expr) => {
        windowed!("SELECT COUNT(*) FROM (SELECT id FROM workflow_run_summaries WHERE ", $predicate, "", ") bounded")
    };
}

const UNARCHIVED_COUNT: Windowed = counted!(unarchived!());
const ARCHIVED_COUNT: Windowed = counted!(archived!());
const UNARCHIVED_RUNS: Windowed = windowed!(
    "SELECT id,workflow_instance_id,workflow_type,status,closed_at FROM workflow_run_summaries WHERE ",
    unarchived!(),
    " ORDER BY closed_at,id",
    ""
);
/// Runs retention never prunes while they stay open.
const NOT_TERMINAL_COUNT: &str = "SELECT COUNT(*) FROM (SELECT id FROM workflow_run_summaries WHERE namespace=$1 AND (status_bucket NOT IN ('completed','failed') OR closed_at IS NULL) LIMIT $2) bounded";

/// Closed runs by when they closed: a run is in the window when its
/// `closed_at` is at or after the lower bound and before the upper one.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum Window {
    Empty,
    All,
    Before(DateTime<Utc>),
    From(DateTime<Utc>),
    Between(DateTime<Utc>, DateTime<Utc>),
}

impl Window {
    fn statement(self, sql: &Windowed) -> Option<(&'static str, Vec<DateTime<Utc>>)> {
        match self {
            Window::Empty => None,
            Window::All => Some((sql.all, Vec::new())),
            Window::Before(at) => Some((sql.before, vec![at])),
            Window::From(at) => Some((sql.from, vec![at])),
            Window::Between(from, to) => Some((sql.between, vec![from, to])),
        }
    }
}

/// How the closed unarchived runs fall under the current and the proposed
/// cutoff (`None` for `forever`): eligible under the proposal only, under the
/// current policy only, under both, and under neither.
fn changes(current: Option<DateTime<Utc>>, proposed: Option<DateTime<Utc>>) -> [Window; 4] {
    match (current, proposed) {
        (None, None) => [Window::Empty, Window::Empty, Window::Empty, Window::All],
        (None, Some(proposed)) => [Window::Before(proposed), Window::Empty, Window::Empty, Window::From(proposed)],
        (Some(current), None) => [Window::Empty, Window::Before(current), Window::Empty, Window::From(current)],
        (Some(current), Some(proposed)) => {
            let newly = if proposed > current { Window::Between(current, proposed) } else { Window::Empty };
            let no_longer = if proposed < current { Window::Between(proposed, current) } else { Window::Empty };
            [newly, no_longer, Window::Before(current.min(proposed)), Window::From(current.max(proposed))]
        }
    }
}

/// A bounded count: exact when the scan found no more than `limit`,
/// otherwise only a lower bound.
fn tally(found: i64, limit: i64) -> Map<String, Value> {
    let mut tally = Map::new();
    let complete = found <= limit;
    tally.insert("count".into(), if complete { json!(found) } else { Value::Null });
    tally.insert("lower_bound".into(), json!(found.min(limit)));
    tally.insert("complete".into(), json!(complete));
    tally
}

/// `policy_revision`: a SHA-256 over the namespace, its stored retention
/// mode and days, the days the policy takes effect with (a bounded namespace
/// without days uses the server default) and the row's `updated_at`. Any
/// change to the policy changes it. So does any other namespace update that
/// moves `updated_at`, which can make a plan stale without changing its
/// policy; a guarded apply then asks for a fresh plan.
pub(super) fn policy_revision(
    namespace: &str,
    mode: Option<&str>,
    days: Option<i64>,
    effective_days: Option<i64>,
    updated_at: Option<DateTime<Utc>>,
) -> String {
    let field = |value: Option<String>| value.unwrap_or_else(|| "null".into());
    let material = [
        REVISION_SCHEMA.to_owned(),
        namespace.to_owned(),
        field(mode.map(str::to_owned)),
        field(days.map(|days| days.to_string())),
        field(effective_days.map(|days| days.to_string())),
        field(updated_at.map(|at| at.to_rfc3339_opts(SecondsFormat::Micros, true))),
    ]
    .join("\n");
    let digest: String = Sha256::digest(material.as_bytes()).iter().map(|byte| format!("{byte:02x}")).collect();
    format!("sha256:{digest}")
}

/// `plan_revision`: a SHA-256 over the current policy's `policy_revision`
/// and the proposed mode and days, as stored once applied. A guarded apply
/// recomputes it from the policy it is asked to apply, so the policy applied
/// is exactly the one planned, over exactly the policy planned against.
pub(super) fn plan_revision(policy_revision: &str, mode: &str, days: Option<i64>) -> String {
    let material = [
        PLAN_REVISION_SCHEMA.to_owned(),
        policy_revision.to_owned(),
        mode.to_owned(),
        days.map_or_else(|| "null".into(), |days| days.to_string()),
    ]
    .join("\n");
    let digest: String = Sha256::digest(material.as_bytes()).iter().map(|byte| format!("{byte:02x}")).collect();
    format!("sha256:{digest}")
}

/// Whether the proposal makes closed runs eligible that the current policy
/// keeps: a bounded policy replacing `forever`, or fewer days.
fn retroactive(current: &Policy, proposed: &Policy) -> bool {
    changes(current.cutoff, proposed.cutoff)[0] != Window::Empty
}

fn no_messages(_: &str, _: Rule) -> Option<&'static str> {
    None
}

/// The plan's request: the proposed policy, validated as a namespace update
/// validates it over the stored one, and the scan bounds.
pub(crate) struct PlanRequest {
    retention_mode: Option<String>,
    retention_days: Option<Option<i64>>,
    scan_limit: i64,
    hold_scan_limit: i64,
}

pub(super) fn plan_request(body: &Value) -> Result<PlanRequest> {
    let (mode, days) = requested_retention(body)?;
    let normalized = super::start_request::normalized(body.clone());
    let rules: &[(&str, &[Rule])] = &[
        ("scan_limit", &[Rule::Nullable, Rule::Integer, Rule::Min(1), Rule::Max(MAX_SCAN_LIMIT)]),
        ("hold_scan_limit", &[Rule::Nullable, Rule::Integer, Rule::Min(1), Rule::Max(MAX_HOLD_SCAN_LIMIT)]),
    ];
    if let Some(error) = failure_messages(rule_errors(&normalized, rules, no_messages)) {
        return Err(error);
    }
    let limit = |field: &str, default: i64| super::laravel_validation::integer(&normalized[field]).unwrap_or(default);
    Ok(PlanRequest {
        retention_mode: mode.map(str::to_owned),
        retention_days: days,
        scan_limit: limit("scan_limit", DEFAULT_SCAN_LIMIT),
        hold_scan_limit: limit("hold_scan_limit", DEFAULT_HOLD_SCAN_LIMIT),
    })
}

/// The guarded apply's request: the proposed policy, validated as the plan
/// validates it, the two revisions the plan returned, and the confirmation a
/// retroactive proposal needs.
pub(crate) struct ApplyRequest {
    retention_mode: Option<String>,
    retention_days: Option<Option<i64>>,
    policy_revision: String,
    plan_revision: String,
    confirm_retroactive_pruning: bool,
}

pub(super) fn apply_request(body: &Value) -> Result<ApplyRequest> {
    let (mode, days) = requested_retention(body)?;
    let normalized = super::start_request::normalized(body.clone());
    let rules: &[(&str, &[Rule])] = &[
        ("policy_revision", &[Rule::Required, Rule::String, Rule::Max(100)]),
        ("plan_revision", &[Rule::Required, Rule::String, Rule::Max(100)]),
        ("confirm_retroactive_pruning", &[Rule::Nullable, Rule::Boolean]),
    ];
    if let Some(error) = failure_messages(rule_errors(&normalized, rules, no_messages)) {
        return Err(error);
    }
    let text = |field: &str| normalized[field].as_str().unwrap_or_default().to_owned();
    let confirmed = &normalized["confirm_retroactive_pruning"];
    Ok(ApplyRequest {
        retention_mode: mode.map(str::to_owned),
        retention_days: days,
        policy_revision: text("policy_revision"),
        plan_revision: text("plan_revision"),
        confirm_retroactive_pruning: *confirmed == json!(true) || *confirmed == json!(1) || *confirmed == json!("1"),
    })
}

/// What `compare` reads and derives for one namespace and proposal.
struct Comparison {
    mode: String,
    days: Option<i64>,
    updated_at: Option<DateTime<Utc>>,
    at: DateTime<Utc>,
    current: Policy,
    proposed: Policy,
    revision: String,
}

fn apply_refusal(status: StatusCode, reason: &str, name: &str, mut extra: Value, message: &str) -> RuntimeError {
    extra["reason"] = json!(reason);
    extra["namespace"] = json!(name);
    extra["message"] = json!(message);
    RuntimeError::Protocol { status, response: extra }
}

/// The policy fields of a plan, without the namespace.
fn policy_fields(policy: &Policy) -> Map<String, Value> {
    let mut fields = policy.fields("");
    fields.remove("namespace");
    fields
}

/// One closed run as an example identity.
fn example<DB: Backend>(row: &DB::Row) -> Result<Value> {
    Ok(json!({
        "run_id": DB::string(row, "id")?,
        "workflow_id": DB::string(row, "workflow_instance_id")?,
        "workflow_type": DB::optional_string(row, "workflow_type")?,
        "status": DB::string(row, "status")?,
        "closed_at": time(DB::optional_instant(row, "closed_at")?),
    }))
}

/// Why a prune would keep a run the proposal makes eligible, in the order
/// the enforcer checks them.
#[derive(Clone, Copy, PartialEq, Eq)]
enum Hold {
    DetachedActivity,
    PayloadCleanupUnavailable,
    BackupHold,
}

const HOLD_KEYS: [(Hold, &str); 3] = [
    (Hold::DetachedActivity, "detached_activity_hold"),
    (Hold::PayloadCleanupUnavailable, "external_payload_cleanup_unavailable"),
    (Hold::BackupHold, "backup_hold"),
];

store_methods! {
    /// `POST /api/namespaces/{namespace}/retention-plan`.
    pub(super) async fn retention_plan(&self, name: &str, request: PlanRequest) -> Result<Value> {
        let name = name.to_ascii_lowercase();
        let mut tx = self.begin_read().await?;
        let result = self.plan_in_snapshot(&mut tx, &name, &request).await;
        tx.rollback().await?;
        result
    }

    /// `POST /api/namespaces/{namespace}/retention-plan/apply`: applies the
    /// planned policy in one write transaction, which every namespace update
    /// also takes, so no policy change can land between the checks and the
    /// update.
    pub(super) async fn apply_retention_plan(&self, name: &str, request: ApplyRequest) -> Result<Value> {
        let name = name.to_ascii_lowercase();
        let mut tx = self.begin().await?;
        let Comparison { mode, days, updated_at, at, current, proposed, revision } =
            Self::compare(&mut tx, &name, request.retention_mode.as_deref(), request.retention_days).await?;
        if revision != request.policy_revision {
            return Err(apply_refusal(StatusCode::CONFLICT, "retention_policy_revision_stale", &name,
                json!({"policy_revision": revision}),
                "The namespace changed after the plan. Request a new plan and apply its revisions."));
        }
        if plan_revision(&revision, &mode, days) != request.plan_revision {
            return Err(apply_refusal(StatusCode::CONFLICT, "retention_plan_revision_mismatch", &name, json!({}),
                "The policy to apply is not the policy that was planned. Request a plan for this policy."));
        }
        let retroactive = retroactive(&current, &proposed);
        if retroactive && !request.confirm_retroactive_pruning {
            return Err(apply_refusal(StatusCode::UNPROCESSABLE_ENTITY, "retention_pruning_confirmation_required", &name,
                json!({"retroactive": true}),
                "This policy makes closed runs eligible for pruning that the current policy keeps, and pruning is irreversible. Set confirm_retroactive_pruning to true to apply it."));
        }
        Self::query("UPDATE workflow_namespaces SET retention_mode=$1,retention_days=NULLIF($2,0),updated_at=$3 WHERE name=$4")
            .bind(&mode).bind(days.unwrap_or_default()).bind(DB::bind_time(at)).bind(&name)
            .execute(&mut *tx).await?;
        let applied = Self::query("SELECT retention_mode,retention_days,updated_at FROM workflow_namespaces WHERE name=$1")
            .bind(&name)
            .fetch_one(&mut *tx)
            .await?;
        let applied_mode = DB::string(&applied, "retention_mode")?;
        let applied_days = DB::optional_number(&applied, "retention_days")?;
        let applied_at = DB::optional_instant(&applied, "updated_at")?;
        let new_revision = policy_revision(&name, Some(&applied_mode), applied_days, proposed.days, applied_at);
        tx.commit().await?;
        let mut previous_policy = policy_fields(&current);
        previous_policy.insert("updated_at".into(), time(updated_at));
        let mut policy = policy_fields(&proposed);
        policy.insert("updated_at".into(), time(applied_at));
        Ok(json!({
            "schema": APPLY_SCHEMA,
            "namespace": name,
            "applied_at": time(applied_at),
            "retroactive": retroactive,
            "confirmed_retroactive_pruning": retroactive && request.confirm_retroactive_pruning,
            "previous_policy_revision": revision,
            "previous_policy": previous_policy,
            "policy_revision": new_revision,
            "policy": policy,
            "scope": APPLY_SCOPE,
        }))
    }

    /// The namespace's current policy and the proposal over it, as the plan
    /// and the apply both compare them, with the current `policy_revision`.
    async fn compare(
        tx: &mut Transaction<'_, DB>,
        name: &str,
        requested_mode: Option<&str>,
        requested_days: Option<Option<i64>>,
    ) -> Result<Comparison> {
        let row = Self::query("SELECT retention_mode,retention_days,updated_at FROM workflow_namespaces WHERE name=$1")
            .bind(name)
            .fetch_optional(&mut **tx)
            .await?
            .ok_or_else(|| RuntimeError::Protocol {
                status: StatusCode::NOT_FOUND,
                response: json!({"reason":"namespace_not_found","namespace":name,"message":format!("Namespace [{name}] not found.")}),
            })?;
        let stored_mode = DB::string(&row, "retention_mode")?;
        let stored_days = DB::optional_number(&row, "retention_days")?;
        let updated_at = DB::optional_instant(&row, "updated_at")?;
        let (mode, days) = resolved_retention((requested_mode, requested_days), stored_mode.clone(), stored_days)?;
        let at = now();
        let current = Policy::new(Some(&stored_mode), stored_days, at);
        let proposed = Policy::new(Some(&mode), days, at);
        let revision = policy_revision(name, Some(&stored_mode), stored_days, current.days, updated_at);
        Ok(Comparison { mode, days, updated_at, at, current, proposed, revision })
    }

    async fn plan_in_snapshot(&self, tx: &mut Transaction<'_, DB>, name: &str, request: &PlanRequest) -> Result<Value> {
        let Comparison { mode, days, updated_at, at, current, proposed, revision } =
            Self::compare(tx, name, request.retention_mode.as_deref(), request.retention_days).await?;
        let planned = plan_revision(&revision, &mode, days);
        let (limit, hold_limit) = (request.scan_limit, request.hold_scan_limit);
        let mut incomplete = Vec::new();

        let mut changes_report = Map::new();
        let keys = ["newly_eligible", "no_longer_eligible", "unchanged_eligible", "unchanged_retained"];
        for (key, window) in keys.into_iter().zip(changes(current.cutoff, proposed.cutoff)) {
            let mut entry = tally(Self::window_count(tx, &UNARCHIVED_COUNT, name, window, limit).await?, limit);
            entry.insert("examples".into(), json!(Self::window_examples(tx, name, window, EXAMPLE_LIMIT as i64).await?));
            if entry["complete"] == false {
                incomplete.push(json!({"field": format!("changes.{key}"), "reason": "scan_limit_reached"}));
            }
            changes_report.insert(key.into(), Value::Object(entry));
        }

        // Exclusions are about the proposal. `forever` keeps every closed run,
        // so then every archived and unarchived closed run counts, whatever
        // its age; a bounded proposal only counts archived runs past its
        // cutoff. Open runs are never pruned under any policy.
        let aged = proposed.cutoff.map_or(Window::All, Window::Before);
        let forever = if proposed.forever { Window::All } else { Window::Empty };
        let mut exclusions = Map::new();
        let counts = [
            ("archived", Self::window_count(tx, &ARCHIVED_COUNT, name, aged, limit).await?),
            ("forever", Self::window_count(tx, &UNARCHIVED_COUNT, name, forever, limit).await?),
            ("not_terminal", Self::scalar(NOT_TERMINAL_COUNT).bind(name).bind(limit + 1).fetch_one(&mut **tx).await?),
        ];
        for (key, found) in counts {
            let entry = tally(found, limit);
            if entry["complete"] == false {
                incomplete.push(json!({"field": format!("exclusions.{key}"), "reason": "scan_limit_reached"}));
            }
            exclusions.insert(key.into(), Value::Object(entry));
        }

        // Holds are read run by run, so only the oldest `hold_scan_limit`
        // runs the proposal makes eligible are inspected.
        let inspected = match proposed.cutoff {
            Some(cutoff) => Self::window_rows(tx, name, Window::Before(cutoff), hold_limit + 1).await?,
            None => Vec::new(),
        };
        let inspection_complete = inspected.len() as i64 <= hold_limit;
        let backup_hold_active = Self::read_backup_hold(tx).await?.is_some_and(|hold| hold.active());
        let mut held: Vec<(Hold, Vec<Value>, i64)> = HOLD_KEYS.iter().map(|(hold, _)| (*hold, Vec::new(), 0)).collect();
        for row in inspected.iter().take(hold_limit as usize) {
            let run_id = DB::string(row, "id")?;
            let Some(hold) = Self::plan_hold(tx, &run_id, backup_hold_active).await? else {
                continue;
            };
            let (_, examples, count) = held.iter_mut().find(|(kind, _, _)| *kind == hold).expect("every hold has a key");
            *count += 1;
            if examples.len() < EXAMPLE_LIMIT {
                examples.push(example::<DB>(row)?);
            }
        }
        for ((_, key), (_, examples, count)) in HOLD_KEYS.iter().zip(held) {
            let mut entry = Map::new();
            entry.insert("count".into(), if inspection_complete { json!(count) } else { Value::Null });
            entry.insert("lower_bound".into(), json!(count));
            entry.insert("complete".into(), json!(inspection_complete));
            entry.insert("examples".into(), json!(examples));
            if !inspection_complete {
                incomplete.push(json!({"field": format!("exclusions.{key}"), "reason": "hold_scan_limit_reached"}));
            }
            exclusions.insert((*key).into(), Value::Object(entry));
        }

        let mut current_policy = policy_fields(&current);
        current_policy.insert("updated_at".into(), time(updated_at));
        Ok(json!({
            "schema": SCHEMA,
            "namespace": name,
            "evaluated_at": time(Some(at)),
            "read_only": true,
            "scope": SCOPE,
            "policy_revision": revision,
            "plan_revision": planned,
            "retroactive": retroactive(&current, &proposed),
            "current_policy": current_policy,
            "proposed_policy": policy_fields(&proposed),
            "scan_limit": limit,
            "hold_scan_limit": hold_limit,
            "example_limit": EXAMPLE_LIMIT,
            "complete": incomplete.is_empty(),
            "incomplete": incomplete,
            "changes": changes_report,
            "exclusions": exclusions,
            "hold_inspection": {
                "scope": "eligible_under_proposed_policy",
                "inspected": inspected.len().min(hold_limit as usize),
                "complete": inspection_complete,
                "backup_hold_active": backup_hold_active,
                // Resolving a payload's storage binding can reach the provider,
                // so the plan leaves it to the prune.
                "not_evaluated": ["external_payload_storage_binding_unavailable"],
            },
        }))
    }

    /// How many runs `window` holds, counting at most `limit + 1`.
    async fn window_count(tx: &mut Transaction<'_, DB>, sql: &Windowed, name: &str, window: Window, limit: i64) -> Result<i64> {
        let Some((statement, times)) = window.statement(sql) else {
            return Ok(0);
        };
        let mut query = Self::scalar(statement).bind(name);
        for at in times {
            query = query.bind(DB::bind_time(at));
        }
        Ok(query.bind(limit + 1).fetch_one(&mut **tx).await?)
    }

    /// The oldest `limit` unarchived closed runs in `window`.
    async fn window_rows(tx: &mut Transaction<'_, DB>, name: &str, window: Window, limit: i64) -> Result<Vec<DB::Row>> {
        let Some((statement, times)) = window.statement(&UNARCHIVED_RUNS) else {
            return Ok(Vec::new());
        };
        let mut query = Self::query(statement).bind(name);
        for at in times {
            query = query.bind(DB::bind_time(at));
        }
        Ok(query.bind(limit).fetch_all(&mut **tx).await?)
    }

    async fn window_examples(tx: &mut Transaction<'_, DB>, name: &str, window: Window, limit: i64) -> Result<Vec<Value>> {
        Self::window_rows(tx, name, window, limit).await?.iter().map(example::<DB>).collect()
    }

    /// The first hold the enforcer would find on the run now, from reads
    /// alone: an open abandoned activity, a payload reference native cannot
    /// settle, or, while an online backup holds reclamation, a native
    /// reference no retained run still holds, whose object the prune would
    /// have to delete.
    async fn plan_hold(tx: &mut Transaction<'_, DB>, run_id: &str, backup_hold_active: bool) -> Result<Option<Hold>> {
        let events = Self::query(ABANDONED_ACTIVITY_EVENTS).bind(run_id).fetch_all(&mut **tx).await?;
        if abandoned_activity_held::<DB>(&events)? {
            return Ok(Some(Hold::DetachedActivity));
        }
        for (sql, binds, _) in retention::HOLDS {
            let mut query = Self::scalar(sql);
            for _ in 0..*binds {
                query = query.bind(run_id);
            }
            if query.fetch_one(&mut **tx).await? > 0 {
                return Ok(Some(Hold::PayloadCleanupUnavailable));
            }
        }
        if Self::scalar(DB::RUN_STREAM_REFERENCES).bind(run_id).bind(run_id).fetch_one(&mut **tx).await? > 0 {
            return Ok(Some(Hold::PayloadCleanupUnavailable));
        }
        let mut query = Self::query(retention::REFERENCE_VALUES);
        for _ in 0..retention::REFERENCE_VALUE_BINDS {
            query = query.bind(run_id);
        }
        let mut references = Vec::new();
        for row in query.fetch_all(&mut **tx).await? {
            match DB::optional_string(&row, "value")?.as_deref().and_then(native_reference) {
                Some(reference) => references.push(reference),
                None => return Ok(Some(Hold::PayloadCleanupUnavailable)),
            }
        }
        if backup_hold_active {
            for reference in references {
                let uri = Self::query("SELECT storage_uri FROM runtime_external_payloads WHERE id=$1")
                    .bind(&reference.reference_id)
                    .fetch_optional(&mut **tx)
                    .await?
                    .map(|row| DB::string(&row, "storage_uri"))
                    .transpose()?;
                if !Self::payload_retained(tx, run_id, &reference, uri.as_deref()).await? {
                    return Ok(Some(Hold::BackupHold));
                }
            }
        }
        Ok(None)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn at(text: &str) -> DateTime<Utc> {
        DateTime::parse_from_rfc3339(text).unwrap().with_timezone(&Utc)
    }

    #[test]
    fn windows_split_closed_runs_between_the_two_cutoffs() {
        let (older, newer) = (at("2026-10-01T00:00:00Z"), at("2026-11-01T00:00:00Z"));
        use Window::*;
        // Shortening moves the cutoff later: runs between the two become eligible.
        assert_eq!(changes(Some(older), Some(newer)), [Between(older, newer), Empty, Before(older), From(newer)]);
        // Lengthening moves it earlier: runs between the two are kept again.
        assert_eq!(changes(Some(newer), Some(older)), [Empty, Between(older, newer), Before(older), From(newer)]);
        assert_eq!(changes(Some(older), Some(older)), [Empty, Empty, Before(older), From(older)]);
        assert_eq!(changes(None, Some(older)), [Before(older), Empty, Empty, From(older)]);
        assert_eq!(changes(Some(older), None), [Empty, Before(older), Empty, From(older)]);
        assert_eq!(changes(None, None), [Empty, Empty, Empty, All]);
    }

    #[test]
    fn a_count_at_its_bound_is_only_a_lower_bound() {
        assert_eq!(Value::Object(tally(3, 3)), json!({"count": 3, "lower_bound": 3, "complete": true}));
        assert_eq!(Value::Object(tally(4, 3)), json!({"count": null, "lower_bound": 3, "complete": false}));
    }

    #[test]
    fn windowed_statements_bind_in_order() {
        let placeholders = |sql: &str| -> Vec<usize> {
            sql.split('$').skip(1).map(|rest| rest.chars().take_while(char::is_ascii_digit).collect::<String>().parse().unwrap()).collect()
        };
        for sql in [&UNARCHIVED_COUNT, &ARCHIVED_COUNT, &UNARCHIVED_RUNS] {
            assert_eq!(placeholders(sql.all), [1, 2]);
            assert_eq!(placeholders(sql.before), [1, 2, 3]);
            assert_eq!(placeholders(sql.from), [1, 2, 3]);
            assert_eq!(placeholders(sql.between), [1, 2, 3, 4]);
        }
        assert_eq!(placeholders(NOT_TERMINAL_COUNT), [1, 2]);
    }

    #[test]
    fn the_plan_revision_binds_the_current_revision_and_the_proposal() {
        let base = plan_revision("sha256:a", "bounded", Some(7));
        assert!(base.starts_with("sha256:") && base.len() == 71, "{base}");
        assert_eq!(base, plan_revision("sha256:a", "bounded", Some(7)));
        for other in [plan_revision("sha256:b", "bounded", Some(7)), plan_revision("sha256:a", "bounded", Some(8)),
            plan_revision("sha256:a", "forever", None)] {
            assert_ne!(base, other);
        }
    }

    #[test]
    fn only_a_policy_that_prunes_more_is_retroactive() {
        let now = at("2026-11-25T10:00:00Z");
        let policy = |mode: &str, days: Option<i64>| Policy::new(Some(mode), days, now);
        assert!(retroactive(&policy("bounded", Some(30)), &policy("bounded", Some(7))));
        assert!(retroactive(&policy("forever", None), &policy("bounded", Some(365))));
        assert!(!retroactive(&policy("bounded", Some(7)), &policy("bounded", Some(30))));
        assert!(!retroactive(&policy("bounded", Some(30)), &policy("bounded", Some(30))));
        assert!(!retroactive(&policy("bounded", Some(30)), &policy("forever", None)));
        assert!(!retroactive(&policy("forever", None), &policy("forever", None)));
    }

    #[test]
    fn the_revision_changes_with_every_policy_field() {
        let updated = Some(at("2026-11-25T10:00:00.123456Z"));
        let base = policy_revision("default", Some("bounded"), Some(30), Some(30), updated);
        assert!(base.starts_with("sha256:") && base.len() == 71, "{base}");
        assert_eq!(base, policy_revision("default", Some("bounded"), Some(30), Some(30), updated));
        for other in [
            policy_revision("other", Some("bounded"), Some(30), Some(30), updated),
            policy_revision("default", Some("forever"), None, None, updated),
            policy_revision("default", Some("bounded"), Some(7), Some(7), updated),
            policy_revision("default", Some("bounded"), None, Some(30), updated),
            policy_revision("default", Some("bounded"), Some(30), Some(30), Some(at("2026-11-25T10:00:00.123457Z"))),
        ] {
            assert_ne!(base, other);
        }
    }
}
