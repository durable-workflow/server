//! Published PHP's `WorkflowQueryTaskBroker::registeredQueryRoute`: whether a
//! fresh worker on the run's task queue can take the run's query tasks. The
//! checks run in PHP's order, and the first one that leaves no worker names
//! the refusal: an active worker, query-task capability, the workflow type,
//! the run's compatibility and its recorded definition fingerprint.
//!
//! Native counts a worker as query-capable only when it registered the
//! `query_tasks` capability, because only such workers can claim native query
//! tasks. PHP also counts a worker that recently polled for query tasks.
use serde_json::Value;

/// `WorkflowQueryTaskBroker::queryRouteResult`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub(super) struct QueryRoute {
    pub(super) servable: bool,
    pub(super) reason: Option<&'static str>,
    pub(super) message: Option<String>,
    pub(super) task_queue: String,
    pub(super) counts: [usize; 4],
}

impl QueryRoute {
    fn refused(reason: &'static str, message: String, task_queue: &str, counts: [usize; 4]) -> Self {
        Self { servable: false, reason: Some(reason), message: Some(message), task_queue: task_queue.to_owned(), counts }
    }
}

/// One registration as the route checks read it.
pub(super) struct Worker {
    pub(super) capabilities: Vec<String>,
    pub(super) workflow_types: Vec<String>,
    pub(super) build_id: Option<String>,
    pub(super) fingerprints: Value,
}

/// `stringArray`: trimmed, non-empty strings.
pub(super) fn strings(value: &Value) -> Vec<String> {
    value
        .as_array()
        .map(|items| items.iter().filter_map(|item| trimmed(item.as_str())).map(str::to_owned).collect())
        .unwrap_or_default()
}

pub(super) fn trimmed(value: Option<&str>) -> Option<&str> {
    value.map(str::trim).filter(|text| !text.is_empty())
}

