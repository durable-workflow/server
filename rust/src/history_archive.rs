//! Self-contained verified history archives (Server #520).
//!
//! A history export keeps every external payload as a reference envelope and
//! copies no bytes, so it still depends on the source database and payload
//! store. An archive is a directory that holds the export, the exact bytes of
//! every external object it references, a manifest that lists each object's
//! codec, length and SHA-256, and a receipt that is written only after the
//! whole directory has been verified from disk.
//!
//! Format version 1:
//!
//! ```text
//! manifest.json            durable-workflow.history-archive.manifest.v1
//! history-export.json      the Server's export response body, byte for byte
//! objects/<reference_id>   the exact bytes of each required external object
//! receipt.json             durable-workflow.history-archive.receipt.v1, last
//! ```
//!
//! This module does no I/O except reading an archive directory. It plans an
//! archive from an export, builds the manifest and receipt documents, and
//! verifies an archive using nothing but its files. A client such as
//! `dw workflow:history-archive` copies the bytes through the Server's HTTP
//! API while it holds the external payload backup hold.
use crate::external_payload::{REFERENCE_SCHEMA, Reference};
use serde_json::{Map, Value, json};
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;
use std::io::Read;
use std::path::Path;

pub const MANIFEST_SCHEMA: &str = "durable-workflow.history-archive.manifest.v1";
pub const RECEIPT_SCHEMA: &str = "durable-workflow.history-archive.receipt.v1";
pub const REPORT_SCHEMA: &str = "durable-workflow.history-archive.verification.v1";
pub const FORMAT_VERSION: i64 = 1;
pub const MANIFEST_FILE: &str = "manifest.json";
pub const HISTORY_FILE: &str = "history-export.json";
pub const RECEIPT_FILE: &str = "receipt.json";
pub const OBJECTS_DIR: &str = "objects";
/// The backup hold an archive copies under.
pub const HOLD_KIND: &str = "durable-workflow.external-payload-backup-hold.v1";
const EXPORT_SCHEMA: &str = "durable-workflow.v2.history-export";
const EXPORT_SCHEMA_VERSION: i64 = 2;

/// Application values the export decodes into plain JSON. A map there that
/// looks like an envelope is application data, not a reference.
const APPLICATION_JSON: &[&str] = &[
    "workflow.memo",
    "workflow.search_attributes",
    "summary.search_attributes",
    "payload_manifest",
    "codec_schemas",
    "integrity",
];

/// One external object the archive must hold, with every export path that
/// references it.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct RequiredObject {
    pub reference: Reference,
    pub paths: Vec<String>,
}

impl RequiredObject {
    /// Its file in the archive. A validated reference id is `ep_` and 26
    /// Crockford base-32 characters, so it is always one safe file name.
    pub fn path(&self) -> String {
        format!("{OBJECTS_DIR}/{}", self.reference.reference_id)
    }
}

/// Why an export cannot be archived, or why an archive does not verify.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Finding {
    pub code: &'static str,
    pub message: String,
    pub path: Option<String>,
}

impl Finding {
    fn new(code: &'static str, message: impl Into<String>, path: Option<String>) -> Self {
        Self { code, message: message.into(), path }
    }

    pub fn to_json(&self) -> Value {
        json!({"code": self.code, "message": self.message, "path": self.path})
    }
}

/// What an export requires: its external objects in first-reference order,
/// and the blockers that refuse an archive of it.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Plan {
    pub objects: Vec<RequiredObject>,
    pub blockers: Vec<Finding>,
}

impl Plan {
    pub fn object_bytes(&self) -> i64 {
        self.objects.iter().map(|object| object.reference.size_bytes).sum()
    }
}

