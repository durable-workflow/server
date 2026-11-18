//! Guarded worker build and task queue retirement (Server #519).
//!
//! Promotion moves new starts, but runs already pinned to an older build and
//! activities recorded on an older queue still need that fleet. Retirement
//! inspection counts those live dependencies for one namespace, task queue
//! and build (or the whole queue), and the guarded action records the
//! terminal `drained` intent only when the same count, taken inside the
//! serialized transition that writes it, finds none.
//!
//! The existing drain, resume, promote and rollback actions are unchanged;
//! drain stays the emergency stop that refuses polls at once.
use super::deployments::{self, RETIRED};
use super::transaction::Transaction;
use super::{Result, RuntimeError, store::now};
use axum::http::StatusCode;
use chrono::{DateTime, SecondsFormat, Utc};
use serde_json::{Map, Value, json};
use sha2::{Digest, Sha256};

pub(super) const CAPABILITY: &str = "worker_build_retirement";
/// Each blocker lists at most this many identities; its count is exact.
const SAMPLE: usize = 20;

/// What retirement acts on within the request's namespace.
#[derive(Clone)]
pub(crate) enum Target {
    /// One build id on the queue; none is the unversioned cohort.
    Build(Option<String>),
    /// Every cohort on the queue.
    Queue,
}

impl Target {
    fn mode(&self) -> &'static str {
        match self {
            Self::Build(_) => "build",
            Self::Queue => "task_queue",
        }
    }

    fn build(&self) -> Option<&str> {
        match self {
            Self::Build(build) => build.as_deref(),
            Self::Queue => None,
        }
    }
}

/// One kind of live dependency: an exact count and a bounded sample.
struct Category {
    kind: &'static str,
    count: i64,
    detail: Map<String, Value>,
    sample: Vec<Value>,
}

impl Category {
    fn new(kind: &'static str, count: i64) -> Self {
        Self { kind, count, detail: Map::new(), sample: Vec::new() }
    }

    fn recovery(&self) -> &'static str {
        match self.kind {
            "open_runs" => "Keep a worker for this cohort running (resume the build if it is draining) until these runs close, or cancel or terminate them. Continued and child runs keep their recorded build pin and queue.",
            "ready_tasks" | "leased_tasks" => "Keep a compatible worker polling this queue until the tasks complete; a leased task completes or its lease expires and it is retried.",
            "delayed_tasks" => "Keep a compatible worker available until these delayed retries and timers come due and complete, or close their runs.",
            "open_activities" => "Keep an activity worker polling each recorded activity queue until these activities complete, fail or are cancelled.",
            "update_validation_tasks" => "Keep a workflow worker polling this queue until the pending update validations finish.",
            "schedules" => "Point these schedules at another task queue, or delete them, before retiring this queue.",
            _ => "Close these open runs, or record their task queue, before retiring; their dependencies cannot be ruled out.",
        }
    }

    fn view(&self) -> Value {
        let mut view = json!({"kind":self.kind,"count":self.count});
        let object = view.as_object_mut().expect("object");
        object.extend(self.detail.clone());
        object.insert("sample".into(), Value::Array(self.sample.clone()));
        object.insert("sample_truncated".into(), json!(self.count > self.sample.len() as i64));
        object.insert("recovery".into(), json!(self.recovery()));
        view
    }
}

/// A retirement inventory observed in one transaction.
pub(super) struct Inventory {
    categories: Vec<Category>,
    observed_at: DateTime<Utc>,
}

impl Inventory {
    /// Open runs whose queue was never recorded could be on any queue.
    fn complete(&self) -> bool {
        self.categories.iter().all(|category| category.kind != "unattributed_runs" || category.count == 0)
    }

    fn blockers(&self) -> impl Iterator<Item = &Category> {
        self.categories.iter().filter(|category| category.count > 0)
    }

    fn counts(&self) -> Value {
        Value::Object(self.categories.iter().map(|category| (category.kind.to_owned(), json!(category.count))).collect())
    }

    /// A digest of the scope and everything observed, without the clock.
    fn revision(&self, scope: &Value, state: &Value) -> String {
        let categories: Vec<Value> = self.categories.iter()
            .map(|category| json!({"kind":category.kind,"count":category.count,"detail":category.detail,"sample":category.sample}))
            .collect();
        let body = serde_json::to_vec(&json!({"v":1,"scope":scope,"state":state,"categories":categories})).unwrap_or_default();
        let digest = Sha256::digest(&body);
        format!("sha256:{}", digest.iter().map(|byte| format!("{byte:02x}")).collect::<String>())
    }
}

