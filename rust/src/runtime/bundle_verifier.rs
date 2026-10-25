//! `BundleIntegrityVerifier` (Workflow 2.10.2, as published Server 2.11.1
//! ships it): the offline integrity report of a history-export bundle, over
//! the document PHP decoded and Laravel normalized. Findings keep PHP's check
//! order, rules, messages, paths and contexts; the checksum and signature are
//! computed over PHP's canonical JSON.
use super::history_export::{SCHEMA, VALUE_FINGERPRINT};
use super::php_value::{CANONICAL, Flags, Key, Php, PhpArray};
use base64::{Engine, engine::general_purpose::STANDARD};
use hmac::{Hmac, KeyInit, Mac};
use serde_json::{Map, Value, json};
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;

const SCHEMA_VERSION: i64 = 2;
const ERROR: &str = "error";
const WARNING: &str = "warning";
const INFO: &str = "info";

/// `MemoPayload::KEY_PATTERN`.
const MEMO_KEY_PATTERN: &str = "^(?!-?[0-9]+$)[A-Za-z0-9_.:-]{1,64}$";

fn string_value(value: Option<&Php>) -> Option<&str> {
    value.and_then(Php::str).filter(|text| !text.is_empty())
}

/// The verifier's `intValue`: an integer, or a string that is exactly one.
fn int_value(value: Option<&Php>) -> Option<i64> {
    match value? {
        Php::Int(number) => Some(*number),
        Php::Str(text) => text.parse::<i64>().ok().filter(|number| number.to_string() == *text),
        _ => None,
    }
}

fn array(value: Option<&Php>) -> Option<&PhpArray> {
    value.and_then(Php::array)
}

struct Findings(Vec<Value>);

impl Findings {
    fn add(&mut self, rule: &str, severity: &str, message: String, path: Option<String>, context: Value) {
        let context = if context.as_object().is_some_and(Map::is_empty) { json!([]) } else { context };
        self.0.push(json!({"rule":rule,"severity":severity,"message":message,"path":path,"context":context}));
    }

    fn error(&mut self, rule: &str, message: impl Into<String>, path: impl Into<String>) {
        self.add(rule, ERROR, message.into(), Some(path.into()), json!({}));
    }
}

/// The verification report and its status.
pub(super) struct Verification {
    pub(super) report: Value,
    pub(super) status: &'static str,
    /// The decoded memo entries, when `workflow.memo_payload` decoded.
    pub(super) memo_entries: Option<Vec<(String, Decoded)>>,
}

/// `BundleIntegrityVerifier::verify`.
pub(super) fn verify(bundle: &PhpArray, signing_key: Option<&str>, configured_key: Option<&str>) -> Verification {
    let mut findings = Findings(Vec::new());
    let root = Php::Array(bundle.clone());
    check_schema(bundle, &mut findings);
    check_required_sections(bundle, &mut findings);
    let memo_entries = check_run_references(&root, &mut findings);
    check_payload_manifest(&root, &mut findings);
    check_history_events(&root, &mut findings);
    check_command_history_consistency(&root, &mut findings);
    check_redaction(&root, &mut findings);
    let mut unsigned = bundle.clone();
    unsigned.remove("integrity");
    let integrity = check_integrity(bundle.get("integrity"), &unsigned, signing_key.or(configured_key), &mut findings);
    let summary_bundle = json!({
        "schema": string_value(bundle.get("schema")),
        "schema_version": int_value(bundle.get("schema_version")),
        "exported_at": string_value(bundle.get("exported_at")),
        "dedupe_key": string_value(bundle.get("dedupe_key")),
        "workflow_run_id": string_value(root.at(&["workflow", "run_id"])),
        "workflow_instance_id": string_value(root.at(&["workflow", "instance_id"])),
        "history_event_count": array(bundle.get("history_events")).map_or(0, PhpArray::len),
    });
    let status = if findings.0.iter().any(|finding| finding["severity"] == ERROR) {
        "failed"
    } else if findings.0.iter().any(|finding| finding["severity"] == WARNING) {
        "warning"
    } else {
        "ok"
    };
    let mut by_rule: BTreeMap<String, i64> = BTreeMap::new();
    let mut counts = [0i64; 3];
    for finding in &findings.0 {
        *by_rule.entry(finding["rule"].as_str().unwrap_or_default().to_owned()).or_default() += 1;
        match finding["severity"].as_str() {
            Some(ERROR) => counts[0] += 1,
            Some(WARNING) => counts[1] += 1,
            Some(INFO) => counts[2] += 1,
            _ => {}
        }
    }
    let by_rule = if by_rule.is_empty() { json!([]) } else { json!(by_rule) };
    Verification {
        report: json!({
            "schema": "durable-workflow.v2.history-bundle-verification",
            "schema_version": 1,
            "status": status,
            "bundle": summary_bundle,
            "integrity": integrity,
            "summary": {"errors":counts[0],"warnings":counts[1],"info":counts[2],"findings_by_rule":by_rule},
            "findings": findings.0,
        }),
        status,
        memo_entries,
    }
}

