//! Published PHP's `CancellationCascadeView` (Workflow 2.9.0): read-only
//! cancellation evidence for a run's root request and every run it reached.
//! Run debug reports it as `cancellation_cascade`. It walks from the root run
//! through child calls and links, at most 20 runs and 100 relations, and
//! reports what it cannot verify as findings rather than guessing.
//!
//! Native writes neither scoped (`cancellation-context/v2`) contexts nor
//! activity stop receipts, so PHP history carrying them reads as an invalid
//! request and stops whose callback state is `unknown`.
use super::{Result, backend::Backend, cooperative::timestamp, store::Store};
use chrono::{DateTime, Duration, SubsecRound, Utc};
use regex_lite::Regex;
use serde_json::{Map, Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::{BTreeSet, HashMap, VecDeque};
use std::sync::LazyLock;

const RUN_LIMIT: usize = 20;
const EDGE_LIMIT: usize = 100;
const HISTORY_LIMIT: usize = 128;
const TOTAL_HISTORY_LIMIT: usize = 512;
const REQUEST_TEXT_BYTES: usize = 8192;
/// `ParentCloseCancellation::CLEANUP_TIMEOUT_SECONDS`.
const PARENT_CLOSE_BUDGET_SECONDS: i64 = 600;

const NODE_HISTORY: &str = "SELECT id,sequence,event_type,workflow_command_id,payload,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('CooperativeCancellationRequested','CooperativeCancellationDelivered','ActivityCancelled','ActivityCancellationAcknowledged','ActivityScheduled','ActivityRetryScheduled','TimerScheduled','ChildCancellationRequested','ChildCancellationResolved','WorkflowCancelled','WorkflowTerminated','WorkflowFailed','WorkflowCompleted','WorkflowTimedOut') ORDER BY sequence DESC LIMIT 129";
const TERMINALS: [&str; 5] = ["WorkflowCancelled", "WorkflowTerminated", "WorkflowFailed", "WorkflowCompleted", "WorkflowTimedOut"];

/// `CancellationContext::timestamp`'s accepted form.
static ISO_TIME: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z").expect("timestamp pattern")
});

/// The run fields the view reads.
#[derive(Clone)]
struct Run {
    id: String,
    instance: String,
    workflow_type: Option<String>,
    status: String,
    request_id: Option<String>,
    deadline: Option<DateTime<Utc>>,
    delivery_sequence: Option<i64>,
    closed_at: Option<DateTime<Utc>>,
}

impl Run {
    fn terminal(&self) -> bool {
        matches!(self.status.as_str(), "completed" | "failed" | "cancelled" | "terminated")
    }
}

#[derive(Clone)]
struct Event {
    id: String,
    sequence: i64,
    event_type: String,
    command_id: Option<String>,
    payload: Value,
    recorded_at: Option<DateTime<Utc>>,
}

/// A v1 `CancellationContext`, as `fromArray` accepts it.
#[derive(Clone)]
struct Context {
    request_id: String,
    root_request_id: String,
    root_instance: String,
    root_run: String,
    parent_request_id: Option<String>,
    reason: Option<String>,
    requester: Map<String, Value>,
    source: String,
    requested_at: DateTime<Utc>,
    deadline: DateTime<Utc>,
    lineage: Vec<[String; 3]>,
}

fn text(value: &Value, key: &str) -> Option<String> {
    value[key].as_str().filter(|text| !text.trim().is_empty()).map(str::to_owned)
}

/// Carbon keeps microseconds.
fn time(value: &str) -> Option<DateTime<Utc>> {
    ISO_TIME.is_match(value).then_some(())?;
    DateTime::parse_from_rfc3339(value).ok().map(|at| at.with_timezone(&Utc).trunc_subsecs(6))
}

