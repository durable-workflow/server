//! Published PHP's `WorkflowStartVersionPin`: the worker cohort a new run
//! starts in and the workflow definition fingerprint that cohort agrees on.
//! `WorkflowStarted` records that fingerprint with source `worker`, so
//! queries and later tasks can tell whether a worker runs the original
//! definition. Native has no build-id rollouts, so PHP's promoted-rollout
//! cohort never applies. The cohort is also the run's build pin: a run
//! started in one build's cohort is stamped with that build as its
//! `compatibility`, and only that build's workers take its tasks.
use serde_json::Value;

/// One active registration on the start's task queue.
pub(super) struct CohortWorker {
    pub(super) build_id: Option<String>,
    pub(super) workflow_types: Value,
    pub(super) fingerprints: Value,
    pub(super) heartbeated: bool,
}

#[derive(Debug, PartialEq)]
pub(super) enum Cohort {
    Build(String),
    Unversioned,
}

impl CohortWorker {
    fn build(&self) -> Option<&str> {
        self.build_id.as_deref().map(str::trim).filter(|build| !build.is_empty())
    }

    /// `workerSupportsWorkflowType`.
    fn supports(&self, workflow_type: &str) -> bool {
        self.workflow_types
            .as_array()
            .is_some_and(|types| types.iter().any(|kind| kind.as_str().is_some_and(|kind| kind.trim() == workflow_type)))
    }
}

/// `cohortFromWorkers`: one build id, or only unversioned workers.
fn cohort<'a>(workers: impl Iterator<Item = &'a CohortWorker>) -> Option<Cohort> {
    let (mut builds, mut unversioned) = (std::collections::BTreeSet::new(), false);
    for worker in workers {
        match worker.build() {
            Some(build) => {
                builds.insert(build);
            }
            None => unversioned = true,
        }
    }
    match builds.len() {
        1 => builds.pop_first().map(|build| Cohort::Build(build.to_owned())),
        0 if unversioned => Some(Cohort::Unversioned),
        _ => None,
    }
}

impl Cohort {
    /// The build a run started in this cohort is pinned to.
    pub(super) fn pin(&self) -> Option<&str> {
        match self {
            Self::Build(build) => Some(build),
            Self::Unversioned => None,
        }
    }

    /// The cohort of a run already pinned (or not) to a build, as redrive
    /// and the declared contract look it up.
    pub(super) fn of_run(compatibility: Option<&str>) -> Self {
        match compatibility.map(str::trim).filter(|build| !build.is_empty()) {
            Some(build) => Self::Build(build.to_owned()),
            None => Self::Unversioned,
        }
    }

    /// Whether a registration with this build id belongs to the cohort.
    pub(super) fn admits(&self, build_id: Option<&str>) -> bool {
        let build_id = build_id.map(str::trim).filter(|build| !build.is_empty());
        match self {
            Self::Build(build) => build_id == Some(build.as_str()),
            Self::Unversioned => build_id.is_none(),
        }
    }
}

/// `resolveForStart` without a promoted rollout: the one cohort among the
/// type's live workers, else the one cohort on the queue, else none.
pub(super) fn resolve(workers: &[CohortWorker], workflow_type: &str) -> Option<Cohort> {
    let workflow_type = workflow_type.trim();
    cohort(workers.iter().filter(|worker| worker.supports(workflow_type))).or_else(|| cohort(workers.iter()))
}

/// `resolveForStart` then `definitionFingerprintForCohort`.
#[cfg(test)]
fn fingerprint(workers: &[CohortWorker], workflow_type: &str) -> Option<String> {
    fingerprint_for(workers, workflow_type, &resolve(workers, workflow_type)?)
}