fn check_schema(bundle: &PhpArray, findings: &mut Findings) {
    match string_value(bundle.get("schema")) {
        None => findings.error("bundle.schema_missing", "Bundle is missing the schema field.", "schema"),
        Some(schema) if schema != SCHEMA => findings.add("bundle.schema_unexpected", ERROR,
            format!("Bundle schema [{schema}] does not match expected [{SCHEMA}]."), Some("schema".into()),
            json!({"expected":SCHEMA,"actual":schema})),
        Some(_) => {}
    }
    match int_value(bundle.get("schema_version")) {
        None => findings.error("bundle.schema_version_missing", "Bundle is missing schema_version.", "schema_version"),
        Some(version) if version != SCHEMA_VERSION => findings.add("bundle.schema_version_unsupported", ERROR,
            format!("Bundle schema_version {version} is not the v2 history-export structural marker ({SCHEMA_VERSION}); the v2 bundle shape is frozen and a different value indicates a parallel primitive that this verifier does not recognize."),
            Some("schema_version".into()), json!({"expected":SCHEMA_VERSION,"actual":version})),
        Some(_) => {}
    }
    if string_value(bundle.get("exported_at")).is_none() {
        findings.add("bundle.exported_at_missing", WARNING,
            "Bundle is missing exported_at; replay reports cannot be ordered without it.".into(), Some("exported_at".into()), json!({}));
    }
}

fn check_required_sections(bundle: &PhpArray, findings: &mut Findings) {
    for (key, shape) in [
        ("workflow", "object"), ("payloads", "object"), ("history_events", "list"), ("commands", "list"),
        ("signals", "list"), ("updates", "list"), ("tasks", "list"), ("activities", "list"), ("timers", "list"),
        ("failures", "list"), ("links", "object"), ("redaction", "object"), ("codec_schemas", "object"),
        ("payload_manifest", "object"),
    ] {
        match bundle.get(key) {
            None => findings.error("bundle.section_missing", format!("Bundle is missing required section [{key}]."), key),
            Some(value) if !value.is_array() => {
                findings.error("bundle.section_invalid", format!("Bundle section [{key}] must be {}.",
                    if shape == "object" { "an object" } else { "a list" }), key);
            }
            Some(_) => {}
        }
    }
}

/// `checkRunReferences`. Returns the decoded memo entries for the writer.
fn check_run_references(bundle: &Php, findings: &mut Findings) -> Option<Vec<(String, Decoded)>> {
    let empty = Php::Array(PhpArray::default());
    let workflow = bundle.get("workflow").filter(|value| value.is_array()).unwrap_or(&empty);
    if string_value(workflow.get("run_id")).is_none() {
        findings.error("workflow.run_id_missing", "Bundle workflow.run_id is required.", "workflow.run_id");
    }
    if string_value(workflow.get("instance_id")).is_none() {
        findings.error("workflow.instance_id_missing", "Bundle workflow.instance_id is required.", "workflow.instance_id");
    }
    if string_value(workflow.get("workflow_type")).is_none() {
        findings.error("workflow.workflow_type_missing", "Bundle workflow.workflow_type is required.", "workflow.workflow_type");
    }
    let redacted = |path: &str| {
        array(bundle.at(&["redaction", "paths"]))
            .is_some_and(|paths| paths.values().any(|value| value.str() == Some(path)))
    };
    let (payload_redacted, projection_redacted) = (redacted("workflow.memo_payload"), redacted("workflow.memo"));
    let mut entries = None;
    match workflow.get("memo_payload") {
        _ if payload_redacted => {}
        Some(Php::Array(payload)) => match decode_entries(payload) {
            Ok(decoded) => {
                if !projection_redacted {
                    let projection = project_entries(&decoded);
                    if !workflow.get("memo").unwrap_or(&Php::Null).identical(&projection) {
                        findings.error("workflow.memo_projection_mismatch",
                            "Bundle workflow.memo does not match the JSON-safe projection of workflow.memo_payload.", "workflow.memo");
                    }
                }
                entries = Some(decoded);
            }
            Err(message) => findings.error("workflow.memo_payload_invalid",
                format!("Bundle workflow.memo_payload is not a valid portable Avro memo map: {message}"), "workflow.memo_payload"),
        },
        _ => findings.error("workflow.memo_payload_missing",
            "Bundle workflow.memo_payload must contain the lossless portable Avro memo authority.", "workflow.memo_payload"),
    }
    let last = int_value(workflow.get("last_history_sequence"));
    let highest = array(bundle.get("history_events")).and_then(|events| {
        events.values().filter(|event| event.is_array()).filter_map(|event| int_value(event.get("sequence"))).max()
    });
    if let (Some(last), Some(highest)) = (last, highest)
        && last < highest
    {
        findings.add("workflow.last_history_sequence_stale", WARNING,
            format!("Workflow last_history_sequence ({last}) is below the highest recorded event sequence ({highest}); the bundle may be truncated."),
            Some("workflow.last_history_sequence".into()), json!({"last_history_sequence":last,"highest_history_sequence":highest}));
    }
    entries
}

