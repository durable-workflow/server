//! Nexus service catalog administration, as published Server 2.11.1 serves it
//! (`ServiceCatalogController`'s endpoint, service and operation routes), and
//! the catalog rows the read surface (`service_catalog`) renders.
//!
//! An admin registers a namespace's service endpoints, the services under an
//! endpoint and the operations under a service. Each is addressed by name,
//! lowercased as PHP's `strtolower` does (ASCII only), in the path and in the
//! body. The database's own comparison then finds it, as PHP's query does.
//!
//! - A create looks for the name first and answers 409 with the existing id.
//!   Otherwise it stores a lowercase ULID (`HasUlids`) and answers 201.
//! - An update writes only when a validated field changes, as Eloquent's
//!   dirty check does, so an unchanged request keeps `updated_at`.
//! - A delete refuses an endpoint that still has services, operations or
//!   service calls, a service with operations or calls, and an operation with
//!   calls, checked in that order.
//! - Service and operation lists come in creation order: PHP's relations
//!   order by `created_at` and `id` before the controller's name order.
//!   Endpoint lists are by name.
//! - Answers render JSON columns as PHP's array cast reads them: an empty
//!   object is `[]` and an object keyed 0..n is a list.
//!
//! Every write takes native's serialized write transaction. PHP checks a name
//! and then inserts without a lock, so two concurrent creates of one name can
//! end in its unique-index error (500), and a delete can race a child's
//! create. Native answers the second create 409 and orders the delete.
//!
//! Differences:
//! - Native JSON documents don't keep object key order. An update that only
//!   reorders a document's keys changes nothing in native, where PHP's strict
//!   comparison writes it and moves `updated_at`. A stored object whose
//!   integer keys are out of order reads back as a list.
//! - Laravel also reads input from the query string. Native reads a ruled
//!   field from it when the body lacks that field, by exact name only
//!   (`metadata[a]=1` style keys are not read).
use super::laravel_rules::{self, Rule};
use super::{Result, RuntimeError};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Map, Value, json};

/// `ServiceCatalogController::NAME_PATTERN`: `/^[a-zA-Z0-9._-]+$/`. Laravel's
/// TrimStrings has removed the trailing newline PHP's `$` would allow.
fn catalog_name(text: &str) -> bool {
    !text.is_empty() && text.bytes().all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'_' | b'-'))
}

const OPERATION_MODES: &[&str] = &["sync", "async"];
const HANDLER_BINDING_KINDS: &[&str] =
    &["start_workflow", "signal_workflow", "update_workflow", "query_workflow", "activity_execution", "invocable_http"];

/// `catalogNameRules(191)`. Operation names use the same 191: PHP takes the
/// smaller of it and DW_MAX_OPERATION_NAME_LENGTH, which native runs only at
/// its default of 256.
const NAME: &[Rule] = &[Rule::Required, Rule::String, Rule::Max(191), Rule::Regex(catalog_name)];
const DESCRIPTION: &[Rule] = &[Rule::Nullable, Rule::String, Rule::Max(1000)];
const DOCUMENT: &[Rule] = &[Rule::Nullable, Rule::Array];

const ENDPOINT_STORE: &laravel_rules::Rules = &[("endpoint_name", NAME), ("description", DESCRIPTION), ("metadata", DOCUMENT)];
const SERVICE_STORE: &laravel_rules::Rules = &[("service_name", NAME), ("description", DESCRIPTION), ("metadata", DOCUMENT)];
const DESCRIBE: &laravel_rules::Rules = &[("description", DESCRIPTION), ("metadata", DOCUMENT)];

/// The operation's JSON documents, in PHP's rule order.
const DOCUMENTS: [&str; 7] =
    ["handler_binding", "deadline_policy", "idempotency_policy", "cancellation_policy", "retry_policy", "boundary_policy", "metadata"];

const MODE: &[Rule] = &[Rule::Required, Rule::String, Rule::In(OPERATION_MODES)];
const MODE_CHANGE: &[Rule] = &[Rule::Sometimes, Rule::String, Rule::In(OPERATION_MODES)];
const KIND: &[Rule] = &[Rule::Required, Rule::String, Rule::In(HANDLER_BINDING_KINDS)];
const KIND_CHANGE: &[Rule] = &[Rule::Sometimes, Rule::String, Rule::In(HANDLER_BINDING_KINDS)];
const TARGET: &[Rule] = &[Rule::Nullable, Rule::String, Rule::Max(191)];
const RENAME: &[Rule] = &[Rule::Sometimes, Rule::Prohibited];

/// `validateOperationPayload`'s rules: a create requires the mode, kind and
/// name; an update may change the mode and kind and prohibits a name.
fn operation_rules(partial: bool) -> Vec<(&'static str, &'static [Rule])> {
    let mut rules = vec![
        ("description", DESCRIPTION),
        ("operation_mode", if partial { MODE_CHANGE } else { MODE }),
        ("handler_binding_kind", if partial { KIND_CHANGE } else { KIND }),
        ("handler_target_reference", TARGET),
    ];
    rules.extend(DOCUMENTS.iter().map(|field| (*field, DOCUMENT)));
    rules.push(("operation_name", if partial { RENAME } else { NAME }));
    rules
}

/// `normalizeCatalogName`: PHP 8's `strtolower`.
fn lower(name: &str) -> String {
    name.to_ascii_lowercase()
}

/// The request input `$request->validate` reads: the JSON body (a body that
/// is not an object holds no named field), then each ruled field the body
/// lacks from the query string, normalized by TrimStrings and
/// ConvertEmptyStringsToNull.
pub(crate) fn input(body: Value, query: &[(String, String)], rules: &laravel_rules::Rules) -> Value {
    let mut fields = match body {
        Value::Object(fields) => fields,
        _ => Map::new(),
    };
    for (field, _) in rules {
        if !fields.contains_key(*field)
            // A repeated parameter's last value wins.
            && let Some((_, value)) = query.iter().rev().find(|(key, _)| key == field)
        {
            fields.insert((*field).to_owned(), json!(value));
        }
    }
    laravel_rules::normalize(&Value::Object(fields))
}

fn validated(input: &Value, rules: &laravel_rules::Rules) -> Result<Map<String, Value>> {
    laravel_rules::validate(input, rules)?;
    match laravel_rules::validated(input, rules) {
        Value::Object(fields) => Ok(fields),
        _ => Ok(Map::new()),
    }
}

/// One `ValidationException::withMessages` failure.
fn invalid(field: &str, message: &str) -> RuntimeError {
    laravel_rules::failure(vec![(field.to_owned(), vec![message.to_owned()])]).expect("a message")
}