/// The required objects and blockers of one history export.
///
/// Refuses an export that is not a complete, unredacted selected-run history
/// with every sequence from 1 to `last_history_sequence`, or that holds a
/// reference the runtime transport cannot fetch.
pub fn plan(export: &Value) -> Plan {
    let mut plan = Plan::default();
    if export["schema"] != EXPORT_SCHEMA || export["schema_version"] != EXPORT_SCHEMA_VERSION {
        plan.blockers.push(Finding::new(
            "unsupported_export",
            format!("Only {EXPORT_SCHEMA} version {EXPORT_SCHEMA_VERSION} exports can be archived."),
            None,
        ));
        return plan;
    }
    let workflow = &export["workflow"];
    if workflow["details_state"] == "pruned" || !workflow["details_pruned_at"].is_null() {
        plan.blockers.push(Finding::new(
            "history_pruned",
            "Retention cleanup already deleted this run's details; its export cannot be a complete archive.",
            Some("workflow.details_state".into()),
        ));
    }
    if export["history_complete"] != true {
        plan.blockers.push(Finding::new(
            "history_incomplete",
            "The export does not report complete history (an open run, or pruned history).",
            Some("history_complete".into()),
        ));
    }
    let redacted_entry = export["payload_manifest"]["entries"]
        .as_array()
        .is_some_and(|entries| entries.iter().any(|entry| entry["redacted"] == true));
    if export["redaction"]["applied"] == true || redacted_entry {
        plan.blockers.push(Finding::new(
            "history_redacted",
            "The export has redacted material; a redacted export is never archived as complete.",
            Some("redaction".into()),
        ));
    }
    let events = export["history_events"].as_array().map(Vec::as_slice).unwrap_or_default();
    let last = workflow["last_history_sequence"].as_i64();
    let contiguous = events
        .iter()
        .enumerate()
        .all(|(index, event)| event["sequence"].as_i64() == Some(index as i64 + 1));
    if !contiguous || last != Some(events.len() as i64) {
        plan.blockers.push(Finding::new(
            "history_sequence_gap",
            "History events must run from sequence 1 to workflow.last_history_sequence without a gap.",
            Some("history_events".into()),
        ));
    }
    let mut seen: BTreeMap<String, usize> = BTreeMap::new();
    walk(export, String::new(), &mut plan, &mut seen);
    plan
}

fn child(path: &str, key: &str) -> String {
    if path.is_empty() { key.to_owned() } else { format!("{path}.{key}") }
}

fn walk(value: &Value, path: String, plan: &mut Plan, seen: &mut BTreeMap<String, usize>) {
    if APPLICATION_JSON.contains(&path.as_str()) {
        return;
    }
    match value {
        Value::Array(items) => {
            for (index, item) in items.iter().enumerate() {
                walk(item, child(&path, &index.to_string()), plan, seen);
            }
        }
        Value::Object(fields) => {
            if let Some(reference) = fields.get("external_payload").filter(|value| value["schema"] == REFERENCE_SCHEMA) {
                match Reference::validate(reference) {
                    Ok(reference) => add(plan, seen, reference, path),
                    Err(error) => plan.blockers.push(Finding::new(
                        "invalid_payload_reference",
                        format!("Invalid runtime external payload reference: {error}"),
                        Some(path),
                    )),
                }
                return;
            }
            if fields.contains_key("codec") && fields.get("external_storage").is_some_and(Value::is_object) {
                plan.blockers.push(Finding::new(
                    "unsupported_payload_reference",
                    "A provider-specific external_storage reference cannot be fetched through the runtime transport.",
                    Some(path),
                ));
                return;
            }
            for (key, item) in fields {
                walk(item, child(&path, key), plan, seen);
            }
        }
        _ => {}
    }
}

fn add(plan: &mut Plan, seen: &mut BTreeMap<String, usize>, reference: Reference, path: String) {
    match seen.get(&reference.reference_id) {
        Some(&index) if plan.objects[index].reference == reference => plan.objects[index].paths.push(path),
        Some(_) => plan.blockers.push(Finding::new(
            "conflicting_payload_reference",
            format!("{} is referenced with different lengths or digests.", reference.reference_id),
            Some(path),
        )),
        None => {
            seen.insert(reference.reference_id.clone(), plan.objects.len());
            plan.objects.push(RequiredObject { reference, paths: vec![path] });
        }
    }
}

/// Lowercase hexadecimal SHA-256 of `bytes`.
pub fn sha256_hex(bytes: &[u8]) -> String {
    hex(&Sha256::digest(bytes))
}

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|byte| format!("{byte:02x}")).collect()
}