/// `definitionFingerprintForCohort`: the fingerprint every worker in the
/// cohort that supports the type advertises, or `None` when any of them has
/// not heartbeated, advertises none, or disagrees.
pub(super) fn fingerprint_for(workers: &[CohortWorker], workflow_type: &str, cohort: &Cohort) -> Option<String> {
    let workflow_type = workflow_type.trim();
    let mut agreed: Option<String> = None;
    for worker in workers.iter().filter(|worker| worker.supports(workflow_type)) {
        if !cohort.admits(worker.build()) {
            continue;
        }
        let advertised = worker.fingerprints.get(workflow_type).and_then(Value::as_str)?;
        if !worker.heartbeated || advertised.trim().is_empty() || advertised.len() > 255 {
            return None;
        }
        let advertised = advertised.trim();
        if agreed.as_deref().is_some_and(|agreed| agreed != advertised) {
            return None;
        }
        agreed = Some(advertised.to_owned());
    }
    agreed
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn worker(build: Option<&str>, types: Value, fingerprints: Value) -> CohortWorker {
        CohortWorker { build_id: build.map(str::to_owned), workflow_types: types, fingerprints, heartbeated: true }
    }

    #[test]
    fn the_cohort_must_agree_on_one_advertised_definition() {
        let a = || worker(None, json!(["order"]), json!({"order": " sha256:a "}));
        assert_eq!(fingerprint(&[a(), a()], "order").as_deref(), Some("sha256:a"));
        assert_eq!(fingerprint(&[], "order"), None);
        // Disagreement, a missing advertisement or no heartbeat leaves none.
        let b = worker(None, json!(["order"]), json!({"order": "sha256:b"}));
        assert_eq!(fingerprint(&[a(), b], "order"), None);
        let silent = worker(None, json!(["order"]), json!({}));
        assert_eq!(fingerprint(&[a(), silent], "order"), None);
        let mut unseen = a();
        unseen.heartbeated = false;
        assert_eq!(fingerprint(&[a(), unseen], "order"), None);
        // Workers without the type neither join nor block the cohort.
        let other = worker(None, json!(["invoice"]), json!({}));
        assert_eq!(fingerprint(&[a(), other], "order").as_deref(), Some("sha256:a"));
    }

    #[test]
    fn build_ids_choose_the_cohort_like_php() {
        let built = |build: &str, fingerprint: &str| worker(Some(build), json!(["order"]), json!({"order": fingerprint}));
        // One build among the type's workers: only that build counts.
        let unversioned = worker(None, json!(["invoice"]), json!({}));
        assert_eq!(fingerprint(&[built("v1", "f1"), unversioned], "order").as_deref(), Some("f1"));
        // Two builds have no cohort. One build beside unversioned workers is
        // that build's cohort, and the unversioned workers do not count.
        assert_eq!(fingerprint(&[built("v1", "f1"), built("v2", "f2")], "order"), None);
        let mixed = worker(None, json!(["order"]), json!({"order": "f9"}));
        assert_eq!(fingerprint(&[built("v1", "f1"), mixed], "order").as_deref(), Some("f1"));
        // No worker supports the type: the queue's single cohort has no member.
        assert_eq!(fingerprint(&[built("v1", "f1")], "invoice"), None);
    }

    #[test]
    fn the_cohort_is_the_run_s_build_pin_like_php() {
        let built = |build: &str, types: Value| worker(Some(build), types, json!({}));
        let pin = |workers: &[CohortWorker], kind: &str| resolve(workers, kind).and_then(|cohort| cohort.pin().map(str::to_owned));
        // One build on the queue pins every start to it.
        assert_eq!(pin(&[built("v1", json!(["echo", "solo"]))], "echo").as_deref(), Some("v1"));
        // Two builds for the type leave the run unpinned; a type only one
        // build serves still pins to that build.
        let fleet = [built("v1", json!(["echo", "solo"])), built("v2", json!(["echo"]))];
        assert_eq!(pin(&fleet, "echo"), None);
        assert_eq!(pin(&fleet, "solo").as_deref(), Some("v1"));
        // Unversioned workers alone, or none at all, pin nothing.
        assert_eq!(pin(&[worker(None, json!(["echo"]), json!({}))], "echo"), None);
        assert_eq!(resolve(&[worker(None, json!(["echo"]), json!({}))], "echo"), Some(Cohort::Unversioned));
        assert_eq!(resolve(&[], "echo"), None);
        assert!(Cohort::of_run(Some(" v1 ")).admits(Some("v1")));
        assert!(!Cohort::of_run(Some("v1")).admits(None));
        assert!(Cohort::of_run(Some("")).admits(Some(" ")));
    }
}