fn check_payload_manifest(bundle: &Php, findings: &mut Findings) {
    let Some(manifest) = array(bundle.get("payload_manifest")) else {
        return;
    };
    let schema = crate::codec::VALUE_SCHEMA.trim();
    let fingerprint = format!("crc64-avro:{VALUE_FINGERPRINT}");
    if let Some(entries) = array(manifest.get("entries")) {
        for (index, entry) in &entries.0 {
            let fallback = format!("payload_manifest.entries[{}]", index.text());
            if entry.array().is_none() {
                findings.error("payload_manifest.entry_invalid", "Payload manifest entry must be an object.", fallback);
                continue;
            }
            let path = string_value(entry.get("path")).map_or(fallback, str::to_owned);
            check_manifest_entry(entry, &path, schema, &fingerprint, findings);
        }
    }
    let Some(codec_schemas) = array(bundle.get("codec_schemas")) else {
        return;
    };
    let Some(avro) = array(codec_schemas.get("avro")) else {
        return;
    };
    let current = string_value(avro.get("current_fingerprint"));
    let entry = current.and_then(|current| array(avro.get("writer_schemas")).and_then(|schemas| array(schemas.get(current))));
    match (current, entry) {
        (Some(current), Some(entry)) if current == VALUE_FINGERPRINT => {
            let same = |field: &str, expected: &str| entry.get(field).and_then(Php::str) == Some(expected);
            if !same("schema", schema) || !same("fingerprint", &fingerprint) {
                findings.error("codec_schemas.value_schema_drift", "Bundled Avro Value schema or fingerprint differs from this runtime.",
                    format!("codec_schemas.avro.writer_schemas.{current}"));
            }
        }
        _ => findings.error("codec_schemas.value_schema_missing", "Avro codec metadata does not bundle the current Value writer schema.",
            "codec_schemas.avro.writer_schemas"),
    }
}

/// One payload manifest entry's codec, availability and Avro framing checks,
/// in PHP's order.
fn check_manifest_entry(entry: &Php, path: &str, schema: &str, fingerprint: &str, findings: &mut Findings) {
    let codec = string_value(entry.get("codec"));
    let available = entry.get("available").is_some_and(Php::truthy);
    let redacted = entry.get("redacted").is_some_and(Php::truthy);
    let diagnostic = string_value(entry.get("diagnostic"));
    if codec.is_none() {
        findings.error("payload_manifest.codec_missing", format!("Payload manifest entry [{path}] is missing codec."), path.to_owned());
    }
    if !redacted && available && diagnostic == Some("payload_missing") {
        findings.error("payload_manifest.payload_missing",
            format!("Manifest reports payload [{path}] available but bundle has no payload bytes."), path.to_owned());
    }
    if codec == Some("avro") && available && !redacted && diagnostic != Some("external_storage_reference") {
        if string_value(entry.get("avro_framing")).is_none() {
            findings.add("payload_manifest.avro_framing_missing", WARNING,
                format!("Avro payload [{path}] is missing framing metadata; offline decoders cannot pick a wrapper schema."),
                Some(path.to_owned()), json!({}));
        }
        if let (Some(writer), Some(actual)) =
            (string_value(entry.get("writer_schema")), string_value(entry.get("writer_schema_fingerprint")))
        {
            let expected = (writer == schema).then(|| fingerprint.to_owned());
            if expected.as_deref() != Some(actual) {
                findings.add("payload_manifest.writer_schema_fingerprint_mismatch", ERROR,
                    format!("Avro payload [{path}] writer_schema_fingerprint disagrees with the embedded schema; offline decoders will reject this entry."),
                    Some(path.to_owned()), json!({"expected":expected,"actual":actual}));
            }
        }
    }
}

