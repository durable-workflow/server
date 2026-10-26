//! Service catalog reads, as published Server 2.11 serves them
//! (`ServiceCatalogReadController`): endpoints, services, operations and
//! service calls, each listed by page or read by identity.
//!
//! Endpoints, services and operations are the namespace's rows
//! (`service_endpoints`), in PHP's orders: by name, then id. A page counts
//! the matches and reads only its window. An endpoint names up to 100 of its
//! services and operations, a service up to 100 operations, each with a flag
//! for more; an operation names its endpoint and service when they are in the
//! namespace.
//!
//! Native records no service calls yet, and takeover refuses a PHP database
//! that holds any, so a calls list is PHP's empty page and a call is PHP's
//! not-found answer, after PHP's validation of the filters.
use super::laravel_rules::{self, Rule};
use super::laravel_validation;
use super::service_endpoints::{endpoint_item, operation_item, service_item};
use super::{Result, RuntimeError};
use axum::http::StatusCode;
use serde_json::{Map, Value, json};

const DEFAULT_PAGE_SIZE: i64 = 50;
/// `ServiceCatalogReadContract::RELATED_LIMIT`.
const RELATED_LIMIT: i64 = 100;
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

/// The validated paging: the page, its size and the rows before it.
fn paging(filters: &Value) -> (i64, i64, i64) {
    let page = whole(&filters["page"], 1);
    let per_page = whole(&filters["per_page"], DEFAULT_PAGE_SIZE);
    (page, per_page, page.saturating_sub(1).saturating_mul(per_page))
}

/// Laravel's `LengthAwarePaginator` fields around a page of rows.
fn page_body(data: Vec<Value>, filters: &Value, total: i64, namespace: &str) -> Value {
    let (page, per_page, _) = paging(filters);
    json!({"data": data, "current_page": page, "per_page": per_page, "total": total,
        "last_page": ((total + per_page - 1) / per_page).max(1), "namespace": namespace})
}

/// A page of service calls: always empty in native.
fn calls_page(filters: &Value, namespace: &str) -> Value {
    let mut page = page_body(Vec::new(), filters, 0, namespace);
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
    page
}

/// PHP's 404 for an identity the namespace does not hold.
fn object_not_found() -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::NOT_FOUND,
        response: json!({"message": "Catalog object not found.", "reason": "catalog_object_not_found"}),
    }
}

/// A filter's value, or null when the query leaves it out.
fn identity<'a>(filters: &'a Value, key: &str) -> Option<&'a str> {
    filters[key].as_str()
}

