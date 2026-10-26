//! The package's DW-primary, WORKFLOW-legacy structural limits: the memo
//! ceiling and `StructuralLimits`' signal, update and payload ceilings.
use super::{Result, refuse};
use axum::http::StatusCode;
use regex_lite::Regex;
use std::{env, sync::LazyLock};

const DEFAULT_BYTES: usize = 262_144;
static NUMERIC_PREFIX: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"^[ \t\r\n\x0b\x0c]*([+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?)")
        .expect("static PHP numeric prefix")
});

pub(super) fn typed_null(value: &str) -> bool {
    matches!(value.to_ascii_lowercase().as_str(), "null" | "(null)")
}

#[cfg(test)]
fn limit(primary: Option<&str>, legacy: Option<&str>) -> Option<usize> {
    limit_or(primary, legacy, DEFAULT_BYTES)
}

/// A limit at or below zero disables its check, as `intConfig` and the
/// guards' `$limit <= 0` do.
fn limit_or(primary: Option<&str>, legacy: Option<&str>, default: usize) -> Option<usize> {
    let Some(value) = primary.filter(|value| !typed_null(value)).or(legacy) else {
        return Some(default);
    };
    let bytes = cast_integer(value);
    (bytes > 0).then_some(bytes.max(0) as usize)
}

pub(super) fn cast_integer(value: &str) -> i64 {
    let value = match value.to_ascii_lowercase().as_str() {
        "true" | "(true)" => "1",
        "false" | "(false)" | "null" | "(null)" | "empty" | "(empty)" => "0",
        _ => value,
    };
    // Laravel unwraps matching quotes before the workflow config casts to int.
    let value = value
        .strip_prefix('"')
        .and_then(|value| value.strip_suffix('"'))
        .or_else(|| {
            value
                .strip_prefix('\'')
                .and_then(|value| value.strip_suffix('\''))
        })
        .unwrap_or(value);
    cast_numeric_string(value)
}

pub(super) fn cast_numeric_string(value: &str) -> i64 {
    let numeric = NUMERIC_PREFIX
        .captures(value)
        .and_then(|value| value.get(1));
    numeric.map_or(0, |value| {
        value
            .as_str()
            .parse::<i64>()
            .unwrap_or_else(|_| value.as_str().parse::<f64>().unwrap_or(0.0) as i64)
    })
}

pub(super) fn configured_limit() -> Result<Option<usize>> {
    configured("MEMO_SIZE_BYTES", DEFAULT_BYTES, "invalid_memo_limit_configuration")
}

fn configured(suffix: &str, default: usize, invalid: &'static str) -> Result<Option<usize>> {
    let read = |key: String| match env::var(key) {
        Ok(value) => Ok(Some(value)),
        Err(env::VarError::NotPresent) => Ok(None),
        Err(env::VarError::NotUnicode(_)) => Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid)),
    };
    let primary = read(format!("DW_V2_LIMIT_{suffix}"))?;
    let legacy = if primary.as_deref().is_none_or(typed_null) {
        read(format!("WORKFLOW_V2_LIMIT_{suffix}"))?
    } else {
        None
    };
    Ok(limit_or(primary.as_deref(), legacy.as_deref(), default))
}

/// `StructuralLimits`' per-run signal and update ceilings and its
/// single-payload byte ceiling. `None` is a disabled check.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct StructuralLimits {
    /// Received signals a run may hold (DW_V2_LIMIT_PENDING_SIGNALS).
    pub pending_signals: Option<usize>,
    /// Accepted updates a run may hold (DW_V2_LIMIT_PENDING_UPDATES).
    pub pending_updates: Option<usize>,
    /// Bytes of one serialized payload blob (DW_V2_LIMIT_PAYLOAD_SIZE_BYTES).
    pub payload_bytes: Option<usize>,
    /// History events one transaction may write
    /// (DW_V2_LIMIT_HISTORY_TRANSACTION_SIZE; Workflow #930): a workflow task
    /// completion or a Waterline v1 projection import.
    pub history_events: Option<usize>,
    /// History bytes one transaction may write, measured as PHP measures an
    /// event (DW_V2_LIMIT_HISTORY_TRANSACTION_BYTES).
    pub history_bytes: Option<usize>,
    /// Metered rows one workflow task completion may write: each history
    /// event and the run position it advances, and each local activity row
    /// (DW_V2_LIMIT_HISTORY_TRANSACTION_ROWS).
    pub history_rows: Option<usize>,
}

impl Default for StructuralLimits {
    fn default() -> Self {
        Self {
            pending_signals: Some(5000),
            pending_updates: Some(500),
            payload_bytes: Some(2_097_152),
            history_events: Some(5000),
            history_bytes: Some(16_777_216),
            history_rows: Some(20_000),
        }
    }
}