fn check_history_events(bundle: &Php, findings: &mut Findings) {
    let Some(events) = array(bundle.get("history_events")) else {
        return;
    };
    let mut previous: Option<i64> = None;
    let mut seen: BTreeMap<String, String> = BTreeMap::new();
    for (index, event) in &events.0 {
        let path = format!("history_events[{}]", index.text());
        if !event.is_array() {
            findings.error("history_events.entry_invalid", "History event entry must be an object.", path);
            continue;
        }
        let sequence = int_value(event.get("sequence"));
        match (sequence, previous) {
            (None, _) => findings.error("history_events.sequence_missing", "History event is missing sequence.", path.clone()),
            (Some(sequence), Some(previous)) if sequence <= previous => findings.add("history_events.sequence_not_monotonic", ERROR,
                format!("History event sequence {sequence} does not advance past previous sequence {previous}."), Some(path.clone()),
                json!({"sequence":sequence,"previous_sequence":previous})),
            _ => {}
        }
        if sequence.is_some() {
            previous = sequence;
        }
        if string_value(event.get("type")).is_none() {
            findings.error("history_events.type_missing", "History event is missing type.", path.clone());
        }
        if let Some(id) = string_value(event.get("id")) {
            match seen.get(id) {
                Some(first) => findings.add("history_events.id_duplicate", ERROR, format!("History event id [{id}] appears more than once."),
                    Some(path.clone()), json!({"id":id,"first_seen_at":first})),
                None => {
                    seen.insert(id.to_owned(), path.clone());
                }
            }
        }
    }
}

fn check_command_history_consistency(bundle: &Php, findings: &mut Findings) {
    let mut referenced: BTreeMap<&str, usize> = BTreeMap::new();
    if let Some(events) = array(bundle.get("history_events")) {
        for event in events.values().filter(|event| event.is_array()) {
            if let Some(command) = string_value(event.get("workflow_command_id")) {
                *referenced.entry(command).or_default() += 1;
            }
        }
    }
    let Some(commands) = array(bundle.get("commands")) else {
        return;
    };
    for (index, command) in &commands.0 {
        let path = format!("commands[{}]", index.text());
        if !command.is_array() {
            findings.error("commands.entry_invalid", "Command row must be an object.", path);
            continue;
        }
        let text = |field: &str| string_value(command.get(field));
        let Some(id) = text("id") else {
            findings.error("commands.id_missing", "Command row is missing id.", path);
            continue;
        };
        let (status, outcome) = (text("status"), text("outcome"));
        let settled = matches!(status, Some("applied" | "rejected")) || matches!(outcome, Some("applied" | "rejected"))
            || text("applied_at").is_some() || text("rejected_at").is_some();
        if settled && !referenced.contains_key(id) {
            findings.add("commands.history_event_missing", WARNING,
                format!("Command [{id}] is settled (status={}, outcome={}) but no history event references it.",
                    status.unwrap_or("null"), outcome.unwrap_or("null")),
                Some(path), json!({"command_id":id,"status":status,"outcome":outcome}));
        }
    }
}

fn check_redaction(bundle: &Php, findings: &mut Findings) {
    let Some(redaction) = array(bundle.get("redaction")) else {
        return;
    };
    let applied = redaction.get("applied").is_some_and(Php::truthy);
    let empty = array(redaction.get("paths")).is_none_or(PhpArray::is_empty);
    if applied && empty {
        findings.add("redaction.empty_paths", INFO,
            "Redaction is marked applied but no paths were redacted; replay-diff diagnostics will treat all payloads as in-scope.".into(),
            Some("redaction.paths".into()), json!({}));
    }
}

/// `canonicalJson` of the bundle without its integrity block.
pub(super) fn canonical_json(unsigned: &PhpArray) -> String {
    Php::Array(unsigned.clone()).canonical().encode(CANONICAL)
}

pub(super) fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|byte| format!("{byte:02x}")).collect()
}

pub(super) fn hmac(canonical: &str, key: &str) -> String {
    let mut mac = Hmac::<Sha256>::new_from_slice(key.as_bytes()).expect("HMAC takes any key length");
    mac.update(canonical.as_bytes());
    hex(&mac.finalize().into_bytes())
}

/// `hash_equals`.
fn equal(left: &str, right: &str) -> bool {
    use subtle::ConstantTimeEq;
    left.len() == right.len() && bool::from(left.as_bytes().ct_eq(right.as_bytes()))
}