fn instant(at: DateTime<Utc>) -> Value {
    json!(at.to_rfc3339_opts(SecondsFormat::Micros, true))
}

/// Why the action is refused, most fundamental first.
struct Refusal {
    reason: &'static str,
    message: String,
    expected: String,
}

/// An inventory with the preconditions the action checks beside it.
struct Evaluation {
    inventory: Inventory,
    scope: Value,
    /// The build ids the action would record as retired.
    keys: Vec<String>,
    deployment: Option<Value>,
    revision: String,
    refusals: Vec<Refusal>,
}

impl Evaluation {
    fn view(&self) -> Value {
        let mut view = self.scope.clone();
        let object = view.as_object_mut().expect("object");
        object.insert("retirable".into(), json!(self.refusals.is_empty()));
        object.insert("observation".into(), json!({
            "status": if self.inventory.complete() { "complete" } else { "incomplete" },
            "observed_at": instant(self.inventory.observed_at),
            "sample_limit": SAMPLE,
            "inventory_revision": self.revision,
            "atomic_with_action": false,
        }));
        object.insert("counts".into(), self.inventory.counts());
        object.insert("blockers".into(), Value::Array(self.inventory.blockers().map(Category::view).collect()));
        object.insert("build_ids".into(), json!(self.keys.iter().map(|key| deployments::public(key)).collect::<Vec<_>>()));
        if self.scope["scope"] == "build" {
            object.insert("deployment".into(), self.deployment.clone().unwrap_or(Value::Null));
        }
        match self.refusals.first() {
            Some(refusal) => {
                object.insert("reason".into(), json!(refusal.reason));
                object.insert("message".into(), json!(refusal.message));
                object.insert("expected_resolution".into(), json!(refusal.expected));
            }
            None => {
                object.insert("reason".into(), Value::Null);
                object.insert("message".into(), json!("No live dependency was observed. The guarded retire action repeats this check atomically before it records retirement."));
                object.insert("expected_resolution".into(), Value::Null);
            }
        }
        view
    }

    /// The 409 body: the refusal, and the full inventory that caused it.
    fn rejection(&self) -> Value {
        let mut view = self.view();
        let reason = view["reason"].as_str().unwrap_or("retirement_blocked").to_owned();
        let outcome = format!("rejected_{reason}");
        let object = view.as_object_mut().expect("object");
        object.insert("rejection_reason".into(), json!(reason));
        object.insert("outcome".into(), json!(outcome));
        object.insert("control_plane_outcome".into(), json!(outcome));
        object.insert("command_status".into(), json!("rejected"));
        object.insert("command_source".into(), json!("control_plane"));
        object.insert("changed".into(), json!(false));
        if let Some(observation) = object.get_mut("observation") {
            observation["atomic_with_action"] = json!(true);
        }
        view
    }
}

/// The optional `expected_inventory_revision` a retire request binds to.
fn expected_revision(body: &Value) -> Result<Option<String>> {
    let invalid = |message: &str| RuntimeError::Protocol {
        status: StatusCode::UNPROCESSABLE_ENTITY,
        response: json!({"reason":"validation_failed","message":message,
            "errors":{"expected_inventory_revision":[message]},
            "validation_errors":{"expected_inventory_revision":[message]}}),
    };
    match body {
        Value::Null => Ok(None),
        Value::Object(fields) => match fields.get("expected_inventory_revision") {
            None | Some(Value::Null) => Ok(None),
            Some(Value::String(revision)) if !revision.is_empty() && revision.len() <= 128 => Ok(Some(revision.clone())),
            Some(_) => Err(invalid("The expected inventory revision must be a revision string from a retirement inspection.")),
        },
        _ => Err(invalid("The retire request body must be a JSON object.")),
    }
}