/// What an archive covers. A selected-run archive is never a backup of the
/// application or its database.
pub fn scope() -> Value {
    json!({
        "kind": "selected_run",
        "complete_backup": false,
        "includes": [
            "the selected run's typed history events, from sequence 1 to last_history_sequence",
            "the run's commands, signals, updates, tasks, activities, timers, failures, waits and timeline as the export carries them",
            "the identities of linked parent, child and continued runs, not their histories",
            "the exact bytes of every external payload the export references",
        ],
        "excludes": [
            "other runs of the workflow instance, including continued, parent and child runs",
            "namespace configuration, schedules, search attribute definitions and worker state",
            "the application database and any other application data",
        ],
    })
}

fn identity(export: &Value, namespace: &str) -> Value {
    let workflow = &export["workflow"];
    json!({
        "namespace": namespace,
        "workflow_id": workflow["instance_id"],
        "run_id": workflow["run_id"],
        "run_number": workflow["run_number"],
        "workflow_type": workflow["workflow_type"],
        "status": workflow["status"],
        "closed_at": workflow["closed_at"],
        "last_history_sequence": workflow["last_history_sequence"],
        "history_event_count": export["history_events"].as_array().map_or(0, Vec::len),
        "dedupe_key": export["dedupe_key"],
    })
}

/// The manifest of an archive of `export`, whose exact bytes are
/// `export_bytes`. `hold` describes the backup hold the copy runs under.
pub fn manifest(
    export: &Value,
    export_bytes: &[u8],
    objects: &[RequiredObject],
    namespace: &str,
    created_at: &str,
    created_by: &str,
    hold: Value,
) -> Value {
    let entries: Vec<Value> = objects
        .iter()
        .map(|object| {
            json!({"reference_id": object.reference.reference_id, "codec": object.reference.codec,
                "size_bytes": object.reference.size_bytes, "sha256": object.reference.sha256,
                "path": object.path(), "referenced_by": object.paths})
        })
        .collect();
    json!({
        "schema": MANIFEST_SCHEMA,
        "format_version": FORMAT_VERSION,
        "created_at": created_at,
        "created_by": created_by,
        "identity": identity(export, namespace),
        "scope": scope(),
        "history": {
            "path": HISTORY_FILE,
            "media_type": "application/json",
            "size_bytes": export_bytes.len(),
            "sha256": sha256_hex(export_bytes),
            "export_schema": export["schema"],
            "export_schema_version": export["schema_version"],
            "export_checksum": export["integrity"]["checksum"],
            "history_complete": export["history_complete"],
        },
        "objects": entries,
        "totals": {"object_count": objects.len(), "object_bytes": objects.iter().map(|object| object.reference.size_bytes).sum::<i64>()},
        "hold": hold,
        "encryption": {"applied": false, "responsibility": "operator"},
        "lifetime": "application_owned",
    })
}

/// The receipt for a cold verification `report` of the archive whose manifest
/// bytes are `manifest_bytes`. `hold` records that the copy's hold was still
/// held after the verification.
pub fn receipt(manifest_bytes: &[u8], report: &Value, verified_at: &str, hold: Value) -> Value {
    json!({
        "schema": RECEIPT_SCHEMA,
        "format_version": FORMAT_VERSION,
        "outcome": "verified",
        "verification": "cold",
        "verified_at": verified_at,
        "manifest_sha256": sha256_hex(manifest_bytes),
        "history_sha256": report["history_sha256"],
        "object_count": report["object_count"],
        "object_bytes": report["object_bytes"],
        "hold": hold,
    })
}

/// Verify an archive from its files alone, its receipt included: `verified`,
/// `incomplete` (a supported archive that fails a check) or `refused` (not
/// an archive this version can read).
pub fn verify(dir: &Path) -> Value {
    check(dir, true)
}

/// [`verify`] without the receipt: the cold verification that decides
/// whether a receipt may be written.
pub fn verify_contents(dir: &Path) -> Value {
    check(dir, false)
}

enum FileRead {
    Bytes(Vec<u8>),
    Missing,
    NotRegular,
    Unreadable(String),
}

/// A regular file's bytes. A link is refused: an archive that points at
/// files elsewhere is not self-contained.
fn read_file(path: &Path) -> FileRead {
    match std::fs::symlink_metadata(path) {
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => FileRead::Missing,
        Err(error) => FileRead::Unreadable(error.to_string()),
        Ok(metadata) if !metadata.file_type().is_file() => FileRead::NotRegular,
        Ok(_) => match std::fs::read(path) {
            Ok(bytes) => FileRead::Bytes(bytes),
            Err(error) => FileRead::Unreadable(error.to_string()),
        },
    }
}