store_methods! {
    /// `endpointsIndex`, `servicesIndex`, `operationsIndex` and `callsIndex`.
    pub(crate) async fn service_catalog_list(&self, query: &[(String, String)], catalog: Catalog) -> Result<Value> {
        let filters = filters(query, catalog, false)?;
        let namespace = self.namespace.as_ref();
        let (_, limit, offset) = paging(&filters);
        let endpoint = identity(&filters, "endpoint_id");
        let service = identity(&filters, "service_id");
        let mut tx = self.begin_read().await?;
        // Each optional filter binds twice: a null keeps every row.
        let (count, rows) = match catalog {
            Catalog::Calls => return Ok(calls_page(&filters, namespace)),
            Catalog::Endpoints => (
                Self::scalar("SELECT COUNT(*) FROM workflow_service_endpoints WHERE namespace=$1")
                    .bind(namespace).fetch_one(&mut *tx).await?,
                Self::query("SELECT * FROM workflow_service_endpoints WHERE namespace=$1 ORDER BY endpoint_name,id LIMIT $2 OFFSET $3")
                    .bind(namespace).bind(limit).bind(offset).fetch_all(&mut *tx).await?,
            ),
            Catalog::Services => (
                Self::scalar("SELECT COUNT(*) FROM workflow_services WHERE namespace=$1 AND ($2 IS NULL OR workflow_service_endpoint_id=$3)")
                    .bind(namespace).bind(endpoint).bind(endpoint).fetch_one(&mut *tx).await?,
                Self::query("SELECT * FROM workflow_services WHERE namespace=$1 AND ($2 IS NULL OR workflow_service_endpoint_id=$3) ORDER BY service_name,id LIMIT $4 OFFSET $5")
                    .bind(namespace).bind(endpoint).bind(endpoint).bind(limit).bind(offset).fetch_all(&mut *tx).await?,
            ),
            Catalog::Operations => (
                Self::scalar("SELECT COUNT(*) FROM workflow_service_operations WHERE namespace=$1 AND ($2 IS NULL OR workflow_service_endpoint_id=$3) AND ($4 IS NULL OR workflow_service_id=$5)")
                    .bind(namespace).bind(endpoint).bind(endpoint).bind(service).bind(service).fetch_one(&mut *tx).await?,
                Self::query("SELECT * FROM workflow_service_operations WHERE namespace=$1 AND ($2 IS NULL OR workflow_service_endpoint_id=$3) AND ($4 IS NULL OR workflow_service_id=$5) ORDER BY operation_name,id LIMIT $6 OFFSET $7")
                    .bind(namespace).bind(endpoint).bind(endpoint).bind(service).bind(service).bind(limit).bind(offset)
                    .fetch_all(&mut *tx).await?,
            ),
        };
        tx.commit().await?;
        // Laravel reads no rows when the count is zero.
        let rows = if count == 0 { Vec::new() } else { rows };
        let data = rows.iter().map(|row| match catalog {
            Catalog::Endpoints => Self::endpoint_row(row).map(|endpoint| endpoint_item(&endpoint)),
            Catalog::Services => Self::service_row(row).map(|service| service_item(&service)),
            _ => Self::operation_row(row).map(|operation| operation_item(&operation)),
        }).collect::<Result<Vec<_>>>()?;
        Ok(page_body(data, &filters, count, namespace))
    }

    /// `endpointShow`, `serviceShow`, `operationShow` and `callShow`.
    pub(crate) async fn service_catalog_show(&self, query: &[(String, String)], catalog: Catalog, id: &str) -> Result<Value> {
        filters(query, catalog, true)?;
        let namespace = self.namespace.as_ref();
        let mut tx = self.begin_read().await?;
        let body = match catalog {
            Catalog::Calls => None,
            Catalog::Endpoints => match self.catalog_endpoint_by_id(&mut tx, id).await? {
                None => None,
                Some(endpoint) => {
                    let (services, more_services) = self.related(&mut tx,
                        "SELECT * FROM workflow_services WHERE namespace=$1 AND workflow_service_endpoint_id=$2 ORDER BY service_name,id LIMIT $3", id).await?;
                    let (operations, more_operations) = self.related(&mut tx,
                        "SELECT * FROM workflow_service_operations WHERE namespace=$1 AND workflow_service_endpoint_id=$2 ORDER BY operation_name,id LIMIT $3", id).await?;
                    let mut body = endpoint_item(&endpoint);
                    body["services"] = services.iter().map(Self::service_row).map(|service| service.map(|service| service_item(&service)))
                        .collect::<Result<Vec<_>>>()?.into();
                    body["operations"] = operations.iter().map(Self::operation_row)
                        .map(|operation| operation.map(|operation| operation_item(&operation))).collect::<Result<Vec<_>>>()?.into();
                    body["related_has_more"] = json!({"services": more_services, "operations": more_operations});
                    Some(body)
                }
            },
            Catalog::Services => match Self::query("SELECT * FROM workflow_services WHERE id=$1 AND namespace=$2 LIMIT 1")
                .bind(id).bind(namespace).fetch_optional(&mut *tx).await?
            {
                None => None,
                Some(row) => {
                    let service = Self::service_row(&row)?;
                    let endpoint = self.catalog_endpoint_by_id(&mut tx, &service.endpoint_id).await?;
                    let (operations, more) = self.related(&mut tx,
                        "SELECT * FROM workflow_service_operations WHERE namespace=$1 AND workflow_service_id=$2 ORDER BY operation_name,id LIMIT $3", id).await?;
                    let mut body = service_item(&service);
                    body["endpoint"] = endpoint.as_ref().map_or(Value::Null, endpoint_item);
                    body["operations"] = operations.iter().map(Self::operation_row)
                        .map(|operation| operation.map(|operation| operation_item(&operation))).collect::<Result<Vec<_>>>()?.into();
                    body["related_has_more"] = json!({"operations": more});
                    Some(body)
                }
            },
            Catalog::Operations => match Self::query("SELECT * FROM workflow_service_operations WHERE id=$1 AND namespace=$2 LIMIT 1")
                .bind(id).bind(namespace).fetch_optional(&mut *tx).await?
            {
                None => None,
                Some(row) => {
                    // `ServiceOperationView::detail`: the endpoint and service
                    // when the namespace holds them.
                    let operation = Self::operation_row(&row)?;
                    let endpoint = self.catalog_endpoint_by_id(&mut tx, &operation.endpoint_id).await?;
                    let service = Self::query("SELECT * FROM workflow_services WHERE id=$1 AND namespace=$2 LIMIT 1")
                        .bind(operation.service_id.as_str()).bind(namespace).fetch_optional(&mut *tx).await?
                        .map(|row| Self::service_row(&row)).transpose()?;
                    let mut body = operation_item(&operation);
                    body["endpoint"] = endpoint.as_ref().map_or(Value::Null, endpoint_item);
                    body["service"] = service.as_ref().map_or(Value::Null, service_item);
                    Some(body)
                }
            },
        };
        tx.commit().await?;
        body.ok_or_else(object_not_found)
    }

    /// `ServiceCatalog::findEndpoint`: an endpoint by id in the namespace.
    async fn catalog_endpoint_by_id(&self, connection: &mut DB::Connection, id: &str) -> Result<Option<super::service_endpoints::Endpoint>> {
        Self::query("SELECT * FROM workflow_service_endpoints WHERE id=$1 AND namespace=$2 LIMIT 1")
            .bind(id).bind(self.namespace.as_ref()).fetch_optional(&mut *connection).await?
            .map(|row| Self::endpoint_row(&row)).transpose()
    }

    /// `related`: up to `RELATED_LIMIT` rows and whether more exist.
    async fn related(&self, connection: &mut DB::Connection, sql: &'static str, id: &str) -> Result<(Vec<DB::Row>, bool)> {
        let mut rows = Self::query(sql).bind(self.namespace.as_ref()).bind(id).bind(RELATED_LIMIT + 1)
            .fetch_all(&mut *connection).await?;
        let more = rows.len() > RELATED_LIMIT as usize;
        rows.truncate(RELATED_LIMIT as usize);
        Ok((rows, more))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn query(pairs: &[(&str, &str)]) -> Vec<(String, String)> {
        pairs.iter().map(|(key, value)| ((*key).to_owned(), (*value).to_owned())).collect()
    }

    fn page(pairs: &[(&str, &str)], catalog: Catalog, total: i64) -> Result<Value> {
        let filters = filters(&query(pairs), catalog, false)?;
        Ok(if catalog == Catalog::Calls { calls_page(&filters, "second") } else { page_body(Vec::new(), &filters, total, "default") })
    }

    #[test]
    fn pages_report_the_requested_paging_and_the_count() {
        assert_eq!(page(&[("page", "2"), ("per_page", "2")], Catalog::Endpoints, 0).unwrap(),
            json!({"data":[],"current_page":2,"per_page":2,"total":0,"last_page":1,"namespace":"default"}));
        assert_eq!(page(&[("per_page", "2")], Catalog::Services, 5).unwrap()["last_page"], 3);
        assert_eq!(page(&[], Catalog::Operations, 50).unwrap()["last_page"], 1);
        assert_eq!(paging(&json!({"page": "3", "per_page": "20"})), (3, 20, 40));
        let calls = page(&[("bucket", "open")], Catalog::Calls, 0).unwrap();
        assert_eq!([&calls["scope"], &calls["bucket"], &calls["status_bucket"], &calls["outcome"], &calls["namespace"]],
            [&json!("relevant"), &json!("open"), &json!("open"), &Value::Null, &json!("second")]);
        assert_eq!(calls["outcome_buckets"]["policy"].as_array().unwrap().len(), 5);
    }

    #[test]
    fn filters_fail_like_laravel() {
        let fail = |pairs: &[(&str, &str)], catalog: Catalog, detail: bool| match filters(&query(pairs), catalog, detail) {
            Err(RuntimeError::Protocol { status, response }) => json!([status.as_u16(), response]),
            other => panic!("expected a refusal, got {other:?}"),
        };
        assert_eq!(fail(&[("per_page", "0")], Catalog::Endpoints, false)[1]["message"],
            "The per page field must be between 1 and 100.");
        assert_eq!(fail(&[("page", "x")], Catalog::Endpoints, false)[1]["message"], "The page field must be an integer.");
        let unknown = fail(&[("bogus", "1"), ("other", "2")], Catalog::Endpoints, false);
        assert_eq!(unknown[1]["message"], "Unsupported catalog filter. (and 1 more error)");
        assert_eq!(fail(&[("endpoint_id", "x")], Catalog::Endpoints, false)[1]["errors"],
            json!({"endpoint_id":["Unsupported catalog filter."]}));
        assert_eq!(fail(&[("endpoint_id", "")], Catalog::Services, false)[1]["message"], "The endpoint id field is required.");
        assert_eq!(fail(&[("bucket", "open"), ("status_bucket", "failed")], Catalog::Calls, false)[1],
            json!({"message":"Must match status_bucket when both are supplied.","reason":"validation_failed",
                "errors":{"bucket":["Must match status_bucket when both are supplied."]},
                "validation_errors":{"bucket":["Must match status_bucket when both are supplied."]}}));
        assert_eq!(fail(&[("page", "2")], Catalog::Endpoints, true)[1]["errors"], json!({"page":["Unsupported catalog filter."]}));
    }

    #[test]
    fn identities_are_not_found_as_php_answers() {
        match object_not_found() {
            RuntimeError::Protocol { status, response } => assert_eq!(json!([status.as_u16(), response]),
                json!([404, {"message":"Catalog object not found.","reason":"catalog_object_not_found"}])),
            other => panic!("{other:?}"),
        }
    }
}
