//! History pages for workflow-task polls and continuation requests, as
//! Server 2.10.0 and Workflow 2.9.0 serve them:
//! - a poll returns the whole history unless the worker asks for a page size
//!   or a supported encoding (`WorkflowTaskPoller::fetchHistory`);
//! - a continuation page holds the requested size, else the configured
//!   default, bounded by the configured maximum;
//! - every page holds 1 to 1000 events (`historyPayloadPaginated`);
//! - a worker that accepts gzip or deflate receives a page of 50 or more
//!   events as one compressed, base64-encoded JSON array, with
//!   `history_events` left empty (`HistoryPayloadCompression`).
use super::{Result, RuntimeError, refuse};
use axum::http::StatusCode;
use base64::{Engine, engine::general_purpose::STANDARD};
use serde_json::{Value, json};

/// `WorkerProtocolVersion::SUPPORTED_HISTORY_ENCODINGS`, in preference order.
const SUPPORTED: [&str; 2] = ["gzip", "deflate"];
/// `WorkerProtocolVersion::COMPRESSION_THRESHOLD`.
const THRESHOLD: usize = 50;
/// zlib's default level, which PHP's `gzencode` and `gzdeflate` use.
const LEVEL: u8 = 6;

/// The configured page sizes (DW_HISTORY_PAGE_SIZE_DEFAULT and _MAX).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) struct HistoryPages {
    pub(crate) default: i64,
    pub(crate) max: i64,
}

impl Default for HistoryPages {
    fn default() -> Self {
        Self { default: 500, max: 1000 }
    }
}

/// `historyPayloadPaginated`: `max(1, min(pageSize, 1000))`.
fn bounded(size: i64) -> i64 {
    size.clamp(1, 1000)
}

impl HistoryPages {
    /// A poll's page size, or `None` for the whole history. An explicit size
    /// is capped by the maximum. An accepted encoding alone pages at the
    /// default, as compression is page-oriented.
    pub(super) fn poll(self, requested: Option<i64>, encoding: Option<&str>) -> Option<i64> {
        match (requested, encoding) {
            (Some(size), _) => Some(bounded(size.min(self.max))),
            (None, Some(_)) => Some(bounded(self.default.min(self.max))),
            (None, None) => None,
        }
    }

    /// `workflowTaskHistory`: `min(history_page_size ?? default, max)`.
    pub(super) fn continuation(self, requested: Option<i64>) -> i64 {
        bounded(requested.unwrap_or(self.default).min(self.max))
    }
}

/// The request's `history_page_size` (`nullable|integer|min:1|max:1000`).
pub(super) fn requested_size(body: &Value) -> Result<Option<i64>> {
    match body.get("history_page_size") {
        None | Some(Value::Null) => Ok(None),
        Some(value) => value
            .as_i64()
            .filter(|size| (1..=1000).contains(size))
            .map(Some)
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_page_size")),
    }
}

/// `HistoryPayloadCompression::resolveEncoding`: the first supported
/// encoding the comma-separated, case-insensitive list names.
pub(super) fn resolve(accept: &str) -> Option<&'static str> {
    let lowered = accept.to_ascii_lowercase();
    // PHP's trim() strips space, tab, newline, carriage return, NUL and
    // vertical tab.
    let candidates: Vec<&str> = lowered
        .split(',')
        .map(|candidate| candidate.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B']))
        .collect();
    SUPPORTED
        .into_iter()
        .find(|supported| candidates.contains(supported))
}

/// The request's `accept_history_encoding` (`nullable|string|max:64`),
/// resolved to a supported encoding.
pub(super) fn requested(body: &Value) -> Result<Option<&'static str>> {
    let message = match body.get("accept_history_encoding") {
        None | Some(Value::Null) => return Ok(None),
        Some(Value::String(accept)) if accept.chars().count() <= 64 => return Ok(resolve(accept)),
        Some(Value::String(_)) => "The accept history encoding field must not be greater than 64 characters.",
        Some(_) => "The accept history encoding field must be a string.",
    };
    Err(refusal(message))
}

fn refusal(message: &'static str) -> RuntimeError {
    super::laravel_validation::failure(vec![("accept_history_encoding", vec![message])])
        .expect("one message")
}

/// `HistoryPayloadCompression::compress` on a payload with `history_events`.
pub(super) fn compress(payload: &mut Value, encoding: Option<&'static str>) -> Result<()> {
    let Some(encoding) = encoding else {
        return Ok(());
    };
    let Some(events) = payload.get("history_events").and_then(Value::as_array) else {
        return Ok(());
    };
    if events.len() < THRESHOLD {
        return Ok(());
    }
    let json = serde_json::to_vec(events)?;
    let compressed = match encoding {
        "gzip" => gzip(&json),
        _ => miniz_oxide::deflate::compress_to_vec(&json, LEVEL),
    };
    payload["history_events"] = json!([]);
    payload["history_events_compressed"] = json!(STANDARD.encode(compressed));
    payload["history_events_encoding"] = json!(encoding);
    Ok(())
}

/// `gzencode`: an RFC 1952 member around one raw DEFLATE stream.
fn gzip(data: &[u8]) -> Vec<u8> {
    const CRC32: crc::Crc<u32> = crc::Crc::<u32>::new(&crc::CRC_32_ISO_HDLC);
    // Magic, deflate method, no flags or time, default level, Unix.
    let mut member = vec![0x1f, 0x8b, 8, 0, 0, 0, 0, 0, 0, 3];
    member.extend(miniz_oxide::deflate::compress_to_vec(data, LEVEL));
    member.extend(CRC32.checksum(data).to_le_bytes());
    member.extend((data.len() as u32).to_le_bytes());
    member
}

