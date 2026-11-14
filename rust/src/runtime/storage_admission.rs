//! Cooperative storage admission (Server #951), PHP's `StoragePressure` and
//! `EnforceStorageAdmission` (docs/storage-admission.md).
//!
//! An operator-owned collector publishes one small JSON observation of the
//! backing storage with an atomic rename. Every check re-opens the path, so a
//! replacement is seen at once; nothing is cached. Under `draining`, new
//! durable work (producers, polls and claims) is refused while existing leases
//! may still acknowledge and heartbeat into the operator's reserve. Under
//! `fenced` only read-only inspection stays open. Any evidence the reader
//! cannot trust (missing, unreadable, malformed, symlinked, group or world
//! writable, oversized, wrong schema or source, future-dated or stale) is read
//! as fenced, never as healthy.
//!
//! This is admission, not a write fence or a byte reservation: work already
//! in progress can still write, and a transition can race a check.
use axum::http::Method;
use serde_json::{Value, json};
#[cfg(unix)]
use std::io::Read;
#[cfg(unix)]
use std::os::unix::fs::MetadataExt;
use std::sync::RwLock;

pub(super) const SCHEMA: &str = "durable-workflow.storage-pressure.v1";
#[cfg(unix)]
const MAX_BYTES: u64 = 4096;
#[cfg(unix)]
const MAX_DEPTH: usize = 8;
pub(super) const RETRY_AFTER_SECONDS: i64 = 5;

/// `DW_STORAGE_ADMISSION_FILE`, `_SOURCE` and `_MAX_AGE_SECONDS`.
#[derive(Clone, Debug, Default)]
pub struct Settings {
    pub file: Option<String>,
    pub source: Option<String>,
    pub max_age_seconds: i64,
}

