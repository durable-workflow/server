//! PHP's `CompressResponse` middleware: a JSON answer of 1024 bytes or more
//! is sent gzip- or deflate-encoded when the request's `Accept-Encoding`
//! allows it, unless `DW_COMPRESSION_ENABLED` is false (default true).
//!
//! As in PHP, gzip wins over deflate at equal weight, `*` stands for both,
//! `q=0` refuses an encoding, an answer that already has a
//! `Content-Encoding` is left alone, and `Vary: Accept-Encoding` is added.
//! Only answers whose whole body is already in memory (an exact size hint)
//! are encoded; streamed bodies pass through unchanged.
use axum::{
    body::{Body, HttpBody as _},
    extract::Request,
    http::{HeaderMap, HeaderValue, header},
    middleware::Next,
    response::Response,
};
use std::sync::OnceLock;

/// `CompressResponse::MIN_COMPRESS_BYTES`.
const MIN_COMPRESS_BYTES: u64 = 1024;
/// The largest in-memory answer that is encoded; bigger ones pass through.
const MAX_COMPRESS_BYTES: u64 = 64 * 1024 * 1024;
/// zlib's default level, which PHP's `gzencode` and `gzdeflate` use.
const LEVEL: u8 = 6;

/// `config('server.compression.enabled')`: `filter_var(DW_COMPRESSION_ENABLED
/// ?? WORKFLOW_SERVER_COMPRESSION_ENABLED ?? true, FILTER_VALIDATE_BOOLEAN,
/// FILTER_NULL_ON_FAILURE) ?? true`, read once.
pub(super) fn enabled() -> bool {
    static ENABLED: OnceLock<bool> = OnceLock::new();
    *ENABLED.get_or_init(|| configured(|name| std::env::var(name).ok()))
}

fn configured(lookup: impl Fn(&str) -> Option<String>) -> bool {
    crate::config_audit::effective(lookup, "DW_COMPRESSION_ENABLED", Some("WORKFLOW_SERVER_COMPRESSION_ENABLED"))
        .is_none_or(|value| value.filter_bool().unwrap_or(true))
}

/// The encodings advertised as `response_compression`.
pub(super) fn advertised() -> &'static [&'static str] {
    if enabled() { &["gzip", "deflate"] } else { &[] }
}

pub(super) async fn compress(request: Request, next: Next) -> Response {
    let encoding = if enabled() { preferred(request.headers()) } else { None };
    let response = next.run(request).await;
    match encoding {
        Some(encoding) => encode(response, encoding).await,
        None => response,
    }
}

/// `CompressResponse::preferredEncoding` over every `Accept-Encoding` value.
fn preferred(headers: &HeaderMap) -> Option<&'static str> {
    let mut accept = String::new();
    for value in headers.get_all(header::ACCEPT_ENCODING) {
        if let Ok(value) = value.to_str() {
            if !accept.is_empty() {
                accept.push(',');
            }
            accept.push_str(value);
        }
    }
    let (mut gzip, mut deflate, mut wildcard) = (None::<f64>, None::<f64>, None::<f64>);
    for part in accept.to_ascii_lowercase().split(',') {
        let mut segments = part.split(';').map(str::trim);
        let name = segments.next().unwrap_or("");
        if name.is_empty() {
            continue;
        }
        let mut weight = 1.0;
        for segment in segments {
            if let Some(value) = segment.strip_prefix("q=") {
                weight = value.trim().parse::<f64>().ok().filter(|q| q.is_finite()).map_or(0.0, |q| q.clamp(0.0, 1.0));
            }
        }
        match name {
            "*" => wildcard = Some(weight),
            "gzip" => gzip = Some(gzip.map_or(weight, |q| q.max(weight))),
            "deflate" => deflate = Some(deflate.map_or(weight, |q| q.max(weight))),
            _ => {}
        }
    }
    let gzip = gzip.or(wildcard).unwrap_or(0.0);
    let deflate = deflate.or(wildcard).unwrap_or(0.0);
    if gzip > 0.0 && gzip >= deflate {
        Some("gzip")
    } else if deflate > 0.0 {
        Some("deflate")
    } else {
        None
    }
}