#[cfg(test)]
mod tests {
    use super::*;

    fn events(count: usize) -> Value {
        json!({"history_events": (0..count).map(|sequence| json!({"sequence": sequence, "event_type": "TimerFired",
            "payload": {"note": "λ/\u{0}"}})).collect::<Vec<_>>()})
    }

    #[test]
    fn page_sizes_follow_the_server_and_bridge() {
        let pages = HistoryPages::default();
        assert_eq!(pages.poll(None, None), None);
        assert_eq!(pages.poll(Some(2), None), Some(2));
        assert_eq!(pages.poll(None, Some("gzip")), Some(500));
        assert_eq!(pages.poll(Some(800), Some("gzip")), Some(800));
        assert_eq!(pages.continuation(None), 500);
        assert_eq!(pages.continuation(Some(7)), 7);
        let configured = HistoryPages { default: 2000, max: 300 };
        assert_eq!(configured.poll(Some(800), None), Some(300));
        assert_eq!(configured.poll(None, Some("deflate")), Some(300));
        assert_eq!(configured.continuation(None), 300);
        let zero = HistoryPages { default: 0, max: 0 };
        assert_eq!(zero.poll(Some(5), None), Some(1));
        assert_eq!(zero.continuation(None), 1);
        assert_eq!(HistoryPages { default: 5000, max: 9000 }.continuation(None), 1000);
        assert_eq!(requested_size(&json!({})).unwrap(), None);
        assert_eq!(requested_size(&json!({"history_page_size": null})).unwrap(), None);
        assert_eq!(requested_size(&json!({"history_page_size": 1000})).unwrap(), Some(1000));
        for invalid in [json!(0), json!(1001), json!("10"), json!(1.5)] {
            assert!(requested_size(&json!({"history_page_size": invalid})).is_err(), "{invalid}");
        }
    }

    #[test]
    fn encodings_resolve_like_php() {
        assert_eq!(resolve("gzip"), Some("gzip"));
        assert_eq!(resolve(" DEFLATE , br"), Some("deflate"));
        // PHP checks its own list in order, so gzip wins wherever it appears.
        assert_eq!(resolve("deflate, gzip"), Some("gzip"));
        assert_eq!(resolve("br,zstd"), None);
        assert_eq!(resolve("gzip;q=1"), None);
        assert_eq!(resolve(""), None);
    }

    #[test]
    fn requests_validate_like_laravel() {
        assert_eq!(requested(&json!({})).unwrap(), None);
        assert_eq!(requested(&json!({"accept_history_encoding": null})).unwrap(), None);
        assert_eq!(requested(&json!({"accept_history_encoding": "br"})).unwrap(), None);
        assert_eq!(requested(&json!({"accept_history_encoding": "gzip"})).unwrap(), Some("gzip"));
        for (value, message) in [
            (json!(1), "The accept history encoding field must be a string."),
            (json!(["gzip"]), "The accept history encoding field must be a string."),
            (json!("x".repeat(65)), "The accept history encoding field must not be greater than 64 characters."),
        ] {
            let Err(RuntimeError::Protocol { response, .. }) = requested(&json!({"accept_history_encoding": value})) else {
                panic!("{value} must be refused");
            };
            assert_eq!(response["errors"]["accept_history_encoding"], json!([message]));
        }
        assert!(requested(&json!({"accept_history_encoding": "λ".repeat(64)})).is_ok());
    }

    #[test]
    fn pages_below_the_threshold_stay_plain() {
        let mut payload = events(49);
        let original = payload.clone();
        compress(&mut payload, Some("gzip")).unwrap();
        assert_eq!(payload, original);
        let mut payload = events(50);
        compress(&mut payload, None).unwrap();
        assert_eq!(payload, events(50));
    }

    #[test]
    fn compressed_pages_decode_to_the_same_events() {
        for encoding in ["gzip", "deflate"] {
            let mut payload = events(50);
            compress(&mut payload, Some(encoding)).unwrap();
            assert_eq!(payload["history_events"], json!([]));
            assert_eq!(payload["history_events_encoding"], encoding);
            let bytes = STANDARD.decode(payload["history_events_compressed"].as_str().unwrap()).unwrap();
            let raw = if encoding == "gzip" {
                assert_eq!(&bytes[..3], &[0x1f, 0x8b, 8]);
                let (body, trailer) = bytes[10..].split_at(bytes.len() - 18);
                let raw = miniz_oxide::inflate::decompress_to_vec(body).unwrap();
                const CRC32: crc::Crc<u32> = crc::Crc::<u32>::new(&crc::CRC_32_ISO_HDLC);
                assert_eq!(trailer[..4], CRC32.checksum(&raw).to_le_bytes());
                assert_eq!(trailer[4..], (raw.len() as u32).to_le_bytes());
                raw
            } else {
                miniz_oxide::inflate::decompress_to_vec(&bytes).unwrap()
            };
            let decoded: Value = serde_json::from_slice(&raw).unwrap();
            assert_eq!(decoded, events(50)["history_events"]);
        }
    }
}