impl Context {
    /// `CancellationContext::fromArray` for v1 contexts.
    fn parse(snapshot: &Value) -> Option<Self> {
        if snapshot["schema"] != "durable-workflow.cancellation-context/v1"
            || snapshot.get("scope_origin").is_some()
            || snapshot.get("scope_authority_deadline_at").is_some()
        {
            return None;
        }
        let requester = snapshot["requester"].as_object().filter(|requester| !requester.is_empty())?;
        if requester.iter().any(|(key, value)| {
            !matches!(key.as_str(), "type" | "id" | "label") || value.as_str().is_none_or(str::is_empty)
        }) {
            return None;
        }
        let mut normalized = Map::new();
        for key in ["type", "id", "label"] {
            if let Some(value) = requester.get(key) {
                normalized.insert(key.into(), value.clone());
            }
        }
        let mut lineage = Vec::new();
        for entry in snapshot["lineage"].as_array().filter(|lineage| !lineage.is_empty())? {
            entry.as_object()?;
            lineage.push([text(entry, "request_id")?, text(entry, "workflow_instance_id")?, text(entry, "workflow_run_id")?]);
        }
        let request_id = text(snapshot, "request_id")?;
        let root_request_id = text(snapshot, "root_request_id")?;
        let root_instance = text(snapshot, "root_workflow_instance_id")?;
        let root_run = text(snapshot, "root_workflow_run_id")?;
        let parent_request_id = match &snapshot["parent_request_id"] {
            Value::Null => None,
            Value::String(parent) if !parent.is_empty() => Some(parent.clone()),
            _ => return None,
        };
        let reason = match &snapshot["reason"] {
            Value::Null => None,
            Value::String(reason) => Some(reason.clone()),
            _ => return None,
        };
        let requests: BTreeSet<_> = lineage.iter().map(|entry| &entry[0]).collect();
        let runs: BTreeSet<_> = lineage.iter().map(|entry| &entry[2]).collect();
        if requests.len() != lineage.len() || runs.len() != lineage.len() {
            return None;
        }
        let expected_parent = (lineage.len() > 1).then(|| lineage[lineage.len() - 2][0].clone());
        if lineage[0] != [root_request_id.clone(), root_instance.clone(), root_run.clone()]
            || lineage[lineage.len() - 1][0] != request_id
            || parent_request_id != expected_parent
        {
            return None;
        }
        let requested_at = time(&text(snapshot, "requested_at")?)?;
        let deadline = time(&text(snapshot, "cleanup_deadline_at")?)?;
        if deadline <= requested_at {
            return None;
        }
        Some(Self {
            request_id,
            root_request_id,
            root_instance,
            root_run,
            parent_request_id,
            reason,
            requester: normalized,
            source: text(snapshot, "source")?,
            requested_at,
            deadline,
            lineage,
        })
    }

    fn target(&self) -> &[String; 3] {
        &self.lineage[self.lineage.len() - 1]
    }

    /// `sameOrigin`.
    fn same_origin(&self, child: &Self) -> bool {
        self.root_request_id == child.root_request_id
            && self.root_run == child.root_run
            && self.root_instance == child.root_instance
            && self.requested_at == child.requested_at
            && self.deadline == child.deadline
            && self.reason == child.reason
            && self.requester == child.requester
            && self.source == child.source
    }
}

fn message(code: &str) -> &'static str {
    match code {
        "run_limit" => "Some related runs are omitted because this view reached its run limit.",
        "edge_limit" => "Some relations are omitted because this view reached its relation limit.",
        "history_limit" => "Some history is omitted. Open the run history for additional evidence.",
        "request_text_limit" => "Some request text is omitted because it exceeds the inspection text limit.",
        "root_unavailable" | "request_root_unavailable" => "The root run is unavailable. Its original cascade budget cannot be verified.",
        "related_run_unavailable" => "A related run is unavailable in this namespace. Its details are omitted.",
        "selected_run_not_reachable" => "The selected run has no retained path from the root. It is shown separately.",
        "request_history_unavailable" => "The recorded cancellation request is missing. Its identity and deadline cannot be verified.",
        "request_context_unavailable" => "The recorded request has no usable cancellation context.",
        "request_projection_mismatch" => "Saved run state disagrees with the original cancellation request or budget. The original request remains in history.",
        "request_history_conflict" | "request_context_invalid" | "root_request_mismatch" => "The recorded request has inconsistent cancellation metadata.",
        "delivery_history_invalid" | "delivery_history_unavailable" => "The original cancellation delivery cannot be verified.",
        "terminal_history_unavailable" => "The run has missing or conflicting terminal history.",
        "terminal_projection_mismatch" => "Run status disagrees with its terminal history. Inspect the recorded terminal event before recovery.",
        "cleanup_outcome_unavailable" => "Cleanup completion cannot be verified from the recorded cancellation outcome.",
        "propagation_history_invalid" => "Child propagation does not match the original cancellation request and budget.",
        _ => "Some cancellation evidence cannot be verified.",
    }
}