/// A regular file's length and SHA-256, streamed in bounded chunks.
fn digest_file(path: &Path) -> Result<(u64, String), FileRead> {
    match std::fs::symlink_metadata(path) {
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Err(FileRead::Missing),
        Err(error) => return Err(FileRead::Unreadable(error.to_string())),
        Ok(metadata) if !metadata.file_type().is_file() => return Err(FileRead::NotRegular),
        Ok(_) => {}
    }
    let mut file = std::fs::File::open(path).map_err(|error| FileRead::Unreadable(error.to_string()))?;
    let mut hasher = Sha256::new();
    let mut buffer = vec![0u8; 64 * 1024];
    let mut length = 0u64;
    loop {
        let read = file.read(&mut buffer).map_err(|error| FileRead::Unreadable(error.to_string()))?;
        if read == 0 {
            break;
        }
        length += read as u64;
        hasher.update(&buffer[..read]);
    }
    Ok((length, hex(&hasher.finalize())))
}

struct Report {
    findings: Vec<Finding>,
    refused: bool,
}

impl Report {
    fn refuse(&mut self, code: &'static str, message: impl Into<String>, path: Option<String>) {
        self.refused = true;
        self.findings.push(Finding::new(code, message, path));
    }

    fn fail(&mut self, code: &'static str, message: impl Into<String>, path: Option<String>) {
        self.findings.push(Finding::new(code, message, path));
    }

    fn unreadable(&mut self, file: &str, read: FileRead) {
        let path = Some(file.to_owned());
        match read {
            FileRead::Missing => self.fail("file_missing", format!("{file} is missing."), path),
            FileRead::NotRegular => self.fail("file_not_regular", format!("{file} is not a regular file; links are not self-contained."), path),
            FileRead::Unreadable(error) => self.fail("file_unreadable", format!("{file} could not be read: {error}"), path),
            FileRead::Bytes(_) => {}
        }
    }
}

