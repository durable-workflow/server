//! `POST /api/workflows/import/embedded-v2`, as published Server 2.11.1
//! serves it (`EmbeddedV2ImportController` over Workflow 2.10.2's
//! `EmbeddedV2HistoryImport`): an embedded application's history-export
//! bundle is verified, checked for eligibility, and written in one
//! transaction into the request's namespace. The answer is the import
//! report: 201 imported, 200 already_imported or dry_run, 422 otherwise.
//!
//! The bundle is read as PHP reads it: the JSON body decoded into ordered
//! PHP arrays and normalized by Laravel's TrimStrings and
//! ConvertEmptyStringsToNull, so the checksum, the dedupe key and every
//! stored value see the same document PHP's importer sees.
//!
//! Published defects are not copied (Server #765). PHP looks the run and the
//! instance up in every namespace, so a bundle imported into another
//! namespace answers already_imported for a namespace that holds no such
//! run, and an import attaches its run to another namespace's instance and
//! takes over its current run. Native counts a run as already imported only
//! in the requested namespace (elsewhere it is a run_id conflict), and
//! refuses to write into an instance of another namespace (a failed write,
//! as PHP itself reports when the run number collides). PHP also copies
//! every row reference unchecked, so a bundle can point its signals and
//! updates at another run's commands, its tasks at another run's
//! activities, and its lineage at another namespace's runs; native refuses
//! such a bundle (`reject_foreign_references`, `check_store_references`)
//! and links only runs of one namespace. And native reads the signing key
//! only from the body, never from the URL.
use super::bundle_verifier::{self, Decoded};
use super::execution::Runtime;
use super::php_value::{self, Flags, Key, Php, PhpArray};
use super::store::{id, now};
use super::{Result, refuse};
use axum::http::StatusCode;
use chrono::{DateTime, NaiveDateTime, Utc};
use serde_json::{Map, Value, json};
use std::collections::{HashMap, HashSet};

pub(super) const IMPORT_SOURCE: &str = "embedded_v2";
pub(super) const ENGINE_SOURCE: &str = "embedded_v2_import";
const REPORT_SCHEMA: &str = "durable-workflow.v2.embedded-v2-import.report";
const CONTRACT_VERSION: i64 = 2;
const SOURCE_RUNTIME: &str = "embedded";

const RUN_STATUSES: &[&str] = &["pending", "running", "waiting", "cancelled", "terminated", "completed", "failed"];
const TERMINAL_STATUSES: &[&str] = &["cancelled", "terminated", "completed", "failed"];
const HISTORY_EVENT_TYPES: &[&str] = &[
    "StartAccepted", "StartRejected", "WorkflowStarted", "WorkflowContinuedAsNew", "ChildWorkflowScheduled",
    "ChildCancellationRequested", "ChildCancellationResolved", "ChildRunStarted", "ChildRunCompleted", "ChildRunFailed",
    "ChildRunCancelled", "ChildRunTerminated", "ServiceCallStarted", "ServiceCallCompleted", "ServiceCallFailed",
    "ServiceCallCancelled", "ConditionWaitOpened", "ConditionWaitSatisfied", "ConditionWaitTimedOut",
    "ConditionWaitCancelled", "SignalWaitOpened", "SignalWaitCancelled", "SignalReceived", "SignalApplied",
    "SignalRejected", "UpdateAccepted", "UpdateRejected", "UpdateApplied", "UpdateCompleted", "RepairRequested",
    "CancelRequested", "CooperativeCancellationRequested", "CooperativeCancellationDelivered", "CancellationScopeOpened",
    "CancellationScopeRequested", "CancellationScopeDeliveryPrepared", "CancellationScopeDelivered",
    "CancellationScopeRequestConflicted", "WorkflowCancelled", "TerminateRequested", "WorkflowTerminated",
    "ArchiveRequested", "WorkflowArchived", "ActivityScheduled", "ActivityStarted", "ActivityHeartbeatRecorded",
    "ActivityRetryScheduled", "ActivityCompleted", "ActivityFailed", "ActivityCancelled",
    "ActivityCancellationAcknowledged", "ActivityTimedOut", "FailureHandled", "SideEffectRecorded",
    "VersionMarkerRecorded", "TimerScheduled", "TimerFired", "TimerCancelled", "SelectionResolved",
    "SelectionOperationCancelled", "SearchAttributesUpserted", "MemoUpserted", "WorkflowTimedOut", "WorkflowCompleted",
    "WorkflowFailed", "ParentClosePolicyApplied", "ParentClosePolicyFailed", "ParentCloseCancellationRequested",
    "MessageCursorAdvanced", "ScheduleCreated", "SchedulePaused", "ScheduleResumed", "ScheduleUpdated",
    "ScheduleTriggered", "ScheduleDeleted", "ScheduleTriggerSkipped",
];
const COMMAND_TYPES: &[&str] = &["start", "redrive", "signal", "update", "repair", "cancel", "request_cancellation", "terminate", "archive"];
const COMMAND_STATUSES: &[&str] = &["accepted", "rejected"];
const COMMAND_OUTCOMES: &[&str] = &[
    "started_new", "redriven", "returned_existing_active", "rejected_duplicate", "signal_received", "update_completed",
    "update_failed", "repair_dispatched", "repair_not_needed", "cancelled", "cancellation_requested", "terminated",
    "archived", "archive_not_needed", "rejected_not_started", "rejected_not_active", "rejected_not_current",
    "rejected_run_not_closed", "rejected_unknown_signal", "rejected_unknown_update", "rejected_invalid_arguments",
    "rejected_compatibility_blocked", "rejected_workflow_definition_unavailable",
];
const SIGNAL_STATUSES: &[&str] = &["received", "applied", "rejected"];
const UPDATE_STATUSES: &[&str] = &["accepted", "rejected", "completed", "failed"];
const TASK_TYPES: &[&str] = &["workflow", "activity", "timer"];
const TASK_STATUSES: &[&str] = &["ready", "leased", "cancelled", "completed", "failed"];
const ACTIVITY_STATUSES: &[&str] = &["pending", "running", "cancelled", "completed", "failed"];
const ATTEMPT_STATUSES: &[&str] = &["running", "completed", "failed", "cancelled", "expired"];
const TIMER_STATUSES: &[&str] = &["pending", "cancelled", "fired"];
const FAILURE_CATEGORIES: &[&str] = &[
    "application", "cancelled", "terminated", "timeout", "activity", "child_workflow", "task_failure", "internal", "structural_limit",
];
/// `EmbeddedV2HistoryImport::HISTORY_EVENT_PAYLOAD_FIELDS`.
const HISTORY_EVENT_PAYLOAD_FIELDS: &[(&str, &[&str])] = &[
    ("ActivityCompleted", &["result"]),
    ("ChildRunCompleted", &["output", "result"]),
    ("MemoUpserted", &["entries", "merged"]),
    ("ServiceCallStarted", &["request_payload", "response_payload"]),
    ("ServiceCallCompleted", &["request_payload", "response_payload"]),
    ("ServiceCallFailed", &["request_payload", "response_payload"]),
    ("ServiceCallCancelled", &["request_payload", "response_payload"]),
    ("SideEffectRecorded", &["result"]),
    ("SignalReceived", &["arguments"]),
    ("SignalApplied", &["arguments", "value"]),
    ("UpdateAccepted", &["arguments"]),
    ("UpdateRejected", &["arguments"]),
    ("UpdateApplied", &["arguments"]),
    ("UpdateCompleted", &["result"]),
    ("WorkflowCompleted", &["output"]),
];
const ACTIVITY_SNAPSHOT_EVENT_TYPES: &[&str] = &[
    "ActivityScheduled", "ActivityStarted", "ActivityHeartbeatRecorded", "ActivityRetryScheduled", "ActivityCompleted",
    "ActivityFailed", "ActivityCancelled", "ActivityTimedOut",
];
const FAILURE_PAYLOAD_EVENT_TYPES: &[&str] = &[
    "ActivityRetryScheduled", "ActivityFailed", "ChildRunFailed", "ServiceCallFailed", "ServiceCallCancelled",
    "UpdateCompleted", "WorkflowFailed",
];
const SERVICE_CALL_EVENT_TYPES: &[&str] = &["ServiceCallStarted", "ServiceCallCompleted", "ServiceCallFailed", "ServiceCallCancelled"];
/// `PAYLOAD_ROW_FIELDS`, in the importer's order.
const PAYLOAD_ROW_FIELDS: &[(&str, &[&str])] = &[
    ("commands", &["payload"]),
    ("signals", &["arguments"]),
    ("updates", &["arguments", "result"]),
    ("activities", &["arguments", "result"]),
];
/// The report's row counts, in PHP's order.
pub(super) const ROWS: [&str; 15] = [
    "workflow_instances", "workflow_runs", "workflow_history_events", "workflow_commands", "workflow_signal_records",
    "workflow_updates", "workflow_tasks", "activity_executions", "activity_attempts", "workflow_run_timers",
    "workflow_failures", "workflow_links", "workflow_memos", "workflow_search_attributes", "workflow_run_summaries",
];
const MEMO_VALUE_LIMIT: usize = 10_240;
const SEARCH_KEYWORD_LIMIT: usize = 255;
const SEARCH_STRING_LIMIT: usize = 2048;

pub(crate) type Rows = [i64; 15];

fn rows_json(rows: &Rows) -> Value {
    Value::Object(ROWS.iter().zip(rows).map(|(name, count)| ((*name).to_owned(), json!(count))).collect())
}

fn row(name: &str) -> usize {
    ROWS.iter().position(|candidate| *candidate == name).expect("known row")
}

// The importer's value coercions.

/// `stringValue`: a non-empty string.
pub(super) fn text(value: Option<&Php>) -> Option<&str> {
    value.and_then(Php::str).filter(|text| !text.is_empty())
}

/// `intValue`: an integer, or a numeric string cast to one.
pub(super) fn int(value: Option<&Php>) -> Option<i64> {
    match value? {
        Php::Int(number) => Some(*number),
        Php::Str(text) => php_value::int_cast(text),
        _ => None,
    }
}

/// `listValue`: the array elements of an array, in order.
pub(super) fn list(value: Option<&Php>) -> Vec<&Php> {
    value.and_then(Php::array).map(|array| array.values().filter(|row| row.is_array()).collect()).unwrap_or_default()
}

fn array(value: Option<&Php>) -> Option<&PhpArray> {
    value.and_then(Php::array)
}

/// `enumString`: a known backing value.
fn enumerated<'a>(allowed: &[&str], value: Option<&'a Php>) -> Option<&'a str> {
    text(value).filter(|text| allowed.contains(text))
}

/// `ulidValue`: a non-empty string of at most 26 bytes.
fn ulid_value(value: Option<&Php>) -> Option<&str> {
    text(value).filter(|text| text.len() <= 26)
}

fn truthy(value: Option<&Php>) -> bool {
    value.is_some_and(Php::truthy)
}

fn max_sequence(rows: &[&Php], key: &str) -> i64 {
    rows.iter().filter_map(|row| int(row.get(key))).fold(0, i64::max)
}

/// The options PHP's controller reads from the body (or the query string,
/// except the signing key).
pub(super) struct Options {
    pub(super) dry_run: bool,
    pub(super) import_id: Option<String>,
    pub(super) require_signature: bool,
    pub(super) signing_key: Option<String>,
}

/// `$request->boolean($key)`: `filter_var(FILTER_VALIDATE_BOOLEAN)`.
fn boolean(value: Option<&Php>) -> bool {
    match value {
        Some(Php::Bool(flag)) => *flag,
        Some(Php::Int(number)) => *number == 1,
        Some(Php::Float(number)) => *number == 1.0,
        Some(Php::Str(text)) => {
            let text = text.trim_matches([' ', '\t', '\n', '\r', '\x0b', '\0']).to_ascii_lowercase();
            matches!(text.as_str(), "1" | "true" | "on" | "yes")
        }
        _ => false,
    }
}