/// UTF-8-safe `mb_strcut`.
fn cut(text: &str) -> String {
    let mut end = REQUEST_TEXT_BYTES.min(text.len());
    while !text.is_char_boundary(end) {
        end -= 1;
    }
    text[..end].to_owned()
}

#[derive(Default)]
struct Inspection {
    findings: Vec<Value>,
    truncated: bool,
    history_count: usize,
    visible: HashMap<String, Option<Run>>,
}

impl Inspection {
    fn incomplete(&mut self, code: &str, run: &str) {
        let finding = json!({"code": code, "run_id": run, "message": message(code)});
        if !self.findings.contains(&finding) {
            self.findings.push(finding);
        }
    }

    fn truncate(&mut self, code: &str, run: &str) {
        self.truncated = true;
        self.incomplete(code, run);
    }

    /// `metadata`: `toArray()` without the lineage, with long text cut.
    fn metadata(&mut self, context: &Context) -> Value {
        let target = context.target()[2].clone();
        let mut requester = context.requester.clone();
        for value in requester.values_mut() {
            if let Some(long) = value.as_str().filter(|text| text.len() > REQUEST_TEXT_BYTES) {
                *value = json!(cut(long));
                self.truncate("request_text_limit", &target);
            }
        }
        let mut field = |value: &str| {
            if value.len() > REQUEST_TEXT_BYTES {
                self.truncate("request_text_limit", &target);
                cut(value)
            } else {
                value.to_owned()
            }
        };
        json!({"schema": "durable-workflow.cancellation-context/v1", "request_id": field(&context.request_id),
            "root_request_id": field(&context.root_request_id), "root_workflow_instance_id": field(&context.root_instance),
            "root_workflow_run_id": field(&context.root_run),
            "parent_request_id": context.parent_request_id.as_deref().map(&mut field),
            "reason": context.reason.as_deref().map(&mut field), "requester": requester,
            "source": field(&context.source), "requested_at": timestamp(context.requested_at),
            "cleanup_deadline_at": timestamp(context.deadline)})
    }
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
    /// The view for a run in this namespace, or null when the run has no
    /// cancellation request and nothing to report.
    pub(super) async fn cancellation_cascade(&self, selected: &str) -> Result<Value> {
        let mut inspection = Inspection::default();
        let Some(selected) = self.visible_run(&mut inspection, selected).await? else {
            return Ok(Value::Null);
        };
        let (selected_context, _) = self.cascade_request(&mut inspection, &selected).await?;
        if selected_context.is_none() && selected.request_id.is_none() && inspection.findings.is_empty() {
            return Ok(Value::Null);
        }
        let mut root = selected.clone();
        let mut origin = selected_context.clone();
        if let Some(context) = &selected_context
            && context.root_run != selected.id
        {
            match self.visible_run(&mut inspection, &context.root_run).await? {
                None => {
                    inspection.incomplete("root_unavailable", &selected.id);
                    origin = None;
                }
                Some(visible) => {
                    let (root_context, _) = self.cascade_request(&mut inspection, &visible).await?;
                    root = visible;
                    origin = root_context.filter(|root_context| root_context.same_origin(context));
                    if origin.is_none() {
                        inspection.incomplete("root_request_mismatch", &selected.id);
                    }
                }
            }
        }
        let mut pending = VecDeque::from([root.clone()]);
        let mut seen = BTreeSet::new();
        let mut nodes = Vec::new();
        let mut edges: Vec<Value> = Vec::new();
        while nodes.len() < RUN_LIMIT {
            let Some(run) = pending.pop_front() else { break };
            if !seen.insert(run.id.clone()) {
                continue;
            }
            nodes.push(self.cascade_node(&mut inspection, &run, origin.as_ref()).await?);
            for (reference_id, source, kind, target) in self.cascade_references(&mut inspection, &run, EDGE_LIMIT - edges.len()).await? {
                let child = match &target {
                    Some(target) => self.visible_run(&mut inspection, target).await?,
                    None => None,
                };
                let state = match (&target, &child) {
                    (None, _) => "pending",
                    (Some(_), None) => "unavailable",
                    _ => "resolved",
                };
                edges.push(json!({"parent_run_id": run.id, "child_run_id": child.as_ref().map(|child| child.id.clone()),
                    "kind": kind, "reference_id": reference_id, "reference_source": source, "reference_state": state}));
                match child {
                    // Nothing about an inaccessible target is disclosed.
                    None if target.is_some() => inspection.incomplete("related_run_unavailable", &run.id),
                    Some(child) if !seen.contains(&child.id) => pending.push_back(child),
                    _ => {}
                }
            }
        }
        if !pending.is_empty() {
            inspection.truncate("run_limit", &root.id);
        }
        if !seen.contains(&selected.id) {
            inspection.incomplete("selected_run_not_reachable", &selected.id);
            if nodes.len() < RUN_LIMIT {
                nodes.push(self.cascade_node(&mut inspection, &selected, origin.as_ref()).await?);
            }
        }
        let root_metadata = match &origin {
            Some(origin) => inspection.metadata(origin),
            None => Value::Null,
        };
        Ok(json!({"schema": "durable-workflow.cancellation-cascade/v1", "selected_run_id": selected.id,
            "root": root_metadata, "runs": nodes, "edges": edges, "inspection_complete": inspection.findings.is_empty(),
            "truncated": inspection.truncated, "findings": inspection.findings,
            "limits": {"runs": RUN_LIMIT, "edges": EDGE_LIMIT, "history_events_per_run": HISTORY_LIMIT,
                "history_events_total": TOTAL_HISTORY_LIMIT, "request_text_bytes": REQUEST_TEXT_BYTES,
                "scope_origin_addresses": EDGE_LIMIT}}))
    }

