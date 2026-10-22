//! Published PHP's `WorkflowStartVersionPin`: the worker cohort a new run
//! starts in and the workflow definition fingerprint that cohort agrees on.
//! `WorkflowStarted` records that fingerprint with source `worker`, so
//! queries and later tasks can tell whether a worker runs the original
//! definition. Native has no build-id rollouts, so PHP's promoted-rollout
//! cohort never applies.
use serde_json::Value;

/// One active registration on the start's task queue.
pub(super) struct CohortWorker {
    pub(super) build_id: Option<String>,
    pub(super) workflow_types: Value,
    pub(super) fingerprints: Value,
    pub(super) heartbeated: bool,
}

#[derive(Debug, PartialEq)]
enum Cohort {
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

/// `resolveForStart` then `definitionFingerprintForCohort`: the fingerprint
/// every worker in the start's cohort that supports the type advertises, or
/// `None` when any of them has not heartbeated, advertises none, or disagrees.
pub(super) fn fingerprint(workers: &[CohortWorker], workflow_type: &str) -> Option<String> {
    let workflow_type = workflow_type.trim();
    let cohort = cohort(workers.iter().filter(|worker| worker.supports(workflow_type)))
        .or_else(|| cohort(workers.iter()))?;
    let mut agreed: Option<String> = None;
    for worker in workers.iter().filter(|worker| worker.supports(workflow_type)) {
        let member = match &cohort {
            Cohort::Build(build) => worker.build() == Some(build.as_str()),
            Cohort::Unversioned => worker.build().is_none(),
        };
        if !member {
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
}