/// `stringInput`: a string, trimmed, with an empty one absent.
fn string_input(value: Option<&Php>) -> Option<String> {
    let text = value?.str()?.trim_matches([' ', '\t', '\n', '\r', '\x0b', '\0']);
    (!text.is_empty()).then(|| text.to_owned())
}

/// The options the query string may carry. The signing key is not one.
const QUERY_OPTION_KEYS: [&str; 3] = ["dry_run", "import_id", "require_signature"];

/// What the controller decides before the importer runs.
pub(super) enum Request {
    /// The 422 `invalid_import_bundle` answer.
    Invalid,
    /// The 422 `signing_key_in_query` answer: a signing key in the URL.
    KeyInQuery,
    Import(PhpArray, Options),
}

/// `EmbeddedV2ImportController::store` up to the importer: the bundle is
/// `bundle` when that is an array, else the whole body; options come from
/// the body, then the query string (`$request->input`).
///
/// Unlike PHP, native reads `signing_key` only from the body and refuses a
/// request that puts one in the query string (Server #765). Access logs,
/// tracing and client history keep URLs, and the key verifies the HMAC.
pub(super) fn request(body: &[u8], query: &[(String, String)]) -> Request {
    // Any spelling PHP would read as the option (`signing_key[]` too).
    if query.iter().any(|(key, _)| key.starts_with("signing_key")) {
        return Request::KeyInQuery;
    }
    let body = php_value::request_body(body);
    // Only the options are read, so only they are kept; a repeated
    // parameter's last value wins.
    let mut query_input = PhpArray::default();
    for (key, value) in query.iter().filter(|(key, _)| QUERY_OPTION_KEYS.contains(&key.as_str())) {
        let mut value = Php::Str(value.clone());
        value.normalize();
        query_input.insert(Key::of(key), value);
    }
    let input = |key: &str| body.get(key).or_else(|| query_input.get(key));
    let options = Options {
        dry_run: boolean(input("dry_run")),
        import_id: string_input(input("import_id")),
        require_signature: boolean(input("require_signature")),
        signing_key: string_input(body.get("signing_key")),
    };
    let bundle = match body.get("bundle") {
        Some(Php::Array(bundle)) => bundle.clone(),
        _ => body,
    };
    if bundle.is_empty() {
        return Request::Invalid;
    }
    Request::Import(bundle, options)
}

/// `workflowSummary`: the bundle's workflow with the importer's defaults,
/// in the request's namespace.
pub(crate) struct Summary {
    pub(super) workflow: PhpArray,
    pub(super) instance_id: Option<String>,
    pub(super) run_id: Option<String>,
    pub(super) run_number: i64,
    pub(super) workflow_type: String,
    pub(super) workflow_class: String,
    pub(super) namespace: String,
    pub(super) status: String,
}

impl Summary {
    fn of(bundle: &PhpArray, namespace: &str) -> Self {
        let workflow = array(bundle.get("workflow")).cloned().unwrap_or_default();
        let field = |key: &str| text(workflow.get(key)).map(str::to_owned);
        let workflow_type = field("workflow_type").or_else(|| field("workflow_class")).unwrap_or_else(|| "imported-workflow".into());
        Self {
            instance_id: field("instance_id"),
            run_id: field("run_id"),
            run_number: int(workflow.get("run_number")).unwrap_or(1),
            workflow_class: field("workflow_class").unwrap_or_else(|| workflow_type.clone()),
            workflow_type,
            namespace: namespace.to_owned(),
            status: field("status").unwrap_or_else(|| "running".into()),
            workflow,
        }
    }

    fn get(&self, key: &str) -> Option<&Php> {
        self.workflow.get(key)
    }

    fn terminal(&self) -> bool {
        TERMINAL_STATUSES.contains(&self.status.as_str())
    }
}

struct Eligibility {
    errors: Vec<Value>,
    warnings: Vec<Value>,
}

impl Eligibility {
    fn json(&self) -> Value {
        json!({"eligible": self.errors.is_empty(), "errors": self.errors, "warnings": self.warnings})
    }

    fn with_error(&self, rule: &str, message: &str) -> Value {
        self.with_errors(vec![finding(rule, message)])
    }

    fn with_errors(&self, findings: Vec<Value>) -> Value {
        let mut errors = self.errors.clone();
        errors.extend(findings);
        json!({"eligible": false, "errors": errors, "warnings": self.warnings})
    }
}

fn finding(rule: &str, message: &str) -> Value {
    json!({"rule": rule, "message": message})
}

/// `eligibility`, in PHP's order.
fn eligibility(bundle: &PhpArray, integrity: &str, signature_verified: bool, summary: &Summary, options: &Options) -> Eligibility {
    let root = Php::Array(bundle.clone());
    let mut errors = Vec::new();
    let mut warnings = Vec::new();
    match integrity {
        "failed" => errors.push(finding("bundle.integrity_failed", "Bundle integrity verification failed.")),
        "warning" => warnings.push(finding("bundle.integrity_warning", "Bundle integrity verification reported warnings.")),
        _ => {}
    }
    if options.require_signature && !signature_verified {
        errors.push(finding("bundle.signature_required", "A verified history-export signature is required for this import."));
    }
    if summary.instance_id.is_none() {
        errors.push(finding("workflow.instance_id_missing", "Bundle workflow.instance_id is required."));
    }
    if summary.run_id.is_none() {
        errors.push(finding("workflow.run_id_missing", "Bundle workflow.run_id is required."));
    }
    if !RUN_STATUSES.contains(&summary.status.as_str()) {
        errors.push(finding("workflow.status_unsupported", "Bundle workflow.status is not a supported v2 run status."));
    } else if summary.terminal() {
        if !matches!(bundle.get("history_complete"), Some(Php::Bool(true))) {
            errors.push(finding("workflow.terminal_history_incomplete", "Terminal run imports require history_complete=true."));
        }
    } else if !truthy(summary.get("is_current_run")) {
        errors.push(finding("workflow.non_terminal_not_current", "Non-terminal embedded v2 imports must be the current run."));
    }
    if text(summary.get("source_runtime")) != Some(SOURCE_RUNTIME) {
        errors.push(finding("workflow.source_runtime_unsupported", "Bundle workflow.source_runtime must be embedded."));
    }
    // Only non-scalar path elements count, so exported string paths pass.
    if !list(root.at(&["redaction", "paths"])).is_empty() {
        errors.push(finding("bundle.redacted_payloads", "Redacted history bundles cannot be imported as server-managed state."));
    }
    reject_unsupported_payload_codecs(&root, &mut errors);
    reject_open_leases(&root, &mut errors);
    Eligibility { errors, warnings }
}

fn reject_payload_codec(codec: Option<&Php>, path: &str, errors: &mut Vec<Value>) {
    let found = match codec {
        None | Some(Php::Null) => return,
        Some(Php::Str(codec)) if codec.is_empty() || codec == "avro" => return,
        Some(Php::Str(codec)) => format!("\"{codec}\""),
        Some(other) => other.debug_type().to_owned(),
    };
    errors.push(finding("payload_codec.unsupported", &format!(
        "Bundle payload codec at [{path}] must be \"avro\"; found {found}. JSON is only the HTTP document transport and cannot be imported as durable payload data.")));
}

fn inspect_payload_envelope(value: Option<&Php>, path: &str, errors: &mut Vec<Value>) {
    let Some(envelope) = value.and_then(Php::array) else {
        return;
    };
    if envelope.contains("blob") || envelope.contains("external_storage") {
        reject_payload_codec(envelope.get("codec"), &format!("{path}.codec"), errors);
    }
    if let Some(external) = array(envelope.get("external_storage")) {
        reject_payload_codec(external.get("codec"), &format!("{path}.external_storage.codec"), errors);
    }
}

fn inspect_payload_row(row: &Php, path: &str, fields: &[&str], errors: &mut Vec<Value>) {
    reject_payload_codec(row.get("payload_codec"), &format!("{path}.payload_codec"), errors);
    for field in fields {
        inspect_payload_envelope(row.get(field), &format!("{path}.{field}"), errors);
    }
}

fn inspect_failure_details(value: Option<&Php>, path: &str, errors: &mut Vec<Value>) {
    let Some(details) = value.filter(|value| value.is_array()) else {
        return;
    };
    reject_payload_codec(details.get("details_payload_codec"), &format!("{path}.details_payload_codec"), errors);
    inspect_payload_envelope(details.get("details"), &format!("{path}.details"), errors);
}

/// `rejectUnsupportedPayloadCodecs`, one finding per offending path in the
/// importer's walk order.
fn reject_unsupported_payload_codecs(bundle: &Php, errors: &mut Vec<Value>) {
    let empty = Php::Array(PhpArray::default());
    let payloads = bundle.get("payloads").filter(|value| value.is_array()).unwrap_or(&empty);
    let arguments = payloads.get("arguments").filter(|value| value.is_array()).unwrap_or(&empty);
    let output = payloads.get("output").filter(|value| value.is_array()).unwrap_or(&empty);
    reject_payload_codec(payloads.get("codec"), "payloads.codec", errors);
    reject_payload_codec(output.get("codec"), "payloads.output.codec", errors);
    inspect_payload_envelope(arguments.get("data"), "payloads.arguments.data", errors);
    inspect_payload_envelope(output.get("data"), "payloads.output.data", errors);
    for (section, fields) in PAYLOAD_ROW_FIELDS {
        for (index, row) in list(bundle.get(section)).into_iter().enumerate() {
            inspect_payload_row(row, &format!("{section}.{index}"), fields, errors);
        }
    }
    for (index, event) in list(bundle.get("history_events")).into_iter().enumerate() {
        let kind = text(event.get("type"));
        let payload = event.get("payload").filter(|value| value.is_array()).unwrap_or(&empty);
        let path = format!("history_events.{index}.payload");
        if let Some((_, fields)) = kind.and_then(|kind| HISTORY_EVENT_PAYLOAD_FIELDS.iter().find(|(name, _)| *name == kind)) {
            inspect_payload_row(payload, &path, fields, errors);
        }
        let Some(kind) = kind else {
            continue;
        };
        if ACTIVITY_SNAPSHOT_EVENT_TYPES.contains(&kind)
            && let Some(activity) = payload.get("activity").filter(|value| value.is_array())
        {
            inspect_payload_row(activity, &format!("{path}.activity"), &["arguments", "result", "exception"], errors);
            inspect_failure_details(activity.get("exception"), &format!("{path}.activity.exception"), errors);
        }
        if FAILURE_PAYLOAD_EVENT_TYPES.contains(&kind) {
            inspect_failure_details(payload.get("exception"), &format!("{path}.exception"), errors);
        }
        if SERVICE_CALL_EVENT_TYPES.contains(&kind) {
            for surface in ["service_call", "response_or_failure_surface"] {
                let Some(value) = payload.get(surface).filter(|value| value.is_array()) else {
                    continue;
                };
                inspect_payload_row(value, &format!("{path}.{surface}"), &["request_payload", "response_payload"], errors);
                inspect_failure_details(value.get("exception"), &format!("{path}.{surface}.exception"), errors);
            }
        }
    }
    let manifest = bundle.get("payload_manifest").filter(|value| value.is_array()).unwrap_or(&empty);
    for (index, entry) in list(manifest.get("entries")).into_iter().enumerate() {
        reject_payload_codec(entry.get("codec"), &format!("payload_manifest.entries.{index}.codec"), errors);
    }
}

