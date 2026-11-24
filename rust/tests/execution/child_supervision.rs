use super::*;

// Core workflow#868 and workflow#1107 (Workflow 2.26.0, Server #1184): a run
// that continues as new while children it started are still open hands their
// supervision to the continued run in the same transaction. The child link,
// call and policy stay as recorded; the chain's final run applies each policy
// when it closes and records the outcome in its own history. Nothing is
// appended to a closed predecessor. A handed-over child that fails while the
// chain still supervises it is retried under the policy its parent recorded.
// These follow Core's `V2ContinueAsNewOpenChildSupervisionTest` and
// `V2WorkflowTaskBridgeTest` cases.

static TASKS: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);

fn child_with(policy: &str, max_attempts: Option<i64>) -> Value {
    let mut command = json!({"type":"start_child_workflow","workflow_type":"child",
        "arguments":envelope(Payload::Array(vec![Payload::Long(1)])),"parent_close_policy":policy});
    if let Some(max_attempts) = max_attempts {
        command["retry_policy"] = json!({"max_attempts":max_attempts,"backoff_seconds":[0]});
    }
    command
}

fn fail() -> Value {
    json!([{"type":"fail_workflow","message":"attempt failed","exception_type":"Boom","exception_class":"Boom","non_retryable":false}])
}

fn complete() -> Value {
    json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}])
}

fn events_of<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

async fn count(database: &TestDatabase, sql: String) -> i64 {
    task_retry::rows(database, Box::leak(sql.into_boxed_str())).await
}

/// The supervision rows naming `child_instance`: (supervising run, child run)
/// and (policy, sequence), in transfer order.
async fn supervisions(database: &TestDatabase, child_instance: &str) -> Vec<((String, String), (String, String))> {
    let filter = format!("FROM dw_child_supervisions WHERE child_workflow_instance_id='{child_instance}' ORDER BY created_at,id");
    let runs = text_rows(database, &format!("SELECT supervising_workflow_run_id,child_workflow_run_id {filter}"),
        &format!("SELECT supervising_workflow_run_id::text,child_workflow_run_id::text {filter}"),
        &format!("SELECT CAST(supervising_workflow_run_id AS CHAR),CAST(child_workflow_run_id AS CHAR) {filter}")).await;
    let policies = text_rows(database, &format!("SELECT parent_close_policy,CAST(sequence AS TEXT) {filter}"),
        &format!("SELECT parent_close_policy::text,sequence::text {filter}"),
        &format!("SELECT CAST(parent_close_policy AS CHAR),CAST(sequence AS CHAR) {filter}")).await;
    runs.into_iter().zip(policies).collect()
}

/// One text column of one row.
async fn text(database: &TestDatabase, column: &str, from: &str) -> String {
    let rows = text_rows(database, &format!("SELECT {column},'' {from}"),
        &format!("SELECT ({column})::text,'' {from}"),
        &format!("SELECT CAST({column} AS CHAR),'' {from}")).await;
    assert_eq!(rows.len(), 1, "{column} {from}");
    rows[0].0.clone()
}

/// Gives a waiting run its next workflow task, as a worker's next turn would.
async fn give_task(database: &TestDatabase, run: &Value) {
    let task = format!("01ZZZZZZZZZZZZZZZZZZZZZ{:03}", TASKS.fetch_add(1, std::sync::atomic::Ordering::Relaxed));
    let run = run.as_str().unwrap();
    database.execute(sqlx::AssertSqlSafe(format!("INSERT INTO workflow_tasks(id,workflow_run_id,task_type,status,payload,queue,available_at,namespace,created_at,updated_at,priority,fairness_key,fairness_weight) SELECT '{task}',id,'workflow','ready','{{}}',queue,created_at,namespace,created_at,created_at,priority,fairness_key,fairness_weight FROM workflow_runs WHERE id='{run}'"))).await;
}