/// `ServiceCallDeadlinePolicy::resolve`'s refusal of a stored policy, if any.
/// A null policy is no deadline.
pub(crate) fn deadline_policy_error(policy: &Value) -> Option<&'static str> {
    let fields = match policy {
        Value::Null => return None,
        Value::Object(fields) => fields,
        _ => return Some("Service deadline_policy must contain exactly timeout_seconds or deadline_at."),
    };
    let deadline = match fields.iter().next() {
        Some((key, seconds)) if fields.len() == 1 && key == "timeout_seconds" => {
            // An integer from 1 to 2^31-1 seconds after acceptance stays
            // within the year limits below.
            return match seconds.as_i64() {
                Some(1..=2_147_483_647) => None,
                _ => Some("Service timeout_seconds must be an integer from 1 to 2147483647."),
            };
        }
        Some((key, value)) if fields.len() == 1 && key == "deadline_at" => {
            match value.as_str().map(rfc3339_instant) {
                Some(Ok(deadline)) => deadline,
                Some(Err(message)) => return Some(message),
                None => return Some("Service deadline_at must be offset-bearing RFC3339 with at most six fractional digits."),
            }
        }
        _ => return Some("Service deadline_policy must contain exactly timeout_seconds or deadline_at."),
    };
    let year = chrono::Datelike::year(&deadline);
    (!(1970..=9999).contains(&year)).then_some("Service call deadlines must resolve within UTC years 1970 through 9999.")
}

/// The `deadline_at` shape and calendar checks: the pattern
/// `\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]([01]\d|2[0-3]):[0-5]\d)\z`,
/// then `createFromFormat`, which warns on a date or time out of range.
fn rfc3339_instant(text: &str) -> std::result::Result<chrono::NaiveDateTime, &'static str> {
    const SHAPE: &str = "Service deadline_at must be offset-bearing RFC3339 with at most six fractional digits.";
    const CALENDAR: &str = "Service deadline_at must be a valid calendar instant.";
    let bytes = text.as_bytes();
    let digits = |range: std::ops::Range<usize>| -> Option<u32> {
        let part = bytes.get(range)?;
        part.iter().all(u8::is_ascii_digit).then(|| part.iter().fold(0, |sum, digit| sum * 10 + u32::from(digit - b'0')))
    };
    let separators = [(4, b'-'), (7, b'-'), (10, b'T'), (13, b':'), (16, b':')];
    if bytes.len() < 20 || separators.iter().any(|(at, byte)| bytes[*at] != *byte) {
        return Err(SHAPE);
    }
    let (Some(year), Some(month), Some(day), Some(hour), Some(minute), Some(second)) =
        (digits(0..4), digits(5..7), digits(8..10), digits(11..13), digits(14..16), digits(17..19))
    else {
        return Err(SHAPE);
    };
    let mut at = 19;
    let mut micros = 0;
    if bytes[at] == b'.' {
        let start = at + 1;
        let end = start + bytes[start..].iter().take_while(|byte| byte.is_ascii_digit()).count();
        if end == start || end - start > 6 {
            return Err(SHAPE);
        }
        // `str_pad($fraction, 6, '0')`: the digits are the leading places.
        micros = digits(start..end).ok_or(SHAPE)? * 10u32.pow(u32::try_from(6 - (end - start)).unwrap_or(0));
        at = end;
    }
    let offset_minutes: i64 = match &bytes[at..] {
        [b'Z'] => 0,
        [sign @ (b'+' | b'-'), h1, h2, b':', m1, m2]
            if matches!((h1, h2), (b'0' | b'1', b'0'..=b'9') | (b'2', b'0'..=b'3'))
                && matches!(m1, b'0'..=b'5')
                && m2.is_ascii_digit() =>
        {
            let minutes = i64::from((h1 - b'0') * 10 + (h2 - b'0')) * 60 + i64::from((m1 - b'0') * 10 + (m2 - b'0'));
            if *sign == b'-' { -minutes } else { minutes }
        }
        _ => return Err(SHAPE),
    };
    let date = chrono::NaiveDate::from_ymd_opt(i32::try_from(year).unwrap_or(0), month, day).ok_or(CALENDAR)?;
    let time = chrono::NaiveTime::from_hms_micro_opt(hour, minute, second, micros).ok_or(CALENDAR)?;
    Ok(date.and_time(time) - chrono::Duration::minutes(offset_minutes))
}

/// PHP's `trim`, as `assertOperationBindingTargetOrPayload` applies it.
fn php_trim(text: &str) -> &str {
    text.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0b'])
}

/// `assertOperationBindingTargetOrPayload`.
fn binding_error(target: &Value, binding: &Value) -> Option<RuntimeError> {
    let has_target = target.as_str().is_some_and(|text| !php_trim(text).is_empty());
    let has_binding = match binding {
        Value::Object(fields) => !fields.is_empty(),
        Value::Array(items) => !items.is_empty(),
        _ => false,
    };
    (!has_target && !has_binding)
        .then(|| invalid("handler_target_reference", "Provide handler_target_reference or a non-empty handler_binding."))
}

/// `validateOperationPayload`: the rules, then the deadline policy when one
/// is given, then the binding check for a create or an update that names
/// either binding field.
fn operation_payload(input: &Value, partial: bool) -> Result<Map<String, Value>> {
    let rules = operation_rules(partial);
    let validated = validated(input, &rules)?;
    if let Some(policy) = validated.get("deadline_policy")
        && let Some(message) = deadline_policy_error(policy)
    {
        return Err(invalid("deadline_policy", message));
    }
    if (!partial || validated.contains_key("handler_target_reference") || validated.contains_key("handler_binding"))
        && let Some(error) = binding_error(
            validated.get("handler_target_reference").unwrap_or(&Value::Null),
            validated.get("handler_binding").unwrap_or(&Value::Null),
        )
    {
        return Err(error);
    }
    Ok(validated)
}

/// A JSON column as PHP's array cast reads it: `json_decode($text, true)`,
/// encoded again as the response does.
fn php_document(value: Value) -> Value {
    match value {
        Value::Null => Value::Null,
        value => super::php_value::decode(value.to_string().as_bytes()).map_or(Value::Null, |decoded| decoded.to_json()),
    }
}

/// Carbon's `toIso8601String` and `format('c')`: whole seconds with an offset.
fn iso(at: Option<DateTime<Utc>>) -> Value {
    at.map_or(Value::Null, |at| json!(at.to_rfc3339_opts(chrono::SecondsFormat::Secs, false)))
}

fn or_empty(value: &Value) -> Value {
    if value.is_array() || value.is_object() { value.clone() } else { json!([]) }
}

fn not_found(response: Value) -> RuntimeError {
    RuntimeError::Protocol { status: StatusCode::NOT_FOUND, response }
}

fn conflict(response: Value) -> RuntimeError {
    RuntimeError::Protocol { status: StatusCode::CONFLICT, response }
}

/// The catalog's own row fields.
#[derive(Clone, Debug)]
pub(crate) struct Row {
    pub(crate) id: String,
    namespace: Option<String>,
    pub(crate) name: String,
    description: Option<String>,
    created_at: Option<DateTime<Utc>>,
    updated_at: Option<DateTime<Utc>>,
}