fn check(dir: &Path, receipt_required: bool) -> Value {
    let mut report = Report { findings: Vec::new(), refused: false };
    let mut out = Map::new();
    out.insert("schema".into(), json!(REPORT_SCHEMA));
    let manifest_bytes = match read_file(&dir.join(MANIFEST_FILE)) {
        FileRead::Bytes(bytes) => bytes,
        FileRead::Missing => {
            report.refuse("manifest_missing", format!("{MANIFEST_FILE} is missing; this is not a history archive."), Some(MANIFEST_FILE.into()));
            return finish(out, report);
        }
        other => {
            report.unreadable(MANIFEST_FILE, other);
            report.refused = true;
            return finish(out, report);
        }
    };
    let manifest: Value = match serde_json::from_slice(&manifest_bytes) {
        Ok(value @ Value::Object(_)) => value,
        _ => {
            report.refuse("manifest_invalid", format!("{MANIFEST_FILE} is not a JSON object."), Some(MANIFEST_FILE.into()));
            return finish(out, report);
        }
    };
    if manifest["schema"] != MANIFEST_SCHEMA {
        report.refuse("unsupported_archive", format!("The manifest schema is not {MANIFEST_SCHEMA}."), Some("schema".into()));
        return finish(out, report);
    }
    if manifest["format_version"] != FORMAT_VERSION {
        report.refuse(
            "unsupported_format_version",
            format!("Archive format version {} is not supported; this verifier reads version {FORMAT_VERSION}.", manifest["format_version"]),
            Some("format_version".into()),
        );
        return finish(out, report);
    }
    out.insert("identity".into(), manifest["identity"].clone());
    out.insert("manifest_sha256".into(), json!(sha256_hex(&manifest_bytes)));

    // The history file, by the manifest's length and digest.
    let history = &manifest["history"];
    if history["path"] != HISTORY_FILE {
        report.fail("manifest_invalid", format!("history.path must be {HISTORY_FILE}."), Some("history.path".into()));
    }
    let export_bytes = match read_file(&dir.join(HISTORY_FILE)) {
        FileRead::Bytes(bytes) => Some(bytes),
        other => {
            report.unreadable(HISTORY_FILE, other);
            None
        }
    };
    let mut plan = Plan::default();
    if let Some(bytes) = &export_bytes {
        let digest = sha256_hex(bytes);
        out.insert("history_sha256".into(), json!(digest));
        if history["size_bytes"].as_u64() != Some(bytes.len() as u64) || history["sha256"] != digest.as_str() {
            report.fail("history_digest_mismatch", format!("{HISTORY_FILE} does not match the manifest's length and SHA-256."), Some(HISTORY_FILE.into()));
        }
        match serde_json::from_slice::<Value>(bytes) {
            Ok(export) => {
                plan = self::plan(&export);
                for blocker in &plan.blockers {
                    report.findings.push(blocker.clone());
                }
                let workflow = &export["workflow"];
                let identity = &manifest["identity"];
                if identity["workflow_id"] != workflow["instance_id"]
                    || identity["run_id"] != workflow["run_id"]
                    || identity["last_history_sequence"] != workflow["last_history_sequence"]
                {
                    report.fail("identity_mismatch", "The manifest identity does not match the archived export.", Some("identity".into()));
                }
            }
            Err(_) => report.fail("history_invalid", format!("{HISTORY_FILE} is not valid JSON."), Some(HISTORY_FILE.into())),
        }
    }

    // Every object the export references, and nothing else, by its length and digest.
    let listed: Vec<&Value> = manifest["objects"].as_array().map(|objects| objects.iter().collect()).unwrap_or_default();
    if !manifest["objects"].is_array() {
        report.fail("manifest_invalid", "objects must be a list.", Some("objects".into()));
    }
    let mut verified = 0usize;
    let mut verified_bytes = 0i64;
    for object in &plan.objects {
        let reference = &object.reference;
        let path = object.path();
        let Some(entry) = listed.iter().find(|entry| entry["reference_id"] == reference.reference_id.as_str()) else {
            report.fail("object_unlisted", format!("{} is referenced by the export but not listed in the manifest.", reference.reference_id), Some(path));
            continue;
        };
        if entry["codec"] != reference.codec
            || entry["size_bytes"] != reference.size_bytes
            || entry["sha256"] != reference.sha256.as_str()
            || entry["path"] != path.as_str()
        {
            report.fail("manifest_object_mismatch", format!("The manifest entry for {} differs from the export's reference.", reference.reference_id), Some(path.clone()));
        }
        match digest_file(&dir.join(OBJECTS_DIR).join(&reference.reference_id)) {
            Err(FileRead::Missing) => report.fail("object_missing", format!("{path} is missing."), Some(path)),
            Err(other) => report.unreadable(&path, other),
            Ok((length, _)) if length != reference.size_bytes as u64 => report.fail(
                "object_size_mismatch",
                format!("{path} has {length} bytes; the reference records {}.", reference.size_bytes),
                Some(path),
            ),
            Ok((_, digest)) if digest != reference.sha256 => report.fail("object_digest_mismatch", format!("{path} does not match its SHA-256."), Some(path)),
            Ok(_) => {
                verified += 1;
                verified_bytes += reference.size_bytes;
            }
        }
    }
    if export_bytes.is_some() {
        for entry in &listed {
            if !plan.objects.iter().any(|object| entry["reference_id"] == object.reference.reference_id.as_str()) {
                report.fail("object_unreferenced", format!("The manifest lists {} but the export does not reference it.", entry["reference_id"]), Some("objects".into()));
            }
        }
    }
    let totals = &manifest["totals"];
    if totals["object_count"] != listed.len() || totals["object_bytes"].as_i64() != Some(listed.iter().filter_map(|entry| entry["size_bytes"].as_i64()).sum()) {
        report.fail("manifest_invalid", "totals do not match the listed objects.", Some("totals".into()));
    }
    out.insert("object_count".into(), json!(plan.objects.len()));
    out.insert("object_bytes".into(), json!(plan.object_bytes()));
    out.insert("verified_objects".into(), json!(verified));
    out.insert("verified_object_bytes".into(), json!(verified_bytes));

    // The receipt binds a cold verification to these exact manifest bytes.
    let receipt = match read_file(&dir.join(RECEIPT_FILE)) {
        FileRead::Bytes(bytes) => match serde_json::from_slice::<Value>(&bytes) {
            Ok(receipt @ Value::Object(_)) => Ok(receipt),
            _ => Err(FileRead::Unreadable("not a JSON object".into())),
        },
        other => Err(other),
    };
    out.insert("receipt".into(), match &receipt {
        Ok(receipt) => json!({"present": true, "verified_at": receipt["verified_at"], "hold": receipt["hold"]}),
        Err(_) => json!({"present": false}),
    });
    if receipt_required {
        match receipt {
            Err(FileRead::Missing) => report.fail(
                "receipt_missing",
                "No verification receipt: the archive was never completed, or its copy or hold failed.",
                Some(RECEIPT_FILE.into()),
            ),
            Err(read) => report.unreadable(RECEIPT_FILE, read),
            Ok(receipt) => {
                if receipt["schema"] != RECEIPT_SCHEMA || receipt["format_version"] != FORMAT_VERSION || receipt["outcome"] != "verified" {
                    report.fail("receipt_invalid", format!("{RECEIPT_FILE} is not a {RECEIPT_SCHEMA} verified receipt."), Some(RECEIPT_FILE.into()));
                } else if receipt["manifest_sha256"] != out["manifest_sha256"] || receipt["history_sha256"] != *out.get("history_sha256").unwrap_or(&Value::Null) {
                    report.fail("receipt_mismatch", "The receipt was issued for a different manifest or history.", Some(RECEIPT_FILE.into()));
                }
            }
        }
    }
    finish(out, report)
}