async fn current(app: &Router, workflow: &str) -> Value {
    let (status, described) = request(app, "GET", &format!("/api/workflows/{workflow}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    described
}

async fn observe(app: &Router, path: &str) -> Value {
    let response = app.clone().oneshot(Request::builder().uri(path)
        .header("authorization", "Bearer test-token")
        .header("x-namespace", "default")
        .header("x-durable-workflow-control-plane-version", "2")
        .body(Body::empty()).unwrap()).await.unwrap();
    assert_eq!(response.status(), StatusCode::OK);
    serde_json::from_slice(&to_bytes(response.into_body(), 4 * 1024 * 1024).await.unwrap()).unwrap()
}

/// A parent chain whose first run started one child and then continued as
/// new `hops` times while the child stayed open.
struct Chain {
    workflow: String,
    runs: Vec<Value>,
    child: String,
    child_run: Value,
    link: Value,
    sequence: Value,
}

impl Chain {
    fn first(&self) -> &Value {
        &self.runs[0]
    }

    fn last(&self) -> &Value {
        self.runs.last().unwrap()
    }

    async fn history(&self, app: &Router, run: &Value) -> Value {
        run_history(app, &json!(self.workflow), run).await
    }

    /// The final run takes its task and completes.
    async fn complete(&self, app: &Router) {
        let task = poll(app, "parent-worker", "workflow").await;
        assert_eq!(task["run_id"], *self.last(), "{task}");
        let (status, body) = finish_task(app, &task, complete()).await;
        assert_eq!(status, StatusCode::OK, "{body}");
    }
}

async fn chain(app: &Router, database: &TestDatabase, workflow: &str, child: Value, hops: usize) -> Chain {
    if child["parent_close_policy"] == "request_cancellation" {
        // A `request_cancellation` child start needs a cooperative worker
        // on protocol 1.20.
        let registered = request_protocol(app, "POST", "/api/worker/register",
            json!({"worker_id":"parent-worker","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
                "supported_activity_types":[],"capabilities":["cooperative_cancellation"]}), "1.20").await;
        assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    } else {
        register(app, "parent-worker", json!(["echo"]), json!([])).await;
    }
    register(app, "child-worker", json!(["child"]), json!([])).await;
    let started = start(app, workflow).await;
    let (status, task) = request_protocol(app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"parent-worker","task_queue":"test","timeout_seconds":0}), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{task}");
    let task = task["task"].clone();
    let (status, body) = request_protocol(app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        completion(&task, json!([child])), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(app, &json!(workflow), &started["run_id"]).await;
    let scheduled = events_of(&history, "ChildWorkflowScheduled")[0]["payload"].clone();
    let mut runs = vec![started["run_id"].clone()];
    for hop in 0..hops {
        if hop == 0 {
            // The first run waits on its child; its next turn continues it.
            give_task(database, &runs[0]).await;
        }
        let task = poll(app, "parent-worker", "workflow").await;
        assert_eq!(task["run_id"], *runs.last().unwrap(), "{task}");
        let (status, body) = finish_task(app, &task, json!([{"type":"continue_as_new"}])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        let next = current(app, workflow).await["run_id"].clone();
        assert!(!runs.contains(&next), "{next}");
        runs.push(next);
    }
    Chain {
        workflow: workflow.to_owned(),
        runs,
        child: scheduled["child_workflow_instance_id"].as_str().unwrap().to_owned(),
        child_run: scheduled["child_workflow_run_id"].clone(),
        link: scheduled["workflow_link_id"].clone(),
        sequence: scheduled["sequence"].clone(),
    }
}

async fn assert_ends_with_continued(app: &Router, chain: &Chain, run: &Value) {
    let history = chain.history(app, run).await;
    assert_eq!(history.as_array().unwrap().last().unwrap()["event_type"], "WorkflowContinuedAsNew", "{history}");
}

fn s(value: &Value) -> String {
    value.as_str().unwrap().to_owned()
}

#[tokio::test]
async fn promoted_continuation_moves_supervision_and_keeps_the_child_build() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    super::continuations::register_target_worker(&app, "parent-a", Some("build-a"), "echo").await;
    super::continuations::register_target_worker(&app, "child-a", Some("build-a"), "child").await;
    let parent = start(&app, "promoted-parent").await;
    let first = poll(&app, "parent-a", "workflow").await;
    let (status, result) = finish_task(&app, &first, json!([child_with("request_cancel", None)])).await;
    assert_eq!(status, StatusCode::OK, "{result}");
    let history = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    let scheduled = &events_of(&history, "ChildWorkflowScheduled")[0]["payload"];
    let child_id = s(&scheduled["child_workflow_instance_id"]);
    let child_run = &scheduled["child_workflow_run_id"];
    assert_eq!(current(&app, &child_id).await["compatibility"], "build-a");

    super::continuations::register_target_worker(&app, "parent-b", Some("build-b"), "echo").await;
    let (status, result) = request(&app, "POST", "/api/task-queues/test/build-ids/promote", json!({"build_id":"build-b"})).await;
    assert_eq!(status, StatusCode::OK, "{result}");
    give_task(&database, &parent["run_id"]).await;
    let task = poll(&app, "parent-a", "workflow").await;
    let path = format!("/api/worker/workflow-tasks/{}/complete", s(&task["task_id"]));
    let (status, result) = request_protocol(&app, "POST", &path,
        completion(&task, json!([{"type":"continue_as_new","target_build":"promoted"}])), "1.21").await;
    assert_eq!(status, StatusCode::OK, "{result}");
    let successor = current(&app, "promoted-parent").await;
    assert_eq!(successor["compatibility"], "build-b");
    assert_eq!(supervisions(&database, &child_id).await, [((s(&successor["run_id"]), s(child_run)),
        ("request_cancel".to_owned(), scheduled["sequence"].to_string()))]);
    let child = current(&app, &child_id).await;
    assert_eq!(child["run_id"], *child_run);
    assert_eq!(child["compatibility"], "build-a");
    assert_eq!(child["status"], "pending");
    let task = poll(&app, "parent-b", "workflow").await;
    assert_eq!(task["run_id"], successor["run_id"]);
    assert_eq!(finish_task(&app, &task, complete()).await.0, StatusCode::OK);
    assert_eq!(current(&app, &child_id).await["status"], "cancelled");
    runtime.close().await;
    database.remove().await;
}

// `testContinueAsNewHandsOpenChildToTheContinuedRunWhichAppliesItsPolicy`.
#[tokio::test]
async fn continue_as_new_hands_an_open_child_to_the_continued_run_which_applies_its_policy() {
    for (policy, child_status, call_status) in
        [("request_cancel", "cancelled", "cancelled"), ("terminate", "terminated", "terminated"), ("abandon", "pending", "started")]
    {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let chain = chain(&app, &database, "handed-parent", child_with(policy, None), 1).await;
        let (first, last) = (chain.first().clone(), chain.last().clone());

        assert_eq!(supervisions(&database, &chain.child).await,
            [((s(&last), s(&chain.child_run)), (policy.to_owned(), chain.sequence.to_string()))], "{policy}");
        // The transfer records Core's columns: where it came from and the
        // child's original link, starting run and instance.
        let from = format!("FROM dw_child_supervisions WHERE supervising_workflow_run_id='{}'", s(&last));
        assert_eq!(text(&database, "transferred_from_workflow_run_id", &from).await, s(&first));
        assert_eq!(text(&database, "workflow_link_id", &from).await, s(&chain.link));
        assert_eq!(text(&database, "parent_workflow_run_id", &from).await, s(&first));
        assert_eq!(text(&database, "parent_workflow_instance_id", &from).await, "handed-parent");
        assert_eq!(text(&database, "namespace", &from).await, "default");
        // No reader of links sees the transfer.
        assert_eq!(count(&database, "SELECT COUNT(*) FROM workflow_links WHERE link_type NOT IN ('child_workflow','continue_as_new')".to_owned()).await, 0);
        // The link and call stay with the run that started the child.
        assert_eq!(count(&database, format!("SELECT COUNT(*) FROM workflow_links WHERE link_type='child_workflow' AND parent_workflow_run_id='{}' AND parent_close_policy='{policy}'", s(&first))).await, 1);
        assert_eq!(count(&database, format!("SELECT COUNT(*) FROM workflow_child_calls WHERE parent_workflow_run_id='{}'", s(&first))).await, 1);
        let predecessor = chain.history(&app, &first).await;
        assert_ends_with_continued(&app, &chain, &first).await;
        // The continued run's description, export and observation do not
        // list the child, and the child's parent is still the first run.
        let continued = chain.history(&app, &last).await;
        assert!(events_of(&continued, "ChildWorkflowScheduled").is_empty() && events_of(&continued, "ChildRunStarted").is_empty(), "{continued}");
        let observed = observe(&app, &format!("/api/workflows/handed-parent/runs/{}/observation", s(&last))).await;
        assert_eq!(observed["children"]["returned_count"], 0, "{observed}");
        let observed = observe(&app, &format!("/api/workflows/{}/runs/{}/observation", chain.child, s(&chain.child_run))).await;
        let parents = observed["parents"]["relationships"].as_array().unwrap();
        assert_eq!(parents.len(), 1, "{observed}");
        assert_eq!((parents[0]["link_id"].clone(), parents[0]["run_id"].clone()), (chain.link.clone(), first.clone()), "{observed}");

        chain.complete(&app).await;
        let (_, child) = request(&app, "GET", &format!("/api/workflows/{}", chain.child), Value::Null).await;
        assert_eq!((child["run_id"].clone(), child["status"].clone()), (chain.child_run.clone(), json!(child_status)), "{policy}: {child}");
        let history = chain.history(&app, &last).await;
        let applied = events_of(&history, "ParentClosePolicyApplied");
        assert!(events_of(&history, "ParentClosePolicyFailed").is_empty(), "{history}");
        if policy == "abandon" {
            assert!(applied.is_empty(), "{history}");
        } else {
            assert_eq!(applied.len(), 1, "{history}");
            assert_eq!(applied[0]["payload"], json!({"child_instance_id":chain.child,"child_run_id":chain.child_run,"policy":policy,
                "reason":format!("Parent workflow closed (completed); parent-close policy: {policy}.")}), "{history}");
            // The policy's events follow the final run's terminal event.
            let kinds: Vec<_> = history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap()).collect();
            assert_eq!(&kinds[kinds.len() - 2..], ["WorkflowCompleted", "ParentClosePolicyApplied"], "{history}");
        }
        // Nothing is appended to the closed predecessor.
        assert_eq!(chain.history(&app, &first).await, predecessor, "{policy}");
        assert_eq!(text(&database, "status", &format!("FROM workflow_child_calls WHERE parent_workflow_run_id='{}'", s(&first))).await, call_status, "{policy}");
        runtime.close().await;
        database.remove().await;
    }
}

// `testCooperativeCancellationPolicyIsRequestedByTheFinalRun`: the final run
// records its own policy-owned root and asks the child under it, naming the
// child's original link and sequence.
#[tokio::test]
async fn the_final_run_requests_a_handed_over_childs_cooperative_cancellation() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let chain = chain(&app, &database, "cooperative-parent", child_with("request_cancellation", None), 1).await;
    chain.complete(&app).await;

    let history = chain.history(&app, chain.last()).await;
    assert!(events_of(&history, "ParentClosePolicyFailed").is_empty(), "{history}");
    let origin = events_of(&history, "ParentCloseCancellationRequested");
    assert_eq!(origin.len(), 1, "{history}");
    let applied = events_of(&history, "ParentClosePolicyApplied");
    assert_eq!(applied.len(), 1, "{history}");
    let payload = &applied[0]["payload"];
    assert_eq!((payload["policy"].clone(), payload["child_run_id"].clone()), (json!("request_cancellation"), chain.child_run.clone()), "{payload}");
    assert_eq!(payload["cancellation"]["root_workflow_run_id"], *chain.last(), "{payload}");
    assert_eq!(payload["cancellation"]["root_request_id"], origin[0]["payload"]["cancellation"]["request_id"], "{payload}");
    let request_id = payload["request_id"].as_str().unwrap();
    assert_eq!(text(&database, "cancellation_request_command_id", &format!("FROM workflow_runs WHERE id='{}'", s(&chain.child_run))).await, request_id);
    let context: Value = serde_json::from_str(&text(&database, "context", &format!("FROM workflow_commands WHERE id='{request_id}'")).await).unwrap();
    assert_eq!(context["workflow"], json!({"parent_instance_id":"cooperative-parent","parent_run_id":chain.last(),
        "sequence":chain.sequence,"child_call_id":chain.link}), "{context}");
    let child = run_history(&app, &json!(chain.child), &chain.child_run).await;
    assert_eq!(events_of(&child, "CooperativeCancellationRequested").len(), 1, "{child}");
    assert_ends_with_continued(&app, &chain, chain.first()).await;
    runtime.close().await;
    database.remove().await;
}