fn check_integrity(raw: Option<&Php>, unsigned: &PhpArray, key: Option<&str>, findings: &mut Findings) -> Value {
    let mut report = json!({"present":false,"canonicalization":null,"checksum_algorithm":null,"expected_checksum":null,
        "recomputed_checksum":null,"checksum_matches":null,"signature_algorithm":null,"signature_present":false,
        "signature_verified":null,"key_id":null});
    let Some(raw) = raw.filter(|raw| raw.is_array()) else {
        findings.error("integrity.missing", "Bundle has no integrity block; offline replay verification cannot vouch for its contents.", "integrity");
        return report;
    };
    let text = |field: &str| string_value(raw.get(field)).map(str::to_owned);
    let canonicalization = text("canonicalization");
    let algorithm = text("checksum_algorithm");
    let expected = text("checksum");
    let signature_algorithm = text("signature_algorithm");
    let signature = text("signature");
    report["present"] = json!(true);
    report["canonicalization"] = json!(canonicalization);
    report["checksum_algorithm"] = json!(algorithm);
    report["expected_checksum"] = json!(expected);
    report["signature_algorithm"] = json!(signature_algorithm);
    report["key_id"] = json!(text("key_id"));
    report["signature_present"] = json!(signature.is_some());
    if canonicalization.as_deref() != Some("json-recursive-ksort-v1") {
        findings.error("integrity.canonicalization_unsupported", format!(
            "Unsupported canonicalization [{}]; only json-recursive-ksort-v1 is recognized offline.",
            canonicalization.as_deref().unwrap_or("null")), "integrity.canonicalization");
        return report;
    }
    if algorithm.as_deref() != Some("sha256") {
        findings.error("integrity.checksum_algorithm_unsupported", format!(
            "Unsupported checksum algorithm [{}]; only sha256 is recognized.", algorithm.as_deref().unwrap_or("null")),
            "integrity.checksum_algorithm");
        return report;
    }
    let canonical = canonical_json(unsigned);
    let recomputed = hex(&Sha256::digest(canonical.as_bytes()));
    report["recomputed_checksum"] = json!(recomputed);
    match &expected {
        None => {
            findings.error("integrity.checksum_missing", "Integrity block is missing checksum.", "integrity.checksum");
            report["checksum_matches"] = json!(false);
        }
        Some(expected) => {
            let matches = equal(expected, &recomputed);
            report["checksum_matches"] = json!(matches);
            if !matches {
                findings.add("integrity.checksum_mismatch", ERROR,
                    "Recomputed bundle checksum does not match the recorded checksum; the bundle has been altered after export.".into(),
                    Some("integrity.checksum".into()), json!({"expected":expected,"actual":recomputed}));
            }
        }
    }
    if signature_algorithm.is_none() && signature.is_none() {
        return report;
    }
    if signature_algorithm.as_deref() != Some("hmac-sha256") {
        findings.error("integrity.signature_algorithm_unsupported", format!(
            "Unsupported signature algorithm [{}]; only hmac-sha256 is recognized.", signature_algorithm.as_deref().unwrap_or("null")),
            "integrity.signature_algorithm");
        return report;
    }
    let Some(signature) = signature else {
        findings.error("integrity.signature_missing", "Integrity block declares a signature_algorithm but carries no signature.", "integrity.signature");
        return report;
    };
    let Some(key) = key else {
        findings.add("integrity.signature_key_unavailable", WARNING,
            "Bundle is signed but no verification key is configured; signature was not verified.".into(),
            Some("integrity.signature".into()), json!({}));
        return report;
    };
    let verified = equal(&hmac(&canonical, key), &signature);
    report["signature_verified"] = json!(verified);
    if !verified {
        findings.add("integrity.signature_mismatch", ERROR,
            "Bundle signature does not match the configured key; treat the bundle as untrusted.".into(),
            Some("integrity.signature".into()), json!({"key_id":report["key_id"]}));
    }
    report
}

/// A value `Avro::unserialize` returns for the fixed Value schema: PHP
/// scalars, bytes, lists, string-keyed maps, and the map adapter PHP uses
/// for an empty map or one with integer-like keys.
#[derive(Clone, Debug)]
pub(super) enum Decoded {
    Null,
    Bool(bool),
    Long(i64),
    Double(f64),
    Bytes(Vec<u8>),
    Str(String),
    List(Vec<Decoded>),
    Map(Vec<(String, Decoded)>),
    MapAdapter(Vec<(String, Decoded)>),
}