/// `rejectOpenLeases`: the first leased task, else the first running attempt.
fn reject_open_leases(bundle: &Php, errors: &mut Vec<Value>) {
    if list(bundle.get("tasks")).iter().any(|task| task.get("status").and_then(Php::str) == Some("leased")) {
        errors.push(finding("tasks.leased_task_present", "Leased workflow or activity tasks must be completed, failed, or released before import."));
        return;
    }
    let running = list(bundle.get("activities")).iter().any(|activity| {
        list(activity.get("attempts")).iter().any(|attempt| attempt.get("status").and_then(Php::str) == Some("running"))
    });
    if running {
        errors.push(finding("activities.running_attempt_present", "Running activity attempts must be released before import."));
    }
}

/// The ids of the rows the writer imports, selected as it selects them.
#[derive(Default)]
struct BundleIds<'a> {
    /// Each command's id and type.
    commands: HashMap<&'a str, &'a str>,
    signals: HashSet<&'a str>,
    updates: HashSet<&'a str>,
    tasks: HashSet<&'a str>,
    activities: HashSet<&'a str>,
    attempts: HashSet<&'a str>,
    timers: HashSet<&'a str>,
    failures: HashSet<&'a str>,
}

impl<'a> BundleIds<'a> {
    fn of(bundle: &'a Php) -> Self {
        let mut ids = Self::default();
        for command in list(bundle.get("commands")) {
            if let (Some(kind), Some(_), Some(id)) = (enumerated(COMMAND_TYPES, command.get("type")),
                enumerated(COMMAND_STATUSES, command.get("status")), text(command.get("id")))
            {
                ids.commands.insert(id, kind);
            }
        }
        let collect = |section: &str, written: &dyn Fn(&Php) -> bool| -> HashSet<&'a str> {
            list(bundle.get(section)).into_iter().filter(|row| written(row)).filter_map(|row| text(row.get("id"))).collect()
        };
        ids.signals = collect("signals", &|row| enumerated(SIGNAL_STATUSES, row.get("status")).is_some());
        ids.updates = collect("updates", &|row| enumerated(UPDATE_STATUSES, row.get("status")).is_some());
        ids.tasks = collect("tasks", &|row| {
            enumerated(TASK_TYPES, row.get("type")).is_some() && enumerated(TASK_STATUSES, row.get("status")).is_some()
        });
        ids.activities = collect("activities", &|row| enumerated(ACTIVITY_STATUSES, row.get("status")).is_some());
        ids.timers = collect("timers", &|row| enumerated(TIMER_STATUSES, row.get("status")).is_some());
        ids.failures = collect("failures", &|_| true);
        // The writer imports the attempts of every activity with an id.
        for activity in list(bundle.get("activities")).into_iter().filter(|activity| text(activity.get("id")).is_some()) {
            for attempt in list(activity.get("attempts")) {
                if let (Some(_), Some(id)) = (enumerated(ATTEMPT_STATUSES, attempt.get("status")), text(attempt.get("id"))) {
                    ids.attempts.insert(id);
                }
            }
        }
        ids
    }
}

/// Each imported command, signal and update that requests a run other
/// than the imported one: its path and that run.
fn requested_runs<'a>(bundle: &'a Php, run_id: &str) -> Vec<(String, &'a str)> {
    let mut requested = Vec::new();
    for (section, statuses) in [("commands", COMMAND_STATUSES), ("signals", SIGNAL_STATUSES), ("updates", UPDATE_STATUSES)] {
        for (index, row) in list(bundle.get(section)).into_iter().enumerate() {
            let written = enumerated(statuses, row.get("status")).is_some()
                && (section != "commands" || enumerated(COMMAND_TYPES, row.get("type")).is_some());
            if let Some(run) = text(row.get("requested_run_id")).filter(|run| written && *run != run_id) {
                requested.push((format!("{section}.{index}.requested_run_id"), run));
            }
        }
    }
    requested
}

/// The references a task payload carries, each to rows of one kind.
const TASK_PAYLOAD_REFERENCES: &[(&str, &str)] = &[
    ("activity_execution_id", "an activity"),
    ("activity_attempt_id", "an activity attempt"),
    ("timer_id", "a timer"),
    ("workflow_signal_id", "a signal"),
    ("workflow_update_id", "an update"),
    ("workflow_command_id", "a command"),
];

/// Native refuses a bundle whose rows point outside the bundle (Server
/// #765). Published PHP copies every reference unchecked, and the runtime
/// joins or updates the rows they name: a signal or update applies to its
/// command by id, a lost activity lease settles the activity its task names,
/// a lineage row puts a related run into history exports. So each copied
/// reference must name a row this import writes, of the expected kind. A
/// requested run may also be another run of the same workflow, and a failed
/// update a failure no run holds; the write checks those and lineage
/// against the store. History event payloads are history, not references:
/// what the runtime resolves from them stays within the run.
fn reject_foreign_references(bundle: &Php, summary: &Summary, errors: &mut Vec<Value>) {
    let mut check = References { ids: BundleIds::of(bundle), run_id: summary.run_id.as_deref(), errors };
    if let Some(id) = text(summary.get("archive_command_id"))
        && !check.command(id, None)
    {
        check.unresolved("workflow.archive_command_id".into(), "a command");
    }
    check.history(bundle);
    check.messages(bundle);
    check.activities(bundle);
    check.tasks(bundle);
    check.failures(bundle);
    check.links(bundle);
}

/// One bundle's reference checks and the findings they add.
struct References<'a, 'e> {
    ids: BundleIds<'a>,
    run_id: Option<&'a str>,
    errors: &'e mut Vec<Value>,
}

impl References<'_, '_> {
    fn unresolved(&mut self, path: String, kind: &str) {
        self.errors.push(finding("references.unresolved", &format!("Bundle {path} must name {kind} in this bundle.")));
    }

    fn command(&self, id: &str, kind: Option<&str>) -> bool {
        self.ids.commands.get(id).is_some_and(|found| kind.is_none_or(|kind| *found == kind))
    }

    /// Whether `id` names a row of the kind a task payload key or a resume
    /// source kind refers to.
    fn names(&self, key: &str, id: &str) -> bool {
        match key {
            "activity_execution_id" | "activity_execution" => self.ids.activities.contains(id),
            "activity_attempt_id" => self.ids.attempts.contains(id),
            "timer_id" | "timer" => self.ids.timers.contains(id),
            "workflow_signal_id" | "workflow_signal" => self.ids.signals.contains(id),
            "workflow_update_id" | "workflow_update" => self.ids.updates.contains(id),
            _ => self.command(id, None),
        }
    }

    fn history(&mut self, bundle: &Php) {
        for (index, event) in list(bundle.get("history_events")).into_iter().enumerate() {
            if enumerated(HISTORY_EVENT_TYPES, event.get("type")).is_none() {
                continue;
            }
            if text(event.get("workflow_task_id")).is_some_and(|id| !self.ids.tasks.contains(id)) {
                self.unresolved(format!("history_events.{index}.workflow_task_id"), "a task");
            }
            if text(event.get("workflow_command_id")).is_some_and(|id| !self.command(id, None)) {
                self.unresolved(format!("history_events.{index}.workflow_command_id"), "a command");
            }
        }
    }

    /// Commands, signals and updates resolve to this run, and a signal's or
    /// update's command is one of this bundle's of its kind.
    fn messages(&mut self, bundle: &Php) {
        for (section, statuses, kind) in
            [("commands", COMMAND_STATUSES, None), ("signals", SIGNAL_STATUSES, Some("signal")), ("updates", UPDATE_STATUSES, Some("update"))]
        {
            for (index, row) in list(bundle.get(section)).into_iter().enumerate() {
                let written = enumerated(statuses, row.get("status")).is_some()
                    && (kind.is_some() || enumerated(COMMAND_TYPES, row.get("type")).is_some());
                if !written {
                    continue;
                }
                if text(row.get("resolved_run_id")).is_some_and(|id| Some(id) != self.run_id) {
                    self.errors.push(finding("references.foreign_run",
                        &format!("Bundle {section}.{index}.resolved_run_id must name the imported run.")));
                }
                if let Some(kind) = kind
                    && text(row.get("command_id")).is_some_and(|id| !self.command(id, Some(kind)))
                {
                    let expected = if kind == "update" { "an update command" } else { "a signal command" };
                    self.unresolved(format!("{section}.{index}.command_id"), expected);
                }
            }
        }
    }

    fn activities(&mut self, bundle: &Php) {
        for (index, activity) in list(bundle.get("activities")).into_iter().enumerate() {
            if text(activity.get("id")).is_none() {
                continue;
            }
            let attempts: Vec<(usize, &Php)> = list(activity.get("attempts")).into_iter().enumerate()
                .filter(|(_, attempt)| enumerated(ATTEMPT_STATUSES, attempt.get("status")).is_some())
                .collect();
            // The writer imports these attempts even when it skips the activity.
            if enumerated(ACTIVITY_STATUSES, activity.get("status")).is_none() {
                if !attempts.is_empty() {
                    self.unresolved(format!("activities.{index}.attempts"), "attempts of an imported activity");
                }
                continue;
            }
            let own: HashSet<&str> = attempts.iter().filter_map(|(_, attempt)| text(attempt.get("id"))).collect();
            if text(activity.get("current_attempt_id")).is_some_and(|id| !own.contains(id)) {
                self.unresolved(format!("activities.{index}.current_attempt_id"), "one of the activity's own attempts");
            }
            for (position, attempt) in attempts {
                for key in ["workflow_task_id", "task_id"] {
                    if text(attempt.get(key)).is_some_and(|id| !self.ids.tasks.contains(id)) {
                        self.unresolved(format!("activities.{index}.attempts.{position}.{key}"), "a task");
                    }
                }
            }
        }
    }

    fn tasks(&mut self, bundle: &Php) {
        for (index, task) in list(bundle.get("tasks")).into_iter().enumerate() {
            if enumerated(TASK_TYPES, task.get("type")).is_none() || enumerated(TASK_STATUSES, task.get("status")).is_none() {
                continue;
            }
            let Some(payload) = task.get("payload").filter(|payload| payload.is_array()) else {
                continue;
            };
            for (key, kind) in TASK_PAYLOAD_REFERENCES {
                if text(payload.get(key)).is_some_and(|id| !self.names(key, id)) {
                    self.unresolved(format!("tasks.{index}.payload.{key}"), kind);
                }
            }
            // A wait's resume source, for the kinds the bundle itself carries.
            if let (Some(kind @ ("activity_execution" | "timer" | "workflow_signal" | "workflow_update")), Some(id)) =
                (text(payload.get("resume_source_kind")), text(payload.get("resume_source_id")))
                && !self.names(kind, id)
            {
                self.unresolved(format!("tasks.{index}.payload.resume_source_id"), &format!("the {kind} it resumes"));
            }
        }
    }

    fn failures(&mut self, bundle: &Php) {
        for (index, failure) in list(bundle.get("failures")).into_iter().enumerate() {
            if text(failure.get("source_kind")) == Some("activity_execution")
                && text(failure.get("source_id")).is_some_and(|id| !self.ids.activities.contains(id))
            {
                self.unresolved(format!("failures.{index}.source_id"), "an activity");
            }
        }
    }

    /// A link never names the imported run as its own related run; the
    /// write checks the related run against the store.
    fn links(&mut self, bundle: &Php) {
        let Some(run_id) = self.run_id else {
            return;
        };
        let empty = Php::Array(PhpArray::default());
        let links = bundle.get("links").filter(|value| value.is_array()).unwrap_or(&empty);
        for (side, other) in [("parents", "parent"), ("children", "child")] {
            for (index, link) in list(links.get(side)).into_iter().enumerate() {
                if text(link.get(&format!("{other}_workflow_run_id"))) == Some(run_id) {
                    self.errors.push(finding("references.self_link",
                        &format!("Bundle links.{side}.{index} names the imported run as its own {other}.")));
                }
            }
        }
    }
}

