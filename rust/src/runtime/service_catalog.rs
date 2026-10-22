//! Service catalog reads, as published Server 2.11 serves them
//! (`ServiceCatalogReadController`): endpoints, services, operations and
//! service calls, each listed by page or read by identity.
//!
//! Native never holds catalog rows. Takeover refuses a PHP database with
//! services, endpoints, operations or service calls, and native has no path
//! that writes them. Every list is therefore PHP's empty page and every
//! identity PHP's not-found answer, after PHP's validation of the filters.
use super::laravel_rules::{self, Rule};
use super::laravel_validation;
use super::{Result, RuntimeError};
use axum::http::StatusCode;
use serde_json::{Map, Value, json};

const DEFAULT_PAGE_SIZE: i64 = 50;
const SCOPES: &[&str] = &["relevant", "owned", "caller", "target"];
const STATUSES: &[&str] = &["pending", "accepted", "started", "completed", "failed", "cancelled"];
const OUTCOMES: &[&str] = &[
    "accepted", "completed", "cancelled", "timed_out", "rejected_not_found", "rejected_forbidden",
    "rejected_throttled", "rejected_concurrency_limited", "rejected_circuit_open", "degraded", "handler_failed",
];
const STATUS_BUCKETS: &[&str] = &["open", "completed", "failed", "cancelled"];
const OUTCOME_BUCKETS: &[&str] = &["open", "completed", "failed", "cancelled", "policy"];

const NAMESPACE: &[Rule] = &[Rule::Sometimes, Rule::String, Rule::Max(191)];
const PAGE: &[Rule] = &[Rule::Sometimes, Rule::Integer, Rule::Min(1)];
const PER_PAGE: &[Rule] = &[Rule::Sometimes, Rule::Integer, Rule::Between(1, 100)];
const IDENTITY: &[Rule] = &[Rule::Sometimes, Rule::Required, Rule::String, Rule::Max(191)];
const SCOPE: &[Rule] = &[Rule::Sometimes, Rule::String, Rule::In(SCOPES)];
const STATUS: &[Rule] = &[Rule::Sometimes, Rule::String, Rule::In(STATUSES)];
const STATUS_BUCKET: &[Rule] = &[Rule::Sometimes, Rule::String, Rule::In(STATUS_BUCKETS)];
const OUTCOME: &[Rule] = &[Rule::Sometimes, Rule::String, Rule::In(OUTCOMES)];
const OUTCOME_BUCKET: &[Rule] = &[Rule::Sometimes, Rule::String, Rule::In(OUTCOME_BUCKETS)];

/// The catalog a route reads.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum Catalog {
    Endpoints,
    Services,
    Operations,
    Calls,
}

impl Catalog {
    pub(crate) fn from_path(segment: &str) -> Option<Self> {
        match segment {
            "endpoints" => Some(Self::Endpoints),
            "services" => Some(Self::Services),
            "operations" => Some(Self::Operations),
            "calls" => Some(Self::Calls),
            _ => None,
        }
    }

    /// The identity filters a list accepts, in PHP's order.
    fn identities(self) -> &'static [&'static str] {
        match self {
            Self::Endpoints => &[],
            Self::Services => &["endpoint_id"],
            Self::Operations => &["endpoint_id", "service_id"],
            Self::Calls => &["endpoint_id", "service_id", "operation_id"],
        }
    }
}

/// PHP's `filters`: unknown keys first, then each rule in its order, then
/// the bucket agreement. Returns the normalized query.
fn filters(query: &[(String, String)], catalog: Catalog, detail: bool) -> Result<Value> {
    let mut rules: Vec<(&'static str, &'static [Rule])> = vec![("namespace", NAMESPACE)];
    if !detail {
        rules.push(("page", PAGE));
        rules.push(("per_page", PER_PAGE));
        rules.extend(catalog.identities().iter().map(|id| (*id, IDENTITY)));
        if catalog == Catalog::Calls {
            rules.extend([("scope", SCOPE), ("status", STATUS), ("status_bucket", STATUS_BUCKET),
                ("bucket", STATUS_BUCKET), ("outcome", OUTCOME), ("outcome_bucket", OUTCOME_BUCKET)]);
        }
    }
    let mut unknown: Vec<(String, Vec<String>)> = Vec::new();
    for (key, _) in query {
        if !rules.iter().any(|(name, _)| name == key) && !unknown.iter().any(|(seen, _)| seen == key) {
            unknown.push((key.clone(), vec!["Unsupported catalog filter.".into()]));
        }
    }
    if let Some(error) = laravel_validation::failure_messages(unknown) {
        return Err(error);
    }
    // A repeated key keeps its last value, as PHP's query parser does.
    let mut body = Map::new();
    for (key, value) in query {
        body.insert(key.clone(), json!(value));
    }
    let body = laravel_rules::normalize(&Value::Object(body));
    laravel_rules::validate(&body, &rules)?;
    if let (Some(bucket), Some(status_bucket)) = (body["bucket"].as_str(), body["status_bucket"].as_str())
        && bucket != status_bucket
    {
        return Err(laravel_validation::failure(vec![("bucket", vec!["Must match status_bucket when both are supplied."])])
            .expect("a message"));
    }
    Ok(body)
}