    /// `visibleRun`: a run in this namespace whose instance is in it too.
    async fn visible_run(&self, inspection: &mut Inspection, id: &str) -> Result<Option<Run>> {
        if let Some(run) = inspection.visible.get(id) {
            return Ok(run.clone());
        }
        let row = Self::query("SELECT r.id,r.workflow_instance_id,r.workflow_type,r.workflow_class,r.status,r.cancellation_request_command_id,r.cancellation_deadline_at,r.cancellation_delivery_sequence,r.closed_at FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE r.id=$1 AND r.namespace=$2 AND i.namespace=$3")
            .bind(id).bind(self.namespace.as_ref()).bind(self.namespace.as_ref()).fetch_optional(&self.pool).await?;
        let run = match row {
            None => None,
            Some(row) => Some(Run {
                id: DB::string(&row, "id")?,
                instance: DB::string(&row, "workflow_instance_id")?,
                workflow_type: DB::optional_string(&row, "workflow_type")?.or(DB::optional_string(&row, "workflow_class")?),
                status: DB::string(&row, "status")?,
                request_id: DB::optional_string(&row, "cancellation_request_command_id")?,
                deadline: DB::optional_instant(&row, "cancellation_deadline_at")?,
                delivery_sequence: DB::optional_number(&row, "cancellation_delivery_sequence")?,
                closed_at: DB::optional_instant(&row, "closed_at")?,
            }),
        };
        inspection.visible.insert(id.to_owned(), run.clone());
        Ok(run)
    }

    fn cascade_event(row: &DB::Row) -> Result<Event> {
        Ok(Event {
            id: DB::string(row, "id")?,
            sequence: DB::number(row, "sequence")?,
            event_type: DB::string(row, "event_type")?,
            command_id: DB::optional_string(row, "workflow_command_id")?,
            payload: DB::document_row(row, "payload")?,
            recorded_at: DB::optional_instant(row, "recorded_at")?,
        })
    }