// `testSupervisionFollowsEveryContinuationToTheFinalRun`.
#[tokio::test]
async fn supervision_follows_every_continuation_to_the_final_run() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let chain = chain(&app, &database, "multi-hop", child_with("request_cancel", None), 2).await;
    let (first, middle, last) = (chain.runs[0].clone(), chain.runs[1].clone(), chain.runs[2].clone());
    let sequence = chain.sequence.to_string();
    assert_eq!(supervisions(&database, &chain.child).await, [
        ((s(&middle), s(&chain.child_run)), ("request_cancel".to_owned(), sequence.clone())),
        ((s(&last), s(&chain.child_run)), ("request_cancel".to_owned(), sequence)),
    ]);
    chain.complete(&app).await;
    let (_, child) = request(&app, "GET", &format!("/api/workflows/{}", chain.child), Value::Null).await;
    assert_eq!(child["status"], "cancelled", "{child}");
    assert_eq!(events_of(&chain.history(&app, &last).await, "ParentClosePolicyApplied").len(), 1);
    assert!(events_of(&chain.history(&app, &middle).await, "ParentClosePolicyApplied").is_empty());
    assert_ends_with_continued(&app, &chain, &first).await;
    assert_ends_with_continued(&app, &chain, &middle).await;
    assert_eq!(text(&database, "status", &format!("FROM workflow_child_calls WHERE parent_workflow_run_id='{}'", s(&first))).await, "cancelled");
    runtime.close().await;
    database.remove().await;
}

