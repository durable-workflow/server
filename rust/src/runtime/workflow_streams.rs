//! Durable workflow stream reads as published PHP's `WorkflowStreamController`
//! answers them. Native runs never produce durable streams, and takeover
//! refuses a PHP database that holds any, so every run has none: the list is
//! empty, a valid name is not found and an invalid one is refused. Producer
//! appends and closes are not implemented.
use super::laravel_validation::{failure, integer, normalized};
use super::{Result, Runtime, RuntimeError, backend::Backend, store::Store};
use axum::{
    Extension, Json,
    extract::{Path, Query},
    http::StatusCode,
};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::HashMap;

impl<DB: Backend> Store<DB>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> Option<&'q str>: Encode<'q, DB>,
    for<'q> Option<DB::EncodedDocument>: Encode<'q, DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'r> i64: Decode<'r, DB>,
    usize: ColumnIndex<DB::Row>,
{
    /// `NamespaceWorkflowScope::workflowBound` and `run`: the instance and
    /// the run both belong to this namespace.
    pub(super) async fn run_in_namespace(&self, workflow_id: &str, run_id: &str) -> Result<bool> {
        let found = Self::scalar("SELECT COUNT(*) FROM workflow_runs WHERE namespace=$1 AND workflow_instance_id=$2 AND id=$3 AND EXISTS (SELECT 1 FROM workflow_instances WHERE id=$4 AND namespace=$5)")
            .bind(self.namespace.as_ref())
            .bind(workflow_id)
            .bind(run_id)
            .bind(workflow_id)
            .bind(self.namespace.as_ref())
            .fetch_one(&self.pool)
            .await?;
        Ok(found > 0)
    }
}

fn protocol(status: StatusCode, response: Value) -> RuntimeError {
    RuntimeError::Protocol { status, response }
}

fn run_not_found() -> RuntimeError {
    protocol(StatusCode::NOT_FOUND, json!({"message":"Workflow run not found.","reason":"instance_not_found"}))
}

/// `WorkflowStreamService::STREAM_NAME_PATTERN`.
fn valid_name(name: &str) -> bool {
    let bytes = name.as_bytes();
    !bytes.is_empty()
        && bytes.len() <= 191
        && bytes[0].is_ascii_alphanumeric()
        && bytes[1..].iter().all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'_' | b':' | b'-'))
}

fn stream_refusal(name: &str) -> RuntimeError {
    if valid_name(name) {
        protocol(StatusCode::NOT_FOUND, json!({"message":"Stream not found.","reason":"stream_not_found"}))
    } else {
        protocol(StatusCode::BAD_REQUEST, json!({"message":"invalid stream name","reason":"invalid_stream_name"}))
    }
}

/// `items()`'s Laravel rules for `from`, `max_items` and `wait_seconds`.
fn items_rules(query: &HashMap<String, String>) -> Result<()> {
    // Query values are strings; empty ones become null and pass `nullable`.
    let integer = |field: &str| -> Option<std::result::Result<i64, ()>> {
        let value = normalized(query.get(field).map(|text| json!(text)).as_ref())?;
        (!value.is_null()).then(|| integer(&value).ok_or(()))
    };
    let mut errors: Vec<(&'static str, Vec<&'static str>)> = Vec::new();
    let mut from = vec![];
    match integer("from") {
        Some(Err(())) => from.push("The from field must be an integer."),
        Some(Ok(value)) if value < 0 => from.push("The from field must be at least 0."),
        _ => {}
    }
    errors.push(("from", from));
    let mut max_items = vec![];
    match integer("max_items") {
        Some(Err(())) => max_items.push("The max items field must be an integer."),
        Some(Ok(value)) if value < 1 => max_items.push("The max items field must be at least 1."),
        Some(Ok(value)) if value > 500 => max_items.push("The max items field must not be greater than 500."),
        _ => {}
    }
    errors.push(("max_items", max_items));
    let mut wait = vec![];
    match integer("wait_seconds") {
        Some(Err(())) => wait.push("The wait seconds field must be an integer."),
        Some(Ok(value)) if value < 0 => wait.push("The wait seconds field must be at least 0."),
        Some(Ok(value)) if value > 60 => wait.push("The wait seconds field must not be greater than 60."),
        _ => {}
    }
    errors.push(("wait_seconds", wait));
    match failure(errors) {
        Some(error) => Err(error),
        None => Ok(()),
    }
}

/// `GET /api/workflows/{id}/runs/{run}/streams`.
pub(super) async fn list(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id)): Path<(String, String)>,
) -> Result<Json<Value>> {
    if !runtime.run_in_namespace(&workflow_id, &run_id).await? {
        return Err(run_not_found());
    }
    Ok(Json(json!({"workflow_id":workflow_id,"workflow_run_id":run_id,"count":0,"streams":[]})))
}

/// `GET /api/workflows/{id}/runs/{run}/streams/{name}`.
pub(super) async fn show(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id, name)): Path<(String, String, String)>,
) -> Result<Json<Value>> {
    if !runtime.run_in_namespace(&workflow_id, &run_id).await? {
        return Err(run_not_found());
    }
    Err(stream_refusal(&name))
}

/// `GET /api/workflows/{id}/runs/{run}/streams/{name}/items`.
pub(super) async fn items(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id, name)): Path<(String, String, String)>,
    Query(query): Query<HashMap<String, String>>,
) -> Result<Json<Value>> {
    if !runtime.run_in_namespace(&workflow_id, &run_id).await? {
        return Err(run_not_found());
    }
    items_rules(&query)?;
    Err(stream_refusal(&name))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn stream_names_follow_php_pattern() {
        assert!(valid_name("orders.v1:main-2"));
        assert!(!valid_name("-leading"));
        assert!(!valid_name("has space"));
        assert!(!valid_name(&"a".repeat(192)));
        assert!(valid_name(&"a".repeat(191)));
    }

    #[test]
    fn item_rules_report_laravel_messages() {
        let query: HashMap<String, String> = [("from", "x"), ("max_items", "501"), ("wait_seconds", "61")]
            .into_iter()
            .map(|(key, value)| (key.to_owned(), value.to_owned()))
            .collect();
        match items_rules(&query) {
            Err(RuntimeError::Protocol { response, .. }) => {
                assert_eq!(response["message"], "The from field must be an integer. (and 2 more errors)");
            }
            other => panic!("{other:?}"),
        }
        assert!(items_rules(&HashMap::new()).is_ok());
        let query: HashMap<String, String> = [("from", "05"), ("max_items", "+5"), ("wait_seconds", " ")]
            .into_iter()
            .map(|(key, value)| (key.to_owned(), value.to_owned()))
            .collect();
        match items_rules(&query) {
            Err(RuntimeError::Protocol { response, .. }) => {
                assert_eq!(response["errors"], json!({"from":["The from field must be an integer."]}));
            }
            other => panic!("{other:?}"),
        }
    }
}