    /// `request`: the run's recorded request and its context, or a finding.
    async fn cascade_request(&self, inspection: &mut Inspection, run: &Run) -> Result<(Option<Context>, Option<Event>)> {
        let rows = match &run.request_id {
            Some(request) => Self::query("SELECT id,sequence,event_type,workflow_command_id,payload,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='CooperativeCancellationRequested' AND workflow_command_id=$2 ORDER BY sequence LIMIT 2")
                .bind(&run.id).bind(request.as_str()).fetch_all(&self.pool).await?,
            None => Self::query("SELECT id,sequence,event_type,workflow_command_id,payload,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('CooperativeCancellationRequested','ParentCloseCancellationRequested') ORDER BY sequence LIMIT 2")
                .bind(&run.id).fetch_all(&self.pool).await?,
        };
        if rows.len() > 1 {
            inspection.incomplete("request_history_conflict", &run.id);
        }
        let Some(row) = rows.first() else {
            if run.request_id.is_some() {
                inspection.incomplete("request_history_unavailable", &run.id);
            }
            return Ok((None, None));
        };
        let event = Self::cascade_event(row)?;
        let snapshot = &event.payload["cancellation"];
        if !snapshot.is_object() || !snapshot["lineage"].as_array().is_some_and(|lineage| lineage.len() <= RUN_LIMIT) {
            inspection.incomplete("request_context_unavailable", &run.id);
            return Ok((None, Some(event)));
        }
        let context = match Context::parse(snapshot) {
            Some(_) if event.event_type == "ParentCloseCancellationRequested" => self.parent_close_context(run).await?,
            Some(context)
                if event.command_id.as_deref() == Some(context.request_id.as_str())
                    && event.payload["workflow_command_id"] == context.request_id.as_str() =>
            {
                Some(context)
            }
            _ => None,
        };
        let Some(context) = context.filter(|context| {
            context.target()[2] == run.id
                && context.target()[1] == run.instance
                && run.request_id.as_ref().is_none_or(|request| *request == context.request_id)
        }) else {
            inspection.incomplete("request_context_invalid", &run.id);
            return Ok((None, Some(event)));
        };
        if event.event_type == "CooperativeCancellationRequested"
            && (run.request_id.as_deref() != Some(context.request_id.as_str()) || run.deadline != Some(context.deadline))
        {
            inspection.incomplete("request_projection_mismatch", &run.id);
        }
        Ok((Some(context), Some(event)))
    }

    /// `ParentCloseCancellation::context`: the request a closed parent
    /// recorded for its parent-close policy, after its latest terminal event.
    async fn parent_close_context(&self, run: &Run) -> Result<Option<Context>> {
        let origin = Self::query("SELECT id,sequence,event_type,workflow_command_id,payload,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='ParentCloseCancellationRequested' ORDER BY sequence LIMIT 1")
            .bind(&run.id).fetch_optional(&self.pool).await?;
        let closed = Self::query("SELECT id,sequence,event_type,workflow_command_id,payload,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('WorkflowCompleted','WorkflowFailed','WorkflowCancelled','WorkflowTerminated','WorkflowTimedOut') ORDER BY sequence DESC LIMIT 1")
            .bind(&run.id).fetch_optional(&self.pool).await?;
        let (Some(origin), Some(closed)) = (origin, closed) else {
            return Ok(None);
        };
        let (origin, closed) = (Self::cascade_event(&origin)?, Self::cascade_event(&closed)?);
        let Some(context) = Context::parse(&origin.payload["cancellation"]) else {
            return Ok(None);
        };
        let valid = origin.sequence > closed.sequence
            && origin.payload["parent_terminal_history_event_id"] == closed.id.as_str()
            && origin.payload["parent_terminal_event_type"] == closed.event_type.as_str()
            && context.root_run == run.id
            && context.root_instance == run.instance
            && context.request_id == context.root_request_id
            && context.lineage.len() == 1
            && context.source == "parent_close_policy"
            && closed.recorded_at.map(|at| at.trunc_subsecs(6)) == Some(context.requested_at)
            && context.deadline == context.requested_at + Duration::seconds(PARENT_CLOSE_BUDGET_SECONDS);
        Ok(valid.then_some(context))
    }