// `testChildCompletingUnderTheContinuedRunAppendsNothingAndIsNotReclosed`.
#[tokio::test]
async fn a_child_completing_under_the_continued_run_appends_nothing_and_is_not_reclosed() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let chain = chain(&app, &database, "quiet-parent", child_with("request_cancel", None), 1).await;
    let predecessor = chain.history(&app, chain.first()).await;
    let child_task = poll(&app, "child-worker", "workflow").await;
    assert_eq!(child_task["run_id"], chain.child_run, "{child_task}");
    let (status, body) = finish_task(&app, &child_task, complete()).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    assert_eq!(chain.history(&app, chain.first()).await, predecessor);
    assert_eq!(count(&database, format!("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id='{}' AND status IN ('ready','leased')", s(chain.first()))).await, 0);
    // The continued run has no handle on the child or its result.
    let continued = chain.history(&app, chain.last()).await;
    assert!(continued.as_array().unwrap().iter().all(|event| !event["event_type"].as_str().unwrap().starts_with("Child")), "{continued}");
    chain.complete(&app).await;
    let history = chain.history(&app, chain.last()).await;
    assert!(events_of(&history, "ParentClosePolicyApplied").is_empty() && events_of(&history, "ParentClosePolicyFailed").is_empty(), "{history}");
    let (_, child) = request(&app, "GET", &format!("/api/workflows/{}", chain.child), Value::Null).await;
    assert_eq!(child["status"], "completed", "{child}");
    assert_eq!(text(&database, "status", &format!("FROM workflow_child_calls WHERE parent_workflow_run_id='{}'", s(chain.first()))).await, "started");
    runtime.close().await;
    database.remove().await;
}