impl Settings {
    pub fn from_env() -> Self {
        let lookup = |key: &str| std::env::var(key).ok();
        let text = |name: &str| crate::config_audit::effective(lookup, name, None)
            .map(|value| value.string().to_owned()).filter(|value| !value.is_empty());
        Self {
            file: text("DW_STORAGE_ADMISSION_FILE"),
            source: text("DW_STORAGE_ADMISSION_SOURCE"),
            max_age_seconds: crate::config_audit::effective(lookup, "DW_STORAGE_ADMISSION_MAX_AGE_SECONDS", None)
                .map_or(15, |value| value.int()),
        }
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(super) enum State {
    Normal,
    Draining,
    Fenced,
}

impl State {
    fn name(self) -> &'static str {
        match self {
            Self::Normal => "normal",
            Self::Draining => "draining",
            Self::Fenced => "fenced",
        }
    }
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub(super) struct Snapshot {
    pub(super) enabled: bool,
    pub(super) state: State,
    pub(super) reason: Option<&'static str>,
    pub(super) observed_at: Option<i64>,
}

impl Snapshot {
    const DISABLED: Self = Self { enabled: false, state: State::Normal, reason: None, observed_at: None };
    const UNAVAILABLE: Self =
        Self { enabled: true, state: State::Fenced, reason: Some("storage_admission_unavailable"), observed_at: None };

    pub(super) fn accepts_new_work(&self) -> bool {
        self.state == State::Normal
    }

    /// The readiness check's view: no path or source identity.
    pub(super) fn describe(&self) -> Value {
        json!({"enabled":self.enabled,"state":self.state.name(),"reason":self.reason,"observed_at":self.observed_at})
    }
}

/// The configured observation, shared by request admission, polls and the
/// maintenance loop. Settings can be replaced at runtime (tests do).
#[derive(Debug, Default)]
pub struct StorageAdmission {
    settings: RwLock<Settings>,
}

impl StorageAdmission {
    pub fn new(settings: Settings) -> Self {
        Self { settings: RwLock::new(settings) }
    }

    pub fn set(&self, settings: Settings) {
        *self.settings.write().expect("storage admission settings") = settings;
    }

    pub(super) fn snapshot(&self) -> Snapshot {
        let settings = self.settings.read().expect("storage admission settings").clone();
        read(&settings, chrono::Utc::now().timestamp())
    }
}

fn read(settings: &Settings, now: i64) -> Snapshot {
    let (path, source) = match (&settings.file, &settings.source) {
        (None, None) => return Snapshot::DISABLED,
        (Some(path), Some(source)) if path.starts_with('/') => (path, source),
        _ => return Snapshot::UNAVAILABLE,
    };
    if !(1..=300).contains(&settings.max_age_seconds) {
        return Snapshot::UNAVAILABLE;
    }
    // The observation must come from root or this process's effective user.
    let Some(value) = platform_observation(path) else {
        return Snapshot::UNAVAILABLE;
    };
    let state = match value.get("state").and_then(Value::as_str) {
        Some("normal") => State::Normal,
        Some("draining") => State::Draining,
        Some("fenced") => State::Fenced,
        _ => return Snapshot::UNAVAILABLE,
    };
    let Some(observed_at) = value.get("observed_at").and_then(Value::as_i64).filter(|_| value["observed_at"].is_i64() || value["observed_at"].is_u64()) else {
        return Snapshot::UNAVAILABLE;
    };
    if value.get("schema").and_then(Value::as_str) != Some(SCHEMA)
        || value.get("source").and_then(Value::as_str) != Some(source.as_str())
    {
        return Snapshot::UNAVAILABLE;
    }
    let age = now.saturating_sub(observed_at);
    if !(0..=settings.max_age_seconds).contains(&age) {
        return Snapshot::UNAVAILABLE;
    }
    Snapshot {
        enabled: true,
        state,
        reason: (state != State::Normal).then_some("storage_pressure"),
        observed_at: Some(observed_at),
    }
}

#[cfg(unix)]
fn platform_observation(path: &str) -> Option<Value> {
    observation(path, unsafe { libc::geteuid() })
}

// File observations require the descriptor/ownership guarantees below. An
// unsupported target cannot turn a configured observation into healthy state.
#[cfg(not(unix))]
fn platform_observation(_path: &str) -> Option<Value> {
    None
}

/// A bounded regular file owned by root or the reader, never a FIFO, device
/// or symlink, nor writable by another user. Trusted parent directories remain
/// an operator requirement. The final path component is opened without following
/// a symlink and without blocking on a FIFO, and every check is made on that
/// same descriptor, so nothing can be swapped in between a check and the read.
#[cfg(unix)]
fn observation(path: &str, reader_uid: u32) -> Option<Value> {
    use std::os::unix::fs::OpenOptionsExt;
    let mut file = std::fs::OpenOptions::new().read(true)
        .custom_flags(libc::O_NOFOLLOW | libc::O_NONBLOCK | libc::O_CLOEXEC).open(path).ok()?;
    let opened = file.metadata().ok()?;
    if !opened.file_type().is_file() || opened.len() > MAX_BYTES || opened.mode() & 0o022 != 0
        || (opened.uid() != 0 && opened.uid() != reader_uid)
    {
        return None;
    }
    let mut bytes = Vec::with_capacity(MAX_BYTES as usize);
    file.by_ref().take(MAX_BYTES + 1).read_to_end(&mut bytes).ok()?;
    if bytes.len() as u64 > MAX_BYTES {
        return None;
    }
    let value: Value = serde_json::from_slice(&bytes).ok()?;
    (value.is_object() && depth(&value) <= MAX_DEPTH).then_some(value)
}

/// PHP's `json_decode` depth: a scalar is one level and each array or object
/// adds one, so `{"a":1}` needs depth 2 and `{}` depth 1.
#[cfg(unix)]
fn depth(value: &Value) -> usize {
    match value {
        Value::Array(items) => 1 + items.iter().map(depth).max().unwrap_or(0),
        Value::Object(fields) => 1 + fields.values().map(depth).max().unwrap_or(0),
        _ => 1,
    }
}

/// What a request may do under pressure.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(super) enum Admission {
    /// Read-only inspection, in every state.
    Always,
    /// Existing-lease acknowledgements and heartbeats: normal or draining.
    Drain,
    /// A poll or claim of new work: normal only, with the poll refusal shape.
    Poll,
    /// Any other mutation: normal only.
    Normal,
}

/// The route classes of PHP's `EnforceStorageAdmission`, on native paths.
pub(super) fn classify(method: &Method, path: &str) -> Admission {
    if matches!(*method, Method::GET | Method::HEAD | Method::OPTIONS) {
        return Admission::Always;
    }
    let segments: Vec<&str> = path.trim_end_matches('/').split('/').collect();
    match segments.as_slice() {
        // Reads served over POST.
        ["", "api", "worker", "workflow-tasks", _, "history"]
        | ["", "api", "worker", "activity-tasks", _, "status" | "continuation"]
        | ["", "api", "worker", "activity-tasks", _, "continuation", "history"] => Admission::Always,
        ["", "api", "worker", "workflow-tasks" | "activity-tasks" | "query-tasks" | "update-validation-tasks", "poll"] => {
            Admission::Poll
        }
        ["", "api", "worker", "heartbeat"]
        | ["", "api", "worker", "registrations", _, "deregister"]
        | ["", "api", "worker", "workflow-tasks", _, "heartbeat" | "complete" | "fail"]
        | ["", "api", "worker", "workflow-tasks", _, "cancellation-scopes", "open" | "prepare" | "deliver" | "checkpoint"]
        | ["", "api", "worker", "workflow-tasks", _, "local-activities", ..]
        | ["", "api", "worker", "query-tasks", _, "complete" | "fail"]
        | ["", "api", "worker", "update-validation-tasks", _, "approve" | "reject"]
        | ["", "api", "worker", "activity-tasks", _, "complete" | "fail" | "heartbeat" | "renew-lease" | "acknowledge-cancellation"]
        | ["", "api", "worker", "activity-tasks", _, "continuation", "complete"]
        | ["", "api", "worker", "sessions", _, "heartbeat"] => Admission::Drain,
        _ => Admission::Normal,
    }
}

/// Whether a request of this class proceeds in this state.
pub(super) fn admits(admission: Admission, snapshot: &Snapshot) -> bool {
    match (admission, snapshot.state) {
        (_, State::Normal) | (Admission::Always, _) => true,
        (Admission::Drain, State::Draining) => true,
        _ => false,
    }
}

/// The refusal body. A refusal before the handler says nothing about any
/// earlier attempt. Paths and the storage identity are never exposed.
pub(super) fn refusal(snapshot: &Snapshot, admission: Admission, poll_request_id: Option<&str>) -> Value {
    let mut body = json!({
        "reason": snapshot.reason.unwrap_or("storage_admission_unavailable"),
        "message": "Storage admission is paused. Wait for the operator to restore capacity.",
        "storage_state": snapshot.state.name(),
        "retryable": true,
        "retry_after_seconds": RETRY_AFTER_SECONDS,
        "request_admitted": false,
    });
    if admission == Admission::Poll {
        // A previous attempt with this poll id may already own a lease.
        body["task"] = Value::Null;
        body["poll_status"] = json!(snapshot.reason.unwrap_or("storage_admission_unavailable"));
        body["poll_request_id"] = json!(poll_request_id.filter(|id| !id.is_empty() && id.len() <= 255));
        body["retry_same_poll_request_id"] = json!(true);
        body["claim_admitted"] = json!(false);
    }
    body
}

#[cfg(test)]
mod tests {
    use super::*;
    #[cfg(unix)]
    use std::os::unix::fs::PermissionsExt;