impl Decoded {
    /// The protocol value, for re-encoding the canonical envelope.
    pub(super) fn protocol(&self) -> crate::codec::Value {
        use crate::codec::Value as Wire;
        match self {
            Self::Null => Wire::Null,
            Self::Bool(flag) => Wire::Boolean(*flag),
            Self::Long(number) => Wire::Long(*number),
            Self::Double(number) => Wire::Double(*number),
            Self::Bytes(bytes) => Wire::Bytes(bytes.clone()),
            Self::Str(text) => Wire::String(text.clone()),
            Self::List(items) => Wire::Array(items.iter().map(Self::protocol).collect()),
            Self::Map(pairs) | Self::MapAdapter(pairs) => {
                Wire::Map(pairs.iter().map(|(key, value)| (key.clone(), value.protocol())).collect())
            }
        }
    }

    /// `AvroValueJsonProjection::project`.
    pub(super) fn project(&self) -> Php {
        let list = |items: Vec<Php>| Php::Array(PhpArray(items.into_iter().enumerate().map(|(index, value)| (Key::Int(index as i64), value)).collect()));
        let map = |pairs: Vec<(&str, Php)>| Php::Array(PhpArray(pairs.into_iter().map(|(key, value)| (Key::of(key), value)).collect()));
        match self {
            Self::Null => Php::Null,
            Self::Bool(flag) => Php::Bool(*flag),
            Self::Long(number) => Php::Int(*number),
            Self::Double(number) => Php::Float(*number),
            Self::Str(text) => Php::Str(text.clone()),
            Self::Bytes(bytes) => map(vec![("$type", Php::Str("bytes".into())), ("base64", Php::Str(STANDARD.encode(bytes)))]),
            Self::List(items) => list(items.iter().map(Self::project).collect()),
            Self::Map(pairs) => map(pairs.iter().map(|(key, value)| (key.as_str(), value.project())).collect()),
            Self::MapAdapter(pairs) => map(vec![
                ("$type", Php::Str("map".into())),
                ("entries", list(pairs.iter().map(|(key, value)| map(vec![("key", Php::Str(key.clone())), ("value", value.project())])).collect())),
            ]),
        }
    }
}

/// The JSON-safe projection of decoded memo entries.
pub(super) fn project_entries(entries: &[(String, Decoded)]) -> Php {
    Php::Array(PhpArray(entries.iter().map(|(key, value)| (Key::of(key), value.project())).collect()))
}

/// `MemoPayload::decodeEntries`: the entries of a `{codec, blob}` memo map,
/// or PHP's exception message.
pub(super) fn decode_entries(envelope: &PhpArray) -> Result<Vec<(String, Decoded)>, String> {
    let mut keys: Vec<String> = envelope.0.iter().map(|(key, _)| key.text()).collect();
    keys.sort();
    let keys_are_strings = envelope.0.iter().all(|(key, _)| matches!(key, Key::Str(_)));
    if !keys_are_strings || keys != ["blob", "codec"] {
        return Err("invalid_memo_payload: expected exactly the standard {codec, blob} payload envelope.".into());
    }
    match envelope.get("codec") {
        Some(Php::Str(codec)) if codec.is_empty() || codec == "avro" => {}
        Some(Php::Str(codec)) => return Err(format!("unsupported_payload_codec: payload codec \"{codec}\" is not supported by Durable Workflow 2.0; use codec=\"avro\" with the fixed Avro Value schema and single-object framing. JSON remains the HTTP document transport, not a workflow payload codec.")),
        _ => return Err("unsupported_payload_codec: memo payloads require codec \"avro\".".into()),
    }
    let Some(blob) = envelope.get("blob").and_then(Php::str).filter(|blob| !blob.is_empty()) else {
        return Err("invalid_memo_payload: the Avro envelope blob must be a non-empty string.".into());
    };
    match unserialize(blob)? {
        // The decoder already merged repeated keys.
        Decoded::MapAdapter(pairs) => match pairs.iter().find(|(key, _)| !memo_key(key)) {
            Some((key, _)) => Err(format!("invalid_memo_key: memo key \"{key}\" does not match the portable key pattern {MEMO_KEY_PATTERN}.")),
            None => Ok(pairs),
        },
        Decoded::Map(pairs) => Ok(pairs),
        _ => Err("invalid_memo_entries_payload: the Avro memo payload must decode to a string-keyed map.".into()),
    }
}

fn memo_key(key: &str) -> bool {
    let digits = key.strip_prefix('-').unwrap_or(key);
    let integer_like = !digits.is_empty() && digits.bytes().all(|byte| byte.is_ascii_digit());
    !integer_like && (1..=64).contains(&key.len()) && key.bytes().all(|byte| byte.is_ascii_alphanumeric() || b"_.:-".contains(&byte))
}