// `testTerminatingTheContinuedRunEnforcesTheTransferredPolicy`, and the same
// for an HTTP cancel.
#[tokio::test]
async fn closing_the_continued_run_by_command_enforces_the_transferred_policy() {
    for (verb, closed) in [("terminate", "terminated"), ("cancel", "cancelled")] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let chain = chain(&app, &database, "commanded-parent", child_with("request_cancel", None), 1).await;
        let (status, body) = request(&app, "POST", &format!("/api/workflows/commanded-parent/{verb}"), json!({"reason":"operator"})).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        let (_, parent) = request(&app, "GET", "/api/workflows/commanded-parent", Value::Null).await;
        assert_eq!((parent["run_id"].clone(), parent["status"].clone()), (chain.last().clone(), json!(closed)), "{parent}");
        let (_, child) = request(&app, "GET", &format!("/api/workflows/{}", chain.child), Value::Null).await;
        assert_eq!(child["status"], "cancelled", "{child}");
        let applied = events_of(&chain.history(&app, chain.last()).await, "ParentClosePolicyApplied").into_iter().cloned().collect::<Vec<_>>();
        assert_eq!(applied.len(), 1, "{verb}");
        assert_eq!(applied[0]["payload"]["reason"], format!("Parent workflow closed ({closed}); parent-close policy: request_cancel."));
        assert_ends_with_continued(&app, &chain, chain.first()).await;
        runtime.close().await;
        database.remove().await;
    }
}