#[derive(Clone, Debug)]
pub(crate) struct Endpoint {
    pub(crate) row: Row,
    metadata: Value,
}

#[derive(Clone, Debug)]
pub(crate) struct Service {
    pub(crate) row: Row,
    pub(crate) endpoint_id: String,
    metadata: Value,
}

#[derive(Clone, Debug)]
pub(crate) struct Operation {
    pub(crate) row: Row,
    pub(crate) endpoint_id: String,
    pub(crate) service_id: String,
    mode: String,
    kind: String,
    target: Option<String>,
    /// `DOCUMENTS`, in order, as PHP reads them.
    documents: [Value; 7],
}

impl Operation {
    fn document(&self, field: &str) -> &Value {
        DOCUMENTS.iter().position(|name| *name == field).map_or(&Value::Null, |index| &self.documents[index])
    }
}

/// `ServiceEndpointView::listItem`.
pub(crate) fn endpoint_item(endpoint: &Endpoint) -> Value {
    json!({"id": endpoint.row.id, "namespace": endpoint.row.namespace, "endpoint_name": endpoint.row.name,
        "description": endpoint.row.description, "metadata": or_empty(&endpoint.metadata),
        "created_at": iso(endpoint.row.created_at), "updated_at": iso(endpoint.row.updated_at)})
}

/// `ServiceView::listItem`.
pub(crate) fn service_item(service: &Service) -> Value {
    json!({"id": service.row.id, "workflow_service_endpoint_id": service.endpoint_id, "namespace": service.row.namespace,
        "service_name": service.row.name, "description": service.row.description, "metadata": or_empty(&service.metadata),
        "created_at": iso(service.row.created_at), "updated_at": iso(service.row.updated_at)})
}

/// `ServiceOperationView::listItem`.
pub(crate) fn operation_item(operation: &Operation) -> Value {
    let mut item = json!({"id": operation.row.id, "workflow_service_endpoint_id": operation.endpoint_id,
        "workflow_service_id": operation.service_id, "namespace": operation.row.namespace,
        "operation_name": operation.row.name, "description": operation.row.description,
        "operation_mode": operation.mode, "handler_binding_kind": operation.kind,
        "handler_target_reference": operation.target,
        "created_at": iso(operation.row.created_at), "updated_at": iso(operation.row.updated_at)});
    for (field, value) in DOCUMENTS.iter().zip(&operation.documents) {
        item[*field] = or_empty(value);
    }
    item
}

/// `serializeEndpoint`.
fn endpoint_body(endpoint: &Endpoint) -> Value {
    json!({"id": endpoint.row.id, "namespace": endpoint.row.namespace, "endpoint_name": endpoint.row.name,
        "description": endpoint.row.description, "metadata": endpoint.metadata,
        "created_at": iso(endpoint.row.created_at), "updated_at": iso(endpoint.row.updated_at)})
}

/// `serializeService`.
fn service_body(service: &Service, endpoint: &Endpoint) -> Value {
    json!({"id": service.row.id, "namespace": service.row.namespace, "endpoint_id": endpoint.row.id,
        "endpoint_name": endpoint.row.name, "service_name": service.row.name, "description": service.row.description,
        "metadata": service.metadata, "created_at": iso(service.row.created_at), "updated_at": iso(service.row.updated_at)})
}

/// `serializeOperation`.
fn operation_body(operation: &Operation, endpoint: &Endpoint, service: &Service) -> Value {
    let mut body = json!({"id": operation.row.id, "namespace": operation.row.namespace, "endpoint_id": endpoint.row.id,
        "endpoint_name": endpoint.row.name, "service_id": service.row.id, "service_name": service.row.name,
        "operation_name": operation.row.name, "description": operation.row.description,
        "operation_mode": operation.mode, "handler_binding_kind": operation.kind,
        "handler_target_reference": operation.target,
        "created_at": iso(operation.row.created_at), "updated_at": iso(operation.row.updated_at)});
    for (field, value) in DOCUMENTS.iter().zip(&operation.documents) {
        body[*field] = value.clone();
    }
    body
}

/// `endpointNotFound`.
fn endpoint_not_found(namespace: &str, name: &str) -> RuntimeError {
    not_found(json!({"accepted": false,
        "message": format!("Service endpoint [{name}] not found in namespace [{namespace}]."),
        "reason": "endpoint_not_found", "namespace": namespace, "endpoint_name": name}))
}

/// `serviceNotFound`.
fn service_not_found(endpoint: &Endpoint, name: &str) -> RuntimeError {
    let namespace = endpoint.row.namespace.as_deref().unwrap_or_default();
    not_found(json!({
        "message": format!("Service [{name}] not found under endpoint [{}] in namespace [{namespace}].", endpoint.row.name),
        "reason": "service_not_found", "namespace": endpoint.row.namespace, "endpoint_name": endpoint.row.name,
        "service_name": name}))
}

/// `operationNotFound`.
fn operation_not_found(endpoint: &Endpoint, service: &Service, name: &str) -> RuntimeError {
    let namespace = service.row.namespace.as_deref().unwrap_or_default();
    not_found(json!({
        "message": format!("Operation [{name}] not found under service [{}] at endpoint [{}] in namespace [{namespace}].",
            service.row.name, endpoint.row.name),
        "reason": "operation_not_found", "namespace": service.row.namespace, "endpoint_name": endpoint.row.name,
        "service_name": service.row.name, "operation_name": name}))
}

/// A lowercase ULID, as `HasUlids::newUniqueId` makes one.
fn new_id() -> String {
    super::store::id().to_ascii_lowercase()
}

/// The validated fields an update changes, as Eloquent's dirty check sees
/// them: a document compares as PHP reads it back.
fn changed(current: &Value, next: &Value) -> bool {
    current != next
}