/// `estimatedRows`: memos and search attributes are never estimated.
fn estimated_rows(bundle: &Php) -> Rows {
    let count = |section: &str| list(bundle.get(section)).len() as i64;
    let mut rows = [0; 15];
    rows[row("workflow_instances")] = 1;
    rows[row("workflow_runs")] = 1;
    rows[row("workflow_history_events")] = count("history_events");
    rows[row("workflow_commands")] = count("commands");
    rows[row("workflow_signal_records")] = count("signals");
    rows[row("workflow_updates")] = count("updates");
    rows[row("workflow_tasks")] = count("tasks");
    rows[row("activity_executions")] = count("activities");
    rows[row("activity_attempts")] = list(bundle.get("activities")).iter().map(|activity| list(activity.get("attempts")).len() as i64).sum();
    rows[row("workflow_run_timers")] = count("timers");
    rows[row("workflow_failures")] = count("failures");
    let links = bundle.get("links").filter(|value| value.is_array());
    rows[row("workflow_links")] = links.map_or(0, |links| (list(links.get("parents")).len() + list(links.get("children")).len()) as i64);
    rows[row("workflow_run_summaries")] = 1;
    rows
}

/// Everything decided before the store is read.
pub(super) struct Prepared {
    bundle: PhpArray,
    options: Options,
    summary: Summary,
    eligibility: Eligibility,
    base: Map<String, Value>,
    memo_entries: Option<Vec<(String, Decoded)>>,
}

/// The configured `workflows.v2.history_export.signing_key`, trimmed.
fn configured_signing_key() -> Option<String> {
    ["DW_V2_HISTORY_EXPORT_SIGNING_KEY", "WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY"]
        .iter()
        .find_map(|name| std::env::var(name).ok())
        .map(|value| value.trim().to_owned())
        .filter(|value| !value.is_empty())
}

/// Summary, verification, eligibility and the report's base fields.
pub(super) fn prepare(bundle: PhpArray, options: Options, namespace: &str) -> Prepared {
    let summary = Summary::of(&bundle, namespace);
    let configured = configured_signing_key();
    let verification = bundle_verifier::verify(&bundle, options.signing_key.as_deref(), configured.as_deref());
    let verified = verification.report["integrity"]["signature_verified"] == json!(true);
    let eligibility = eligibility(&bundle, verification.status, verified, &summary, &options);
    let workflow = json!({
        "instance_id": summary.instance_id,
        "run_id": summary.run_id,
        "run_number": summary.run_number,
        "workflow_type": summary.workflow_type,
        "namespace": summary.namespace,
        "status": summary.status,
        "is_current_run": truthy(summary.get("is_current_run")),
        "history_complete": truthy(bundle.get("history_complete")),
        "dedupe_key": text(bundle.get("dedupe_key")),
    });
    let mut base = Map::new();
    base.insert("schema".into(), json!(REPORT_SCHEMA));
    base.insert("schema_version".into(), json!(1));
    base.insert("contract_version".into(), json!(CONTRACT_VERSION));
    base.insert("source".into(), json!(IMPORT_SOURCE));
    base.insert("dry_run".into(), json!(options.dry_run));
    base.insert("workflow".into(), workflow);
    base.insert("eligibility".into(), eligibility.json());
    base.insert("integrity".into(), verification.report);
    base.insert("rollback".into(), json!({"mode":"database_transaction","partial_import_behavior":"no_rows_committed_on_failure"}));
    Prepared { bundle, options, summary, eligibility, base, memo_entries: verification.memo_entries }
}

fn report(base: &Map<String, Value>, status: &str, eligibility: Option<Value>, rows: &Rows, imported: Option<(&str, &str)>) -> Value {
    let mut body = base.clone();
    if let Some(eligibility) = eligibility {
        body.insert("eligibility".into(), eligibility);
    }
    body.insert("status".into(), json!(status));
    if let Some((import_id, imported_at)) = imported {
        body.insert("import_id".into(), json!(import_id));
        body.insert("imported_at".into(), json!(imported_at));
    }
    body.insert("rows".into(), rows_json(rows));
    Value::Object(body)
}

/// A run with the bundle's run id, wherever it lives.
pub(crate) struct ExistingRun {
    pub(super) import_source: Option<String>,
    pub(super) dedupe_key: Option<String>,
    pub(super) namespace: Option<String>,
}

/// One prepared memo row: the stored projection and the canonical envelope.
pub(crate) struct MemoRow {
    pub(super) key: String,
    pub(super) value: Value,
    pub(super) portable: Value,
}

/// One typed search attribute row.
pub(crate) struct AttributeRow {
    pub(super) key: String,
    pub(super) value: super::search_attribute_upserts::Typed,
}

/// The write plan the store executes in one transaction.
pub(crate) struct Plan {
    pub(super) bundle: Php,
    pub(super) summary: Summary,
    pub(super) import_id: String,
    pub(super) imported_at: DateTime<Utc>,
    /// The memo rows, or the importer's exception message.
    pub(super) memos: std::result::Result<Vec<MemoRow>, String>,
    /// The search attribute rows, or the importer's exception message.
    pub(super) attributes: std::result::Result<Vec<AttributeRow>, String>,
}

/// `writeMemos`' rows from the decoded entries: every non-empty key, its
/// value re-encoded canonically and projected as the Server stores it.
fn memo_rows(summary: &Summary, decoded: Option<Vec<(String, Decoded)>>) -> std::result::Result<Vec<MemoRow>, String> {
    let entries = match decoded {
        Some(entries) => entries,
        None => {
            let Some(payload) = array(summary.get("memo_payload")) else {
                return Err("workflow.memo_payload must contain the lossless portable memo payload envelope.".into());
            };
            bundle_verifier::decode_entries(payload)?
        }
    };
    let codec = crate::codec::ValueCodec::new().map_err(|error| error.to_string())?;
    let mut rows = Vec::new();
    for (key, value) in entries {
        if key.is_empty() {
            continue;
        }
        let blob = codec.encode_ordered(&value.protocol()).map_err(|error| error.to_string())?;
        let size = crate::codec::php_base64(&blob).map_err(|error| error.to_string())?.len();
        if size > MEMO_VALUE_LIMIT {
            return Err(format!("Memo value for key \"{key}\" exceeds maximum size of {MEMO_VALUE_LIMIT} bytes (actual: {size} bytes)"));
        }
        rows.push(MemoRow { value: bundle_verifier::stored_projection(&value), portable: json!({"codec":"avro","blob":blob}), key });
    }
    Ok(rows)
}

/// `writeSearchAttributes`' rows: `setTypedValueWithInference` for every
/// non-empty string key.
fn attribute_rows(summary: &Summary) -> std::result::Result<Vec<AttributeRow>, String> {
    use super::search_attribute_upserts::Typed;
    let Some(attributes) = array(summary.get("search_attributes")) else {
        return Ok(Vec::new());
    };
    let length = |text: &str| text.chars().count();
    let scalar_text = |value: &Php| -> std::result::Result<String, String> {
        Ok(match value {
            Php::Null => String::new(),
            Php::Str(text) => text.clone(),
            Php::Bool(true) => "1".into(),
            Php::Bool(false) => String::new(),
            Php::Int(number) => number.to_string(),
            Php::Float(number) => php_value::float(*number, false),
            Php::Array(_) => return Err("Cannot coerce value to string: array".into()),
        })
    };
    let mut rows = Vec::new();
    for (key, value) in &attributes.0 {
        let Key::Str(key) = key else {
            continue;
        };
        if key.is_empty() {
            continue;
        }
        let typed = match value {
            Php::Bool(flag) => Typed::Bool(*flag),
            Php::Int(number) => Typed::Int(*number),
            Php::Float(number) => Typed::Float(*number),
            Php::Str(text) if length(text) > SEARCH_KEYWORD_LIMIT || text.chars().any(char::is_whitespace) => {
                if length(text) > SEARCH_STRING_LIMIT {
                    return Err(format!("Search attribute string value exceeds maximum length ({} > {SEARCH_STRING_LIMIT})", length(text)));
                }
                Typed::String(text.clone())
            }
            Php::Array(items) => {
                if !items.is_list() {
                    return Err("Search attribute keyword_list value must be a list of strings.".into());
                }
                let mut list = Vec::new();
                for item in items.values() {
                    let Php::Str(item) = item else {
                        return Err("Search attribute keyword_list entries must be strings.".into());
                    };
                    if length(item) > SEARCH_KEYWORD_LIMIT {
                        return Err(format!("Search attribute keyword_list entry exceeds maximum length ({} > {SEARCH_KEYWORD_LIMIT})", length(item)));
                    }
                    list.push(item.clone());
                }
                Typed::KeywordList(list)
            }
            other => {
                let keyword = scalar_text(other)?;
                if length(&keyword) > SEARCH_KEYWORD_LIMIT {
                    return Err(format!("Search attribute keyword value exceeds maximum length ({} > {SEARCH_KEYWORD_LIMIT})", length(&keyword)));
                }
                Typed::Keyword(keyword)
            }
        };
        rows.push(AttributeRow { key: key.clone(), value: typed });
    }
    Ok(rows)
}

/// A parsed bundle time: its wall-clock time in its own offset, which
/// Eloquent stores for ordinary date columns, and the UTC instant, which the
/// history recorded-at and archive casts store.
#[derive(Clone, Copy, Debug)]
pub(super) struct Stamp {
    pub(super) wall: DateTime<Utc>,
    pub(super) utc: DateTime<Utc>,
}

/// `Carbon::parse` for the times an export carries: RFC 3339 with an offset,
/// or a date and time without one (read in the UTC application timezone).
pub(super) fn parse_time(text: &str) -> std::result::Result<Stamp, String> {
    if let Ok(time) = DateTime::parse_from_rfc3339(text).or_else(|_| DateTime::parse_from_str(text, "%Y-%m-%dT%H:%M:%S%.f%z")) {
        return Ok(Stamp { wall: time.naive_local().and_utc(), utc: time.with_timezone(&Utc) });
    }
    for format in ["%Y-%m-%dT%H:%M:%S%.f", "%Y-%m-%d %H:%M:%S%.f", "%Y-%m-%dT%H:%M", "%Y-%m-%d %H:%M"] {
        if let Ok(time) = NaiveDateTime::parse_from_str(text, format) {
            let time = time.and_utc();
            return Ok(Stamp { wall: time, utc: time });
        }
    }
    if let Ok(date) = chrono::NaiveDate::parse_from_str(text, "%Y-%m-%d") {
        let time = date.and_hms_opt(0, 0, 0).expect("midnight").and_utc();
        return Ok(Stamp { wall: time, utc: time });
    }
    let first = text.chars().next().unwrap_or(' ');
    Err(format!("Could not parse '{text}': Failed to parse time string ({text}) at position 0 ({first}): The timezone could not be found in the database"))
}

/// `timestamp`: a non-empty string parsed, or absent.
pub(super) fn time(value: Option<&Php>) -> std::result::Result<Option<Stamp>, String> {
    text(value).map(parse_time).transpose()
}

/// `payloadRowValue`: a stored payload string, an envelope's blob, or an
/// external-storage envelope encoded as a stored reference.
pub(super) fn payload_row_value(value: Option<&Php>) -> std::result::Result<Option<String>, String> {
    if let Some(text) = text(value) {
        return Ok(Some(text.to_owned()));
    }
    let Some(envelope) = value.and_then(Php::array) else {
        return Ok(None);
    };
    if let Some(blob) = text(envelope.get("blob")) {
        return Ok(Some(blob.to_owned()));
    }
    if envelope.get("external_storage").is_some_and(Php::is_array) {
        return encode_stored_envelope(envelope).map(Some);
    }
    Ok(None)
}