// A handed-over child that continues as new is followed to its current run.
#[tokio::test]
async fn the_policy_follows_a_handed_over_child_that_continued_as_new() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let chain = chain(&app, &database, "following-parent", child_with("terminate", None), 1).await;
    let child_task = poll(&app, "child-worker", "workflow").await;
    let (status, body) = finish_task(&app, &child_task, json!([{"type":"continue_as_new"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let successor = current(&app, &chain.child).await["run_id"].clone();
    assert_ne!(successor, chain.child_run);
    chain.complete(&app).await;
    let applied = events_of(&chain.history(&app, chain.last()).await, "ParentClosePolicyApplied").into_iter().cloned().collect::<Vec<_>>();
    assert_eq!(applied.len(), 1);
    assert_eq!(applied[0]["payload"]["child_run_id"], successor, "{}", applied[0]);
    let child = current(&app, &chain.child).await;
    assert_eq!((child["run_id"].clone(), child["status"].clone()), (successor, json!("terminated")), "{child}");
    runtime.close().await;
    database.remove().await;
}

// `testHandedOverChildFailureIsRetriedUnderTheRecordedRetryPolicy`.
#[tokio::test]
async fn a_handed_over_child_failure_is_retried_under_the_recorded_retry_policy() {
    for hops in [1, 2] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let chain = chain(&app, &database, "retry-parent", child_with("request_cancel", Some(3)), hops).await;
        let first = chain.first().clone();
        let mut closed = Vec::new();
        for run in &chain.runs[..hops] {
            closed.push(chain.history(&app, run).await);
        }
        let child_task = poll(&app, "child-worker", "workflow").await;
        assert_eq!(child_task["run_id"], chain.child_run);
        let (status, body) = finish_task(&app, &child_task, fail()).await;
        assert_eq!(status, StatusCode::OK, "{body}");

        let links = text_rows(&database,
            &format!("SELECT child_workflow_run_id,parent_close_policy FROM workflow_links WHERE parent_workflow_run_id='{}' AND link_type='child_workflow' ORDER BY created_at,id", s(&first)),
            &format!("SELECT child_workflow_run_id::text,parent_close_policy::text FROM workflow_links WHERE parent_workflow_run_id='{}' AND link_type='child_workflow' ORDER BY created_at,id", s(&first)),
            &format!("SELECT CAST(child_workflow_run_id AS CHAR),CAST(parent_close_policy AS CHAR) FROM workflow_links WHERE parent_workflow_run_id='{}' AND link_type='child_workflow' ORDER BY created_at,id", s(&first))).await;
        assert_eq!(links.len(), 2, "{hops}: {links:?}");
        let retry = json!(links[1].0);
        assert_eq!(links[1].1, "request_cancel");
        let retried = current(&app, &chain.child).await;
        assert_eq!((retried["run_id"].clone(), retried["status"].clone()), (retry.clone(), json!("pending")), "{retried}");
        // The retry keeps the original call identity and counts the attempt.
        let started = run_history(&app, &json!(chain.child), &retry).await;
        let started = &events_of(&started, "WorkflowStarted")[0]["payload"];
        assert_eq!((started["parent_workflow_run_id"].clone(), started["parent_sequence"].clone(), started["retry_attempt"].clone(),
            started["retry_of_child_workflow_run_id"].clone(), started["child_call_id"].clone()),
            (first.clone(), chain.sequence.clone(), json!(2), chain.child_run.clone(), chain.link.clone()), "{started}");
        // Every supervision of the child now names the retry run.
        let rows = supervisions(&database, &chain.child).await;
        assert_eq!(rows.iter().map(|((_, child), _)| child.clone()).collect::<Vec<_>>(), vec![s(&retry); hops]);
        // Closed runs of the chain keep their final history.
        for (run, history) in chain.runs[..hops].iter().zip(&closed) {
            assert_eq!(&chain.history(&app, run).await, history, "{hops}");
        }
        assert_eq!(text(&database, "status", &format!("FROM workflow_child_calls WHERE parent_workflow_run_id='{}'", s(&first))).await, "started");

        // The chain's final run applies the parent-close policy to the retry run.
        chain.complete(&app).await;
        assert_eq!(current(&app, &chain.child).await["status"], "cancelled");
        let applied = events_of(&chain.history(&app, chain.last()).await, "ParentClosePolicyApplied").into_iter().cloned().collect::<Vec<_>>();
        assert_eq!(applied.len(), 1);
        assert_eq!(applied[0]["payload"]["child_run_id"], retry, "{}", applied[0]);
        runtime.close().await;
        database.remove().await;
    }
}

// `testHandedOverChildFailureIsFinalOnceItsAttemptsAreSpent`.
#[tokio::test]
async fn a_handed_over_child_failure_is_final_once_its_attempts_are_spent() {
    for hops in [1, 2] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let chain = chain(&app, &database, "spent-parent", child_with("request_cancel", Some(2)), hops).await;
        for _ in 0..2 {
            let child_task = poll(&app, "child-worker", "workflow").await;
            assert_eq!(child_task["workflow_type"], "child", "{child_task}");
            let (status, body) = finish_task(&app, &child_task, fail()).await;
            assert_eq!(status, StatusCode::OK, "{body}");
        }
        assert_eq!(count(&database, format!("SELECT COUNT(*) FROM workflow_runs WHERE workflow_instance_id='{}'", chain.child)).await, 2);
        assert_eq!(current(&app, &chain.child).await["status"], "failed");
        assert!(poll(&app, "child-worker", "workflow").await.is_null());
        // Nothing is left open for the chain to close.
        chain.complete(&app).await;
        let history = chain.history(&app, chain.last()).await;
        assert!(events_of(&history, "ParentClosePolicyApplied").is_empty() && events_of(&history, "ParentClosePolicyFailed").is_empty(), "{history}");
        runtime.close().await;
        database.remove().await;
    }
}

// `testHandedOverChildFailureIsFinalOnceTheChainHasClosed`.
#[tokio::test]
async fn a_handed_over_child_failure_is_final_once_the_chain_has_closed() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let chain = chain(&app, &database, "closed-chain", child_with("abandon", Some(3)), 1).await;
    chain.complete(&app).await;
    let child_task = poll(&app, "child-worker", "workflow").await;
    let (status, body) = finish_task(&app, &child_task, fail()).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(count(&database, format!("SELECT COUNT(*) FROM workflow_runs WHERE workflow_instance_id='{}'", chain.child)).await, 1);
    assert_eq!(count(&database, format!("SELECT COUNT(*) FROM workflow_links WHERE parent_workflow_run_id='{}' AND link_type='child_workflow'", s(chain.first()))).await, 1);
    runtime.close().await;
    database.remove().await;
}