/// The route for a run, given its queue, type, compatibility and
/// `WorkflowStarted` payload, over the queue's active registrations.
pub(super) fn route(
    task_queue: &str,
    workflow_type: Option<&str>,
    compatibility: Option<&str>,
    started: &Value,
    workers: &[Worker],
) -> QueryRoute {
    let active = workers.len();
    if active == 0 {
        return QueryRoute::refused(
            "query_worker_unavailable",
            format!("No active worker is registered on task queue [{task_queue}]."),
            task_queue,
            [0; 4],
        );
    }
    let query: Vec<_> = workers.iter().filter(|worker| worker.capabilities.iter().any(|value| value == "query_tasks")).collect();
    if query.is_empty() {
        return QueryRoute::refused(
            "query_worker_unavailable",
            format!("Active workers are registered on task queue [{task_queue}], but none are accepting workflow query tasks."),
            task_queue,
            [active, 0, 0, 0],
        );
    }
    let type_label = workflow_type.unwrap_or("unknown");
    let typed: Vec<_> = query
        .iter()
        .filter(|worker| workflow_type.is_some_and(|kind| worker.workflow_types.iter().any(|value| value == kind)))
        .collect();
    if typed.is_empty() {
        return QueryRoute::refused(
            "query_worker_incompatible",
            format!("Query-capable workers on task queue [{task_queue}] do not advertise workflow type [{type_label}]."),
            task_queue,
            [active, query.len(), 0, 0],
        );
    }
    // `queryTaskCompatibilityScope`: an unversioned run with declared queries
    // is served only by unversioned workers.
    let declares_queries = started["declared_queries"].as_array().is_some_and(|items| !items.is_empty())
        || started["declared_query_contracts"].as_array().is_some_and(|items| !items.is_empty())
        || started["declared_query_contracts"].as_object().is_some_and(|items| !items.is_empty());
    let unversioned = compatibility.is_none() && declares_queries;
    let built: Vec<_> = typed
        .iter()
        .filter(|worker| match compatibility {
            Some(required) => worker.build_id.as_deref() == Some(required),
            None if unversioned => worker.build_id.is_none(),
            None => true,
        })
        .collect();
    if built.is_empty() {
        let label = compatibility.unwrap_or(if unversioned { "unversioned" } else { "legacy" });
        return QueryRoute::refused(
            "query_worker_incompatible",
            format!("Query-capable workers on task queue [{task_queue}] support workflow type [{type_label}], but none match run compatibility [{label}]."),
            task_queue,
            [active, query.len(), typed.len(), 0],
        );
    }
    let recorded = trimmed(started["workflow_definition_fingerprint"].as_str());
    let compatible = built
        .iter()
        .filter(|worker| match recorded {
            None => true,
            Some(recorded) => workflow_type
                .and_then(|kind| trimmed(worker.fingerprints[kind].as_str()))
                .is_some_and(|advertised| advertised == recorded),
        })
        .count();
    if compatible == 0 {
        return QueryRoute::refused(
            "query_worker_incompatible",
            format!("Query-capable workers on task queue [{task_queue}] support workflow type [{type_label}], but none advertise the recorded workflow definition fingerprint."),
            task_queue,
            [active, query.len(), typed.len(), 0],
        );
    }
    QueryRoute {
        servable: true,
        reason: None,
        message: None,
        task_queue: task_queue.to_owned(),
        counts: [active, query.len(), typed.len(), compatible],
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn worker(capabilities: &[&str], types: &[&str], build_id: Option<&str>, fingerprints: Value) -> Worker {
        Worker {
            capabilities: capabilities.iter().map(|value| (*value).to_owned()).collect(),
            workflow_types: types.iter().map(|value| (*value).to_owned()).collect(),
            build_id: build_id.map(str::to_owned),
            fingerprints,
        }
    }

    #[test]
    fn route_refusals_follow_php_order() {
        let started = json!({"declared_queries":["status"]});
        let refused = |workers: &[Worker], compatibility| route("orders", Some("echo"), compatibility, &started, workers);
        assert_eq!(refused(&[], None).message.unwrap(), "No active worker is registered on task queue [orders].");
        let plain = worker(&[], &["echo"], None, json!({}));
        assert_eq!(refused(&[plain], None).counts, [1, 0, 0, 0]);
        let other = worker(&["query_tasks"], &["other"], None, json!({}));
        let route_other = refused(&[other], None);
        assert_eq!(route_other.reason, Some("query_worker_incompatible"));
        assert!(route_other.message.unwrap().ends_with("do not advertise workflow type [echo]."));
        // A declared query makes an unversioned run need an unversioned worker.
        let built = worker(&["query_tasks"], &["echo"], Some("b1"), json!({}));
        assert!(refused(std::slice::from_ref(&built), None).message.unwrap().ends_with("match run compatibility [unversioned]."));
        assert!(refused(std::slice::from_ref(&built), Some("b1")).servable);
        assert!(refused(std::slice::from_ref(&built), Some("b2")).message.unwrap().ends_with("match run compatibility [b2]."));
        let open = worker(&["query_tasks"], &["echo"], None, json!({}));
        assert_eq!(refused(&[open], None).counts, [1, 1, 1, 1]);
    }

    #[test]
    fn recorded_fingerprints_must_match() {
        let started = json!({"workflow_definition_fingerprint":"sha256:a"});
        let matching = worker(&["query_tasks"], &["echo"], Some("b1"), json!({"echo":" sha256:a "}));
        let stale = worker(&["query_tasks"], &["echo"], Some("b1"), json!({"echo":"sha256:b"}));
        assert!(route("q", Some("echo"), None, &started, &[matching]).servable);
        let refused = route("q", Some("echo"), None, &started, &[stale]);
        assert!(refused.message.unwrap().ends_with("recorded workflow definition fingerprint."));
        // Without declared queries an unversioned run accepts any build.
        assert!(route("q", Some("echo"), None, &json!({}), &[worker(&["query_tasks"], &["echo"], Some("b9"), json!({}))]).servable);
    }
}