fn codec_error(detail: &str, remediation: &str) -> String {
    format!("{detail} Remediation: {remediation}")
}

/// `Avro::unserialize` for one single-object frame of the Value schema, in
/// the frame's map order.
pub(super) fn unserialize(blob: &str) -> Result<Decoded, String> {
    let malformed = || codec_error("invalid_payload_framing: malformed Avro Value datum.",
        "Provide one complete datum encoded with the writer schema selected by the single-object fingerprint.");
    let Ok(bytes) = crate::codec::php_base64(blob) else {
        let first = blob.chars().next().unwrap_or(' ');
        return Err(if matches!(first, '{' | '[' | '"' | '-' | 't' | 'f' | 'n' | '0'..='9') {
            codec_error("invalid_payload_framing: payload bytes look like JSON, not base64-encoded Avro.",
                "Encode the value with the fixed Avro Value codec; JSON is only the HTTP document transport.")
        } else {
            codec_error("invalid_payload_framing: failed to base64-decode Avro payload bytes.",
                "Avro payloads must be strict base64 containing a c301 single-object frame.")
        });
    };
    if bytes.len() < 10 || !bytes.starts_with(&[0xc3, 0x01]) {
        return Err(codec_error("invalid_payload_framing: expected Avro single-object magic c301.",
            "Encode the value with the Durable Workflow 2.0 fixed Avro Value schema and single-object framing; JSON is only the HTTP document transport."));
    }
    let fingerprint = hex(&bytes[2..10]);
    if fingerprint != VALUE_FINGERPRINT {
        return Err(codec_error(&format!("unsupported_payload_schema: unknown CRC-64-AVRO fingerprint {fingerprint}."),
            "Upgrade to a runtime that bundles this writer schema; never guess or fall back to JSON."));
    }
    let mut reader = Reader { bytes: &bytes[10..], at: 0, truncated: false };
    let value = reader.value(0);
    if reader.truncated {
        return Err(codec_error("invalid_payload_framing: truncated Avro Value datum.",
            "Provide one complete Avro Value datum after the single-object fingerprint."));
    }
    let value = value.ok_or_else(malformed)?;
    if reader.at != reader.bytes.len() {
        return Err(codec_error("invalid_payload_framing: trailing bytes after Avro Value datum.",
            "Provide exactly one Avro Value datum in each single-object frame."));
    }
    Ok(value)
}

struct Reader<'a> {
    bytes: &'a [u8],
    at: usize,
    truncated: bool,
}

impl Reader<'_> {
    fn take(&mut self, count: usize) -> Option<&[u8]> {
        if self.bytes.len() - self.at < count {
            self.truncated = true;
            return None;
        }
        let slice = &self.bytes[self.at..self.at + count];
        self.at += count;
        Some(slice)
    }

    /// A zig-zag varint of at most ten bytes within int64.
    fn long(&mut self) -> Option<i64> {
        let mut raw: u64 = 0;
        for index in 0..10 {
            let byte = self.take(1)?[0];
            if index == 9 && byte > 1 {
                return None;
            }
            raw |= u64::from(byte & 0x7f) << (7 * index);
            if byte & 0x80 == 0 {
                return Some((raw >> 1) as i64 ^ -((raw & 1) as i64));
            }
        }
        None
    }

    fn length(&mut self) -> Option<usize> {
        usize::try_from(self.long()?).ok()
    }

    fn string(&mut self) -> Option<String> {
        let length = self.length()?;
        String::from_utf8(self.take(length)?.to_vec()).ok()
    }

    /// Each block of items: a count (negative counts carry a byte size).
    fn blocks(&mut self, depth: usize, mut item: impl FnMut(&mut Self, usize) -> Option<()>) -> Option<()> {
        loop {
            let mut count = self.long()?;
            if count == 0 {
                return Some(());
            }
            if count < 0 {
                count = count.checked_neg()?;
                self.long()?;
            }
            for _ in 0..count {
                item(self, depth + 1)?;
            }
        }
    }

    fn value(&mut self, depth: usize) -> Option<Decoded> {
        if depth > 512 {
            return None;
        }
        Some(match self.long()? {
            0 => Decoded::Null,
            1 => match self.take(1)?[0] {
                0 => Decoded::Bool(false),
                1 => Decoded::Bool(true),
                _ => return None,
            },
            2 => Decoded::Long(self.long()?),
            3 => {
                let value = f64::from_le_bytes(self.take(8)?.try_into().ok()?);
                if !value.is_finite() {
                    return None;
                }
                Decoded::Double(value)
            }
            4 => {
                let length = self.length()?;
                Decoded::Bytes(self.take(length)?.to_vec())
            }
            5 => Decoded::Str(self.string()?),
            6 => {
                let mut items = Vec::new();
                self.blocks(depth, |reader, depth| {
                    items.push(reader.value(depth)?);
                    Some(())
                })?;
                Decoded::List(items)
            }
            7 => {
                // Apache's reader assigns `$items[$key] = $value`: a
                // repeated key keeps its first position.
                let mut pairs: Vec<(String, Decoded)> = Vec::new();
                let mut positions: std::collections::HashMap<String, usize> = std::collections::HashMap::new();
                self.blocks(depth, |reader, depth| {
                    let key = reader.string()?;
                    let value = reader.value(depth)?;
                    let key = Key::of(&key).text();
                    match positions.get(&key) {
                        Some(&index) => pairs[index].1 = value,
                        None => {
                            positions.insert(key.clone(), pairs.len());
                            pairs.push((key, value));
                        }
                    }
                    Some(())
                })?;
                if pairs.is_empty() || pairs.iter().any(|(key, _)| matches!(Key::of(key), Key::Int(_))) {
                    Decoded::MapAdapter(pairs)
                } else {
                    Decoded::Map(pairs)
                }
            }
            _ => return None,
        })
    }
}