store_methods! {
    fn catalog_row(row: &DB::Row, name: &str) -> Result<Row> {
        Ok(Row {
            id: DB::string(row, "id")?,
            namespace: DB::optional_string(row, "namespace")?,
            name: DB::string(row, name)?,
            description: DB::optional_string(row, "description")?,
            created_at: DB::optional_instant(row, "created_at")?,
            updated_at: DB::optional_instant(row, "updated_at")?,
        })
    }

    fn catalog_document(row: &DB::Row, field: &str) -> Result<Value> {
        Ok(DB::optional_document_row(row, field)?.map_or(Value::Null, php_document))
    }

    pub(crate) fn endpoint_row(row: &DB::Row) -> Result<Endpoint> {
        Ok(Endpoint { row: Self::catalog_row(row, "endpoint_name")?, metadata: Self::catalog_document(row, "metadata")? })
    }

    pub(crate) fn service_row(row: &DB::Row) -> Result<Service> {
        Ok(Service {
            row: Self::catalog_row(row, "service_name")?,
            endpoint_id: DB::string(row, "workflow_service_endpoint_id")?,
            metadata: Self::catalog_document(row, "metadata")?,
        })
    }

    pub(crate) fn operation_row(row: &DB::Row) -> Result<Operation> {
        let mut documents: [Value; 7] = Default::default();
        for (slot, field) in documents.iter_mut().zip(DOCUMENTS) {
            *slot = Self::catalog_document(row, field)?;
        }
        Ok(Operation {
            row: Self::catalog_row(row, "operation_name")?,
            endpoint_id: DB::string(row, "workflow_service_endpoint_id")?,
            service_id: DB::string(row, "workflow_service_id")?,
            mode: DB::string(row, "operation_mode")?,
            kind: DB::string(row, "handler_binding_kind")?,
            target: DB::optional_string(row, "handler_target_reference")?,
            documents,
        })
    }

    /// `findEndpoint`: the namespace's endpoint of this (lowercased) name.
    async fn catalog_endpoint(&self, connection: &mut DB::Connection, name: &str) -> Result<Option<Endpoint>> {
        Self::query("SELECT * FROM workflow_service_endpoints WHERE namespace=$1 AND endpoint_name=$2 LIMIT 1")
            .bind(self.namespace.as_ref()).bind(name).fetch_optional(&mut *connection).await?
            .map(|row| Self::endpoint_row(&row)).transpose()
    }

    /// `findService`.
    async fn catalog_service(&self, connection: &mut DB::Connection, endpoint: &Endpoint, name: &str) -> Result<Option<Service>> {
        Self::query("SELECT * FROM workflow_services WHERE namespace=$1 AND workflow_service_endpoint_id=$2 AND service_name=$3 LIMIT 1")
            .bind(self.namespace.as_ref()).bind(endpoint.row.id.as_str()).bind(name).fetch_optional(&mut *connection).await?
            .map(|row| Self::service_row(&row)).transpose()
    }

    /// `findOperation`.
    async fn catalog_operation(&self, connection: &mut DB::Connection, service: &Service, name: &str) -> Result<Option<Operation>> {
        Self::query("SELECT * FROM workflow_service_operations WHERE namespace=$1 AND workflow_service_id=$2 AND operation_name=$3 LIMIT 1")
            .bind(self.namespace.as_ref()).bind(service.row.id.as_str()).bind(name).fetch_optional(&mut *connection).await?
            .map(|row| Self::operation_row(&row)).transpose()
    }

    /// The endpoint, or PHP's 404 for it.
    async fn required_endpoint(&self, connection: &mut DB::Connection, name: &str) -> Result<Endpoint> {
        let name = lower(name);
        match self.catalog_endpoint(connection, &name).await? {
            Some(endpoint) => Ok(endpoint),
            None => Err(endpoint_not_found(&self.namespace, &name)),
        }
    }

    /// The endpoint and service, or PHP's 404 for the first one missing.
    async fn required_service(&self, connection: &mut DB::Connection, endpoint: &str, service: &str) -> Result<(Endpoint, Service)> {
        let endpoint = self.required_endpoint(connection, endpoint).await?;
        let name = lower(service);
        match self.catalog_service(connection, &endpoint, &name).await? {
            Some(service) => Ok((endpoint, service)),
            None => Err(service_not_found(&endpoint, &name)),
        }
    }

    /// The endpoint, service and operation, or PHP's 404 for the first one missing.
    async fn required_operation(&self, connection: &mut DB::Connection, names: [&str; 3]) -> Result<(Endpoint, Service, Operation)> {
        let (endpoint, service) = self.required_service(connection, names[0], names[1]).await?;
        let name = lower(names[2]);
        match self.catalog_operation(connection, &service, &name).await? {
            Some(operation) => Ok((endpoint, service, operation)),
            None => Err(operation_not_found(&endpoint, &service, &name)),
        }
    }

    async fn catalog_has(connection: &mut DB::Connection, sql: &'static str, id: &str) -> Result<bool> {
        Ok(Self::scalar(sql).bind(id).fetch_one(&mut *connection).await? > 0)
    }

    /// `endpointIndex`.
    pub(crate) async fn list_service_endpoints(&self) -> Result<Value> {
        let mut tx = self.begin_read().await?;
        let rows = Self::query("SELECT * FROM workflow_service_endpoints WHERE namespace=$1 ORDER BY endpoint_name")
            .bind(self.namespace.as_ref()).fetch_all(&mut *tx).await?;
        let endpoints = rows.iter().map(|row| Self::endpoint_row(row).map(|endpoint| endpoint_body(&endpoint))).collect::<Result<Vec<_>>>()?;
        tx.commit().await?;
        Ok(json!({"service_endpoints": endpoints}))
    }

    /// `endpointStore`.
    pub(crate) async fn create_service_endpoint(&self, input: Value) -> Result<Value> {
        let fields = validated(&input, ENDPOINT_STORE)?;
        let name = lower(fields.get("endpoint_name").and_then(Value::as_str).unwrap_or_default());
        let mut tx = self.begin().await?;
        if let Some(existing) = self.catalog_endpoint(&mut tx, &name).await? {
            return Err(conflict(json!({
                "message": format!("A service endpoint [{name}] already exists in namespace [{}].", self.namespace),
                "reason": "endpoint_already_exists", "namespace": self.namespace.as_ref(), "endpoint_name": name,
                "endpoint_id": existing.row.id})));
        }
        let at = super::store::now();
        let endpoint = Endpoint {
            row: Row { id: new_id(), namespace: Some(self.namespace.to_string()), name,
                description: fields.get("description").and_then(Value::as_str).map(str::to_owned),
                created_at: Some(at), updated_at: Some(at) },
            metadata: php_document(fields.get("metadata").cloned().unwrap_or(Value::Null)),
        };
        Self::query("INSERT INTO workflow_service_endpoints(id,namespace,endpoint_name,description,metadata,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7)")
            .bind(endpoint.row.id.as_str()).bind(self.namespace.as_ref()).bind(endpoint.row.name.as_str())
            .bind(endpoint.row.description.as_deref()).bind(DB::optional_document(&endpoint.metadata))
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut *tx).await?;
        tx.commit().await?;
        Ok(endpoint_body(&endpoint))
    }

    /// `endpointShow`.
    pub(crate) async fn show_service_endpoint(&self, name: &str) -> Result<Value> {
        let mut tx = self.begin_read().await?;
        let endpoint = self.required_endpoint(&mut tx, name).await?;
        tx.commit().await?;
        Ok(endpoint_body(&endpoint))
    }

    /// `endpointUpdate`: the endpoint first, then the input.
    pub(crate) async fn update_service_endpoint(&self, name: &str, input: Value) -> Result<Value> {
        let mut tx = self.begin().await?;
        let mut endpoint = self.required_endpoint(&mut tx, name).await?;
        let fields = validated(&input, DESCRIBE)?;
        let mut dirty = false;
        if let Some(description) = fields.get("description") {
            let description = description.as_str().map(str::to_owned);
            dirty |= description != endpoint.row.description;
            endpoint.row.description = description;
        }
        if let Some(metadata) = fields.get("metadata") {
            let metadata = php_document(metadata.clone());
            dirty |= changed(&endpoint.metadata, &metadata);
            endpoint.metadata = metadata;
        }
        if dirty {
            let at = super::store::now();
            Self::query("UPDATE workflow_service_endpoints SET description=$1,metadata=$2,updated_at=$3 WHERE id=$4")
                .bind(endpoint.row.description.as_deref()).bind(DB::optional_document(&endpoint.metadata))
                .bind(DB::bind_time(at)).bind(endpoint.row.id.as_str()).execute(&mut *tx).await?;
            endpoint.row.updated_at = Some(at);
        }
        tx.commit().await?;
        Ok(endpoint_body(&endpoint))
    }

    /// `endpointDestroy`.
    pub(crate) async fn delete_service_endpoint(&self, name: &str) -> Result<Value> {
        let mut tx = self.begin().await?;
        let endpoint = self.required_endpoint(&mut tx, name).await?;
        let namespace = endpoint.row.namespace.as_deref().unwrap_or_default();
        for (sql, children, reason) in [
            ("SELECT COUNT(*) FROM workflow_services WHERE workflow_service_endpoint_id=$1", "registered services", "endpoint_has_services"),
            ("SELECT COUNT(*) FROM workflow_service_operations WHERE workflow_service_endpoint_id=$1", "registered operations", "endpoint_has_operations"),
            ("SELECT COUNT(*) FROM workflow_service_calls WHERE workflow_service_endpoint_id=$1", "recorded service calls", "endpoint_has_service_calls"),
        ] {
            if Self::catalog_has(&mut tx, sql, &endpoint.row.id).await? {
                return Err(conflict(json!({
                    "message": format!("Service endpoint [{}] in namespace [{namespace}] still has {children}.", endpoint.row.name),
                    "reason": reason, "namespace": endpoint.row.namespace, "endpoint_name": endpoint.row.name})));
            }
        }
        Self::query("DELETE FROM workflow_service_endpoints WHERE id=$1").bind(endpoint.row.id.as_str()).execute(&mut *tx).await?;
        tx.commit().await?;
        Ok(json!({"namespace": self.namespace.as_ref(), "endpoint_name": endpoint.row.name, "outcome": "deleted"}))
    }

    /// `serviceIndex`: the endpoint's services in creation order.
    pub(crate) async fn list_services(&self, endpoint: &str) -> Result<Value> {
        let mut tx = self.begin_read().await?;
        let endpoint = self.required_endpoint(&mut tx, endpoint).await?;
        let rows = Self::query("SELECT * FROM workflow_services WHERE workflow_service_endpoint_id=$1 ORDER BY created_at,id,service_name")
            .bind(endpoint.row.id.as_str()).fetch_all(&mut *tx).await?;
        let services = rows.iter().map(|row| Self::service_row(row).map(|service| service_body(&service, &endpoint)))
            .collect::<Result<Vec<_>>>()?;
        tx.commit().await?;
        Ok(json!({"services": services}))
    }

    /// `serviceStore`: the endpoint first, then the input.
    pub(crate) async fn create_service(&self, endpoint: &str, input: Value) -> Result<Value> {
        let mut tx = self.begin().await?;
        let endpoint = self.required_endpoint(&mut tx, endpoint).await?;
        let fields = validated(&input, SERVICE_STORE)?;
        let name = lower(fields.get("service_name").and_then(Value::as_str).unwrap_or_default());
        let namespace = endpoint.row.namespace.clone();
        if let Some(existing) = self.catalog_service(&mut tx, &endpoint, &name).await? {
            return Err(conflict(json!({
                "message": format!("A service [{name}] already exists under endpoint [{}] in namespace [{}].",
                    endpoint.row.name, namespace.as_deref().unwrap_or_default()),
                "reason": "service_already_exists", "namespace": namespace, "endpoint_name": endpoint.row.name,
                "service_name": name, "service_id": existing.row.id})));
        }
        let at = super::store::now();
        let service = Service {
            row: Row { id: new_id(), namespace, name,
                description: fields.get("description").and_then(Value::as_str).map(str::to_owned),
                created_at: Some(at), updated_at: Some(at) },
            endpoint_id: endpoint.row.id.clone(),
            metadata: php_document(fields.get("metadata").cloned().unwrap_or(Value::Null)),
        };
        Self::query("INSERT INTO workflow_services(id,workflow_service_endpoint_id,namespace,service_name,description,metadata,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8)")
            .bind(service.row.id.as_str()).bind(service.endpoint_id.as_str()).bind(service.row.namespace.as_deref())
            .bind(service.row.name.as_str()).bind(service.row.description.as_deref()).bind(DB::optional_document(&service.metadata))
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut *tx).await?;
        tx.commit().await?;
        Ok(service_body(&service, &endpoint))
    }

    /// `serviceShow`.
    pub(crate) async fn show_service(&self, endpoint: &str, service: &str) -> Result<Value> {
        let mut tx = self.begin_read().await?;
        let (endpoint, service) = self.required_service(&mut tx, endpoint, service).await?;
        tx.commit().await?;
        Ok(service_body(&service, &endpoint))
    }

    /// `serviceUpdate`.
    pub(crate) async fn update_service(&self, endpoint: &str, service: &str, input: Value) -> Result<Value> {
        let mut tx = self.begin().await?;
        let (endpoint, mut service) = self.required_service(&mut tx, endpoint, service).await?;
        let fields = validated(&input, DESCRIBE)?;
        let mut dirty = false;
        if let Some(description) = fields.get("description") {
            let description = description.as_str().map(str::to_owned);
            dirty |= description != service.row.description;
            service.row.description = description;
        }
        if let Some(metadata) = fields.get("metadata") {
            let metadata = php_document(metadata.clone());
            dirty |= changed(&service.metadata, &metadata);
            service.metadata = metadata;
        }
        if dirty {
            let at = super::store::now();
            Self::query("UPDATE workflow_services SET description=$1,metadata=$2,updated_at=$3 WHERE id=$4")
                .bind(service.row.description.as_deref()).bind(DB::optional_document(&service.metadata))
                .bind(DB::bind_time(at)).bind(service.row.id.as_str()).execute(&mut *tx).await?;
            service.row.updated_at = Some(at);
        }
        tx.commit().await?;
        Ok(service_body(&service, &endpoint))
    }

    /// `serviceDestroy`.
    pub(crate) async fn delete_service(&self, endpoint: &str, service: &str) -> Result<Value> {
        let mut tx = self.begin().await?;
        let (endpoint, service) = self.required_service(&mut tx, endpoint, service).await?;
        let namespace = service.row.namespace.as_deref().unwrap_or_default();
        for (sql, children, reason) in [
            ("SELECT COUNT(*) FROM workflow_service_operations WHERE workflow_service_id=$1", "registered operations", "service_has_operations"),
            ("SELECT COUNT(*) FROM workflow_service_calls WHERE workflow_service_id=$1", "recorded service calls", "service_has_service_calls"),
        ] {
            if Self::catalog_has(&mut tx, sql, &service.row.id).await? {
                return Err(conflict(json!({
                    "message": format!("Service [{}] under endpoint [{}] in namespace [{namespace}] still has {children}.",
                        service.row.name, endpoint.row.name),
                    "reason": reason, "namespace": service.row.namespace, "endpoint_name": endpoint.row.name,
                    "service_name": service.row.name})));
            }
        }
        Self::query("DELETE FROM workflow_services WHERE id=$1").bind(service.row.id.as_str()).execute(&mut *tx).await?;
        tx.commit().await?;
        Ok(json!({"namespace": endpoint.row.namespace, "endpoint_name": endpoint.row.name,
            "service_name": service.row.name, "outcome": "deleted"}))
    }

    /// `operationIndex`: the service's operations in creation order.
    pub(crate) async fn list_operations(&self, endpoint: &str, service: &str) -> Result<Value> {
        let mut tx = self.begin_read().await?;
        let (endpoint, service) = self.required_service(&mut tx, endpoint, service).await?;
        let rows = Self::query("SELECT * FROM workflow_service_operations WHERE workflow_service_id=$1 ORDER BY created_at,id,operation_name")
            .bind(service.row.id.as_str()).fetch_all(&mut *tx).await?;
        let operations = rows.iter()
            .map(|row| Self::operation_row(row).map(|operation| operation_body(&operation, &endpoint, &service)))
            .collect::<Result<Vec<_>>>()?;
        tx.commit().await?;
        Ok(json!({"operations": operations}))
    }

    /// `operationStore`: the endpoint and service first, then the input.
    pub(crate) async fn create_operation(&self, endpoint: &str, service: &str, input: Value) -> Result<Value> {
        let mut tx = self.begin().await?;
        let (endpoint, service) = self.required_service(&mut tx, endpoint, service).await?;
        let fields = operation_payload(&input, false)?;
        let name = lower(fields.get("operation_name").and_then(Value::as_str).unwrap_or_default());
        let namespace = service.row.namespace.clone();
        if let Some(existing) = self.catalog_operation(&mut tx, &service, &name).await? {
            return Err(conflict(json!({
                "message": format!("An operation [{name}] already exists under service [{}] at endpoint [{}] in namespace [{}].",
                    service.row.name, endpoint.row.name, namespace.as_deref().unwrap_or_default()),
                "reason": "operation_already_exists", "namespace": namespace, "endpoint_name": endpoint.row.name,
                "service_name": service.row.name, "operation_name": name, "operation_id": existing.row.id})));
        }
        let at = super::store::now();
        let text = |field: &str| fields.get(field).and_then(Value::as_str).map(str::to_owned);
        let operation = Operation {
            row: Row { id: new_id(), namespace, name, description: text("description"), created_at: Some(at), updated_at: Some(at) },
            endpoint_id: endpoint.row.id.clone(),
            service_id: service.row.id.clone(),
            mode: text("operation_mode").unwrap_or_default(),
            kind: text("handler_binding_kind").unwrap_or_default(),
            target: text("handler_target_reference"),
            documents: DOCUMENTS.map(|field| php_document(fields.get(field).cloned().unwrap_or(Value::Null))),
        };
        Self::query("INSERT INTO workflow_service_operations(id,workflow_service_endpoint_id,workflow_service_id,namespace,operation_name,description,operation_mode,handler_binding_kind,handler_target_reference,handler_binding,deadline_policy,idempotency_policy,cancellation_policy,retry_policy,boundary_policy,metadata,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18)")
            .bind(operation.row.id.as_str()).bind(operation.endpoint_id.as_str()).bind(operation.service_id.as_str())
            .bind(operation.row.namespace.as_deref()).bind(operation.row.name.as_str()).bind(operation.row.description.as_deref())
            .bind(operation.mode.as_str()).bind(operation.kind.as_str()).bind(operation.target.as_deref())
            .bind(DB::optional_document(&operation.documents[0])).bind(DB::optional_document(&operation.documents[1]))
            .bind(DB::optional_document(&operation.documents[2])).bind(DB::optional_document(&operation.documents[3]))
            .bind(DB::optional_document(&operation.documents[4])).bind(DB::optional_document(&operation.documents[5]))
            .bind(DB::optional_document(&operation.documents[6]))
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut *tx).await?;
        tx.commit().await?;
        Ok(operation_body(&operation, &endpoint, &service))
    }

    /// `operationShow`.
    pub(crate) async fn show_operation(&self, names: [&str; 3]) -> Result<Value> {
        let mut tx = self.begin_read().await?;
        let (endpoint, service, operation) = self.required_operation(&mut tx, names).await?;
        tx.commit().await?;
        Ok(operation_body(&operation, &endpoint, &service))
    }

    /// `operationUpdate`: the given fields, with the binding check repeated
    /// against the stored target and binding when either changes.
    pub(crate) async fn update_operation(&self, names: [&str; 3], input: Value) -> Result<Value> {
        let mut tx = self.begin().await?;
        let (endpoint, service, mut operation) = self.required_operation(&mut tx, names).await?;
        let fields = operation_payload(&input, true)?;
        if fields.contains_key("handler_target_reference") || fields.contains_key("handler_binding") {
            let target = fields.get("handler_target_reference").cloned().unwrap_or_else(|| json!(operation.target));
            let binding = fields.get("handler_binding").cloned().unwrap_or_else(|| operation.document("handler_binding").clone());
            if let Some(error) = binding_error(&target, &binding) {
                return Err(error);
            }
        }
        let mut dirty = false;
        let text = |field: &str| fields.get(field).map(|value| value.as_str().map(str::to_owned));
        if let Some(description) = text("description") {
            dirty |= description != operation.row.description;
            operation.row.description = description;
        }
        // A required string column: PHP would fail to write null, which the
        // rules never let through (`sometimes|string`).
        if let Some(Some(mode)) = text("operation_mode") {
            dirty |= mode != operation.mode;
            operation.mode = mode;
        }
        if let Some(Some(kind)) = text("handler_binding_kind") {
            dirty |= kind != operation.kind;
            operation.kind = kind;
        }
        if let Some(target) = text("handler_target_reference") {
            dirty |= target != operation.target;
            operation.target = target;
        }
        for (index, field) in DOCUMENTS.iter().enumerate() {
            if let Some(value) = fields.get(*field) {
                let value = php_document(value.clone());
                dirty |= changed(&operation.documents[index], &value);
                operation.documents[index] = value;
            }
        }
        if dirty {
            let at = super::store::now();
            Self::query("UPDATE workflow_service_operations SET description=$1,operation_mode=$2,handler_binding_kind=$3,handler_target_reference=$4,handler_binding=$5,deadline_policy=$6,idempotency_policy=$7,cancellation_policy=$8,retry_policy=$9,boundary_policy=$10,metadata=$11,updated_at=$12 WHERE id=$13")
                .bind(operation.row.description.as_deref()).bind(operation.mode.as_str()).bind(operation.kind.as_str())
                .bind(operation.target.as_deref())
                .bind(DB::optional_document(&operation.documents[0])).bind(DB::optional_document(&operation.documents[1]))
                .bind(DB::optional_document(&operation.documents[2])).bind(DB::optional_document(&operation.documents[3]))
                .bind(DB::optional_document(&operation.documents[4])).bind(DB::optional_document(&operation.documents[5]))
                .bind(DB::optional_document(&operation.documents[6]))
                .bind(DB::bind_time(at)).bind(operation.row.id.as_str()).execute(&mut *tx).await?;
            operation.row.updated_at = Some(at);
        }
        tx.commit().await?;
        Ok(operation_body(&operation, &endpoint, &service))
    }

    /// `operationDestroy`.
    pub(crate) async fn delete_operation(&self, names: [&str; 3]) -> Result<Value> {
        let mut tx = self.begin().await?;
        let (endpoint, service, operation) = self.required_operation(&mut tx, names).await?;
        if Self::catalog_has(&mut tx, "SELECT COUNT(*) FROM workflow_service_calls WHERE workflow_service_operation_id=$1", &operation.row.id).await? {
            return Err(conflict(json!({
                "message": format!("Operation [{}] under service [{}] at endpoint [{}] in namespace [{}] still has recorded service calls.",
                    operation.row.name, service.row.name, endpoint.row.name, operation.row.namespace.as_deref().unwrap_or_default()),
                "reason": "operation_has_service_calls", "namespace": operation.row.namespace, "endpoint_name": endpoint.row.name,
                "service_name": service.row.name, "operation_name": operation.row.name})));
        }
        Self::query("DELETE FROM workflow_service_operations WHERE id=$1").bind(operation.row.id.as_str()).execute(&mut *tx).await?;
        tx.commit().await?;
        Ok(json!({"namespace": endpoint.row.namespace, "endpoint_name": endpoint.row.name,
            "service_name": service.row.name, "operation_name": operation.row.name, "outcome": "deleted"}))
    }
}