/// `ExternalPayloads::encodeStoredEnvelope`.
fn encode_stored_envelope(envelope: &PhpArray) -> std::result::Result<String, String> {
    use base64::{Engine, engine::general_purpose::STANDARD};
    let codec = text(envelope.get("codec")).ok_or("External payload envelope codec must be a non-empty string.")?;
    let reference = array(envelope.get("external_storage")).ok_or("External payload envelope must contain an external_storage object.")?;
    if reference.get("schema").and_then(Php::str) != Some("durable-workflow.v2.external-payload-reference.v1") {
        return Err("Unsupported external payload reference schema.".into());
    }
    if text(reference.get("uri")).is_none() {
        return Err("External payload reference URI must be a non-empty string.".into());
    }
    let sha = reference.get("sha256").and_then(Php::str).ok_or("External payload reference sha256 must be a hex digest.")?;
    if sha.len() != 64 || !sha.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        return Err("External payload reference sha256 must be a hex digest.".into());
    }
    if !matches!(reference.get("size_bytes"), Some(Php::Int(size)) if *size >= 0) {
        return Err("External payload reference size_bytes must be a non-negative integer.".into());
    }
    let reference_codec = text(reference.get("codec")).ok_or("External payload reference codec must be a non-empty string.")?;
    let canonical = |codec: &str| -> std::result::Result<(), String> {
        if codec == "avro" {
            Ok(())
        } else {
            Err(format!("unsupported_payload_codec: payload codec \"{codec}\" is not supported by Durable Workflow 2.0; use codec=\"avro\" with the fixed Avro Value schema and single-object framing. JSON remains the HTTP document transport, not a workflow payload codec."))
        }
    };
    canonical(reference_codec)?;
    canonical(codec)?;
    let json = Php::Array(envelope.clone()).canonical().encode(Flags { unescaped_slashes: true, ..Flags::default() });
    Ok(format!("dw-external-payload:v1:{}", STANDARD.encode(json)))
}

impl Runtime {
    /// `EmbeddedV2ImportController::store` once the gates have passed.
    pub(crate) async fn import_embedded_v2(&self, body: &[u8], query: &[(String, String)]) -> Result<(StatusCode, Value)> {
        let operation = || super::control_plane::Operation::ImportEmbeddedV2;
        let (bundle, options) = match request(body, query) {
            Request::Invalid => {
                return Ok((StatusCode::UNPROCESSABLE_ENTITY, operation().response(json!({
                    "message": "Import request body must be a history-export bundle or {\"bundle\": ...}.",
                    "reason": "invalid_import_bundle"}))));
            }
            // The answer never repeats the key.
            Request::KeyInQuery => {
                return Ok((StatusCode::UNPROCESSABLE_ENTITY, operation().response(json!({
                    "message": "signing_key must be sent in the request body, not in the URL.",
                    "reason": "signing_key_in_query"}))));
            }
            Request::Import(bundle, options) => (bundle, options),
        };
        let namespace = self.namespace_name();
        // Canonical JSON, hashing and Avro decoding stay off the async workers.
        let prepared = tokio::task::spawn_blocking(move || prepare(bundle, options, &namespace))
            .await
            .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "history_import_unavailable"))?;
        let empty = [0; 15];
        let answer = |status: &str, eligibility: Option<Value>, rows: &Rows, imported: Option<(&str, &str)>| {
            let code = match status {
                "imported" => StatusCode::CREATED,
                "already_imported" | "dry_run" => StatusCode::OK,
                _ => StatusCode::UNPROCESSABLE_ENTITY,
            };
            (code, operation().response(report(&prepared.base, status, eligibility, rows, imported)))
        };
        if !prepared.eligibility.errors.is_empty() {
            return Ok(answer("rejected", None, &empty, None));
        }
        let run_id = prepared.summary.run_id.clone().unwrap_or_default();
        let dedupe_key = text(prepared.bundle.get("dedupe_key")).map(str::to_owned);
        if let Some(existing) = self.import_existing_run(&run_id).await? {
            let same_bundle = existing.import_source.as_deref() == Some(IMPORT_SOURCE)
                && existing.dedupe_key.is_some()
                && existing.dedupe_key == dedupe_key;
            // A run counts as already imported only in this namespace.
            if same_bundle && existing.namespace.as_deref() == Some(prepared.summary.namespace.as_str()) {
                return Ok(answer("already_imported", None, &empty, None));
            }
            return Ok(answer("rejected", Some(prepared.eligibility.with_error("target.run_id_conflict",
                "A workflow run with this run_id already exists and was not imported from the same bundle.")), &empty, None));
        }
        // Core 2.15.12 (Server 2.11.8): an instance of another namespace is
        // refused before a dry run or a write, not left to the write's failure.
        let instance_id = prepared.summary.instance_id.clone().unwrap_or_default();
        if let Some(namespace) = self.import_instance_namespace(&instance_id).await?
            && namespace != prepared.summary.namespace
        {
            return Ok(answer("rejected", Some(prepared.eligibility.with_error("target.instance_namespace_conflict",
                "A workflow instance with this instance_id already exists outside the target namespace.")), &empty, None));
        }
        let root = Php::Array(prepared.bundle.clone());
        // Native's reference fence (Server #765), after PHP's eligibility and
        // run checks so their answers stay PHP's, and before a dry run or a
        // write.
        let mut references = Vec::new();
        reject_foreign_references(&root, &prepared.summary, &mut references);
        if !references.is_empty() {
            return Ok(answer("rejected", Some(prepared.eligibility.with_errors(references)), &empty, None));
        }
        if prepared.options.dry_run {
            return Ok(answer("dry_run", None, &estimated_rows(&root), None));
        }
        let import_id = prepared.options.import_id.clone().unwrap_or_else(id);
        let imported_at = now();
        let memos = memo_rows(&prepared.summary, prepared.memo_entries.clone());
        let attributes = attribute_rows(&prepared.summary);
        let Prepared { summary, eligibility, base, .. } = prepared;
        let plan = Plan { bundle: root, summary, import_id: import_id.clone(), imported_at, memos, attributes };
        let written = self.import_write(&plan).await;
        let answer = |status: &str, eligibility: Option<Value>, rows: &Rows, imported: Option<(&str, &str)>| {
            let code = if status == "imported" { StatusCode::CREATED } else { StatusCode::UNPROCESSABLE_ENTITY };
            (code, operation().response(report(&base, status, eligibility, rows, imported)))
        };
        Ok(match written {
            Ok(rows) => {
                let at = super::task_queues::json_time(Some(imported_at));
                answer("imported", None, &rows, Some((&import_id, at.as_str().unwrap_or_default())))
            }
            Err(message) => answer("rejected", Some(eligibility.with_error("target.write_failed", &message)), &empty, None),
        })
    }
}

/// The JSON column value PHP's array cast stores for `arrayValue(x) ?? []`.
fn document_or_empty(value: Option<&Php>) -> Value {
    match value {
        Some(Php::Array(array)) => Php::Array(array.clone()).to_json(),
        _ => json!([]),
    }
}

/// The current time for a row PHP stamps with `now()`.
fn stamp_now() -> Stamp {
    let at = now();
    Stamp { wall: at, utc: at }
}

fn wall(stamp: Option<Stamp>) -> Option<DateTime<Utc>> {
    stamp.map(|stamp| stamp.wall)
}

/// An enum-cast column: PHP's `BackedEnum::from` refuses an unknown value.
fn cast_enum<'a>(allowed: &[&str], name: &str, value: Option<&'a str>) -> std::result::Result<Option<&'a str>, String> {
    match value {
        Some(value) if !allowed.contains(&value) => {
            Err(format!("\"{value}\" is not a valid backing value for enum Workflow\\V2\\Enums\\{name}"))
        }
        other => Ok(other),
    }
}