// A run that continued before this change recorded no supervision, as a
// Core run continued before Workflow 2.26.0 has no supervision rows: its
// children keep running when the chain closes, and a failure is final.
#[tokio::test]
async fn a_chain_continued_without_supervision_leaves_its_children_as_before() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let chain = chain(&app, &database, "legacy-chain", child_with("request_cancel", Some(3)), 1).await;
    database.execute(sqlx::AssertSqlSafe("DELETE FROM dw_child_supervisions".to_owned())).await;
    let predecessor = chain.history(&app, chain.first()).await;
    chain.complete(&app).await;
    let history = chain.history(&app, chain.last()).await;
    assert!(events_of(&history, "ParentClosePolicyApplied").is_empty() && events_of(&history, "ParentClosePolicyFailed").is_empty(), "{history}");
    assert_eq!(current(&app, &chain.child).await["status"], "pending");
    let child_task = poll(&app, "child-worker", "workflow").await;
    let (status, body) = finish_task(&app, &child_task, fail()).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(count(&database, format!("SELECT COUNT(*) FROM workflow_runs WHERE workflow_instance_id='{}'", chain.child)).await, 1);
    assert_eq!(chain.history(&app, chain.first()).await, predecessor);
    runtime.close().await;
    database.remove().await;
}

// Retention may prune the run that started a handed-over child while its
// chain is still open. The supervision keeps the child's original identity,
// as Core's row does, so the final run still applies the policy under the
// original link and sequence. Pruning the supervising run removes its rows.
#[tokio::test]
async fn a_pruned_starting_run_keeps_the_handed_over_childs_identity() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let chain = chain(&app, &database, "pruned-origin", child_with("request_cancellation", None), 1).await;
    let (status, report) = request(&app, "POST", "/api/system/retention/pass", json!({"run_ids":[chain.first()]})).await;
    assert_eq!(status, StatusCode::OK, "{report}");
    assert_eq!(report["pruned"], 1, "{report}");
    assert_eq!(count(&database, format!("SELECT COUNT(*) FROM workflow_links WHERE parent_workflow_run_id='{}'", s(chain.first()))).await, 0);
    assert_eq!(supervisions(&database, &chain.child).await.len(), 1);
    chain.complete(&app).await;

    let history = chain.history(&app, chain.last()).await;
    let applied = events_of(&history, "ParentClosePolicyApplied");
    assert_eq!(applied.len(), 1, "{history}");
    let request_id = applied[0]["payload"]["request_id"].as_str().unwrap();
    let context: Value = serde_json::from_str(&text(&database, "context", &format!("FROM workflow_commands WHERE id='{request_id}'")).await).unwrap();
    assert_eq!(context["workflow"], json!({"parent_instance_id":"pruned-origin","parent_run_id":chain.last(),
        "sequence":chain.sequence,"child_call_id":chain.link}), "{context}");

    let (status, report) = request(&app, "POST", "/api/system/retention/pass", json!({"run_ids":[chain.last()]})).await;
    assert_eq!(status, StatusCode::OK, "{report}");
    assert_eq!(report["pruned"], 1, "{report}");
    assert!(supervisions(&database, &chain.child).await.is_empty());
    runtime.close().await;
    database.remove().await;
}