    /// `node`: one run's request, delivery, cleanup and propagation.
    async fn cascade_node(&self, inspection: &mut Inspection, run: &Run, origin: Option<&Context>) -> Result<Value> {
        let (mut context, request) = self.cascade_request(inspection, run).await?;
        if let Some(found) = &context
            && self.visible_run(inspection, &found.root_run).await?.is_none()
        {
            inspection.incomplete("request_root_unavailable", &run.id);
            context = None;
        }
        let limit = HISTORY_LIMIT.min(TOTAL_HISTORY_LIMIT - inspection.history_count);
        let rows = Self::query(NODE_HISTORY).bind(&run.id).fetch_all(&self.pool).await?;
        if rows.len() > limit {
            inspection.truncate("history_limit", &run.id);
        }
        let mut events: Vec<Event> = rows.iter().take(limit).map(Self::cascade_event).collect::<Result<_>>()?;
        events.reverse();
        inspection.history_count += events.len();
        if let Some(request) = &request
            && !events.iter().any(|event| event.id == request.id)
        {
            events.insert(0, request.clone());
        }
        let request_id = context.as_ref().map(|context| context.request_id.clone());
        let deliveries: Vec<&Event> = events.iter().filter(|event| event.event_type == "CooperativeCancellationDelivered").collect();
        let mut delivery = deliveries.first().copied();
        if delivery.is_none() && run.delivery_sequence.is_some() {
            inspection.incomplete("delivery_history_unavailable", &run.id);
        }
        if let Some(found) = delivery
            && (deliveries.len() != 1
                || request_id.is_none()
                || found.command_id != request_id
                || found.payload["workflow_command_id"].as_str() != request_id.as_deref()
                || found.payload["sequence"].as_i64().is_none()
                || found.payload["sequence"].as_i64() != run.delivery_sequence)
        {
            inspection.incomplete("delivery_history_invalid", &run.id);
            delivery = None;
        }
        let terminals: Vec<&Event> = events.iter().filter(|event| TERMINALS.contains(&event.event_type.as_str())).collect();
        let terminal = terminals.last().copied();
        if terminals.len() > 1 || (run.terminal() && terminal.is_none()) {
            inspection.incomplete("terminal_history_unavailable", &run.id);
        }
        let mut cleanup = terminal
            .map(|terminal| terminal.payload["cancellation_cleanup"].clone())
            .filter(|cleanup| (cleanup.is_object() || cleanup.is_array()) && context.is_some());
        if let (Some(terminal), Some(found)) = (terminal, &context)
            && terminal.event_type == "WorkflowCancelled"
            && !cleanup.as_ref().is_some_and(|cleanup| Self::valid_cleanup(run, found, cleanup, delivery))
        {
            inspection.incomplete("cleanup_outcome_unavailable", &run.id);
            cleanup = None;
        }
        if let Some(terminal) = terminal {
            let expected = match terminal.event_type.as_str() {
                "WorkflowCancelled" => "cancelled",
                "WorkflowTerminated" => "terminated",
                "WorkflowCompleted" => "completed",
                _ => "failed",
            };
            if run.status != expected {
                inspection.incomplete("terminal_projection_mismatch", &run.id);
            }
        }
        let mut stops = Vec::new();
        let mut recovery = Vec::new();
        let mut propagation = Vec::new();
        for event in &events {
            if let Some(found) = &context
                && matches!(event.event_type.as_str(), "ChildCancellationRequested" | "ChildCancellationResolved")
            {
                if event.command_id.as_deref() != Some(found.request_id.as_str())
                    || event.payload["parent_request_id"] != found.request_id.as_str()
                    || event.payload["root_request_id"] != found.root_request_id.as_str()
                    || event.payload["cleanup_deadline_at"] != timestamp(found.deadline).as_str()
                {
                    inspection.incomplete("propagation_history_invalid", &run.id);
                    continue;
                }
                let target = match event.payload["child_workflow_run_id"].as_str() {
                    Some(target) => self.visible_run(inspection, target).await?,
                    None => None,
                };
                if target.is_none() {
                    inspection.incomplete("related_run_unavailable", &run.id);
                }
                propagation.push(json!({"history_event_id": event.id, "event_type": event.event_type,
                    "child_run_id": target.as_ref().map(|target| target.id.clone()), "policy": event.payload["policy"],
                    "request_outcome": event.payload["request_outcome"], "rejection_reason": event.payload["rejection_reason"],
                    "child_terminal_history_event_id": if target.is_some() { event.payload["child_terminal_history_event_id"].clone() } else { Value::Null }}));
            }
            if event.event_type == "ActivityCancelled" && request_id.is_some() && event.command_id == request_id {
                stops.push(json!({"activity_execution_id": event.payload["activity_execution_id"],
                    "activity_attempt_id": event.payload["activity_attempt_id"], "activity_type": event.payload["activity_type"],
                    "execution_mode": if event.payload["local_activity"] == true { "local" } else { "remote" },
                    "fence_history_event_id": event.id, "callback_state": "unknown", "stop_history_event_id": null,
                    "evidence_source": null, "acknowledged_at": null, "received_after_deadline": null}));
            }
            if event.event_type == "ActivityRetryScheduled"
                && let Some(local) = event.payload["local_recovery"].as_object()
            {
                let attempt: Map<String, Value> = local.iter()
                    .filter(|(key, _)| matches!(key.as_str(), "original_workflow_task_id" | "original_workflow_task_attempt"
                        | "original_lease_owner" | "original_lease_expires_at" | "workflow_task_id" | "workflow_task_attempt"
                        | "lease_owner" | "callback_stop_state"))
                    .map(|(key, value)| (key.clone(), value.clone()))
                    .collect();
                recovery.push(json!({"history_event_id": event.id, "activity_execution_id": event.payload["activity_execution_id"],
                    "recorded_at": event.recorded_at.map(timestamp), "attempt": attempt}));
            }
        }
        let lifecycle = Self::cascade_lifecycle(run, context.is_some(), &events, delivery, terminal, cleanup.as_ref());
        let request_metadata = match &context {
            Some(found) => inspection.metadata(found),
            None => Value::Null,
        };
        let same_root = matches!((&context, origin), (Some(found), Some(origin)) if origin.same_origin(found));
        let cleanup = cleanup.map(|cleanup| {
            let mut kept = Map::new();
            for field in ["request_id", "outcome", "cleanup_deadline_at", "finished_at", "delivery_history_event_id", "delivery_sequence"] {
                if let Some(value) = cleanup.get(field) {
                    kept.insert(field.into(), value.clone());
                }
            }
            Value::Object(kept)
        });
        Ok(json!({"run_id": run.id, "workflow_id": run.instance, "workflow_type": run.workflow_type,
            "projected_status": run.status, "lifecycle": lifecycle,
            "terminal_event_type": terminal.map(|terminal| terminal.event_type.clone()),
            "terminal_history_event_id": terminal.map(|terminal| terminal.id.clone()),
            "request": request_metadata, "same_root_budget": same_root,
            "delivery": delivery.map(|delivery| json!({"history_event_id": delivery.id, "sequence": delivery.payload["sequence"],
                "sequence_span": delivery.payload.get("sequence_span").cloned().unwrap_or(json!(1)),
                "call_kind": delivery.payload["call_kind"], "recorded_at": delivery.recorded_at.map(timestamp)})),
            "cleanup": cleanup, "activity_stops": stops, "cleanup_recovery": recovery, "child_propagation": propagation}))
    }