// Every statement uses ordered, unique placeholders. `$mode` selects the
// whole queue or one cohort: unversioned is a null or empty pin.
const RUNS: &str = "SELECT COUNT(*) AS total,COUNT(CASE WHEN r.status='waiting' THEN 1 END) AS parked,COUNT(CASE WHEN r.run_number>1 THEN 1 END) AS continued,COUNT(CASE WHEN EXISTS (SELECT 1 FROM workflow_links l WHERE l.child_workflow_run_id=r.id AND l.link_type='child_workflow') THEN 1 END) AS children FROM workflow_runs r WHERE r.namespace=$1 AND r.queue=$2 AND r.status IN ('pending','running','waiting') AND ($3='task_queue' OR ($4 IS NULL AND (r.compatibility IS NULL OR r.compatibility='')) OR r.compatibility=$5)";
const RUN_SAMPLE: &str = "SELECT r.id,r.workflow_instance_id,r.workflow_type,r.status,r.run_number,r.compatibility FROM workflow_runs r WHERE r.namespace=$1 AND r.queue=$2 AND r.status IN ('pending','running','waiting') AND ($3='task_queue' OR ($4 IS NULL AND (r.compatibility IS NULL OR r.compatibility='')) OR r.compatibility=$5) ORDER BY r.id LIMIT 20";
const TASKS: &str = "SELECT COUNT(CASE WHEN t.status='leased' THEN 1 END) AS leased,COUNT(CASE WHEN t.status='ready' AND (t.available_at IS NULL OR t.available_at<=$1) THEN 1 END) AS ready,COUNT(CASE WHEN t.status='ready' AND t.available_at>$2 THEN 1 END) AS deferred,COUNT(CASE WHEN t.status='ready' AND t.available_at>$3 AND t.task_type='activity' THEN 1 END) AS activity_retries,COUNT(CASE WHEN t.status='ready' AND t.available_at>$4 AND t.task_type='timer' THEN 1 END) AS timers FROM workflow_tasks t WHERE t.namespace=$5 AND t.queue=$6 AND t.status IN ('ready','leased') AND ($7='task_queue' OR ($8 IS NULL AND (t.compatibility IS NULL OR t.compatibility='')) OR t.compatibility=$9)";
const TASK_SAMPLE: &str = "SELECT t.id,t.workflow_run_id,t.task_type,t.status,t.available_at,t.lease_expires_at FROM workflow_tasks t WHERE t.namespace=$1 AND t.queue=$2 AND t.status IN ('ready','leased') AND ($3='task_queue' OR ($4 IS NULL AND (t.compatibility IS NULL OR t.compatibility='')) OR t.compatibility=$5) ORDER BY t.id LIMIT 60";
const ACTIVITIES: &str = "SELECT COUNT(*) AS total FROM activity_executions a JOIN workflow_runs r ON r.id=a.workflow_run_id WHERE r.namespace=$1 AND a.status IN ('pending','running') AND (a.queue=$2 OR r.queue=$3) AND ($4='task_queue' OR ($5 IS NULL AND (r.compatibility IS NULL OR r.compatibility='')) OR r.compatibility=$6)";
const ACTIVITY_SAMPLE: &str = "SELECT a.id,a.workflow_run_id,a.activity_type,a.status,a.queue,a.attempt_count FROM activity_executions a JOIN workflow_runs r ON r.id=a.workflow_run_id WHERE r.namespace=$1 AND a.status IN ('pending','running') AND (a.queue=$2 OR r.queue=$3) AND ($4='task_queue' OR ($5 IS NULL AND (r.compatibility IS NULL OR r.compatibility='')) OR r.compatibility=$6) ORDER BY a.id LIMIT 20";
const VALIDATIONS: &str = "SELECT COUNT(*) AS total FROM workflow_update_validation_tasks v WHERE v.namespace=$1 AND v.task_queue=$2 AND v.status IN ('pending','leased') AND ($3='task_queue' OR ($4 IS NULL AND (v.compatibility IS NULL OR v.compatibility='')) OR v.compatibility=$5)";
const UNATTRIBUTED: &str = "SELECT COUNT(*) AS total FROM workflow_runs r WHERE r.namespace=$1 AND (r.queue IS NULL OR r.queue='') AND r.status IN ('pending','running','waiting')";
const UNATTRIBUTED_SAMPLE: &str = "SELECT r.id,r.workflow_instance_id,r.workflow_type,r.status FROM workflow_runs r WHERE r.namespace=$1 AND (r.queue IS NULL OR r.queue='') AND r.status IN ('pending','running','waiting') ORDER BY r.id LIMIT 20";
const SCHEDULES: &str = "SELECT schedule_id,status,action FROM workflow_schedules WHERE namespace=$1 AND status<>'deleted' ORDER BY schedule_id";
const QUEUE_KEYS: &str = "SELECT DISTINCT COALESCE(build_id,'') AS build_key FROM workflow_worker_registrations WHERE namespace=$1 AND task_queue=$2";
const STAMP_QUEUE: &str = "UPDATE workflow_worker_registrations SET status='draining' WHERE namespace=$1 AND task_queue=$2";