/// The ruled fields each write reads, for `input`.
pub(crate) fn rules_for(level: Level, create: bool) -> Vec<(&'static str, &'static [Rule])> {
    match (level, create) {
        (Level::Endpoint, true) => ENDPOINT_STORE.to_vec(),
        (Level::Service, true) => SERVICE_STORE.to_vec(),
        (Level::Endpoint | Level::Service, false) => DESCRIBE.to_vec(),
        (Level::Operation, create) => operation_rules(!create),
    }
}

/// The catalog level a route writes.
#[derive(Clone, Copy, Debug)]
pub(crate) enum Level {
    Endpoint,
    Service,
    Operation,
}

/// Catalog rows as published PHP writes them, for the takeover tests: lowercase
/// ULIDs, microsecond UTC times and `json_encode` documents, with escaped
/// slashes and `[]` for an empty map.
#[cfg(test)]
pub(crate) const PHP_CATALOG_ROWS: [&str; 3] = [
    r#"INSERT INTO workflow_service_endpoints(id,namespace,endpoint_name,description,metadata,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05k7vey','default','billing','Payments λ','{"team":"core","docs":"https:\/\/example.test\/billing","empty":[]}','2026-10-26 19:45:31.123456','2026-10-26 19:45:32.654321')"#,
    r#"INSERT INTO workflow_services(id,workflow_service_endpoint_id,namespace,service_name,description,metadata,created_at,updated_at) VALUES ('01m5wvy8takr150q5sh66c1xck','01m5wvy8d1j3dtr13es05k7vey','default','invoices',NULL,NULL,'2026-10-26 19:45:33.000001','2026-10-26 19:45:33.000001')"#,
    r#"INSERT INTO workflow_service_operations(id,workflow_service_endpoint_id,workflow_service_id,namespace,operation_name,description,operation_mode,handler_binding_kind,handler_target_reference,handler_binding,deadline_policy,idempotency_policy,cancellation_policy,retry_policy,boundary_policy,metadata,created_at,updated_at) VALUES ('01m5wvy930w32awr226fys6rsj','01m5wvy8d1j3dtr13es05k7vey','01m5wvy8takr150q5sh66c1xck','default','charge',NULL,'sync','start_workflow','billing.charge',NULL,'{"timeout_seconds":30}',NULL,NULL,'{"max_attempts":3}',NULL,'[]','2026-10-26 19:45:34.5','2026-10-26 19:45:34.5')"#,
];