    /// `validCleanup`: the terminal's cleanup names this request, deadline
    /// and close time, and its outcome agrees with them.
    fn valid_cleanup(run: &Run, context: &Context, cleanup: &Value, delivery: Option<&Event>) -> bool {
        let Some(closed_at) = run.closed_at else {
            return false;
        };
        if cleanup["request_id"] != context.request_id.as_str()
            || cleanup["cleanup_deadline_at"] != timestamp(context.deadline).as_str()
            || cleanup["finished_at"] != timestamp(closed_at).as_str()
        {
            return false;
        }
        let expired = closed_at >= context.deadline;
        match cleanup["outcome"].as_str() {
            Some("deadline_expired") => expired,
            Some("completed") => {
                !expired
                    && delivery.is_some_and(|delivery| {
                        cleanup["delivery_history_event_id"] == delivery.id.as_str()
                            && cleanup.get("delivery_sequence").unwrap_or(&Value::Null) == &delivery.payload["sequence"]
                    })
            }
            Some("not_delivered") => !expired && delivery.is_none() && run.delivery_sequence.is_none(),
            Some("unavailable") => !expired,
            _ => false,
        }
    }

    fn cascade_lifecycle(
        run: &Run,
        requested: bool,
        events: &[Event],
        delivery: Option<&Event>,
        terminal: Option<&Event>,
        cleanup: Option<&Value>,
    ) -> Value {
        if let Some(terminal) = terminal {
            return json!(match terminal.event_type.as_str() {
                "WorkflowCancelled" if cleanup.is_some_and(|cleanup| cleanup["outcome"] == "deadline_expired") => "deadline_expired",
                "WorkflowCancelled" => "cancelled",
                "WorkflowTerminated" => "terminated",
                "WorkflowFailed" => "failed",
                "WorkflowTimedOut" => "timed_out",
                "WorkflowCompleted" => "completed",
                _ => "unknown",
            });
        }
        if run.terminal() {
            return json!("unknown");
        }
        if let Some(delivery) = delivery {
            let last = delivery.payload["sequence"].as_i64().unwrap_or(0)
                + delivery.payload["sequence_span"].as_i64().unwrap_or(1)
                - 1;
            let started = events.iter().any(|event| {
                matches!(event.event_type.as_str(), "ActivityScheduled" | "TimerScheduled")
                    && event.sequence > delivery.sequence
                    && event.payload["sequence"].as_i64().is_some_and(|sequence| sequence > last)
            });
            return json!(if started { "cleaning_up" } else { "delivered" });
        }
        if requested { json!("requested") } else { Value::Null }
    }

