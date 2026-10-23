//! Worker build-id rollouts, as published PHP 2.11.1 keeps them:
//! `DeploymentLifecycleService`, `DeploymentLifecyclePlan`,
//! `WorkerBuildIdNewStartSelector`, `TaskQueueRoutingGate` and the
//! task-queue build-id and `/api/deployments` routes.
//!
//! One rollout row per namespace, task queue and build id records what the
//! operator asked for. A promoted build is where new starts on the queue are
//! pinned. A draining build's workers are refused polls, and a queue whose
//! live workers all drain refuses new starts.
use super::laravel_rules::{self, Rule};
use super::transaction::Transaction;
use super::{Result, RuntimeError, backend::Backend, start_definition::Cohort, store::{Store, now}};
use axum::http::StatusCode;
use chrono::{DateTime, Timelike, Utc};
use serde_json::{Map, Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

pub(super) const DRAINING: &str = "draining";
const ACTIVE: &str = "active";

/// `WorkerBuildIdRollout::buildIdKey`: the trimmed build id, empty when
/// unversioned.
pub(super) fn key(build: Option<&str>) -> String {
    build.map(str::trim).unwrap_or_default().to_owned()
}

/// `buildIdFromKey`.
fn public(key: &str) -> Option<String> {
    (!key.is_empty()).then(|| key.to_owned())
}

/// Carbon's `toIso8601String`.
fn iso(at: Option<DateTime<Utc>>) -> Value {
    json!(at.map(|at| at.format("%Y-%m-%dT%H:%M:%S+00:00").to_string()))
}

/// `ControlPlaneTimestamp::zuluSecond`.
pub(super) fn zulu(at: Option<DateTime<Utc>>) -> Value {
    json!(at.map(|at| at.format("%Y-%m-%dT%H:%M:%SZ").to_string()))
}

/// Laravel stores these timestamps to the second.
fn second() -> DateTime<Utc> {
    let at = now();
    at.with_nanosecond(0).unwrap_or(at)
}

/// PHP's `trim`, with an empty result as none.
fn trimmed(value: Option<String>) -> Option<String> {
    value.map(|value| value.trim().to_owned()).filter(|value| !value.is_empty())
}

/// One rollout row.
pub(super) struct Rollout {
    id: i64,
    pub(super) key: String,
    pub(super) drain_intent: String,
    pub(super) drained_at: Option<DateTime<Utc>>,
    pub(super) promoted_at: Option<DateTime<Utc>>,
    pub(super) rolled_back_at: Option<DateTime<Utc>>,
    required: Option<String>,
    fingerprint: Option<String>,
    policy: Option<String>,
    workflow_types: Option<Value>,
}

impl Rollout {
    fn read<DB: Backend>(row: &DB::Row) -> Result<Self> {
        Ok(Self {
            id: DB::number(row, "id")?,
            key: DB::optional_string(row, "build_id")?.unwrap_or_default(),
            drain_intent: DB::optional_string(row, "drain_intent")?.unwrap_or_else(|| ACTIVE.to_owned()),
            drained_at: DB::optional_instant(row, "drained_at")?,
            promoted_at: DB::optional_instant(row, "promoted_at")?,
            rolled_back_at: DB::optional_instant(row, "rolled_back_at")?,
            required: DB::optional_string(row, "required_compatibility")?,
            fingerprint: DB::optional_string(row, "recorded_fingerprint")?,
            policy: DB::optional_string(row, "compatibility_policy")?,
            workflow_types: DB::optional_document_row(row, "workflow_types")?,
        })
    }

    fn new(key: String) -> Self {
        Self { id: 0, key, drain_intent: ACTIVE.to_owned(), drained_at: None, promoted_at: None, rolled_back_at: None,
            required: None, fingerprint: None, policy: None, workflow_types: None }
    }

    /// `WorkerBuildIdNewStartSelector::isSelectable`.
    fn selectable(&self) -> bool {
        self.drain_intent == ACTIVE && self.promoted_at.is_some() && self.rolled_back_at.is_none()
    }
}

/// `selectedKeyFromRollouts`: the most recently promoted selectable rollout,
/// the later row on a tie.
pub(super) fn selected_key(rollouts: &[Rollout]) -> Option<&str> {
    rollouts
        .iter()
        .filter(|rollout| rollout.selectable())
        .max_by(|left, right| left.promoted_at.cmp(&right.promoted_at).then(left.id.cmp(&right.id)))
        .map(|rollout| rollout.key.as_str())
}

/// `DeploymentLifecycleState`, without `pending`, which no row derives.
#[derive(Clone, Copy, PartialEq, Debug)]
enum State {
    Active,
    Promoted,
    Draining,
    Drained,
    RolledBack,
}

impl State {
    fn name(self) -> &'static str {
        match self {
            Self::Active => "active",
            Self::Promoted => "promoted",
            Self::Draining => "draining",
            Self::Drained => "drained",
            Self::RolledBack => "rolled_back",
        }
    }

    /// `WorkerDeployment::deriveState`.
    fn of(rollout: &Rollout) -> Self {
        let intent = rollout.drain_intent.trim();
        if rollout.rolled_back_at.is_some() {
            Self::RolledBack
        } else if intent == DRAINING || rollout.drained_at.is_some() {
            if intent == "drained" { Self::Drained } else { Self::Draining }
        } else if intent == "drained" {
            Self::Drained
        } else if rollout.promoted_at.is_some() {
            Self::Promoted
        } else {
            Self::Active
        }
    }
}