/// `json_encode` of a memo projection as the Server stores it: with
/// JSON_PRESERVE_ZERO_FRACTION.
pub(super) fn stored_projection(value: &Decoded) -> Value {
    serde_json::from_str(&value.project().encode(Flags { zero_fraction: true, ..Flags::default() })).unwrap_or(Value::Null)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn bundle(text: &str) -> PhpArray {
        super::super::php_value::request_body(text.as_bytes())
    }

    #[test]
    fn an_empty_body_reports_php_s_twenty_two_findings() {
        let verification = verify(&bundle(r#"{"dry_run":true}"#), None, None);
        let rules: Vec<&str> = verification.report["findings"].as_array().unwrap().iter().map(|f| f["rule"].as_str().unwrap()).collect();
        assert_eq!(rules.len(), 22);
        assert_eq!(&rules[..3], ["bundle.schema_missing", "bundle.schema_version_missing", "bundle.exported_at_missing"]);
        assert_eq!(&rules[17..], ["workflow.run_id_missing", "workflow.instance_id_missing", "workflow.workflow_type_missing",
            "workflow.memo_payload_missing", "integrity.missing"]);
        assert_eq!(verification.status, "failed");
        assert_eq!(verification.report["summary"]["findings_by_rule"]["bundle.section_missing"], 14);
    }

    #[test]
    fn memo_payloads_decode_in_frame_order_and_project() {
        // {"n": 3, "note": "λ rich"} from a published export.
        let entries = decode_entries(&bundle(r#"{"codec":"avro","blob":"wwHioz3/VYAiNw4EAm4EBghub3RlCg7OuyByaWNoAA=="}"#)).unwrap();
        assert_eq!(entries.iter().map(|(key, _)| key.as_str()).collect::<Vec<_>>(), ["n", "note"]);
        assert_eq!(project_entries(&entries).encode(Flags::default()), r#"{"n":3,"note":"\u03bb rich"}"#);
        assert!(decode_entries(&bundle(r#"{"codec":"avro"}"#)).unwrap_err().starts_with("invalid_memo_payload"));
        assert!(decode_entries(&bundle(r#"{"codec":"json","blob":"x"}"#)).unwrap_err().starts_with("unsupported_payload_codec: payload codec \"json\""));
        assert!(unserialize("{}").unwrap_err().contains("look like JSON"));
        assert!(unserialize("AAAA").unwrap_err().contains("magic c301"));
    }

    #[test]
    fn a_repeated_map_key_keeps_its_first_position() {
        // {"a": 1, "b": 2, "a": 3} as one Avro Value map block.
        let mut frame = vec![0xc3, 0x01, 0xe2, 0xa3, 0x3d, 0xff, 0x55, 0x80, 0x22, 0x37];
        frame.extend([0x0e, 0x06, 0x02, b'a', 0x04, 0x02, 0x02, b'b', 0x04, 0x04, 0x02, b'a', 0x04, 0x06, 0x00]);
        let Decoded::Map(pairs) = unserialize(&STANDARD.encode(frame)).unwrap() else {
            panic!("a string-keyed map");
        };
        let pairs: Vec<_> = pairs.iter().map(|(key, value)| (key.as_str(), if let Decoded::Long(n) = value { *n } else { -1 })).collect();
        assert_eq!(pairs, [("a", 3), ("b", 2)]);
    }
}