fn json(headers: &HeaderMap) -> bool {
    headers
        .get(header::CONTENT_TYPE)
        .and_then(|value| value.to_str().ok())
        .is_some_and(|value| value.contains("application/json"))
}

async fn encode(response: Response, encoding: &'static str) -> Response {
    let size = response.body().size_hint().exact();
    if response.headers().contains_key(header::CONTENT_ENCODING)
        || !json(response.headers())
        || !size.is_some_and(|size| (MIN_COMPRESS_BYTES..=MAX_COMPRESS_BYTES).contains(&size))
    {
        return response;
    }
    let (mut parts, body) = response.into_parts();
    let Ok(bytes) = axum::body::to_bytes(body, MAX_COMPRESS_BYTES as usize).await else {
        // An exact-sized in-memory body cannot fail to collect.
        return Response::from_parts(parts, Body::empty());
    };
    let encoded = match encoding {
        "gzip" => gzip(&bytes),
        _ => miniz_oxide::deflate::compress_to_vec(&bytes, LEVEL),
    };
    parts.headers.remove(header::CONTENT_LENGTH);
    parts.headers.insert(header::CONTENT_ENCODING, HeaderValue::from_static(encoding));
    let vary = parts
        .headers
        .get_all(header::VARY)
        .iter()
        .filter_map(|value| value.to_str().ok())
        .any(|value| value.split(',').any(|name| name.trim().eq_ignore_ascii_case("accept-encoding")));
    if !vary {
        parts.headers.append(header::VARY, HeaderValue::from_static("Accept-Encoding"));
    }
    Response::from_parts(parts, Body::from(encoded))
}