impl StructuralLimits {
    pub fn from_env() -> Result<Self> {
        let defaults = Self::default();
        let invalid = "invalid_structural_limit_configuration";
        Ok(Self {
            pending_signals: configured("PENDING_SIGNALS", defaults.pending_signals.unwrap(), invalid)?,
            pending_updates: configured("PENDING_UPDATES", defaults.pending_updates.unwrap(), invalid)?,
            payload_bytes: configured("PAYLOAD_SIZE_BYTES", defaults.payload_bytes.unwrap(), invalid)?,
            history_events: configured("HISTORY_TRANSACTION_SIZE", defaults.history_events.unwrap(), invalid)?,
            history_bytes: configured("HISTORY_TRANSACTION_BYTES", defaults.history_bytes.unwrap(), invalid)?,
            history_rows: configured("HISTORY_TRANSACTION_ROWS", defaults.history_rows.unwrap(), invalid)?,
        })
    }
}

/// One exceeded ceiling, as `StructuralLimitExceededException` reports it.
#[derive(Clone, Debug)]
pub(super) struct Exceeded {
    pub(super) kind: &'static str,
    pub(super) value: i64,
    pub(super) configured: i64,
    pub(super) message: String,
}

impl Exceeded {
    fn new(kind: &'static str, value: usize, configured: usize, message: String) -> Self {
        let int = |value: usize| i64::try_from(value).unwrap_or(i64::MAX);
        Self { kind, value: int(value), configured: int(configured), message }
    }

    /// `guardPendingSignals`: received signals at or above the ceiling.
    pub(super) fn pending_signals(limit: Option<usize>, count: i64) -> Option<Self> {
        let (limit, count) = (limit?, usize::try_from(count).unwrap_or(0));
        (count >= limit).then(|| Self::new("pending_signal_count", count, limit,
            format!("Structural limit exceeded: {count} pending signals (limit {limit}).")))
    }

    /// `guardPendingUpdates`: accepted updates at or above the ceiling.
    pub(super) fn pending_updates(limit: Option<usize>, count: i64) -> Option<Self> {
        let (limit, count) = (limit?, usize::try_from(count).unwrap_or(0));
        (count >= limit).then(|| Self::new("pending_update_count", count, limit,
            format!("Structural limit exceeded: {count} pending updates (limit {limit}).")))
    }

    /// `guardPayloadSize`: a serialized blob longer than the ceiling.
    pub(super) fn payload(limit: Option<usize>, blob: &str) -> Option<Self> {
        let (limit, bytes) = (limit?, blob.len());
        (bytes > limit).then(|| Self::new("payload_size", bytes, limit,
            format!("Structural limit exceeded: payload size {bytes} bytes (limit {limit} bytes).")))
    }

    /// The command payload's diagnostic fields
    /// (`structuralLimitExceededCommandPayloadAttributes`).
    pub(super) fn fields(&self) -> serde_json::Value {
        serde_json::json!({"structural_limit_kind":self.kind,"structural_limit_value":self.value,
            "structural_limit_configured":self.configured})
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn published_php_casts_and_alias_priority_are_preserved() {
        let cases: Vec<(Option<&str>, Option<usize>)> = vec![
            (None, Some(73)),
            (Some(""), None),
            (Some("0"), None),
            (Some("-1"), None),
            (Some("15"), Some(15)),
            (Some("  15 "), Some(15)),
            (Some("15.9"), Some(15)),
            (Some("1e2"), Some(100)),
            (Some("junk"), None),
            (Some("12junk"), Some(12)),
            (Some("true"), Some(1)),
            (Some("false"), None),
            (Some("null"), Some(73)),
            (Some("(null)"), Some(73)),
            (Some("empty"), None),
            (Some("(empty)"), None),
            (Some("\"21\""), Some(21)),
            (Some("9223372036854775808"), Some(i64::MAX as usize)),
            (Some("1e100"), Some(i64::MAX as usize)),
        ];
        for (primary, expected) in cases {
            assert_eq!(limit(primary, Some("73")), expected, "{primary:?}");
        }
        assert_eq!(limit(None, None), Some(DEFAULT_BYTES));
        assert_eq!(limit(Some("(null)"), None), Some(DEFAULT_BYTES));
        assert_eq!(limit(Some("25"), Some("9")), Some(25));
        assert_eq!(limit(None, Some("null")), None);
    }

    #[test]
    fn structural_ceilings_use_php_defaults_and_disable_at_zero() {
        assert_eq!(limit_or(None, None, 5000), Some(5000));
        assert_eq!(limit_or(Some("2"), None, 5000), Some(2));
        assert_eq!(limit_or(Some("0"), None, 500), None);
        assert_eq!(limit_or(Some("-3"), Some("9"), 500), None);
        assert_eq!(limit_or(Some("null"), Some("9"), 500), Some(9));
        let signals = Exceeded::pending_signals(Some(2), 2).unwrap();
        assert_eq!((signals.kind, signals.value, signals.configured), ("pending_signal_count", 2, 2));
        assert_eq!(signals.message, "Structural limit exceeded: 2 pending signals (limit 2).");
        assert!(Exceeded::pending_signals(Some(2), 1).is_none());
        assert!(Exceeded::pending_updates(None, 900).is_none());
        let payload = Exceeded::payload(Some(300), &"x".repeat(556)).unwrap();
        assert_eq!(payload.message, "Structural limit exceeded: payload size 556 bytes (limit 300 bytes).");
        assert!(Exceeded::payload(Some(300), &"x".repeat(300)).is_none());
    }
}