fn whole(value: &Value, default: i64) -> i64 {
    value.as_str().and_then(|text| text.parse().ok()).unwrap_or(default)
}

/// A page of the catalog: always empty in native.
pub(crate) fn list(query: &[(String, String)], catalog: Catalog, namespace: &str) -> Result<Value> {
    let filters = filters(query, catalog, false)?;
    let mut page = json!({"data": [], "current_page": whole(&filters["page"], 1),
        "per_page": whole(&filters["per_page"], DEFAULT_PAGE_SIZE), "total": 0, "last_page": 1, "namespace": namespace});
    if catalog == Catalog::Calls {
        let status_bucket = match &filters["status_bucket"] {
            Value::Null => filters["bucket"].clone(),
            bucket => bucket.clone(),
        };
        let buckets = |names: &[&str], members: &[&[&str]]| -> Value {
            Value::Object(names.iter().zip(members).map(|(name, values)| ((*name).to_owned(), json!(values))).collect())
        };
        let extra = json!({
            "scope": if filters["scope"].is_null() { json!("relevant") } else { filters["scope"].clone() },
            "bucket": status_bucket, "status_bucket": status_bucket,
            "outcome": filters["outcome"], "outcome_bucket": filters["outcome_bucket"],
            "statuses": STATUSES, "outcomes": OUTCOMES,
            "status_buckets": buckets(STATUS_BUCKETS, &[&["pending", "accepted", "started"], &["completed"], &["failed"], &["cancelled"]]),
            "outcome_buckets": buckets(OUTCOME_BUCKETS, &[&["accepted"], &["completed", "degraded"], &["timed_out", "handler_failed"],
                &["cancelled"], &["rejected_not_found", "rejected_forbidden", "rejected_throttled",
                    "rejected_concurrency_limited", "rejected_circuit_open"]]),
        });
        page.as_object_mut().expect("page").extend(extra.as_object().expect("extra").clone());
    }
    Ok(page)
}

/// One catalog object by identity: never present in native.
pub(crate) fn show(query: &[(String, String)], catalog: Catalog) -> Result<Value> {
    filters(query, catalog, true)?;
    Err(RuntimeError::Protocol {
        status: StatusCode::NOT_FOUND,
        response: json!({"message": "Catalog object not found.", "reason": "catalog_object_not_found"}),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    fn query(pairs: &[(&str, &str)]) -> Vec<(String, String)> {
        pairs.iter().map(|(key, value)| ((*key).to_owned(), (*value).to_owned())).collect()
    }

    fn refusal(result: Result<Value>) -> Value {
        match result {
            Err(RuntimeError::Protocol { status, response }) => json!([status.as_u16(), response]),
            other => panic!("expected a refusal, got {other:?}"),
        }
    }

    #[test]
    fn lists_are_empty_pages_with_the_requested_paging() {
        assert_eq!(list(&query(&[("page", "2"), ("per_page", "2")]), Catalog::Endpoints, "default").unwrap(),
            json!({"data":[],"current_page":2,"per_page":2,"total":0,"last_page":1,"namespace":"default"}));
        let calls = list(&query(&[("bucket", "open")]), Catalog::Calls, "second").unwrap();
        assert_eq!([&calls["scope"], &calls["bucket"], &calls["status_bucket"], &calls["outcome"], &calls["namespace"]],
            [&json!("relevant"), &json!("open"), &json!("open"), &Value::Null, &json!("second")]);
        assert_eq!(calls["outcome_buckets"]["policy"].as_array().unwrap().len(), 5);
    }

    #[test]
    fn filters_fail_like_laravel() {
        assert_eq!(refusal(list(&query(&[("per_page", "0")]), Catalog::Endpoints, "default"))[1]["message"],
            "The per page field must be between 1 and 100.");
        assert_eq!(refusal(list(&query(&[("page", "x")]), Catalog::Endpoints, "default"))[1]["message"],
            "The page field must be an integer.");
        let unknown = refusal(list(&query(&[("bogus", "1"), ("other", "2")]), Catalog::Endpoints, "default"));
        assert_eq!(unknown[1]["message"], "Unsupported catalog filter. (and 1 more error)");
        assert_eq!(refusal(list(&query(&[("endpoint_id", "x")]), Catalog::Endpoints, "default"))[1]["errors"],
            json!({"endpoint_id":["Unsupported catalog filter."]}));
        assert_eq!(refusal(list(&query(&[("endpoint_id", "")]), Catalog::Services, "default"))[1]["message"],
            "The endpoint id field is required.");
        assert_eq!(refusal(list(&query(&[("bucket", "open"), ("status_bucket", "failed")]), Catalog::Calls, "default"))[1],
            json!({"message":"Must match status_bucket when both are supplied.","reason":"validation_failed",
                "errors":{"bucket":["Must match status_bucket when both are supplied."]},
                "validation_errors":{"bucket":["Must match status_bucket when both are supplied."]}}));
        assert_eq!(refusal(show(&query(&[("page", "2")]), Catalog::Endpoints))[1]["errors"],
            json!({"page":["Unsupported catalog filter."]}));
    }

    #[test]
    fn identities_are_not_found() {
        assert_eq!(refusal(show(&query(&[("namespace", "default")]), Catalog::Calls)),
            json!([404, {"message":"Catalog object not found.","reason":"catalog_object_not_found"}]));
    }
}