store_methods! {
    /// The run with this id in any namespace: PHP's lookup is global, and a
    /// run id is unique across the store.
    pub(crate) async fn import_existing_run(&self, run_id: &str) -> Result<Option<ExistingRun>> {
        let Some(row) = Self::query("SELECT import_source,import_dedupe_key,namespace FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_optional(&self.pool)
            .await?
        else {
            return Ok(None);
        };
        Ok(Some(ExistingRun {
            import_source: DB::optional_string(&row, "import_source")?,
            dedupe_key: DB::optional_string(&row, "import_dedupe_key")?,
            namespace: DB::optional_string(&row, "namespace")?,
        }))
    }

    /// The namespace of an existing workflow instance, if there is one.
    pub(crate) async fn import_instance_namespace(&self, instance_id: &str) -> Result<Option<String>> {
        let Some(row) = Self::query("SELECT namespace FROM workflow_instances WHERE id=$1")
            .bind(instance_id)
            .fetch_optional(&self.pool)
            .await?
        else {
            return Ok(None);
        };
        Ok(Some(DB::optional_string(&row, "namespace")?.unwrap_or_default()))
    }

    pub(crate) fn namespace_name(&self) -> String {
        self.namespace.as_ref().to_owned()
    }

    /// `writeBundle` in one transaction: the written row counts, or the
    /// message of whatever failed (nothing stays).
    pub(crate) async fn import_write(&self, plan: &Plan) -> std::result::Result<Rows, String> {
        let mut tx = self.begin().await.map_err(|error| error.to_string())?;
        let rows = Self::import_rows(&mut tx, plan, self.namespace.as_ref()).await?;
        let run_id = plan.summary.run_id.as_deref().unwrap_or_default();
        tx.summary_changed(run_id);
        tx.commit().await.map_err(|error| error.to_string())?;
        self.wake.notify_waiters();
        Ok(rows)
    }

    /// The references `reject_foreign_references` leaves to the store. A
    /// requested run other than the imported one must be another run of the
    /// same workflow in this namespace, and a failure a failed update names
    /// outside the bundle must belong to no run (Server #765).
    async fn check_store_references(
        tx: &mut super::transaction::Transaction<'_, DB>,
        bundle: &Php,
        namespace: &str,
        run_id: &str,
        instance_id: &str,
    ) -> std::result::Result<(), String> {
        let field = |row: &DB::Row, name: &str| DB::optional_string(row, name).map_err(|error| error.to_string());
        for (path, requested) in requested_runs(bundle, run_id) {
            let found = Self::query("SELECT namespace,workflow_instance_id FROM workflow_runs WHERE id=$1")
                .bind(requested).fetch_optional(&mut **tx).await.map_err(|error| error.to_string())?;
            let same_workflow = match &found {
                Some(found) => field(found, "namespace")?.as_deref() == Some(namespace)
                    && field(found, "workflow_instance_id")?.as_deref() == Some(instance_id),
                None => false,
            };
            if !same_workflow {
                return Err(format!("Imported {path} is not a run of this workflow in this namespace."));
            }
        }
        Self::check_update_failures(tx, bundle).await
    }

    /// A failure a failed update names outside the bundle belongs to no run.
    async fn check_update_failures(
        tx: &mut super::transaction::Transaction<'_, DB>,
        bundle: &Php,
    ) -> std::result::Result<(), String> {
        let db = |error: sqlx::Error| error.to_string();
        let failures = BundleIds::of(bundle).failures;
        for (index, update) in list(bundle.get("updates")).into_iter().enumerate() {
            if enumerated(UPDATE_STATUSES, update.get("status")).is_none() {
                continue;
            }
            let Some(failure) = text(update.get("failure_id")).filter(|failure| !failures.contains(failure)) else {
                continue;
            };
            if Self::query("SELECT id FROM workflow_failures WHERE id=$1").bind(failure).fetch_optional(&mut **tx).await.map_err(db)?.is_some() {
                return Err(format!("Imported updates.{index}.failure_id names a failure of another run."));
            }
        }
        Ok(())
    }

    /// Whether to write a lineage link to this related run now. Native
    /// links only runs of one namespace (Server #765): a related run or
    /// instance in another namespace fails the import. A related run that
    /// does not exist yet is left to its own import, whose bundle carries
    /// the same link, so no link ever waits for a run that a later import
    /// could create in another namespace.
    async fn import_link_endpoint(
        tx: &mut super::transaction::Transaction<'_, DB>,
        namespace: &str,
        link_id: &str,
        other_instance: &str,
        other_run: &str,
    ) -> std::result::Result<bool, String> {
        let db = |error: sqlx::Error| error.to_string();
        let field = |row: &DB::Row, name: &str| DB::optional_string(row, name).map_err(|error| error.to_string());
        if let Some(related) = Self::query("SELECT namespace,workflow_instance_id FROM workflow_runs WHERE id=$1")
            .bind(other_run).fetch_optional(&mut **tx).await.map_err(db)?
        {
            if field(&related, "namespace")?.as_deref() != Some(namespace) {
                return Err(format!("Imported workflow link [{link_id}] names a run of another namespace."));
            }
            if field(&related, "workflow_instance_id")?.as_deref() != Some(other_instance) {
                return Err(format!("Imported workflow link [{link_id}] names a run outside the instance it names."));
            }
            return Ok(true);
        }
        if let Some(owner) = Self::query("SELECT namespace FROM workflow_instances WHERE id=$1")
            .bind(other_instance).fetch_optional(&mut **tx).await.map_err(db)?
            && field(&owner, "namespace")?.as_deref() != Some(namespace)
        {
            return Err(format!("Imported workflow link [{link_id}] names an instance of another namespace."));
        }
        Ok(false)
    }

    async fn import_rows(tx: &mut super::transaction::Transaction<'_, DB>, plan: &Plan, namespace: &str) -> std::result::Result<Rows, String> {
        let db = |error: sqlx::Error| error.to_string();
        let mut rows: Rows = [0; 15];
        let bundle = &plan.bundle;
        let summary = &plan.summary;
        let imported = Stamp { wall: plan.imported_at, utc: plan.imported_at };
        let run_id = summary.run_id.as_deref().unwrap_or_default();
        let instance_id = summary.instance_id.as_deref().unwrap_or_default();
        let run_number = summary.run_number;
        let events = list(bundle.get("history_events"));
        let commands = list(bundle.get("commands"));
        let history_sequence = int(summary.get("last_history_sequence")).unwrap_or(0).max(max_sequence(&events, "sequence"));
        let last_command_sequence = max_sequence(&commands, "sequence");
        let message_cursor = max_sequence(&commands, "message_sequence");
        let empty = Php::Array(PhpArray::default());
        let payloads = bundle.get("payloads").filter(|value| value.is_array());
        let payload_codec = text(payloads.and_then(|payloads| payloads.get("codec"))).unwrap_or("avro").to_owned();
        let output = payloads.and_then(|payloads| payloads.get("output")).filter(|value| value.is_array());
        let output_codec = if matches!(output.and_then(|output| output.get("available")), Some(Php::Bool(true))) {
            text(output.and_then(|output| output.get("codec")))
                .or_else(|| output.and_then(|output| output.get("data")).and_then(|data| text(data.get("codec"))))
                .or_else(|| {
                    events.iter().rev()
                        .find(|event| event.get("type").and_then(Php::str) == Some("WorkflowCompleted") && event.get("payload").is_some_and(Php::is_array))
                        .and_then(|event| text(event.at(&["payload", "payload_codec"])))
                })
                .map(str::to_owned)
        } else {
            None
        };
        // `payloadData`: an available payload's stored value.
        let payload_data = |key: &str| -> std::result::Result<Option<String>, String> {
            let payload = payloads.and_then(|payloads| payloads.get(key)).filter(|value| value.is_array());
            match payload {
                Some(payload) if matches!(payload.get("available"), Some(Php::Bool(true))) => payload_row_value(payload.get("data")),
                _ => Ok(None),
            }
        };
        let field = |key: &str| text(summary.get(key));
        let started = time(summary.get("started_at"))?;
        let business_key = field("business_key");
        let visibility = document_or_empty(summary.get("visibility_labels"));

        // The instance, reused only within this namespace.
        let instance = Self::query("SELECT namespace,current_run_id,run_count,last_message_sequence FROM workflow_instances WHERE id=$1")
            .bind(instance_id).fetch_optional(&mut **tx).await.map_err(db)?;
        let (current_run, run_count, last_message) = match &instance {
            Some(instance) => {
                let owner = DB::optional_string(instance, "namespace").map_err(|error| error.to_string())?;
                if owner.as_deref() != Some(namespace) {
                    return Err(format!("Workflow instance [{instance_id}] belongs to another namespace."));
                }
                (DB::optional_string(instance, "current_run_id").map_err(|error| error.to_string())?,
                    DB::number(instance, "run_count").map_err(|error| error.to_string())?,
                    DB::number(instance, "last_message_sequence").map_err(|error| error.to_string())?)
            }
            None => {
                Self::query("INSERT INTO workflow_instances(id,workflow_class,workflow_type,namespace,business_key,visibility_labels,memo,current_run_id,run_count,last_message_sequence,started_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,NULL,$8,$9,$10,$11,$12)")
                    .bind(instance_id).bind(summary.workflow_class.as_str()).bind(summary.workflow_type.as_str()).bind(namespace)
                    .bind(business_key).bind(DB::document(&visibility)).bind(DB::document(&document_or_empty(summary.get("memo"))))
                    .bind(run_number).bind(message_cursor).bind(DB::optional_time(wall(started)))
                    .bind(DB::bind_time(started.unwrap_or(imported).wall)).bind(DB::bind_time(imported.wall))
                    .execute(&mut **tx).await.map_err(db)?;
                rows[row("workflow_instances")] += 1;
                (None, run_number, message_cursor)
            }
        };
        Self::check_store_references(tx, bundle, namespace, run_id, instance_id).await?;

        let closed = time(summary.get("closed_at"))?;
        let archived = time(summary.get("archived_at"))?;
        let progress = time(summary.get("last_progress_at"))?;
        let arguments = payload_data("arguments")?;
        let output_data = payload_data("output")?;
        Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,run_number,workflow_class,workflow_type,namespace,business_key,visibility_labels,status,closed_reason,compatibility,payload_codec,output_payload_codec,arguments,output,connection,queue,last_history_sequence,last_command_sequence,message_cursor_position,started_at,closed_at,archived_at,archive_command_id,archive_reason,last_progress_at,import_source,import_id,import_dedupe_key,import_contract_version,imported_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21,$22,$23,$24,$25,$26,$27,$28,$29,$30,$31,$32,$33)")
            .bind(run_id).bind(instance_id).bind(run_number).bind(summary.workflow_class.as_str()).bind(summary.workflow_type.as_str())
            .bind(namespace).bind(business_key).bind(DB::document(&visibility)).bind(summary.status.as_str())
            .bind(field("closed_reason")).bind(field("compatibility")).bind(payload_codec.as_str()).bind(output_codec.as_deref())
            .bind(arguments.as_deref()).bind(output_data.as_deref()).bind(field("connection")).bind(field("queue"))
            .bind(history_sequence).bind(last_command_sequence).bind(message_cursor)
            .bind(DB::optional_time(wall(started))).bind(DB::optional_time(wall(closed)))
            .bind(DB::optional_time(archived.map(|stamp| stamp.utc))).bind(field("archive_command_id")).bind(field("archive_reason"))
            .bind(DB::bind_time(progress.unwrap_or(imported).wall)).bind(IMPORT_SOURCE).bind(plan.import_id.as_str())
            .bind(text(bundle.get("dedupe_key"))).bind(CONTRACT_VERSION).bind(DB::bind_time(imported.wall))
            .bind(DB::bind_time(started.unwrap_or(imported).wall)).bind(DB::bind_time(progress.unwrap_or(imported).wall))
            .execute(&mut **tx).await.map_err(db)?;
        rows[row("workflow_runs")] += 1;

        // The run becomes current when it says so, or when it closes an
        // instance with no current run.
        let current = truthy(summary.get("is_current_run")) || (current_run.is_none() && summary.terminal());
        Self::query("UPDATE workflow_instances SET current_run_id=$1,run_count=$2,last_message_sequence=$3,updated_at=$4 WHERE id=$5")
            .bind(if current { Some(run_id) } else { current_run.as_deref() }).bind(run_count.max(run_number))
            .bind(last_message.max(message_cursor)).bind(DB::bind_time(imported.wall)).bind(instance_id)
            .execute(&mut **tx).await.map_err(db)?;

        for event in &events {
            let Some(kind) = enumerated(HISTORY_EVENT_TYPES, event.get("type")) else {
                continue;
            };
            let recorded = time(event.get("recorded_at"))?;
            let created = recorded.unwrap_or_else(stamp_now);
            let event_id = text(event.get("id")).map_or_else(id, str::to_owned);
            Self::query("INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,workflow_task_id,workflow_command_id,recorded_at,recorded_at_utc,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11)")
                .bind(event_id).bind(run_id).bind(int(event.get("sequence")).unwrap_or(0)).bind(kind)
                .bind(DB::document(&document_or_empty(event.get("payload")))).bind(text(event.get("workflow_task_id")))
                .bind(text(event.get("workflow_command_id"))).bind(DB::optional_time(recorded.map(|stamp| stamp.utc)))
                .bind(DB::optional_time(recorded.map(|stamp| stamp.utc))).bind(DB::bind_time(created.wall)).bind(DB::bind_time(created.wall))
                .execute(&mut **tx).await.map_err(db)?;
            rows[row("workflow_history_events")] += 1;
        }

        for command in &commands {
            let (Some(kind), Some(status)) = (enumerated(COMMAND_TYPES, command.get("type")), enumerated(COMMAND_STATUSES, command.get("status"))) else {
                continue;
            };
            let accepted = time(command.get("accepted_at"))?;
            let applied = time(command.get("applied_at"))?;
            let rejected = time(command.get("rejected_at"))?;
            let outcome = cast_enum(COMMAND_OUTCOMES, "CommandOutcome", text(command.get("outcome")))?;
            let payload = payload_row_value(command.get("payload"))?;
            let created = accepted.unwrap_or_else(stamp_now);
            let updated = applied.or(rejected).or(accepted).unwrap_or_else(stamp_now);
            Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,requested_workflow_run_id,resolved_workflow_run_id,command_type,target_scope,source,context,status,outcome,workflow_class,workflow_type,payload_codec,payload,rejection_reason,command_sequence,message_sequence,accepted_at,applied_at,rejected_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21,$22,$23)")
                .bind(text(command.get("id")).map_or_else(id, str::to_owned)).bind(instance_id).bind(run_id)
                .bind(text(command.get("requested_run_id"))).bind(text(command.get("resolved_run_id")).unwrap_or(run_id)).bind(kind)
                .bind(text(command.get("target_scope")).unwrap_or("instance")).bind(text(command.get("source")).unwrap_or("embedded_v2_import"))
                .bind(DB::document(&document_or_empty(command.get("context")))).bind(status).bind(outcome)
                .bind(Some(summary.workflow_class.as_str()).filter(|value| !value.is_empty()))
                .bind(Some(summary.workflow_type.as_str()).filter(|value| !value.is_empty()))
                .bind(text(command.get("payload_codec"))).bind(payload.as_deref()).bind(text(command.get("rejection_reason")))
                .bind(DB::optional_integer(int(command.get("sequence")))).bind(DB::optional_integer(int(command.get("message_sequence"))))
                .bind(DB::optional_time(wall(accepted))).bind(DB::optional_time(wall(applied))).bind(DB::optional_time(wall(rejected)))
                .bind(DB::bind_time(created.wall)).bind(DB::bind_time(updated.wall))
                .execute(&mut **tx).await.map_err(db)?;
            rows[row("workflow_commands")] += 1;
        }

        for signal in list(bundle.get("signals")) {
            let Some(status) = enumerated(SIGNAL_STATUSES, signal.get("status")) else {
                continue;
            };
            let received = time(signal.get("received_at"))?;
            let applied = time(signal.get("applied_at"))?;
            let rejected = time(signal.get("rejected_at"))?;
            let closed = time(signal.get("closed_at"))?;
            let outcome = cast_enum(COMMAND_OUTCOMES, "CommandOutcome", text(signal.get("outcome")))?;
            let arguments = payload_row_value(signal.get("arguments"))?;
            let created = received.unwrap_or_else(stamp_now);
            let updated = closed.or(received).unwrap_or_else(stamp_now);
            Self::query("INSERT INTO workflow_signal_records(id,workflow_command_id,workflow_instance_id,workflow_run_id,target_scope,requested_workflow_run_id,resolved_workflow_run_id,signal_name,signal_wait_id,status,outcome,rejection_reason,validation_errors,command_sequence,workflow_sequence,payload_codec,arguments,received_at,applied_at,rejected_at,closed_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21,$22,$23)")
                .bind(text(signal.get("id")).map_or_else(id, str::to_owned)).bind(text(signal.get("command_id")).map_or_else(id, str::to_owned))
                .bind(instance_id).bind(run_id).bind(text(signal.get("target_scope")).unwrap_or("instance"))
                .bind(text(signal.get("requested_run_id"))).bind(text(signal.get("resolved_run_id")).unwrap_or(run_id))
                .bind(text(signal.get("name")).unwrap_or("imported-signal")).bind(text(signal.get("signal_wait_id"))).bind(status)
                .bind(outcome).bind(text(signal.get("rejection_reason"))).bind(DB::document(&document_or_empty(signal.get("validation_errors"))))
                .bind(DB::optional_integer(int(signal.get("command_sequence")))).bind(DB::optional_integer(int(signal.get("workflow_sequence"))))
                .bind(text(signal.get("payload_codec"))).bind(arguments.as_deref())
                .bind(DB::optional_time(wall(received))).bind(DB::optional_time(wall(applied))).bind(DB::optional_time(wall(rejected)))
                .bind(DB::optional_time(wall(closed))).bind(DB::bind_time(created.wall)).bind(DB::bind_time(updated.wall))
                .execute(&mut **tx).await.map_err(db)?;
            rows[row("workflow_signal_records")] += 1;
        }

        for update in list(bundle.get("updates")) {
            let Some(status) = enumerated(UPDATE_STATUSES, update.get("status")) else {
                continue;
            };
            let accepted = time(update.get("accepted_at"))?;
            let applied = time(update.get("applied_at"))?;
            let rejected = time(update.get("rejected_at"))?;
            let closed = time(update.get("closed_at"))?;
            let outcome = cast_enum(COMMAND_OUTCOMES, "CommandOutcome", text(update.get("outcome")))?;
            let arguments = payload_row_value(update.get("arguments"))?;
            let result = payload_row_value(update.get("result"))?;
            let created = accepted.unwrap_or_else(stamp_now);
            let updated = closed.or(accepted).unwrap_or_else(stamp_now);
            Self::query("INSERT INTO workflow_updates(id,workflow_command_id,workflow_instance_id,workflow_run_id,target_scope,requested_workflow_run_id,resolved_workflow_run_id,update_name,status,outcome,command_sequence,workflow_sequence,payload_codec,arguments,result,validation_errors,rejection_reason,failure_id,accepted_at,applied_at,rejected_at,closed_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21,$22,$23,$24)")
                .bind(text(update.get("id")).map_or_else(id, str::to_owned)).bind(text(update.get("command_id")).map_or_else(id, str::to_owned))
                .bind(instance_id).bind(run_id).bind(text(update.get("target_scope")).unwrap_or("instance"))
                .bind(text(update.get("requested_run_id"))).bind(text(update.get("resolved_run_id")).unwrap_or(run_id))
                .bind(text(update.get("name")).unwrap_or("imported-update")).bind(status).bind(outcome)
                .bind(DB::optional_integer(int(update.get("command_sequence")))).bind(DB::optional_integer(int(update.get("workflow_sequence"))))
                .bind(text(update.get("payload_codec"))).bind(arguments.as_deref()).bind(result.as_deref())
                .bind(DB::document(&document_or_empty(update.get("validation_errors")))).bind(text(update.get("rejection_reason")))
                .bind(text(update.get("failure_id"))).bind(DB::optional_time(wall(accepted))).bind(DB::optional_time(wall(applied)))
                .bind(DB::optional_time(wall(rejected))).bind(DB::optional_time(wall(closed)))
                .bind(DB::bind_time(created.wall)).bind(DB::bind_time(updated.wall))
                .execute(&mut **tx).await.map_err(db)?;
            rows[row("workflow_updates")] += 1;
        }

        for task in list(bundle.get("tasks")) {
            let (Some(kind), Some(status)) = (enumerated(TASK_TYPES, task.get("type")), enumerated(TASK_STATUSES, task.get("status"))) else {
                continue;
            };
            let available = time(task.get("available_at"))?;
            let leased = time(task.get("leased_at"))?;
            let lease_expires = time(task.get("lease_expires_at"))?;
            let dispatch_attempt = time(task.get("last_dispatch_attempt_at"))?;
            let dispatched = time(task.get("last_dispatched_at"))?;
            let claim_failed = time(task.get("last_claim_failed_at"))?;
            let repair_available = time(task.get("repair_available_at"))?;
            let created = available.unwrap_or_else(stamp_now);
            let updated = dispatched.or(available).unwrap_or_else(stamp_now);
            Self::query("INSERT INTO workflow_tasks(id,workflow_run_id,namespace,task_type,status,payload,connection,queue,compatibility,available_at,leased_at,lease_owner,lease_expires_at,attempt_count,repair_count,last_dispatch_attempt_at,last_dispatched_at,last_dispatch_error,last_claim_failed_at,last_claim_error,repair_available_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21,$22,$23)")
                .bind(text(task.get("id")).map_or_else(id, str::to_owned)).bind(run_id).bind(namespace).bind(kind).bind(status)
                .bind(DB::document(&document_or_empty(task.get("payload")))).bind(text(task.get("connection"))).bind(text(task.get("queue")))
                .bind(text(task.get("compatibility"))).bind(DB::optional_time(wall(available))).bind(DB::optional_time(wall(leased)))
                .bind(text(task.get("lease_owner"))).bind(DB::optional_time(wall(lease_expires)))
                .bind(int(task.get("attempt_count")).unwrap_or(0)).bind(int(task.get("repair_count")).unwrap_or(0))
                .bind(DB::optional_time(wall(dispatch_attempt))).bind(DB::optional_time(wall(dispatched)))
                .bind(text(task.get("last_dispatch_error"))).bind(DB::optional_time(wall(claim_failed)))
                .bind(text(task.get("last_claim_error"))).bind(DB::optional_time(wall(repair_available)))
                .bind(DB::bind_time(created.wall)).bind(DB::bind_time(updated.wall))
                .execute(&mut **tx).await.map_err(db)?;
            rows[row("workflow_tasks")] += 1;
        }

        let activities = list(bundle.get("activities"));
        for activity in &activities {
            let (Some(activity_id), Some(status)) = (text(activity.get("id")), enumerated(ACTIVITY_STATUSES, activity.get("status"))) else {
                continue;
            };
            let created = time(activity.get("created_at"))?;
            let started = time(activity.get("started_at"))?;
            let closed = time(activity.get("closed_at"))?;
            let heartbeat = time(activity.get("last_heartbeat_at"))?;
            let class = text(activity.get("activity_class")).or_else(|| text(activity.get("activity_type"))).unwrap_or("imported-activity");
            let kind = text(activity.get("activity_type")).or_else(|| text(activity.get("activity_class"))).unwrap_or("imported-activity");
            let arguments = payload_row_value(activity.get("arguments"))?;
            let result = payload_row_value(activity.get("result"))?;
            let row_created = created.unwrap_or_else(stamp_now);
            let row_updated = closed.or(created).unwrap_or_else(stamp_now);
            Self::query("INSERT INTO activity_executions(id,workflow_run_id,sequence,activity_class,activity_type,status,payload_codec,arguments,result,exception,connection,queue,attempt_count,current_attempt_id,retry_policy,parallel_group_path,started_at,closed_at,last_heartbeat_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21)")
                .bind(activity_id).bind(run_id).bind(int(activity.get("sequence")).unwrap_or(0)).bind(class).bind(kind).bind(status)
                .bind(text(activity.get("payload_codec")).unwrap_or(payload_codec.as_str())).bind(arguments.as_deref()).bind(result.as_deref())
                .bind(text(activity.get("exception"))).bind(text(activity.get("connection"))).bind(text(activity.get("queue")))
                .bind(int(activity.get("attempt_count")).unwrap_or(0)).bind(text(activity.get("current_attempt_id")))
                .bind(DB::document(&document_or_empty(activity.get("retry_policy"))))
                .bind(DB::document(&document_or_empty(activity.get("parallel_group_path"))))
                .bind(DB::optional_time(wall(started))).bind(DB::optional_time(wall(closed))).bind(DB::optional_time(wall(heartbeat)))
                .bind(DB::bind_time(row_created.wall)).bind(DB::bind_time(row_updated.wall))
                .execute(&mut **tx).await.map_err(db)?;
            rows[row("activity_executions")] += 1;
        }

        for activity in &activities {
            let Some(activity_id) = text(activity.get("id")) else {
                continue;
            };
            for attempt in list(activity.get("attempts")) {
                let Some(status) = enumerated(ATTEMPT_STATUSES, attempt.get("status")) else {
                    continue;
                };
                let started = time(attempt.get("started_at"))?.unwrap_or_else(stamp_now);
                let heartbeat = time(attempt.get("last_heartbeat_at"))?;
                let lease_expires = time(attempt.get("lease_expires_at"))?;
                let closed = time(attempt.get("closed_at"))?;
                Self::query("INSERT INTO activity_attempts(id,workflow_run_id,activity_execution_id,workflow_task_id,worker_attempt_id,attempt_number,status,lease_owner,started_at,last_heartbeat_at,lease_expires_at,closed_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14)")
                    .bind(text(attempt.get("id")).map_or_else(id, str::to_owned)).bind(run_id).bind(activity_id)
                    .bind(text(attempt.get("workflow_task_id")).or_else(|| text(attempt.get("task_id"))))
                    .bind(text(attempt.get("worker_attempt_id"))).bind(int(attempt.get("attempt_number")).unwrap_or(1)).bind(status)
                    .bind(text(attempt.get("lease_owner"))).bind(DB::bind_time(started.wall)).bind(DB::optional_time(wall(heartbeat)))
                    .bind(DB::optional_time(wall(lease_expires))).bind(DB::optional_time(wall(closed)))
                    .bind(DB::bind_time(started.wall)).bind(DB::bind_time(closed.unwrap_or(started).wall))
                    .execute(&mut **tx).await.map_err(db)?;
                rows[row("activity_attempts")] += 1;
            }
        }

        for timer in list(bundle.get("timers")) {
            let (Some(timer_id), Some(status)) = (text(timer.get("id")), enumerated(TIMER_STATUSES, timer.get("status"))) else {
                continue;
            };
            let created = time(timer.get("created_at"))?;
            let fire = time(timer.get("fire_at"))?;
            let fired = time(timer.get("fired_at"))?;
            let fire_at = fire.or(created).unwrap_or_else(stamp_now);
            let row_created = created.unwrap_or_else(stamp_now);
            let row_updated = fired.or(created).unwrap_or_else(stamp_now);
            Self::query("INSERT INTO workflow_run_timers(id,workflow_run_id,sequence,status,delay_seconds,fire_at,fired_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9)")
                .bind(timer_id).bind(run_id).bind(int(timer.get("sequence")).unwrap_or(0)).bind(status)
                .bind(int(timer.get("delay_seconds")).unwrap_or(0).max(0)).bind(DB::bind_time(fire_at.wall))
                .bind(DB::optional_time(wall(fired))).bind(DB::bind_time(row_created.wall)).bind(DB::bind_time(row_updated.wall))
                .execute(&mut **tx).await.map_err(db)?;
            rows[row("workflow_run_timers")] += 1;
        }

        for failure in list(bundle.get("failures")) {
            let created = time(failure.get("created_at"))?.unwrap_or_else(stamp_now);
            let category = cast_enum(FAILURE_CATEGORIES, "FailureCategory", text(failure.get("failure_category")))?;
            Self::query("INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,non_retryable,handled,exception_class,message,file,line,trace_preview,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,CASE WHEN $7=1 THEN TRUE ELSE FALSE END,CASE WHEN $8=1 THEN TRUE ELSE FALSE END,$9,$10,$11,$12,$13,$14,$15)")
                .bind(text(failure.get("id")).map_or_else(id, str::to_owned)).bind(run_id)
                .bind(text(failure.get("source_kind")).unwrap_or("workflow")).bind(text(failure.get("source_id")).unwrap_or(run_id))
                .bind(text(failure.get("propagation_kind")).unwrap_or("local")).bind(category)
                .bind(i64::from(truthy(failure.get("non_retryable")))).bind(i64::from(truthy(failure.get("handled"))))
                .bind(text(failure.get("exception_class")).or_else(|| text(failure.get("exception_type"))).unwrap_or("ImportedWorkflowFailure"))
                .bind(text(failure.get("message")).unwrap_or("")).bind(text(failure.get("file")).unwrap_or(""))
                .bind(DB::optional_integer(int(failure.get("line")))).bind(text(failure.get("trace_preview")))
                .bind(DB::bind_time(created.wall)).bind(DB::bind_time(created.wall))
                .execute(&mut **tx).await.map_err(db)?;
            rows[row("workflow_failures")] += 1;
        }

        let links = bundle.get("links").filter(|value| value.is_array()).unwrap_or(&empty);
        for (side, parent) in [("parents", true), ("children", false)] {
            for link in list(links.get(side)) {
                let (other_instance, other_run) = if parent {
                    (text(link.get("parent_workflow_instance_id")), text(link.get("parent_workflow_run_id")))
                } else {
                    (text(link.get("child_workflow_instance_id")), text(link.get("child_workflow_run_id")))
                };
                let (Some(other_instance), Some(other_run)) = (other_instance, other_run) else {
                    continue;
                };
                let (parent_instance, parent_run, child_instance, child_run) = if parent {
                    (other_instance, other_run, instance_id, run_id)
                } else {
                    (instance_id, run_id, other_instance, other_run)
                };
                let link_id = ulid_value(link.get("id")).map_or_else(id, str::to_owned);
                if !Self::import_link_endpoint(tx, namespace, &link_id, other_instance, other_run).await? {
                    continue;
                }
                let link_type = text(link.get("type")).unwrap_or(if parent { "parent" } else { "child" });
                let sequence = int(link.get("sequence"));
                let primary = truthy(link.get("is_primary_parent"));
                let created = time(link.get("created_at"))?.unwrap_or_else(stamp_now);
                // A link exported from both its runs is written once; any
                // other difference fails the import.
                if let Some(existing) = Self::query("SELECT link_type,sequence,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,child_workflow_run_id,CASE WHEN is_primary_parent THEN 1 ELSE 0 END AS primary_flag FROM workflow_links WHERE id=$1")
                    .bind(link_id.as_str()).fetch_optional(&mut **tx).await.map_err(db)?
                {
                    let read = |name: &str| DB::optional_string(&existing, name).map_err(|error| error.to_string());
                    let checks: [(&str, bool); 7] = [
                        ("link_type", read("link_type")?.as_deref() == Some(link_type)),
                        ("sequence", DB::optional_number(&existing, "sequence").map_err(|error| error.to_string())? == sequence),
                        ("parent_workflow_instance_id", read("parent_workflow_instance_id")?.as_deref() == Some(parent_instance)),
                        ("parent_workflow_run_id", read("parent_workflow_run_id")?.as_deref() == Some(parent_run)),
                        ("child_workflow_instance_id", read("child_workflow_instance_id")?.as_deref() == Some(child_instance)),
                        ("child_workflow_run_id", read("child_workflow_run_id")?.as_deref() == Some(child_run)),
                        ("is_primary_parent", (DB::number(&existing, "primary_flag").map_err(|error| error.to_string())? != 0) == primary),
                    ];
                    if let Some((field, _)) = checks.iter().find(|(_, same)| !same) {
                        return Err(format!("Imported workflow link [{link_id}] conflicts on [{field}]."));
                    }
                    continue;
                }
                Self::query("INSERT INTO workflow_links(id,link_type,sequence,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,child_workflow_run_id,is_primary_parent,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,CASE WHEN $8=1 THEN TRUE ELSE FALSE END,$9,$10)")
                    .bind(link_id.as_str()).bind(link_type).bind(DB::optional_integer(sequence)).bind(parent_instance).bind(parent_run)
                    .bind(child_instance).bind(child_run).bind(i64::from(primary))
                    .bind(DB::bind_time(created.wall)).bind(DB::bind_time(created.wall))
                    .execute(&mut **tx).await.map_err(db)?;
                rows[row("workflow_links")] += 1;
            }
        }

        let written = now();
        for memo in plan.memos.as_ref().map_err(Clone::clone)? {
            Self::query(DB::MEMO_UPSERT)
                .bind(run_id).bind(instance_id).bind(memo.key.as_str()).bind(DB::document(&memo.value)).bind(history_sequence)
                .bind(DB::bind_time(written)).bind(DB::bind_time(written)).bind(DB::document(&memo.portable)).bind(history_sequence)
                .execute(&mut **tx).await.map_err(db)?;
            rows[row("workflow_memos")] += 1;
        }

        for attribute in plan.attributes.as_ref().map_err(Clone::clone)? {
            use super::search_attribute_upserts::Typed;
            let value = &attribute.value;
            let text_of = |kind| match value {
                Typed::String(text) if kind == "string" => Some(text.as_str()),
                Typed::Keyword(text) if kind == "keyword" => Some(text.as_str()),
                _ => None,
            };
            Self::query(DB::SEARCH_ATTRIBUTE_UPSERT)
                .bind(run_id).bind(instance_id).bind(attribute.key.as_str()).bind(value.kind())
                .bind(text_of("string")).bind(text_of("keyword"))
                .bind(match value {
                    Typed::KeywordList(items) => Some(DB::document(&json!(items))),
                    _ => None,
                })
                .bind(DB::optional_integer(match value {
                    Typed::Int(number) => Some(*number),
                    _ => None,
                }))
                .bind(DB::optional_float_value(match value {
                    Typed::Float(number) => Some(*number),
                    _ => None,
                }))
                .bind(DB::optional_flag(match value {
                    Typed::Bool(flag) => Some(*flag),
                    _ => None,
                }))
                .bind(DB::optional_time(match value {
                    Typed::Datetime(time) => Some(*time),
                    _ => None,
                }))
                .bind(history_sequence).bind(DB::bind_time(written)).bind(DB::bind_time(written))
                .execute(&mut **tx).await.map_err(db)?;
            rows[row("workflow_search_attributes")] += 1;
        }
        rows[row("workflow_run_summaries")] = 1;
        Ok(rows)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn bundle(text: &str) -> PhpArray {
        php_value::request_body(text.as_bytes())
    }

    #[test]
    fn the_controller_unwraps_bundles_and_reads_options_like_laravel() {
        assert!(matches!(request(b"{}", &[]), Request::Invalid));
        assert!(matches!(request(br#"{"bundle":{}}"#, &[]), Request::Invalid));
        assert!(matches!(request(b"null", &[]), Request::Invalid));
        let Request::Import(bundle, options) = request(br#"{"bundle":{"a":1},"dry_run":"yes","import_id":"  i-1  ","require_signature":"no","signing_key":" "}"#, &[]) else {
            panic!("an import");
        };
        assert!(bundle.contains("a") && options.dry_run && !options.require_signature);
        assert_eq!(options.import_id.as_deref(), Some("i-1"));
        assert_eq!(options.signing_key, None);
        // A string bundle leaves the whole body as the bundle.
        let Request::Import(bundle, options) = request(br#"{"bundle":"x","import_id":12345}"#, &[("dry_run".into(), "1".into())]) else {
            panic!("an import");
        };
        assert!(bundle.contains("import_id") && options.dry_run && options.import_id.is_none());
        // The signing key is read only from the body; in the URL it refuses
        // the request, in any spelling PHP would read.
        let Request::Import(_, options) = request(br#"{"bundle":{"a":1},"signing_key":" k1 "}"#, &[("dry_run".into(), "1".into())]) else {
            panic!("an import");
        };
        assert_eq!(options.signing_key.as_deref(), Some("k1"));
        for key in ["signing_key", "signing_key[]", "signing_key[0]"] {
            assert!(matches!(request(br#"{"bundle":{"a":1}}"#, &[(key.into(), "k1".into())]), Request::KeyInQuery));
        }
    }

    #[test]
    fn eligibility_follows_php_s_order_and_quirks() {
        let body = bundle(r#"{"workflow":{"status":"completed"},"history_complete":1,"redaction":{"paths":["payloads.arguments.data"]},
            "payloads":{"codec":"json","output":{"codec":5}},"tasks":[{"status":"leased"}],"activities":[{"attempts":[{"status":"running"}]}]}"#);
        let summary = Summary::of(&body, "ns");
        let options = Options { dry_run: false, import_id: None, require_signature: true, signing_key: None };
        let rules: Vec<String> = eligibility(&body, "warning", false, &summary, &options).errors.iter()
            .map(|error| error["rule"].as_str().unwrap().to_owned()).collect();
        assert_eq!(rules, ["bundle.signature_required", "workflow.instance_id_missing", "workflow.run_id_missing",
            "workflow.terminal_history_incomplete", "workflow.source_runtime_unsupported", "payload_codec.unsupported",
            "payload_codec.unsupported", "tasks.leased_task_present"]);
        let messages = eligibility(&body, "ok", true, &summary, &options).errors;
        assert!(messages[5]["message"].as_str().unwrap().contains("at [payloads.output.codec] must be \"avro\"; found int."));
        assert_eq!(estimated_rows(&Php::Array(body))[row("workflow_tasks")], 1);
    }

    #[test]
    fn times_parse_like_carbon_for_exported_forms() {
        let stamp = parse_time("2026-10-24T13:00:00.123456+02:00").unwrap();
        assert_eq!(stamp.wall.to_rfc3339(), "2026-10-24T13:00:00.123456+00:00");
        assert_eq!(stamp.utc.to_rfc3339(), "2026-10-24T11:00:00.123456+00:00");
        assert!(parse_time("2026-10-24 13:00:00").is_ok());
        assert!(parse_time("not a time").unwrap_err().starts_with("Could not parse 'not a time'"));
    }
}