/// `gzencode`: an RFC 1952 member around one raw DEFLATE stream.
pub(super) fn gzip(data: &[u8]) -> Vec<u8> {
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
    use axum::{Router, http::StatusCode, middleware, response::IntoResponse, routing::get};
    use tower::ServiceExt as _;

    fn accept(value: &str) -> HeaderMap {
        let mut headers = HeaderMap::new();
        headers.insert(header::ACCEPT_ENCODING, HeaderValue::from_str(value).unwrap());
        headers
    }

    #[test]
    fn preferred_encoding_follows_php() {
        assert_eq!(preferred(&HeaderMap::new()), None);
        assert_eq!(preferred(&accept("gzip")), Some("gzip"));
        assert_eq!(preferred(&accept("br, gzip")), Some("gzip"));
        assert_eq!(preferred(&accept("deflate")), Some("deflate"));
        assert_eq!(preferred(&accept("deflate, gzip")), Some("gzip"));
        assert_eq!(preferred(&accept("gzip;q=0.5, deflate")), Some("deflate"));
        assert_eq!(preferred(&accept("gzip;q=0")), None);
        assert_eq!(preferred(&accept("GZIP")), Some("gzip"));
        assert_eq!(preferred(&accept("*")), Some("gzip"));
        assert_eq!(preferred(&accept("*;q=0")), None);
        assert_eq!(preferred(&accept("gzip;q=0, *")), Some("deflate"));
        assert_eq!(preferred(&accept("br, zstd")), None);
        assert_eq!(preferred(&accept("identity")), None);
        assert_eq!(preferred(&accept("gzip;q=x")), None);
    }

    #[test]
    fn setting_defaults_to_enabled_and_reads_the_legacy_name() {
        let env = |pairs: &'static [(&'static str, &'static str)]| {
            move |name: &str| pairs.iter().find(|(key, _)| *key == name).map(|(_, value)| (*value).to_owned())
        };
        assert!(configured(env(&[])));
        assert!(configured(env(&[("DW_COMPRESSION_ENABLED", "true")])));
        assert!(!configured(env(&[("DW_COMPRESSION_ENABLED", "false")])));
        assert!(!configured(env(&[("DW_COMPRESSION_ENABLED", "0")])));
        assert!(!configured(env(&[("DW_COMPRESSION_ENABLED", "")])));
        assert!(configured(env(&[("DW_COMPRESSION_ENABLED", "maybe")])));
        assert!(!configured(env(&[("WORKFLOW_SERVER_COMPRESSION_ENABLED", "off")])));
        assert!(configured(env(&[("DW_COMPRESSION_ENABLED", "on"), ("WORKFLOW_SERVER_COMPRESSION_ENABLED", "off")])));
    }

    fn app() -> Router {
        Router::new()
            .route("/big", get(|| async { axum::Json(serde_json::json!({"blob": "x".repeat(4096)})) }))
            .route("/small", get(|| async { axum::Json(serde_json::json!({"ok": true})) }))
            .route("/text", get(|| async { "y".repeat(4096) }))
            .route(
                "/encoded",
                get(|| async {
                    ([(header::CONTENT_TYPE, "application/json"), (header::CONTENT_ENCODING, "identity")], "z".repeat(4096))
                        .into_response()
                }),
            )
            .route(
                "/streamed",
                get(|| async {
                    let chunks = futures_util::stream::iter(vec![Ok::<_, std::io::Error>("w".repeat(4096))]);
                    ([(header::CONTENT_TYPE, "application/json")], Body::from_stream(chunks)).into_response()
                }),
            )
            .layer(middleware::from_fn(compress))
    }

    async fn get_with(path: &str, accept_encoding: Option<&str>) -> (StatusCode, HeaderMap, Vec<u8>) {
        let mut request = axum::http::Request::builder().uri(path);
        if let Some(value) = accept_encoding {
            request = request.header(header::ACCEPT_ENCODING, value);
        }
        let response = app().oneshot(request.body(Body::empty()).unwrap()).await.unwrap();
        let (parts, body) = response.into_parts();
        (parts.status, parts.headers, axum::body::to_bytes(body, usize::MAX).await.unwrap().to_vec())
    }

    #[tokio::test]
    async fn large_json_answers_are_encoded_and_decode_to_the_same_bytes() {
        let (_, plain_headers, plain) = get_with("/big", None).await;
        assert!(!plain_headers.contains_key(header::CONTENT_ENCODING));
        let (status, headers, gzipped) = get_with("/big", Some("br, gzip")).await;
        assert_eq!(status, StatusCode::OK);
        assert_eq!(headers[header::CONTENT_ENCODING], "gzip");
        assert_eq!(headers[header::VARY], "Accept-Encoding");
        assert!(gzipped.len() < plain.len() / 10);
        assert_eq!(&gzipped[..2], &[0x1f, 0x8b]);
        let crc = u32::from_le_bytes(gzipped[gzipped.len() - 8..gzipped.len() - 4].try_into().unwrap());
        let length = u32::from_le_bytes(gzipped[gzipped.len() - 4..].try_into().unwrap());
        let inflated = miniz_oxide::inflate::decompress_to_vec(&gzipped[10..gzipped.len() - 8]).unwrap();
        assert_eq!(inflated, plain);
        assert_eq!(length as usize, plain.len());
        assert_eq!(crc, crc::Crc::<u32>::new(&crc::CRC_32_ISO_HDLC).checksum(&plain));

        let (_, headers, deflated) = get_with("/big", Some("deflate")).await;
        assert_eq!(headers[header::CONTENT_ENCODING], "deflate");
        assert_eq!(miniz_oxide::inflate::decompress_to_vec(&deflated).unwrap(), plain);
    }

    #[tokio::test]
    async fn small_non_json_encoded_and_streamed_answers_pass_through() {
        for path in ["/small", "/text", "/encoded", "/streamed"] {
            let (_, plain_headers, plain) = get_with(path, None).await;
            let (_, headers, body) = get_with(path, Some("gzip")).await;
            assert_eq!(body, plain, "{path}");
            assert_eq!(headers.get(header::CONTENT_ENCODING), plain_headers.get(header::CONTENT_ENCODING), "{path}");
            assert!(!headers.contains_key(header::VARY), "{path}");
        }
    }
}