/// `WorkerDeployment`.
pub(super) struct Deployment {
    namespace: String,
    task_queue: String,
    build: Option<String>,
    state: State,
    policy: &'static str,
    required: Option<String>,
    fingerprint: Option<String>,
    workflow_types: Vec<String>,
    promoted_at: Option<DateTime<Utc>>,
    drained_at: Option<DateTime<Utc>>,
    rolled_back_at: Option<DateTime<Utc>>,
}

impl Deployment {
    /// `forActiveBuild`, and `find`'s answer for a build only workers know.
    fn active(namespace: &str, task_queue: &str, build: Option<String>, required: Option<String>) -> Self {
        Self { namespace: namespace.to_owned(), task_queue: task_queue.to_owned(), build, state: State::Active,
            policy: "pinned", required, fingerprint: None, workflow_types: Vec::new(), promoted_at: None,
            drained_at: None, rolled_back_at: None }
    }

    /// `deploymentFromRollout`.
    fn of(namespace: &str, task_queue: &str, rollout: &Rollout) -> Self {
        let build = public(&rollout.key);
        let workflow_types = match &rollout.workflow_types {
            Some(Value::String(types)) => types.split(',').map(str::to_owned).collect(),
            Some(Value::Array(types)) => types.iter().filter_map(Value::as_str).map(str::to_owned).collect(),
            _ => Vec::new(),
        };
        let mut workflow_types: Vec<String> =
            workflow_types.into_iter().map(|kind| kind.trim().to_owned()).filter(|kind| !kind.is_empty()).collect();
        workflow_types.sort();
        workflow_types.dedup();
        Self {
            namespace: namespace.to_owned(),
            task_queue: task_queue.to_owned(),
            required: trimmed(rollout.required.clone()).or_else(|| build.clone()),
            build,
            state: State::of(rollout),
            policy: if rollout.policy.as_deref() == Some("auto_upgrade") { "auto_upgrade" } else { "pinned" },
            fingerprint: trimmed(rollout.fingerprint.clone()),
            workflow_types,
            promoted_at: rollout.promoted_at,
            drained_at: rollout.drained_at,
            rolled_back_at: rollout.rolled_back_at,
        }
    }

    fn name(&self) -> String {
        format!("{}/{}@{}", self.namespace, self.task_queue, self.build.as_deref().unwrap_or("unversioned"))
    }

    /// `deploymentPayload`, with the route's timestamp format.
    fn view(&self, selected: Option<&str>, time: fn(Option<DateTime<Utc>>) -> Value) -> Value {
        json!({"name":self.name(),"namespace":self.namespace,"task_queue":self.task_queue,"build_id":self.build,
            "state":self.state.name(),"accepts_new_work":matches!(self.state, State::Active | State::Promoted),
            "compatibility_policy":self.policy,"required_compatibility":self.required,
            "recorded_fingerprint":self.fingerprint,"workflow_types":self.workflow_types,
            "promoted_at":time(self.promoted_at),"drained_at":time(self.drained_at),"rolled_back_at":time(self.rolled_back_at),
            "new_start_selected":selected == Some(key(self.build.as_deref()).as_str())})
    }

    /// `DeploymentLifecyclePlan::scopeOf`, or the service's `blockageScope`,
    /// which leaves out the workflow types.
    fn scope(&self, plan: bool) -> Value {
        let mut scope = json!({"namespace":self.namespace,"task_queue":self.task_queue,"build_id":self.build,
            "state":self.state.name()});
        if let Some(required) = &self.required {
            scope["required_compatibility"] = json!(required);
        }
        if plan && !self.workflow_types.is_empty() {
            scope["workflow_types"] = json!(self.workflow_types.join(","));
        }
        scope
    }
}

/// `DeploymentBlockage`.
struct Blockage {
    reason: &'static str,
    message: String,
    scope: Value,
    expected: Option<String>,
}

impl Blockage {
    fn view(&self) -> Value {
        json!({"reason":self.reason,"message":self.message,"scope":self.scope,"expected_resolution":self.expected})
    }

    /// `orderBlockages`' rank.
    fn rank(&self) -> u8 {
        match self.reason {
            "unknown_deployment" | "incompatible_policy" | "fleet_is_draining" => 0,
            "no_compatible_workers" | "missing_worker_heartbeat" | "fingerprint_mismatch" => 1,
            "replay_safety_failed" => 2,
            _ => 3,
        }
    }

    fn unknown(namespace: &str, task_queue: &str, build: Option<&str>, rollback: bool) -> Self {
        let name = format!("{namespace}/{task_queue}@{}", build.unwrap_or("unversioned"));
        Self {
            reason: "unknown_deployment",
            message: if rollback {
                format!("No deployment is registered for {name}; nothing to roll back.")
            } else {
                format!("No deployment is registered for {name}.")
            },
            scope: json!({"namespace":namespace,"task_queue":task_queue,"build_id":build}),
            expected: (!rollback).then(|| "Roll a worker that heartbeats this build id, then retry promotion.".to_owned()),
        }
    }