fn finish(mut out: Map<String, Value>, report: Report) -> Value {
    let outcome = if report.refused {
        "refused"
    } else if report.findings.is_empty() {
        "verified"
    } else {
        "incomplete"
    };
    out.insert("outcome".into(), json!(outcome));
    out.insert("findings".into(), Value::Array(report.findings.iter().map(Finding::to_json).collect()));
    Value::Object(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    const ID_A: &str = "ep_01ARZ3NDEKTSV4RRFFQ69G5FAV";
    const ID_B: &str = "ep_01BX5ZZKBKACTAV9WEVGEMMVRZ";

    fn reference(id: &str, bytes: &[u8]) -> Value {
        json!({"schema": REFERENCE_SCHEMA, "reference_id": id, "codec": "avro",
            "size_bytes": bytes.len(), "sha256": sha256_hex(bytes)})
    }

    fn envelope(id: &str, bytes: &[u8]) -> Value {
        json!({"codec": "avro", "external_payload": reference(id, bytes)})
    }

    fn export(a: &[u8], b: &[u8]) -> Value {
        json!({
            "schema": EXPORT_SCHEMA, "schema_version": 2, "dedupe_key": "run-1:3:t", "history_complete": true,
            "workflow": {"instance_id": "order-1", "run_id": "run-1", "run_number": 1, "workflow_type": "echo",
                "status": "completed", "closed_at": "2026-11-25T00:00:00.000000Z", "last_history_sequence": 3,
                "details_state": "retained", "details_pruned_at": null,
                // Application data shaped like an envelope is not a reference.
                "memo": {"looks": envelope(ID_B, b"not a reference")}},
            "payloads": {"codec": "avro", "arguments": {"available": true, "data": envelope(ID_A, a)},
                "output": {"available": true, "codec": "avro", "data": envelope(ID_B, b)}},
            "history_events": [
                {"sequence": 1, "type": "StartAccepted", "payload": {}},
                {"sequence": 2, "type": "WorkflowStarted", "payload": {"arguments": envelope(ID_A, a)}},
                {"sequence": 3, "type": "WorkflowCompleted", "payload": {"output": envelope(ID_B, b)}},
            ],
            "redaction": {"applied": false, "policy": null, "paths": []},
            "payload_manifest": {"version": 1, "entries": [{"path": "payloads.arguments.data", "redacted": false}]},
            "integrity": {"checksum": "c".repeat(64)},
        })
    }

    #[test]
    fn plan_lists_each_runtime_reference_once_with_every_path() {
        let plan = plan(&export(b"first", b"second"));
        assert_eq!(plan.blockers, vec![]);
        assert_eq!(plan.objects.len(), 2);
        assert_eq!(plan.objects[0].reference.reference_id, ID_A);
        assert_eq!(plan.objects[0].paths, ["payloads.arguments.data", "history_events.1.payload.arguments"]);
        assert_eq!(plan.objects[1].paths, ["payloads.output.data", "history_events.2.payload.output"]);
        assert_eq!(plan.objects[1].path(), format!("objects/{ID_B}"));
        assert_eq!(plan.object_bytes(), 11);
    }

    fn codes(plan: &Plan) -> Vec<&'static str> {
        plan.blockers.iter().map(|finding| finding.code).collect()
    }

    #[test]
    fn plan_refuses_incomplete_pruned_redacted_gapped_and_unfetchable_exports() {
        let mut open = export(b"a", b"b");
        open["history_complete"] = json!(false);
        assert_eq!(codes(&plan(&open)), ["history_incomplete"]);

        let mut pruned = export(b"a", b"b");
        pruned["workflow"]["details_state"] = json!("pruned");
        pruned["history_complete"] = json!(false);
        assert_eq!(codes(&plan(&pruned)), ["history_pruned", "history_incomplete"]);

        let mut redacted = export(b"a", b"b");
        redacted["payload_manifest"]["entries"][0]["redacted"] = json!(true);
        assert_eq!(codes(&plan(&redacted)), ["history_redacted"]);

        let mut gap = export(b"a", b"b");
        gap["history_events"].as_array_mut().unwrap().remove(1);
        assert_eq!(codes(&plan(&gap)), ["history_sequence_gap"]);

        let mut provider = export(b"a", b"b");
        provider["payloads"]["arguments"]["data"] = json!({"codec": "avro", "external_storage": {"uri": "s3://bucket/key"}});
        assert_eq!(codes(&plan(&provider)), ["unsupported_payload_reference"]);

        let mut invalid = export(b"a", b"b");
        invalid["payloads"]["arguments"]["data"]["external_payload"]["size_bytes"] = json!(-1);
        assert_eq!(codes(&plan(&invalid)), ["invalid_payload_reference"]);

        let mut conflicting = export(b"a", b"b");
        conflicting["history_events"][1]["payload"]["arguments"] = envelope(ID_A, b"other bytes");
        assert_eq!(codes(&plan(&conflicting)), ["conflicting_payload_reference"]);

        assert_eq!(codes(&plan(&json!({"schema": EXPORT_SCHEMA, "schema_version": 1}))), ["unsupported_export"]);
    }

    /// A complete archive of `export(a, b)` in `dir`.
    fn write_archive(dir: &Path, a: &[u8], b: &[u8]) {
        let export = export(a, b);
        let bytes = serde_json::to_vec_pretty(&export).unwrap();
        let plan = plan(&export);
        std::fs::create_dir_all(dir.join(OBJECTS_DIR)).unwrap();
        std::fs::write(dir.join(HISTORY_FILE), &bytes).unwrap();
        std::fs::write(dir.join(OBJECTS_DIR).join(ID_A), a).unwrap();
        std::fs::write(dir.join(OBJECTS_DIR).join(ID_B), b).unwrap();
        let hold = json!({"kind": HOLD_KIND, "owner": "7f1c3a1e-0000-4000-8000-000000000001"});
        let manifest = manifest(&export, &bytes, &plan.objects, "default", "2026-11-25T00:00:00Z", "test", hold.clone());
        let manifest_bytes = serde_json::to_vec_pretty(&manifest).unwrap();
        std::fs::write(dir.join(MANIFEST_FILE), &manifest_bytes).unwrap();
        let contents = verify_contents(dir);
        assert_eq!(contents["outcome"], "verified", "{contents}");
        let receipt = receipt(&manifest_bytes, &contents, "2026-11-25T00:00:01Z", hold);
        std::fs::write(dir.join(RECEIPT_FILE), serde_json::to_vec_pretty(&receipt).unwrap()).unwrap();
    }

    fn finding_codes(report: &Value) -> Vec<String> {
        report["findings"].as_array().unwrap().iter().map(|finding| finding["code"].as_str().unwrap().to_owned()).collect()
    }

    #[test]
    fn a_written_archive_verifies_from_its_files_alone() {
        let dir = tempfile::tempdir().unwrap();
        write_archive(dir.path(), b"first", b"second");
        let report = verify(dir.path());
        assert_eq!(report["outcome"], "verified", "{report}");
        assert_eq!(report["object_count"], 2);
        assert_eq!(report["verified_objects"], 2);
        assert_eq!(report["object_bytes"], 11);
        assert_eq!(report["identity"]["run_id"], "run-1");
        assert_eq!(report["receipt"]["present"], true);
    }

    #[test]
    fn damage_makes_an_archive_incomplete_and_foreign_formats_are_refused() {
        let cases: &[(&str, fn(&Path), &[&str], &str)] = &[
            ("missing object", |dir| std::fs::remove_file(dir.join(OBJECTS_DIR).join(ID_A)).unwrap(), &["object_missing"], "incomplete"),
            ("corrupt object", |dir| std::fs::write(dir.join(OBJECTS_DIR).join(ID_B), b"secono").unwrap(), &["object_digest_mismatch"], "incomplete"),
            ("short object", |dir| std::fs::write(dir.join(OBJECTS_DIR).join(ID_B), b"sec").unwrap(), &["object_size_mismatch"], "incomplete"),
            ("no receipt", |dir| std::fs::remove_file(dir.join(RECEIPT_FILE)).unwrap(), &["receipt_missing"], "incomplete"),
            ("edited history", |dir| {
                let mut bytes = std::fs::read(dir.join(HISTORY_FILE)).unwrap();
                bytes.push(b'\n');
                std::fs::write(dir.join(HISTORY_FILE), bytes).unwrap();
            }, &["history_digest_mismatch", "receipt_mismatch"], "incomplete"),
            ("edited manifest", |dir| {
                let mut manifest: Value = serde_json::from_slice(&std::fs::read(dir.join(MANIFEST_FILE)).unwrap()).unwrap();
                manifest["created_by"] = json!("someone else");
                std::fs::write(dir.join(MANIFEST_FILE), serde_json::to_vec(&manifest).unwrap()).unwrap();
            }, &["receipt_mismatch"], "incomplete"),
            ("unlisted object", |dir| {
                let mut manifest: Value = serde_json::from_slice(&std::fs::read(dir.join(MANIFEST_FILE)).unwrap()).unwrap();
                manifest["objects"].as_array_mut().unwrap().remove(0);
                manifest["totals"] = json!({"object_count": 1, "object_bytes": 6});
                std::fs::write(dir.join(MANIFEST_FILE), serde_json::to_vec(&manifest).unwrap()).unwrap();
            }, &["object_unlisted", "receipt_mismatch"], "incomplete"),
            ("linked object", |dir| {
                let outside = dir.join("outside");
                std::fs::write(&outside, b"first").unwrap();
                std::fs::remove_file(dir.join(OBJECTS_DIR).join(ID_A)).unwrap();
                #[cfg(unix)]
                std::os::unix::fs::symlink(&outside, dir.join(OBJECTS_DIR).join(ID_A)).unwrap();
                #[cfg(not(unix))]
                std::fs::create_dir(dir.join(OBJECTS_DIR).join(ID_A)).unwrap();
            }, &["file_not_regular"], "incomplete"),
            ("newer format", |dir| {
                let mut manifest: Value = serde_json::from_slice(&std::fs::read(dir.join(MANIFEST_FILE)).unwrap()).unwrap();
                manifest["format_version"] = json!(2);
                std::fs::write(dir.join(MANIFEST_FILE), serde_json::to_vec(&manifest).unwrap()).unwrap();
            }, &["unsupported_format_version"], "refused"),
            ("no manifest", |dir| std::fs::remove_file(dir.join(MANIFEST_FILE)).unwrap(), &["manifest_missing"], "refused"),
        ];
        for (name, damage, expected, outcome) in cases {
            let dir = tempfile::tempdir().unwrap();
            write_archive(dir.path(), b"first", b"second");
            damage(dir.path());
            let report = verify(dir.path());
            assert_eq!(report["outcome"], *outcome, "{name}: {report}");
            assert_eq!(finding_codes(&report), *expected, "{name}: {report}");
        }
    }

    #[test]
    fn the_published_fixture_verifies() {
        // The v1 conformance archive other verifiers (dw) check against.
        let dir = Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/history-archive-v1");
        let report = verify(&dir);
        assert_eq!(report["outcome"], "verified", "{report}");
        assert_eq!(report["object_count"], 2);
        assert_eq!(report["object_bytes"], 11);
    }
}
