//! Process-local HTTP timing from routed request entry to the response.
use axum::{
    Extension,
    extract::{MatchedPath, Request},
    http::Method,
    middleware::Next,
    response::Response,
};
use serde_json::json;
use std::{
    collections::BTreeMap,
    fmt::Write as _,
    io::Write as _,
    sync::{Arc, Mutex},
    time::Instant,
};

const FAMILY: &str = "dw_http_request_duration_seconds";
const MAX_SERIES: usize = 512;
const BUCKETS: [f64; 12] = [
    0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 5.0, 15.0, 60.0,
];

#[derive(Clone, Copy, Eq, Ord, PartialEq, PartialOrd)]
enum Kind {
    Request,
    WorkerPoll,
}

impl Kind {
    fn label(self) -> &'static str {
        match self {
            Self::Request => "request",
            Self::WorkerPoll => "worker_poll",
        }
    }
}

#[derive(Clone, Eq, Ord, PartialEq, PartialOrd)]
struct Series {
    route: String,
    status: u16,
    kind: Kind,
}

#[derive(Clone, Default)]
struct Histogram {
    // Store disjoint buckets and calculate the cumulative counts at scrape time.
    buckets: [u64; BUCKETS.len() + 1],
    sum: f64,
}

#[derive(Clone)]
pub(super) struct HttpRequestMetrics {
    series: Arc<Mutex<BTreeMap<Series, Histogram>>>,
    access_log: bool,
}

impl HttpRequestMetrics {
    pub(super) fn new(access_log: bool) -> Self {
        Self {
            series: Arc::default(),
            access_log,
        }
    }

    fn record(&self, route: &str, status: u16, kind: Kind, seconds: f64) {
        let mut series = self
            .series
            .lock()
            .unwrap_or_else(|error| error.into_inner());
        let mut key = Series {
            route: route.to_owned(),
            status,
            kind,
        };
        if series.len() >= MAX_SERIES && !series.contains_key(&key) {
            // Preserve counts and poll separation even after reaching the cap.
            key = Series {
                route: "__overflow__".into(),
                status: 0,
                kind,
            };
        }
        let histogram = series.entry(key).or_default();
        let bucket = BUCKETS.partition_point(|bound| *bound < seconds);
        histogram.buckets[bucket] = histogram.buckets[bucket].saturating_add(1);
        histogram.sum += seconds;
    }

    pub(super) fn append_exposition(&self, out: &mut String) {
        let series = self
            .series
            .lock()
            .unwrap_or_else(|error| error.into_inner())
            .clone();
        let _ = writeln!(
            out,
            "# HELP {FAMILY} Server time from HTTP routing until the response is ready, in seconds."
        );
        let _ = writeln!(out, "# TYPE {FAMILY} histogram");
        for (key, histogram) in series {
            let route = key
                .route
                .replace('\\', "\\\\")
                .replace('"', "\\\"")
                .replace('\n', "\\n");
            let status = if key.status == 0 {
                "other".to_owned()
            } else {
                key.status.to_string()
            };
            let labels = format!(
                "route=\"{route}\",status=\"{status}\",kind=\"{}\"",
                key.kind.label()
            );
            let mut count = 0_u64;
            for (bound, bucket) in BUCKETS.iter().zip(histogram.buckets) {
                count = count.saturating_add(bucket);
                let _ = writeln!(out, "{FAMILY}_bucket{{{labels},le=\"{bound}\"}} {count}");
            }
            count = count.saturating_add(histogram.buckets[BUCKETS.len()]);
            let _ = writeln!(out, "{FAMILY}_bucket{{{labels},le=\"+Inf\"}} {count}");
            let _ = writeln!(out, "{FAMILY}_sum{{{labels}}} {}", histogram.sum);
            let _ = writeln!(out, "{FAMILY}_count{{{labels}}} {count}");
        }
    }
}

pub(super) async fn observe(
    Extension(metrics): Extension<HttpRequestMetrics>,
    request: Request,
    next: Next,
) -> Response {
    let started = Instant::now();
    let matched = request.extensions().get::<MatchedPath>().cloned();
    let route = matched
        .as_ref()
        .map_or("__unmatched__", MatchedPath::as_str);
    let kind = if request.method() == Method::POST
        && matches!(
            route,
            "/api/worker/workflow-tasks/poll"
                | "/api/worker/activity-tasks/poll"
                | "/api/worker/query-tasks/poll"
                | "/api/worker/update-validation-tasks/poll"
        ) {
        Kind::WorkerPoll
    } else {
        Kind::Request
    };
    let method = match request.method().as_str() {
        "GET" => "GET",
        "HEAD" => "HEAD",
        "POST" => "POST",
        "PUT" => "PUT",
        "PATCH" => "PATCH",
        "DELETE" => "DELETE",
        "OPTIONS" => "OPTIONS",
        _ => "OTHER",
    };
    let response = next.run(request).await;
    let seconds = started.elapsed().as_secs_f64();
    let status = response.status().as_u16();
    metrics.record(route, status, kind, seconds);
    if metrics.access_log {
        let entry = json!({
            "event": "http_access", "route": route, "method": method, "status": status,
            "kind": kind.label(), "duration_seconds": seconds,
        });
        let _ = writeln!(std::io::stderr().lock(), "{entry}");
    }
    response
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn bounds_series_and_keeps_existing_counts_and_poll_overflow() {
        let metrics = HttpRequestMetrics::new(false);
        for index in 0..MAX_SERIES {
            metrics.record(&format!("/test/{index}"), 200, Kind::Request, 0.001);
        }
        metrics.record("/test/0", 200, Kind::Request, 0.006);
        metrics.record("/ignored", 404, Kind::Request, 0.005);
        metrics.record("/ignored-poll", 504, Kind::WorkerPoll, 61.0);

        let mut text = String::new();
        metrics.append_exposition(&mut text);
        assert_eq!(
            text.lines()
                .filter(|line| line.starts_with("dw_http_request_duration_seconds_count"))
                .count(),
            MAX_SERIES + 2
        );
        assert!(text.contains(
            "_bucket{route=\"/test/0\",status=\"200\",kind=\"request\",le=\"0.001\"} 1\n"
        ));
        assert!(text.contains(
            "_bucket{route=\"/test/0\",status=\"200\",kind=\"request\",le=\"+Inf\"} 2\n"
        ));
        assert!(
            text.contains("_count{route=\"__overflow__\",status=\"other\",kind=\"request\"} 1\n")
        );
        assert!(text.contains(
            "_bucket{route=\"__overflow__\",status=\"other\",kind=\"worker_poll\",le=\"60\"} 0\n"
        ));
        assert!(text.contains(
            "_bucket{route=\"__overflow__\",status=\"other\",kind=\"worker_poll\",le=\"+Inf\"} 1\n"
        ));
        assert!(!text.contains("/ignored"));
    }
}