    fn policy(deployment: &Deployment, message: String, expected: &str) -> Self {
        Self { reason: "incompatible_policy", message, scope: deployment.scope(true), expected: Some(expected.to_owned()) }
    }
}

/// `fleetSnapshot`: the queue's live workers by id, and the stale ones
/// that support the required build.
struct Fleet {
    active: std::collections::BTreeSet<String>,
    supporting: std::collections::BTreeSet<String>,
    advertised: Vec<String>,
    stale_supporting: usize,
    last_stale: Option<DateTime<Utc>>,
}

/// A lifecycle action on a deployment.
#[derive(Clone, Copy)]
pub(crate) enum Action {
    Promote,
    Drain,
    Resume,
    Rollback,
}

impl Action {
    pub(crate) fn named(name: &str) -> Option<Self> {
        Some(match name {
            "promote" => Self::Promote,
            "drain" => Self::Drain,
            "resume" => Self::Resume,
            "rollback" => Self::Rollback,
            _ => return None,
        })
    }
}

/// `DeploymentLifecycleService::parseName`.
fn parse_name(name: &str) -> std::result::Result<(String, String, Option<String>), &'static str> {
    let name = name.trim();
    if name.is_empty() {
        return Err("Deployment name must not be empty.");
    }
    let Some((scope, build)) = name.rsplit_once('@') else {
        return Err("Deployment name must be of the form namespace/task_queue@build_id (use \"@unversioned\" for the unversioned cohort).");
    };
    let Some((namespace, task_queue)) = scope.split_once('/') else {
        return Err("Deployment name must include a namespace and task queue separated by \"/\".");
    };
    let (namespace, task_queue, build) = (namespace.trim(), task_queue.trim(), build.trim());
    if namespace.is_empty() || task_queue.is_empty() || build.is_empty() {
        return Err("Deployment name must include namespace, task queue, and build id (or \"unversioned\").");
    }
    Ok((namespace.to_owned(), task_queue.to_owned(), (build != "unversioned").then(|| build.to_owned())))
}

/// `TaskQueueController`'s `build_id` rule: present, nullable, a string of
/// at most 255 characters. Returns the trimmed build id, none when blank.
fn build_id(body: &Value) -> Result<Option<String>> {
    const RULES: &laravel_rules::Rules = &[("build_id", &[Rule::Present, Rule::Nullable, Rule::String, Rule::Max(255)])];
    let body = laravel_rules::normalize(body);
    laravel_rules::validate(&body, RULES)?;
    Ok(body["build_id"].as_str().map(str::trim).filter(|build| !build.is_empty()).map(str::to_owned))
}