    const NOW: i64 = 1_793_829_000;

    #[cfg(unix)]
    fn settings(path: &std::path::Path) -> Settings {
        Settings { file: Some(path.to_str().unwrap().to_owned()), source: Some("cell-a".into()), max_age_seconds: 5 }
    }

    #[cfg(unix)]
    fn write(path: &std::path::Path, body: &str) {
        let staged = path.with_extension("tmp");
        std::fs::write(&staged, body).unwrap();
        std::fs::set_permissions(&staged, std::fs::Permissions::from_mode(0o644)).unwrap();
        // The producer replaces the file atomically.
        std::fs::rename(&staged, path).unwrap();
    }

    #[cfg(unix)]
    fn record(state: &str, observed_at: i64, usage: &str) -> String {
        format!(r#"{{"schema":"{SCHEMA}","source":"cell-a","observed_at":{observed_at},"state":"{state}","usage":{usage}}}"#)
    }

    #[cfg(unix)]
    #[test]
    fn producer_records_are_read_fresh_on_every_check() {
        let directory = tempfile::tempdir().unwrap();
        let path = directory.path().join("pressure.json");
        let settings = settings(&path);
        assert_eq!(read(&Settings::default(), NOW), Snapshot::DISABLED);
        write(&path, &record("normal", NOW - 1, r#"{"free_bytes":1048576,"used_bytes":42}"#));
        assert_eq!(read(&settings, NOW), Snapshot { enabled: true, state: State::Normal, reason: None, observed_at: Some(NOW - 1) });
        write(&path, &record("draining", NOW, r#"{"free_bytes":1024}"#));
        let draining = read(&settings, NOW);
        assert_eq!((draining.state, draining.reason), (State::Draining, Some("storage_pressure")));
        // A fenced observation failure may carry no usage at all.
        write(&path, &record("fenced", NOW - 5, "{}"));
        assert_eq!(read(&settings, NOW).state, State::Fenced);
        write(&path, &record("normal", NOW, "{}"));
        assert!(read(&settings, NOW).accepts_new_work(), "the replacement is seen at once");
    }

    #[cfg(unix)]
    #[test]
    fn untrusted_evidence_fails_closed() {
        let directory = tempfile::tempdir().unwrap();
        let path = directory.path().join("pressure.json");
        let base = settings(&path);
        let unavailable = |settings: &Settings| assert_eq!(read(settings, NOW), Snapshot::UNAVAILABLE);
        unavailable(&base); // missing
        for body in [
            "not json".to_owned(),
            "[]".to_owned(),
            record("normal", NOW - 6, "{}"), // stale
            record("normal", NOW + 1, "{}"), // future
            record("melting", NOW, "{}"),
            record("normal", NOW, "{}").replace("cell-a", "cell-b"),
            record("normal", NOW, "{}").replace(SCHEMA, "durable-workflow.storage-pressure.v2"),
            record("normal", NOW, "{}").replace(&NOW.to_string(), &format!("\"{NOW}\"")),
            record("normal", NOW, "{}").replace(&NOW.to_string(), &format!("{NOW}.0")),
            record("normal", NOW, r#"{"a":{"b":{"c":{"d":{"e":{"f":{"g":1}}}}}}}"#), // depth 9
            format!("{}{}", record("normal", NOW, "{}"), " ".repeat(4096)),
        ] {
            write(&path, &body);
            assert_eq!(read(&base, NOW), Snapshot::UNAVAILABLE, "{body}");
        }
        // Depth 8 is accepted: seven objects and the scalar inside them.
        write(&path, &record("normal", NOW, r#"{"a":{"b":{"c":{"d":{"e":{"f":1}}}}}}"#));
        assert!(read(&base, NOW).accepts_new_work());
        // Writable by group or others, or a symlink to a good record.
        std::fs::set_permissions(&path, std::fs::Permissions::from_mode(0o664)).unwrap();
        unavailable(&base);
        std::fs::set_permissions(&path, std::fs::Permissions::from_mode(0o644)).unwrap();
        let link = directory.path().join("link.json");
        std::os::unix::fs::symlink(&path, &link).unwrap();
        unavailable(&settings(&link));
        // A FIFO is refused without blocking the reader.
        let fifo = directory.path().join("fifo.json");
        let fifo_path = std::ffi::CString::new(fifo.to_str().unwrap()).unwrap();
        assert_eq!(unsafe { libc::mkfifo(fifo_path.as_ptr(), 0o644) }, 0);
        unavailable(&settings(&fifo));
        // A directory, a relative path, half a configuration or an age bound
        // outside 1..=300.
        unavailable(&settings(directory.path()));
        unavailable(&Settings { file: Some("pressure.json".into()), ..base.clone() });
        unavailable(&Settings { source: None, ..base.clone() });
        unavailable(&Settings { file: None, ..base.clone() });
        unavailable(&Settings { max_age_seconds: 0, ..base.clone() });
        unavailable(&Settings { max_age_seconds: 301, ..base.clone() });
    }

    #[cfg(unix)]
    #[test]
    fn observation_owner_must_be_root_or_the_reader() {
        let directory = tempfile::tempdir().unwrap();
        let path = directory.path().join("pressure.json");
        let path_text = path.to_str().unwrap();
        let reader_uid = unsafe { libc::geteuid() };
        write(&path, &record("normal", NOW, "{}"));
        assert!(read(&settings(&path), NOW).accepts_new_work());

        if reader_uid == 0 {
            // In the privileged container, exercise a real foreign-owned
            // descriptor through the production snapshot path as well.
            std::os::unix::fs::chown(&path, Some(65534), None).unwrap();
            assert_eq!(read(&settings(&path), NOW), Snapshot::UNAVAILABLE);
        }
        let owner_uid = std::fs::metadata(&path).unwrap().uid();
        assert_ne!(owner_uid, 0);
        // This also exercises the descriptor policy on unprivileged runners,
        // without requiring permission to change an inode's owner.
        let other_uid = if owner_uid == 1 { 2 } else { 1 };
        assert!(observation(path_text, owner_uid).is_some());
        assert!(observation(path_text, other_uid).is_none());

        if reader_uid == 0 {
            std::os::unix::fs::chown(&path, Some(0), None).unwrap();
            // A root-owned observation is trusted by a non-root reader too.
            assert!(observation(path_text, other_uid).is_some());
            assert!(read(&settings(&path), NOW).accepts_new_work());
        }
        // Atomic producer replacement restores the actual reader's ownership.
        write(&path, &record("draining", NOW, "{}"));
        assert_eq!(read(&settings(&path), NOW).state, State::Draining);
    }

    #[test]
    fn disabled_admission_allows_work_without_a_file_observation() {
        let snapshot = read(&Settings::default(), NOW);
        assert_eq!(snapshot, Snapshot::DISABLED);
        assert!(admits(Admission::Normal, &snapshot));
        assert_eq!(snapshot.describe()["enabled"], false);
    }

    #[cfg(not(unix))]
    #[test]
    fn configured_observation_on_an_unsupported_platform_fails_closed() {
        for path in ["/storage-pressure.json", r"C:\storage-pressure.json"] {
            let snapshot = read(&Settings { file:Some(path.into()),source:Some("fixture".into()),max_age_seconds:15 }, NOW);
            assert_eq!(snapshot, Snapshot::UNAVAILABLE);
            assert!(!admits(Admission::Normal, &snapshot));
            assert_eq!(snapshot.describe()["reason"], "storage_admission_unavailable");
        }
    }

    #[test]
    fn routes_follow_php_s_admission_classes() {
        for (method, path, expected) in [
            (Method::GET, "/api/workflows/a", Admission::Always),
            (Method::POST, "/api/worker/workflow-tasks/t/history", Admission::Always),
            (Method::POST, "/api/worker/activity-tasks/t/continuation/history", Admission::Always),
            (Method::POST, "/api/worker/workflow-tasks/poll", Admission::Poll),
            (Method::POST, "/api/worker/activity-tasks/poll", Admission::Poll),
            (Method::POST, "/api/worker/query-tasks/poll", Admission::Poll),
            (Method::POST, "/api/worker/update-validation-tasks/poll", Admission::Poll),
            (Method::POST, "/api/worker/workflow-tasks/t/complete", Admission::Drain),
            (Method::POST, "/api/worker/activity-tasks/t/heartbeat", Admission::Drain),
            (Method::POST, "/api/worker/activity-tasks/t/continuation/complete", Admission::Drain),
            (Method::POST, "/api/worker/workflow-tasks/t/local-activities/a/outcome", Admission::Drain),
            (Method::POST, "/api/worker/registrations/w/deregister", Admission::Drain),
            (Method::POST, "/api/worker/register", Admission::Normal),
            (Method::POST, "/api/workflows", Admission::Normal),
            (Method::POST, "/api/workflows/a/signal/s", Admission::Normal),
            (Method::POST, "/api/workflows/a/query/q", Admission::Normal),
            (Method::POST, "/api/external-payloads/v1", Admission::Normal),
            (Method::DELETE, "/api/namespaces/n", Admission::Normal),
        ] {
            assert_eq!(classify(&method, path), expected, "{method} {path}");
        }
        let draining = Snapshot { enabled: true, state: State::Draining, reason: Some("storage_pressure"), observed_at: Some(NOW) };
        assert!(admits(Admission::Drain, &draining) && !admits(Admission::Poll, &draining) && !admits(Admission::Normal, &draining));
        assert!(admits(Admission::Always, &Snapshot::UNAVAILABLE) && !admits(Admission::Drain, &Snapshot::UNAVAILABLE));
        let body = refusal(&draining, Admission::Poll, Some("poll-1"));
        assert_eq!((body["poll_request_id"].clone(), body["claim_admitted"].clone(), body["task"].clone()), (json!("poll-1"), json!(false), Value::Null));
        assert_eq!(refusal(&Snapshot::UNAVAILABLE, Admission::Normal, None)["reason"], "storage_admission_unavailable");
    }
}