store_methods! {
    /// Every live dependency of the target, observed in `tx`.
    async fn retirement_inventory(&self, tx: &mut Transaction<'_, DB>, task_queue: &str, target: &Target) -> Result<Inventory> {
        let namespace = self.namespace.as_ref();
        let (mode, build) = (target.mode(), target.build());
        let at = now();
        let mut categories = Vec::new();

        let runs = Self::query(RUNS).bind(namespace).bind(task_queue).bind(mode).bind(build).bind(build)
            .fetch_one(&mut **tx).await?;
        let mut open = Category::new("open_runs", DB::number(&runs, "total")?);
        for (field, name) in [("parked", "waiting_runs"), ("continued", "continued_runs"), ("children", "child_runs")] {
            open.detail.insert(name.into(), json!(DB::number(&runs, field)?));
        }
        if open.count > 0 {
            for row in Self::query(RUN_SAMPLE).bind(namespace).bind(task_queue).bind(mode).bind(build).bind(build)
                .fetch_all(&mut **tx).await?
            {
                open.sample.push(json!({"workflow_id":DB::string(&row, "workflow_instance_id")?,"run_id":DB::string(&row, "id")?,
                    "workflow_type":DB::string(&row, "workflow_type")?,"status":DB::string(&row, "status")?,
                    "run_number":DB::number(&row, "run_number")?,
                    "build_id":DB::optional_string(&row, "compatibility")?.filter(|build| !build.is_empty())}));
            }
        }
        categories.push(open);

        let time = || DB::bind_time(at);
        let tasks = Self::query(TASKS).bind(time()).bind(time()).bind(time()).bind(time())
            .bind(namespace).bind(task_queue).bind(mode).bind(build).bind(build).fetch_one(&mut **tx).await?;
        let mut ready = Category::new("ready_tasks", DB::number(&tasks, "ready")?);
        let mut leased = Category::new("leased_tasks", DB::number(&tasks, "leased")?);
        let mut delayed = Category::new("delayed_tasks", DB::number(&tasks, "deferred")?);
        delayed.detail.insert("activity_retries".into(), json!(DB::number(&tasks, "activity_retries")?));
        delayed.detail.insert("timers".into(), json!(DB::number(&tasks, "timers")?));
        if ready.count + leased.count + delayed.count > 0 {
            for row in Self::query(TASK_SAMPLE).bind(namespace).bind(task_queue).bind(mode).bind(build).bind(build)
                .fetch_all(&mut **tx).await?
            {
                let available = DB::optional_instant(&row, "available_at")?;
                let status = DB::string(&row, "status")?;
                let category = if status == "leased" { &mut leased }
                    else if available.is_some_and(|available| available > at) { &mut delayed }
                    else { &mut ready };
                if category.sample.len() < SAMPLE {
                    category.sample.push(json!({"task_id":DB::string(&row, "id")?,"run_id":DB::string(&row, "workflow_run_id")?,
                        "task_type":DB::string(&row, "task_type")?,"status":status,
                        "available_at":available.map(instant),
                        "lease_expires_at":DB::optional_instant(&row, "lease_expires_at")?.map(instant)}));
                }
            }
        }
        categories.extend([ready, leased, delayed]);

        let total = Self::query(ACTIVITIES).bind(namespace).bind(task_queue).bind(task_queue).bind(mode).bind(build).bind(build)
            .fetch_one(&mut **tx).await?;
        let mut activities = Category::new("open_activities", DB::number(&total, "total")?);
        if activities.count > 0 {
            let mut queues = std::collections::BTreeSet::new();
            for row in Self::query(ACTIVITY_SAMPLE).bind(namespace).bind(task_queue).bind(task_queue).bind(mode).bind(build).bind(build)
                .fetch_all(&mut **tx).await?
            {
                let queue = DB::optional_string(&row, "queue")?;
                if let Some(queue) = &queue {
                    queues.insert(queue.clone());
                }
                activities.sample.push(json!({"activity_execution_id":DB::string(&row, "id")?,
                    "run_id":DB::string(&row, "workflow_run_id")?,"activity_type":DB::string(&row, "activity_type")?,
                    "status":DB::string(&row, "status")?,"task_queue":queue,"attempt_count":DB::number(&row, "attempt_count")?}));
            }
            activities.detail.insert("recorded_task_queues".into(), json!(queues));
        }
        categories.push(activities);

        let validations = Self::query(VALIDATIONS).bind(namespace).bind(task_queue).bind(mode).bind(build).bind(build)
            .fetch_one(&mut **tx).await?;
        categories.push(Category::new("update_validation_tasks", DB::number(&validations, "total")?));

        if let Target::Queue = target {
            let mut schedules = Category::new("schedules", 0);
            for row in Self::query(SCHEDULES).bind(namespace).fetch_all(&mut **tx).await? {
                // `ScheduleExecution`: a blank or missing queue starts on `default`.
                let action = DB::optional_document_row(&row, "action")?.unwrap_or(Value::Null);
                let queue = action["task_queue"].as_str().map(str::trim).filter(|queue| !queue.is_empty()).unwrap_or("default");
                if queue != task_queue {
                    continue;
                }
                schedules.count += 1;
                if schedules.sample.len() < SAMPLE {
                    schedules.sample.push(json!({"schedule_id":DB::string(&row, "schedule_id")?,"status":DB::string(&row, "status")?}));
                }
            }
            categories.push(schedules);
        }

        let unattributed = Self::query(UNATTRIBUTED).bind(namespace).fetch_one(&mut **tx).await?;
        let mut unknown = Category::new("unattributed_runs", DB::number(&unattributed, "total")?);
        if unknown.count > 0 {
            for row in Self::query(UNATTRIBUTED_SAMPLE).bind(namespace).fetch_all(&mut **tx).await? {
                unknown.sample.push(json!({"workflow_id":DB::string(&row, "workflow_instance_id")?,"run_id":DB::string(&row, "id")?,
                    "workflow_type":DB::string(&row, "workflow_type")?,"status":DB::string(&row, "status")?}));
            }
        }
        categories.push(unknown);
        Ok(Inventory { categories, observed_at: at })
    }

    /// The inventory and the action's own preconditions.
    async fn evaluate_retirement(&self, tx: &mut Transaction<'_, DB>, task_queue: &str, target: &Target) -> Result<Evaluation> {
        let namespace = self.namespace.as_ref();
        let inventory = self.retirement_inventory(tx, task_queue, target).await?;
        let rollouts = Self::rollouts(tx, namespace, task_queue).await?;
        let selected = deployments::selected_key(&rollouts).map(str::to_owned);
        let mut refusals = Vec::new();
        let (scope, keys, deployment) = match target {
            Target::Build(build) => {
                let scope = json!({"namespace":namespace,"task_queue":task_queue,"build_id":build,"scope":"build"});
                let found = Self::find(tx, namespace, task_queue, build.as_deref()).await?;
                let name = format!("{namespace}/{task_queue}@{}", build.as_deref().unwrap_or("unversioned"));
                if found.is_none() {
                    refusals.push(Refusal { reason: "unknown_deployment",
                        message: format!("No deployment is registered for {name}; nothing to retire."),
                        expected: "Name a build id that a worker registered or a rollout recorded on this task queue.".into() });
                }
                if selected.as_deref() == Some(deployments::key(build.as_deref()).as_str()) {
                    refusals.push(Refusal { reason: "selected_for_new_starts",
                        message: format!("Deployment {name} is the build new starts on this task queue are pinned to."),
                        expected: "Promote the replacement build first, then retire this one once its pinned work is gone.".into() });
                }
                let view = found.as_ref().map(|deployment| deployment.view(selected.as_deref(), deployments::iso));
                (scope, vec![deployments::key(build.as_deref())], view)
            }
            Target::Queue => {
                let scope = json!({"namespace":namespace,"task_queue":task_queue,"scope":"task_queue"});
                let mut keys: std::collections::BTreeSet<String> = rollouts.iter().map(|rollout| rollout.key.clone()).collect();
                for row in Self::query(QUEUE_KEYS).bind(namespace).bind(task_queue).fetch_all(&mut **tx).await? {
                    keys.insert(deployments::key(DB::optional_string(&row, "build_key")?.as_deref()));
                }
                if keys.is_empty() {
                    refusals.push(Refusal { reason: "unknown_task_queue",
                        message: format!("No worker registered and no build rollout was recorded on task queue [{task_queue}]; nothing to retire."),
                        expected: "Name a task queue whose workers registered with this namespace.".into() });
                }
                (scope, keys.into_iter().collect(), None)
            }
        };
        if !inventory.complete() {
            refusals.push(Refusal { reason: "inventory_incomplete",
                message: "The dependency inventory is incomplete: open runs in this namespace have no recorded task queue, so they could depend on this target.".into(),
                expected: "Close or repair the listed unattributed runs, inspect again, then retry. Missing inventory is never permission to retire.".into() });
        }
        if inventory.blockers().any(|category| category.kind != "unattributed_runs") {
            let summary: Vec<String> = inventory.blockers().filter(|category| category.kind != "unattributed_runs")
                .map(|category| format!("{} {}", category.count, category.kind.replace('_', " "))).collect();
            refusals.push(Refusal { reason: "live_dependencies",
                message: format!("Retirement is refused while live dependencies remain: {}.", summary.join(", ")),
                expected: "Nothing was changed; recorded build pins, queues, history and deadlines are kept. Let the listed work finish under a compatible worker or close it, inspect again, then retry.".into() });
        }
        // The rollout state the revision binds, beside the inventory.
        let state: Vec<Value> = rollouts.iter().filter(|rollout| keys.contains(&rollout.key))
            .map(|rollout| json!([rollout.key, rollout.drain_intent, deployments::zulu(rollout.promoted_at), deployments::zulu(rollout.rolled_back_at)]))
            .collect();
        let state = json!({"rollouts":state,"selected":selected});
        let revision = inventory.revision(&scope, &state);
        Ok(Evaluation { inventory, scope, keys, deployment, revision, refusals })
    }

    /// `GET .../retirement`: a read-only preview. It is not a barrier; the
    /// action repeats the same check atomically.
    pub(crate) async fn retirement(&self, task_queue: &str, target: Target) -> Result<Value> {
        let mut tx = self.begin_read().await?;
        let evaluation = self.evaluate_retirement(&mut tx, task_queue, &target).await?;
        tx.commit().await?;
        Ok(evaluation.view())
    }

    /// `POST .../retire`: inside one serialized transition, which starts,
    /// continuations, retries and task creation also take, observe the
    /// inventory again and record retirement only when it is empty.
    pub(crate) async fn retire(&self, task_queue: &str, target: Target, body: &Value, retired_by: Option<Value>) -> Result<(StatusCode, Value)> {
        let expected = expected_revision(body)?;
        let namespace = self.namespace.as_ref();
        let mut tx = self.begin().await?;
        let mut evaluation = self.evaluate_retirement(&mut tx, task_queue, &target).await?;
        if let Some(expected) = &expected && *expected != evaluation.revision {
            evaluation.refusals.push(Refusal { reason: "inventory_changed",
                message: "The dependency inventory changed after the inspection this request names.".into(),
                expected: "Inspect retirement again, review the new inventory, then retry with its revision.".into() });
        }
        if !evaluation.refusals.is_empty() {
            // Nothing was written; end the transition.
            tx.commit().await?;
            return Ok((StatusCode::CONFLICT, evaluation.rejection()));
        }
        let at = deployments::second();
        for key in &evaluation.keys {
            let build = deployments::public(key);
            Self::upsert(&mut tx, namespace, task_queue, build.as_deref(), |row| {
                if !(row.drain_intent == deployments::DRAINING || row.drain_intent == RETIRED) || row.drained_at.is_none() {
                    row.drained_at = Some(at);
                }
                row.drain_intent = RETIRED.to_owned();
            }).await?;
            if let Target::Build(_) = target {
                Self::stamp_workers(&mut tx, namespace, task_queue, build.as_deref(), deployments::DRAINING, false).await?;
            }
        }
        if let Target::Queue = target {
            Self::query(STAMP_QUEUE).bind(namespace).bind(task_queue).execute(&mut *tx).await?;
        }
        let selected = Self::selected(&mut tx, namespace, task_queue).await?;
        let deployment = match &target {
            Target::Build(build) => Self::find(&mut tx, namespace, task_queue, build.as_deref()).await?
                .map(|deployment| deployment.view(selected.as_deref(), deployments::iso)),
            Target::Queue => None,
        };
        tx.commit().await?;
        let mut response = evaluation.view();
        let object = response.as_object_mut().expect("object");
        object.insert("retirable".into(), json!(true));
        object.insert("outcome".into(), json!("retired"));
        object.insert("command_status".into(), json!("accepted"));
        object.insert("command_source".into(), json!("control_plane"));
        object.insert("changed".into(), json!(true));
        object.insert("retired_at".into(), deployments::zulu(Some(at)));
        object.insert("retired_by".into(), retired_by.unwrap_or(Value::Null));
        object.insert("message".into(), json!("Retired: no live dependency was observed in the transition that recorded retirement. Its workers are marked draining and claim nothing."));
        object.insert("expected_resolution".into(), Value::Null);
        if let Some(observation) = object.get_mut("observation") {
            observation["atomic_with_action"] = json!(true);
        }
        if let Target::Build(_) = target {
            object.insert("deployment".into(), deployment.unwrap_or(Value::Null));
        }
        Ok((StatusCode::OK, response))
    }

    /// `GET /api/deployments/{name}/retirement`.
    pub(crate) async fn deployment_retirement(&self, name: &str) -> Result<Value> {
        let (task_queue, build) = self.deployment_scope(name)?;
        self.retirement(&task_queue, Target::Build(build)).await
    }

    /// `POST /api/deployments/{name}/retire`.
    pub(crate) async fn deployment_retire(&self, name: &str, body: &Value, retired_by: Option<Value>) -> Result<(StatusCode, Value)> {
        let (task_queue, build) = self.deployment_scope(name)?;
        self.retire(&task_queue, Target::Build(build), body, retired_by).await
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn inventory(counts: &[(&'static str, i64)]) -> Inventory {
        Inventory { categories: counts.iter().map(|(kind, count)| Category::new(kind, *count)).collect(),
            observed_at: DateTime::from_timestamp(0, 0).unwrap() }
    }

    #[test]
    fn unattributed_runs_make_the_inventory_incomplete_not_empty() {
        let empty = inventory(&[("open_runs", 0), ("unattributed_runs", 0)]);
        assert!(empty.complete());
        assert_eq!(empty.blockers().count(), 0);
        let unknown = inventory(&[("open_runs", 0), ("unattributed_runs", 2)]);
        assert!(!unknown.complete());
        assert_eq!(unknown.blockers().count(), 1);
    }

    #[test]
    fn the_revision_binds_scope_state_and_counts_but_not_the_clock() {
        let scope = json!({"namespace":"default","task_queue":"q","build_id":"v1","scope":"build"});
        let state = json!({"rollouts":[],"selected":null});
        let first = inventory(&[("open_runs", 0)]);
        let mut later = inventory(&[("open_runs", 0)]);
        later.observed_at = DateTime::from_timestamp(99, 0).unwrap();
        assert_eq!(first.revision(&scope, &state), later.revision(&scope, &state));
        assert!(first.revision(&scope, &state).starts_with("sha256:"));
        assert_ne!(first.revision(&scope, &state), inventory(&[("open_runs", 1)]).revision(&scope, &state));
        assert_ne!(first.revision(&scope, &state), first.revision(&json!({"scope":"task_queue"}), &state));
        assert_ne!(first.revision(&scope, &state), first.revision(&scope, &json!({"rollouts":[],"selected":"v1"})));
    }

    #[test]
    fn retire_bodies_accept_only_an_optional_revision_string() {
        assert_eq!(expected_revision(&Value::Null).unwrap(), None);
        assert_eq!(expected_revision(&json!({})).unwrap(), None);
        assert_eq!(expected_revision(&json!({"expected_inventory_revision":"sha256:ab"})).unwrap().as_deref(), Some("sha256:ab"));
        assert!(expected_revision(&json!({"expected_inventory_revision":7})).is_err());
        assert!(expected_revision(&json!({"expected_inventory_revision":""})).is_err());
        assert!(expected_revision(&json!([])).is_err());
    }

    #[test]
    fn a_blocker_view_is_bounded_and_names_its_recovery() {
        let mut category = Category::new("delayed_tasks", 25);
        category.sample = (0..SAMPLE).map(|index| json!(index)).collect();
        let view = category.view();
        assert_eq!(view["sample_truncated"], true);
        assert_eq!(view["sample"].as_array().unwrap().len(), SAMPLE);
        assert!(view["recovery"].as_str().unwrap().contains("delayed retries"));
    }
}