    /// `references`: the run's child calls, then its child and
    /// continue-as-new links to runs the calls did not already name.
    async fn cascade_references(&self, inspection: &mut Inspection, run: &Run, remaining: usize) -> Result<Vec<(Value, &'static str, String, Option<String>)>> {
        let calls = Self::query("SELECT id,resolved_child_run_id FROM workflow_child_calls WHERE parent_workflow_run_id=$1 ORDER BY sequence LIMIT 101")
            .bind(&run.id).fetch_all(&self.pool).await?;
        let mut references = Vec::new();
        for call in calls.iter().take(remaining) {
            references.push((json!(DB::number(call, "id")?), "child_call", "child_workflow".to_owned(), DB::optional_string(call, "resolved_child_run_id")?));
        }
        if calls.len() > remaining {
            inspection.truncate("edge_limit", &run.id);
        }
        let remaining = remaining - references.len();
        let named: BTreeSet<String> = references.iter().filter_map(|reference| reference.3.clone()).collect();
        // PHP excludes the named runs in SQL. There are at most 100, each with
        // one link, so 201 rows always reach one row past the remaining budget.
        let links = Self::query("SELECT id,link_type,child_workflow_run_id FROM workflow_links WHERE parent_workflow_run_id=$1 AND link_type IN ('child_workflow','continue_as_new') ORDER BY sequence,id LIMIT 201")
            .bind(&run.id).fetch_all(&self.pool).await?;
        let mut kept = 0;
        for link in &links {
            let child = DB::string(link, "child_workflow_run_id")?;
            if named.contains(&child) {
                continue;
            }
            if kept == remaining {
                inspection.truncate("edge_limit", &run.id);
                break;
            }
            references.push((json!(DB::string(link, "id")?), "workflow_link", DB::string(link, "link_type")?, Some(child)));
            kept += 1;
        }
        Ok(references)
    }
}