/// What native serves over `PHP_CATALOG_ROWS` after a takeover, and that it
/// administers them as its own rows.
#[cfg(test)]
pub(crate) async fn assert_php_catalog_served(runtime: &super::Runtime) {
    let endpoint = runtime.show_service_endpoint("Billing").await.unwrap();
    assert_eq!(endpoint, json!({"id": "01m5wvy8d1j3dtr13es05k7vey", "namespace": "default", "endpoint_name": "billing",
        "description": "Payments λ", "metadata": {"team": "core", "docs": "https://example.test/billing", "empty": []},
        "created_at": "2026-10-26T19:45:31+00:00", "updated_at": "2026-10-26T19:45:32+00:00"}));
    let operations = runtime.list_operations("billing", "invoices").await.unwrap();
    let charge = &operations["operations"][0];
    assert_eq!((charge["deadline_policy"].clone(), charge["retry_policy"].clone(), charge["metadata"].clone(), charge["created_at"].clone()),
        (json!({"timeout_seconds": 30}), json!({"max_attempts": 3}), json!([]), json!("2026-10-26T19:45:34+00:00")));
    // PHP's documents compare as PHP reads them: an unchanged update keeps updated_at.
    let same = runtime.update_service_endpoint("billing",
        json!({"metadata": {"team": "core", "docs": "https://example.test/billing", "empty": {}}})).await.unwrap();
    assert_eq!(same["updated_at"], "2026-10-26T19:45:32+00:00");
    // A native service follows PHP's in creation order.
    runtime.create_service("billing", json!({"service_name": "accounts"})).await.unwrap();
    let services = runtime.list_services("billing").await.unwrap();
    let names: Vec<_> = services["services"].as_array().unwrap().iter().map(|service| service["service_name"].clone()).collect();
    assert_eq!(names, [json!("invoices"), json!("accounts")]);
    let page = runtime.service_catalog_list(&[], super::service_catalog::Catalog::Operations).await.unwrap();
    assert_eq!((page["total"].clone(), page["data"][0]["id"].clone()), (json!(1), json!("01m5wvy930w32awr226fys6rsj")));
    match runtime.delete_service_endpoint("billing").await {
        Err(RuntimeError::Protocol { status, response }) => {
            assert_eq!((status, response["reason"].clone()), (StatusCode::CONFLICT, json!("endpoint_has_services")));
        }
        other => panic!("expected a refusal, got {other:?}"),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn refusal(result: Result<Map<String, Value>>) -> Value {
        match result {
            Err(RuntimeError::Protocol { status, response }) => json!([status.as_u16(), response["errors"]]),
            other => panic!("expected a refusal, got {other:?}"),
        }
    }

    #[test]
    fn names_follow_laravel_rules() {
        let check = |body: Value| validated(&laravel_rules::normalize(&body), ENDPOINT_STORE);
        assert!(check(json!({"endpoint_name": "Billing.v2_x-1"})).is_ok());
        assert_eq!(refusal(check(json!({}))), json!([422, {"endpoint_name": ["The endpoint name field is required."]}]));
        assert_eq!(refusal(check(json!({"endpoint_name": "bad name!"}))),
            json!([422, {"endpoint_name": ["The endpoint name field format is invalid."]}]));
        // A number is not a string, but its digits fit the pattern and the length.
        assert_eq!(refusal(check(json!({"endpoint_name": 500}))),
            json!([422, {"endpoint_name": ["The endpoint name field must be a string."]}]));
        assert_eq!(refusal(check(json!({"endpoint_name": ["a"]}))),
            json!([422, {"endpoint_name": ["The endpoint name field must be a string.", "The endpoint name field format is invalid."]}]));
        assert_eq!(refusal(check(json!({"endpoint_name": "a".repeat(192)}))),
            json!([422, {"endpoint_name": ["The endpoint name field must not be greater than 191 characters."]}]));
        assert_eq!(refusal(check(json!({"endpoint_name": "a", "metadata": "x", "description": 5}))),
            json!([422, {"description": ["The description field must be a string."], "metadata": ["The metadata field must be an array."]}]));
    }

    #[test]
    fn operation_payloads_follow_validate_operation_payload() {
        let check = |body: Value, partial: bool| operation_payload(&laravel_rules::normalize(&body), partial);
        let create = json!({"operation_name": "charge", "operation_mode": "sync", "handler_binding_kind": "start_workflow",
            "handler_target_reference": "billing.charge"});
        assert!(check(create.clone(), false).is_ok());
        assert_eq!(refusal(check(json!({}), false)), json!([422, {
            "operation_mode": ["The operation mode field is required."],
            "handler_binding_kind": ["The handler binding kind field is required."],
            "operation_name": ["The operation name field is required."]}]));
        let mut unbound = create.clone();
        unbound["handler_target_reference"] = json!("  ");
        unbound["handler_binding"] = json!({});
        assert_eq!(refusal(check(unbound, false)),
            json!([422, {"handler_target_reference": ["Provide handler_target_reference or a non-empty handler_binding."]}]));
        let mut deadline = create.clone();
        deadline["deadline_policy"] = json!({"timeout_seconds": 0});
        assert_eq!(refusal(check(deadline, false)),
            json!([422, {"deadline_policy": ["Service timeout_seconds must be an integer from 1 to 2147483647."]}]));
        // An update may not rename, and checks the binding only when it names one.
        assert_eq!(refusal(check(json!({"operation_name": "x"}), true)),
            json!([422, {"operation_name": ["The operation name field is prohibited."]}]));
        assert!(check(json!({"operation_name": "", "description": "d"}), true).is_ok());
        assert_eq!(refusal(check(json!({"handler_binding": null}), true)),
            json!([422, {"handler_target_reference": ["Provide handler_target_reference or a non-empty handler_binding."]}]));
        assert_eq!(refusal(check(json!({"operation_mode": "later"}), true)),
            json!([422, {"operation_mode": ["The selected operation mode is invalid."]}]));
    }

    #[test]
    fn deadline_policies_follow_the_package_resolver() {
        let exactly = Some("Service deadline_policy must contain exactly timeout_seconds or deadline_at.");
        let shape = Some("Service deadline_at must be offset-bearing RFC3339 with at most six fractional digits.");
        let calendar = Some("Service deadline_at must be a valid calendar instant.");
        let years = Some("Service call deadlines must resolve within UTC years 1970 through 9999.");
        let seconds = Some("Service timeout_seconds must be an integer from 1 to 2147483647.");
        for (policy, expected) in [
            (json!(null), None),
            (json!({"timeout_seconds": 1}), None),
            (json!({"timeout_seconds": 2_147_483_647}), None),
            (json!({"timeout_seconds": 2_147_483_648_i64}), seconds),
            (json!({"timeout_seconds": 5.0}), seconds),
            (json!({"timeout_seconds": "5"}), seconds),
            (json!({}), exactly),
            (json!([5]), exactly),
            (json!({"timeout_seconds": 5, "deadline_at": "2030-01-01T00:00:00Z"}), exactly),
            (json!({"deadline_at": "2030-01-01T00:00:00Z"}), None),
            (json!({"deadline_at": "2030-01-01T00:00:00.123456+05:30"}), None),
            (json!({"deadline_at": "2030-01-01T00:00:00.1234567Z"}), shape),
            (json!({"deadline_at": "2030-01-01T00:00:00"}), shape),
            (json!({"deadline_at": "2030-01-01 00:00:00Z"}), shape),
            (json!({"deadline_at": "2030-01-01T00:00:00+24:00"}), shape),
            (json!({"deadline_at": "2030-01-01T00:00:00.Z"}), shape),
            (json!({"deadline_at": 5}), shape),
            (json!({"deadline_at": "2030-02-30T00:00:00Z"}), calendar),
            (json!({"deadline_at": "2030-01-01T24:00:00Z"}), calendar),
            (json!({"deadline_at": "2030-01-01T23:59:60Z"}), calendar),
            (json!({"deadline_at": "1969-12-31T23:59:59Z"}), years),
            (json!({"deadline_at": "1970-01-01T00:30:00+01:00"}), years),
            (json!({"deadline_at": "9999-12-31T23:30:00-01:00"}), years),
        ] {
            assert_eq!(deadline_policy_error(&policy), expected, "{policy}");
        }
    }

    #[test]
    fn documents_read_back_as_php_arrays() {
        assert_eq!(php_document(json!({})), json!([]));
        assert_eq!(php_document(json!({"0": "a", "1": {"x": {}}})), json!(["a", {"x": []}]));
        assert_eq!(php_document(json!({"a": 1.0, "b": [1, 2]})), json!({"a": 1, "b": [1, 2]}));
        assert_eq!(php_document(Value::Null), Value::Null);
    }

    #[test]
    fn input_reads_missing_ruled_fields_from_the_query() {
        let query = vec![("description".to_owned(), " from query ".to_owned()), ("endpoint_name".to_owned(), "q".to_owned()),
            ("other".to_owned(), "x".to_owned())];
        assert_eq!(input(json!({"endpoint_name": " body "}), &query, ENDPOINT_STORE),
            json!({"endpoint_name": "body", "description": "from query"}));
        assert_eq!(input(json!(["x"]), &[], ENDPOINT_STORE), json!({}));
    }
}