fn bad_request(response: Value) -> RuntimeError {
    RuntimeError::Protocol { status: StatusCode::BAD_REQUEST, response }
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
    pub(super) async fn rollouts(tx: &mut Transaction<'_, DB>, namespace: &str, task_queue: &str) -> Result<Vec<Rollout>> {
        let rows = Self::query("SELECT * FROM workflow_worker_build_id_rollouts WHERE namespace=$1 AND task_queue=$2 ORDER BY id")
            .bind(namespace).bind(task_queue).fetch_all(&mut **tx).await?;
        rows.iter().map(Rollout::read::<DB>).collect()
    }

    async fn rollout(tx: &mut Transaction<'_, DB>, namespace: &str, task_queue: &str, key: &str) -> Result<Option<Rollout>> {
        Self::query("SELECT * FROM workflow_worker_build_id_rollouts WHERE namespace=$1 AND task_queue=$2 AND build_id=$3")
            .bind(namespace).bind(task_queue).bind(key).fetch_optional(&mut **tx).await?
            .map(|row| Rollout::read::<DB>(&row)).transpose()
    }

    /// `WorkflowStartVersionPin::promotedBuildId`: the cohort new starts on
    /// the queue are pinned to, when a build was promoted there.
    pub(super) async fn promoted_cohort(tx: &mut Transaction<'_, DB>, namespace: &str, task_queue: &str) -> Result<Option<Cohort>> {
        let rollouts = Self::rollouts(tx, namespace, task_queue).await?;
        Ok(selected_key(&rollouts).map(|key| Cohort::of_run(Some(key))))
    }

    /// `workerRegistrationStatus`: a registration on a draining build stays
    /// draining across registrations and heartbeats.
    pub(super) async fn registration_status(tx: &mut Transaction<'_, DB>, namespace: &str, task_queue: &str, build: Option<&str>) -> Result<&'static str> {
        let draining = Self::rollout(tx, namespace, task_queue, &key(build)).await?
            .is_some_and(|rollout| rollout.drain_intent == DRAINING);
        Ok(if draining { DRAINING } else { ACTIVE })
    }

    /// `resolveRegisteredWorker`'s last check: a draining worker claims
    /// nothing until its build resumes.
    pub(super) async fn refuse_draining_worker(&self, worker_id: &str, task_queue: &str) -> Result<()> {
        let Some(worker) = Self::query("SELECT task_queue,build_id,status FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2")
            .bind(worker_id).bind(self.namespace.as_ref()).fetch_optional(&self.pool).await?
        else {
            return Ok(());
        };
        if DB::string(&worker, "task_queue")? != task_queue || DB::optional_string(&worker, "status")?.as_deref() != Some(DRAINING) {
            return Ok(());
        }
        Err(RuntimeError::Protocol {
            status: StatusCode::CONFLICT,
            response: json!({"task":null,"poll_status":"draining",
                "error":format!("Worker [{worker_id}] is marked draining for task queue [{task_queue}] and cannot claim new tasks until the cohort is resumed."),
                "reason":"worker_draining","worker_id":worker_id,"task_queue":task_queue,
                "registered_build_id":DB::optional_string(&worker, "build_id")?.filter(|build| !build.is_empty()),
                "worker_status":DRAINING,"drain_intent":DRAINING}),
        })
    }

    /// `TaskQueueRoutingGate::workflowStartBlock`: why a start on the queue
    /// is refused, when every live worker there drains.
    pub(super) async fn routing_block(tx: &mut Transaction<'_, DB>, namespace: &str, task_queue: &str, stale_after: i64) -> Result<Option<Map<String, Value>>> {
        let cutoff = now() - chrono::Duration::seconds(stale_after.max(1));
        let (mut active, mut draining, mut stale) = (0, 0, 0);
        for worker in Self::query("SELECT status,last_heartbeat_at FROM workflow_worker_registrations WHERE namespace=$1 AND task_queue=$2")
            .bind(namespace).bind(task_queue).fetch_all(&mut **tx).await?
        {
            if DB::optional_instant(&worker, "last_heartbeat_at")?.is_some_and(|heartbeat| heartbeat < cutoff) {
                stale += 1;
            } else if DB::optional_string(&worker, "status")?.as_deref() == Some(DRAINING) {
                draining += 1;
            } else {
                active += 1;
            }
        }
        let mut builds: Vec<String> = Self::rollouts(tx, namespace, task_queue).await?.into_iter()
            .filter(|rollout| rollout.drain_intent == DRAINING).map(|rollout| rollout.key).collect();
        builds.sort();
        if active > 0 || (draining == 0 && builds.is_empty()) {
            return Ok(None);
        }
        let Value::Object(block) = json!({"routing_status":"draining","active_worker_count":active,
            "draining_worker_count":draining,"stale_worker_count":stale,
            "draining_build_ids":builds.iter().map(|key| public(key)).collect::<Vec<_>>(),"drain_intent":DRAINING})
        else {
            unreachable!()
        };
        Ok(Some(block))
    }

    /// `find`: the rollout's deployment, else one only a registration knows.
    async fn find(tx: &mut Transaction<'_, DB>, namespace: &str, task_queue: &str, build: Option<&str>) -> Result<Option<Deployment>> {
        if let Some(rollout) = Self::rollout(tx, namespace, task_queue, &key(build)).await? {
            return Ok(Some(Deployment::of(namespace, task_queue, &rollout)));
        }
        let worker = Self::query("SELECT build_id FROM workflow_worker_registrations WHERE namespace=$1 AND task_queue=$2 AND (($3 IS NULL AND (build_id IS NULL OR build_id='')) OR build_id=$4) ORDER BY last_heartbeat_at DESC LIMIT 1")
            .bind(namespace).bind(task_queue).bind(build).bind(build).fetch_optional(&mut **tx).await?;
        Ok(match worker {
            Some(worker) => {
                let required = trimmed(DB::optional_string(&worker, "build_id")?);
                Some(Deployment::active(namespace, task_queue, build.map(str::to_owned), required))
            }
            None => None,
        })
    }

    /// `fleetSnapshot`. PHP joins two views of the queue's workers. Its
    /// compatibility heartbeats, recorded at each registration and heartbeat
    /// and kept 30 seconds, count draining workers too; native reads them
    /// from the registration's heartbeat time. The registrations count live,
    /// undrained workers, and the stale ones that support the required build.
    async fn fleet(&self, tx: &mut Transaction<'_, DB>, task_queue: &str, required: Option<&str>) -> Result<Fleet> {
        let at = now();
        let cutoff = at - chrono::Duration::seconds(self.worker_stale_after_seconds.max(1));
        let recorded = at - chrono::Duration::seconds(30);
        let mut fleet = Fleet { active: Default::default(), supporting: Default::default(), advertised: Vec::new(),
            stale_supporting: 0, last_stale: None };
        for worker in Self::query("SELECT worker_id,build_id,status,last_heartbeat_at FROM workflow_worker_registrations WHERE namespace=$1 AND task_queue=$2 ORDER BY worker_id")
            .bind(self.namespace.as_ref()).bind(task_queue).fetch_all(&mut **tx).await?
        {
            let id = DB::string(&worker, "worker_id")?.trim().to_owned();
            let build = trimmed(DB::optional_string(&worker, "build_id")?);
            let supports = required.is_none() || build.as_deref() == required;
            let heartbeat = DB::optional_instant(&worker, "last_heartbeat_at")?;
            if heartbeat.is_some_and(|heartbeat| heartbeat >= recorded) {
                fleet.active.insert(id.clone());
                if supports {
                    fleet.supporting.insert(id.clone());
                }
                if let Some(build) = &build && !fleet.advertised.contains(build) {
                    fleet.advertised.push(build.clone());
                }
            }
            if heartbeat.is_some_and(|heartbeat| heartbeat < cutoff) {
                if supports && required.is_some() {
                    fleet.stale_supporting += 1;
                    fleet.last_stale = fleet.last_stale.max(heartbeat);
                }
                continue;
            }
            if trimmed(DB::optional_string(&worker, "status")?).as_deref() == Some(DRAINING) {
                continue;
            }
            fleet.active.insert(id.clone());
            if supports {
                fleet.supporting.insert(id);
            }
            if let Some(build) = build && !fleet.advertised.contains(&build) {
                fleet.advertised.push(build);
            }
        }
        fleet.advertised.sort();
        Ok(fleet)
    }

    /// `evaluatePromote` with `normalizePromotionBlockages`.
    fn promote_blockages(deployment: &Deployment, fleet: &Fleet) -> Vec<Blockage> {
        let name = deployment.name();
        if matches!(deployment.state, State::Drained | State::RolledBack) {
            return vec![Blockage::policy(deployment,
                format!("Deployment {name} is {} and cannot be promoted; recreate the deployment first.", deployment.state.name()),
                "Create a new active deployment for this build id, then promote it.")];
        }
        let mut blockages = Vec::new();
        if deployment.state == State::Draining {
            blockages.push(Blockage { reason: "fleet_is_draining", message: format!("Deployment {name} is draining; resume it before promoting."),
                scope: deployment.scope(true), expected: Some("Resume the deployment, then retry promotion.".to_owned()) });
        }
        let Some(required) = &deployment.required else {
            return blockages;
        };
        let (namespace, queue) = (&deployment.namespace, &deployment.task_queue);
        let start = format!("Start at least one worker that advertises compatibility [{required}] for this task queue, then retry promotion.");
        let stale = || Blockage {
            reason: "missing_worker_heartbeat",
            message: format!("{} worker(s) for {namespace}/{queue} advertise required compatibility [{required}], but none have a fresh heartbeat.{}",
                fleet.stale_supporting,
                fleet.last_stale.map(|at| format!(" Last matching heartbeat was {}.", at.format("%Y-%m-%dT%H:%M:%S%.6fZ"))).unwrap_or_default()),
            scope: deployment.scope(false),
            expected: Some(format!("Restart or heartbeat a worker that advertises compatibility [{required}] for this task queue, then retry promotion.")),
        };
        if fleet.active.is_empty() {
            blockages.push(if fleet.stale_supporting > 0 { stale() } else {
                Blockage { reason: "no_compatible_workers",
                    message: format!("No workers are heartbeating against {namespace}/{queue}; promotion would route fresh tasks to a fleet that cannot claim them."),
                    scope: deployment.scope(true), expected: Some(start) }
            });
        } else if fleet.supporting.is_empty() {
            blockages.push(if fleet.stale_supporting > 0 { stale() } else {
                let advertised = if fleet.advertised.is_empty() { "none".to_owned() } else { fleet.advertised.join(", ") };
                Blockage { reason: "no_compatible_workers",
                    message: format!("No active worker on {namespace}/{queue} advertises required compatibility [{required}]; active workers advertise [{advertised}]."),
                    scope: deployment.scope(false), expected: Some(start) }
            });
        }
        blockages
    }

    /// `upsertRollout`: the row for the build, created active when missing.
    async fn upsert(tx: &mut Transaction<'_, DB>, namespace: &str, task_queue: &str, build: Option<&str>, apply: impl FnOnce(&mut Rollout)) -> Result<Rollout> {
        let key = key(build);
        let existing = Self::rollout(tx, namespace, task_queue, &key).await?;
        let exists = existing.is_some();
        let mut rollout = existing.unwrap_or_else(|| Rollout::new(key.clone()));
        apply(&mut rollout);
        let at = second();
        if exists {
            Self::query("UPDATE workflow_worker_build_id_rollouts SET drain_intent=$1,drained_at=$2,promoted_at=$3,rolled_back_at=$4,updated_at=$5 WHERE id=$6")
                .bind(rollout.drain_intent.as_str()).bind(DB::optional_time(rollout.drained_at)).bind(DB::optional_time(rollout.promoted_at))
                .bind(DB::optional_time(rollout.rolled_back_at)).bind(DB::bind_time(at)).bind(rollout.id).execute(&mut **tx).await?;
        } else {
            Self::query("INSERT INTO workflow_worker_build_id_rollouts(namespace,task_queue,build_id,drain_intent,drained_at,promoted_at,rolled_back_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9)")
                .bind(namespace).bind(task_queue).bind(key.as_str()).bind(rollout.drain_intent.as_str()).bind(DB::optional_time(rollout.drained_at))
                .bind(DB::optional_time(rollout.promoted_at)).bind(DB::optional_time(rollout.rolled_back_at)).bind(DB::bind_time(at)).bind(DB::bind_time(at))
                .execute(&mut **tx).await?;
        }
        Self::rollout(tx, namespace, task_queue, &key).await?.ok_or_else(|| super::refuse(StatusCode::CONFLICT, "build_id_rollout_lost"))
    }

    /// `stampWorkerDrainStatus`.
    async fn stamp_workers(tx: &mut Transaction<'_, DB>, namespace: &str, task_queue: &str, build: Option<&str>, status: &str, only_draining: bool) -> Result<()> {
        Self::query("UPDATE workflow_worker_registrations SET status=$1 WHERE namespace=$2 AND task_queue=$3 AND (($4 IS NULL AND (build_id IS NULL OR build_id='')) OR build_id=$5) AND ($6='all' OR status='draining')")
            .bind(status).bind(namespace).bind(task_queue).bind(build).bind(build).bind(if only_draining { "draining" } else { "all" })
            .execute(&mut **tx).await?;
        Ok(())
    }

    /// One lifecycle action: the resulting deployment, or why it was refused.
    async fn lifecycle(&self, tx: &mut Transaction<'_, DB>, action: Action, task_queue: &str, build: Option<&str>) -> Result<std::result::Result<Deployment, (Option<Deployment>, Vec<Blockage>)>> {
        let namespace = self.namespace.as_ref();
        let found = Self::find(tx, namespace, task_queue, build).await?;
        match action {
            Action::Promote => {
                let Some(deployment) = found else {
                    return Ok(Err((None, vec![Blockage::unknown(namespace, task_queue, build, false)])));
                };
                let fleet = self.fleet(tx, task_queue, deployment.required.as_deref()).await?;
                let blockages = Self::promote_blockages(&deployment, &fleet);
                if !blockages.is_empty() {
                    return Ok(Err((Some(deployment), blockages)));
                }
                let at = second();
                let rollout = Self::upsert(tx, namespace, task_queue, build, |row| {
                    row.drain_intent = ACTIVE.to_owned();
                    row.drained_at = None;
                    row.promoted_at = Some(at);
                    row.rolled_back_at = None;
                }).await?;
                Ok(Ok(Deployment::of(namespace, task_queue, &rollout)))
            }
            Action::Drain => {
                let deployment = found.unwrap_or_else(|| Deployment::active(namespace, task_queue, build.map(str::to_owned), None));
                if deployment.state == State::RolledBack {
                    let message = format!("Deployment {} is rolled back; drain is unnecessary because the deployment no longer accepts work.", deployment.name());
                    let blockage = Blockage::policy(&deployment, message, "No action required; the deployment is already terminal.");
                    return Ok(Err((Some(deployment), vec![blockage])));
                }
                let at = second();
                let rollout = Self::upsert(tx, namespace, task_queue, build, |row| {
                    if row.drain_intent != DRAINING {
                        row.drained_at = Some(at);
                    }
                    row.drain_intent = DRAINING.to_owned();
                }).await?;
                Self::stamp_workers(tx, namespace, task_queue, build, DRAINING, false).await?;
                Ok(Ok(Deployment::of(namespace, task_queue, &rollout)))
            }
            Action::Resume => {
                let deployment = found.unwrap_or_else(|| Deployment::active(namespace, task_queue, build.map(str::to_owned), None));
                let refused = match deployment.state {
                    State::Drained => Some((format!("Deployment {} is fully drained; resume is not allowed once a deployment has reached the drained terminal state — recreate it instead.", deployment.name()),
                        "Create a new active deployment for this build id.")),
                    State::RolledBack => Some((format!("Deployment {} is rolled back; promote a different deployment rather than resuming this one.", deployment.name()),
                        "Promote the prior healthy deployment, or create a new active deployment for this build id.")),
                    _ => None,
                };
                if let Some((message, expected)) = refused {
                    let blockage = Blockage::policy(&deployment, message, expected);
                    return Ok(Err((Some(deployment), vec![blockage])));
                }
                let rollout = Self::upsert(tx, namespace, task_queue, build, |row| {
                    row.drain_intent = ACTIVE.to_owned();
                    row.drained_at = None;
                }).await?;
                Self::stamp_workers(tx, namespace, task_queue, build, ACTIVE, true).await?;
                Ok(Ok(Deployment::of(namespace, task_queue, &rollout)))
            }
            Action::Rollback => {
                let Some(deployment) = found else {
                    return Ok(Err((None, vec![Blockage::unknown(namespace, task_queue, build, true)])));
                };
                if deployment.state == State::RolledBack {
                    let message = format!("Deployment {} is already rolled back.", deployment.name());
                    let blockage = Blockage::policy(&deployment, message, "No action required; the deployment is already terminal.");
                    return Ok(Err((Some(deployment), vec![blockage])));
                }
                let at = second();
                let rollout = Self::upsert(tx, namespace, task_queue, build, |row| {
                    row.rolled_back_at = Some(at);
                    row.promoted_at = None;
                }).await?;
                // `withState(RolledBack)`: rolled back now, and no drain time.
                let mut deployment = Deployment::of(namespace, task_queue, &rollout);
                deployment.state = State::RolledBack;
                deployment.drained_at = None;
                Ok(Ok(deployment))
            }
        }
    }

    /// The queue's selected rollout key after a change.
    async fn selected(tx: &mut Transaction<'_, DB>, namespace: &str, task_queue: &str) -> Result<Option<String>> {
        let rollouts = Self::rollouts(tx, namespace, task_queue).await?;
        Ok(selected_key(&rollouts).map(str::to_owned))
    }

    /// `lifecycleRejectionPayload`, with blockages in PHP's order.
    fn rejection(deployment: Option<Value>, mut blockages: Vec<Blockage>, scope: Option<Value>) -> Value {
        blockages.sort_by_key(Blockage::rank);
        let primary = &blockages[0];
        let outcome = format!("rejected_{}", primary.reason);
        let mut response = json!({"message":primary.message,"reason":primary.reason,"rejection_reason":primary.reason,
            "expected_resolution":primary.expected,"outcome":outcome,"control_plane_outcome":outcome,
            "command_status":"rejected","command_source":"control_plane"});
        if let Some(Value::Object(scope)) = scope {
            response.as_object_mut().expect("object").extend(scope);
        }
        response["deployment"] = deployment.unwrap_or(Value::Null);
        response["blockages"] = Value::Array(blockages.iter().map(Blockage::view).collect());
        response
    }

    /// `POST /api/deployments/{name}/{action}`.
    pub(crate) async fn deployment_action(&self, name: &str, action: Action) -> Result<(StatusCode, Value)> {
        let (task_queue, build) = self.deployment_scope(name)?;
        let mut tx = self.begin().await?;
        let result = self.lifecycle(&mut tx, action, &task_queue, build.as_deref()).await?;
        let selected = Self::selected(&mut tx, self.namespace.as_ref(), &task_queue).await?;
        tx.commit().await?;
        Ok(match result {
            Ok(deployment) => (StatusCode::OK, deployment.view(selected.as_deref(), iso)),
            Err((deployment, blockages)) => (StatusCode::CONFLICT,
                Self::rejection(deployment.map(|deployment| deployment.view(selected.as_deref(), iso)), blockages, None)),
        })
    }

    /// `GET /api/deployments/{name}`.
    pub(crate) async fn deployment(&self, name: &str) -> Result<(StatusCode, Value)> {
        let (task_queue, build) = self.deployment_scope(name)?;
        let mut tx = self.begin().await?;
        let found = Self::find(&mut tx, self.namespace.as_ref(), &task_queue, build.as_deref()).await?;
        let selected = Self::selected(&mut tx, self.namespace.as_ref(), &task_queue).await?;
        tx.commit().await?;
        Ok(match found {
            Some(deployment) => (StatusCode::OK, deployment.view(selected.as_deref(), iso)),
            None => (StatusCode::NOT_FOUND, json!({"error":"deployment_not_found"})),
        })
    }

    /// The task queue and build a deployment name in this namespace names.
    fn deployment_scope(&self, name: &str) -> Result<(String, Option<String>)> {
        let (namespace, task_queue, build) = parse_name(name).map_err(|error| bad_request(json!({"error":error})))?;
        if namespace != self.namespace.as_ref() {
            return Err(bad_request(json!({"error":"namespace_mismatch",
                "message":format!("Deployment name {name} is in namespace {namespace} but the request targets {}.", self.namespace)})));
        }
        Ok((task_queue, build))
    }

    /// `GET /api/deployments`: `listForNamespace`.
    pub(crate) async fn deployments(&self) -> Result<Value> {
        let namespace = self.namespace.as_ref();
        let mut tx = self.begin().await?;
        let rollouts = Self::query("SELECT * FROM workflow_worker_build_id_rollouts WHERE namespace=$1 ORDER BY task_queue,build_id")
            .bind(namespace).fetch_all(&mut *tx).await?;
        let mut rollouts: Vec<(String, Rollout)> = rollouts.iter()
            .map(|row| Ok((DB::string(row, "task_queue")?, Rollout::read::<DB>(row)?))).collect::<Result<_>>()?;
        let workers = Self::query("SELECT task_queue,build_id FROM workflow_worker_registrations WHERE namespace=$1 ORDER BY task_queue,COALESCE(build_id,'')")
            .bind(namespace).fetch_all(&mut *tx).await?;
        let mut seen: Vec<(String, String)> = Vec::new();
        let mut deployments = Vec::new();
        for worker in &workers {
            let Some(queue) = trimmed(DB::optional_string(worker, "task_queue")?) else {
                continue;
            };
            let build = trimmed(DB::optional_string(worker, "build_id")?);
            let scope = (queue.clone(), key(build.as_deref()));
            if seen.contains(&scope) {
                continue;
            }
            seen.push(scope.clone());
            deployments.push(match rollouts.iter().position(|(q, rollout)| *q == scope.0 && rollout.key == scope.1) {
                Some(index) => {
                    let (queue, rollout) = rollouts.remove(index);
                    (queue.clone(), Deployment::of(namespace, &queue, &rollout))
                }
                None => (queue.clone(), Deployment::active(namespace, &queue, build.clone(), build)),
            });
        }
        deployments.extend(rollouts.iter().map(|(queue, rollout)| (queue.clone(), Deployment::of(namespace, queue, rollout))));
        let mut views = Vec::with_capacity(deployments.len());
        for (queue, deployment) in &deployments {
            let selected = Self::selected(&mut tx, namespace, queue).await?;
            views.push(deployment.view(selected.as_deref(), iso));
        }
        tx.commit().await?;
        Ok(json!({"namespace":namespace,"deployments":views}))
    }

    /// `POST /api/task-queues/{queue}/build-ids/{promote|drain|resume}`.
    pub(crate) async fn build_id_action(&self, task_queue: &str, body: &Value, action: Action) -> Result<(StatusCode, Value)> {
        let build = build_id(body)?;
        let namespace = self.namespace.as_ref();
        let mut tx = self.begin().await?;
        let scope = json!({"namespace":namespace,"task_queue":task_queue,"build_id":build});
        if let Action::Promote = action {
            let result = self.lifecycle(&mut tx, action, task_queue, build.as_deref()).await?;
            let selected = Self::selected(&mut tx, namespace, task_queue).await?;
            tx.commit().await?;
            return Ok(match result {
                Ok(deployment) => {
                    let view = deployment.view(selected.as_deref(), zulu);
                    let mut response = scope;
                    response["drain_intent"] = json!(if deployment.state == State::Draining { DRAINING } else { ACTIVE });
                    for field in ["drained_at", "promoted_at", "rolled_back_at"] {
                        response[field] = view[field].clone();
                    }
                    response["new_start_selected"] = json!(selected.as_deref() == Some(key(build.as_deref()).as_str()));
                    response["deployment"] = view;
                    (StatusCode::OK, response)
                }
                Err((deployment, blockages)) => (StatusCode::CONFLICT,
                    Self::rejection(deployment.map(|deployment| deployment.view(selected.as_deref(), zulu)), blockages, Some(scope))),
            });
        }
        // `setBuildIdDrainIntent`: the drain intent alone, without the plan.
        let draining = matches!(action, Action::Drain);
        let at = second();
        let rollout = Self::upsert(&mut tx, namespace, task_queue, build.as_deref(), |row| {
            row.drained_at = if draining { if row.drain_intent == DRAINING { row.drained_at } else { Some(at) } } else { None };
            row.drain_intent = if draining { DRAINING } else { ACTIVE }.to_owned();
        }).await?;
        Self::stamp_workers(&mut tx, namespace, task_queue, build.as_deref(), if draining { DRAINING } else { ACTIVE }, !draining).await?;
        let selected = Self::selected(&mut tx, namespace, task_queue).await?;
        tx.commit().await?;
        let mut response = scope;
        response["drain_intent"] = json!(rollout.drain_intent);
        response["drained_at"] = zulu(rollout.drained_at);
        response["promoted_at"] = zulu(rollout.promoted_at);
        response["rolled_back_at"] = zulu(rollout.rolled_back_at);
        response["new_start_selected"] = json!(rollout.selectable() && selected.as_deref() == Some(rollout.key.as_str()));
        Ok((StatusCode::OK, response))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn rollout(key: &str, intent: &str, promoted: Option<i64>, rolled_back: bool, id: i64) -> Rollout {
        let at = |seconds: i64| DateTime::from_timestamp(seconds, 0).unwrap();
        Rollout { id, drain_intent: intent.to_owned(), promoted_at: promoted.map(at), rolled_back_at: rolled_back.then(|| at(9)),
            ..Rollout::new(key.to_owned()) }
    }

    #[test]
    fn names_parse_like_php() {
        assert_eq!(parse_name(" default/q@v1 "), Ok(("default".into(), "q".into(), Some("v1".into()))));
        assert_eq!(parse_name("default/a/b@unversioned"), Ok(("default".into(), "a/b".into(), None)));
        assert_eq!(parse_name("default/q@x@v2"), Ok(("default".into(), "q@x".into(), Some("v2".into()))));
        assert!(parse_name("nonsense").unwrap_err().starts_with("Deployment name must be of the form"));
        assert!(parse_name("q@v1").unwrap_err().contains("separated by"));
        assert!(parse_name("default/@v1").unwrap_err().contains("(or \"unversioned\")"));
        assert_eq!(parse_name("  "), Err("Deployment name must not be empty."));
    }

    #[test]
    fn the_latest_selectable_promotion_is_selected() {
        let rollouts = [rollout("v1", "active", Some(5), false, 1), rollout("v2", "active", Some(6), true, 2),
            rollout("", "active", Some(5), false, 3), rollout("v3", "draining", Some(8), false, 4)];
        // v2 is rolled back and v3 drains; v1 and unversioned tie, and the later row wins.
        assert_eq!(selected_key(&rollouts), Some(""));
        assert_eq!(selected_key(&rollouts[..1]), Some("v1"));
        assert_eq!(selected_key(&[]), None);
    }

    #[test]
    fn states_derive_like_worker_deployment() {
        let state = |intent: &str, drained: bool, promoted: bool, rolled_back: bool| {
            let mut row = rollout("v1", intent, promoted.then_some(1), rolled_back, 1);
            row.drained_at = drained.then(|| DateTime::from_timestamp(2, 0).unwrap());
            State::of(&row)
        };
        assert_eq!(state("active", false, false, false), State::Active);
        assert_eq!(state("active", false, true, false), State::Promoted);
        assert_eq!(state("draining", true, true, false), State::Draining);
        assert_eq!(state("active", true, false, false), State::Draining);
        assert_eq!(state("drained", true, false, false), State::Drained);
        assert_eq!(state("draining", true, true, true), State::RolledBack);
    }
}
