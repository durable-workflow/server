//! Custom search attribute definitions per namespace, as PHP's
//! SearchAttributeController registers them. Native start and upsert do not
//! accept search attribute values yet, so a definition is registry state only.
use super::{
    Result, RuntimeError,
    backend::Backend,
    laravel_validation::{failure, normalized, present},
    memo_limits,
    store::{Store, now},
};
use axum::http::StatusCode;
use serde_json::{Map, Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::env;

/// PHP's `SearchAttributeDefinition::SYSTEM_ATTRIBUTES`.
pub(super) const SYSTEM_ATTRIBUTES: [(&str, &str); 11] = [
    ("WorkflowType", "keyword"),
    ("WorkflowId", "keyword"),
    ("RunId", "keyword"),
    ("Status", "keyword"),
    ("ExecutionStatus", "keyword"),
    ("StartTime", "datetime"),
    ("ExecutionTime", "datetime"),
    ("CloseTime", "datetime"),
    ("TaskQueue", "keyword"),
    ("BuildId", "keyword"),
    ("BuildIds", "keyword"),
];
const SYSTEM_ALIASES: [&str; 12] = [
    "workflow_type",
    "workflow_id",
    "wf_id",
    "run_id",
    "status",
    "execution_status",
    "start_time",
    "execution_time",
    "close_time",
    "task_queue",
    "build_id",
    "build_ids",
];
const ALLOWED_TYPES: [&str; 8] = [
    "keyword",
    "string",
    "text",
    "int",
    "double",
    "bool",
    "datetime",
    "keyword_list",
];
const DEFAULT_LIMIT: i64 = 100;

/// `server.limits.max_search_attributes`: DW-primary, WORKFLOW-legacy, cast
/// to an integer as Laravel's env() and PHP's (int) do.
pub(super) fn configured_limit() -> Result<i64> {
    let read = |key| match env::var(key) {
        Ok(value) => Ok(Some(value)),
        Err(env::VarError::NotPresent) => Ok(None),
        Err(env::VarError::NotUnicode(_)) => Err(super::refuse(
            StatusCode::BAD_REQUEST,
            "invalid_search_attribute_configuration",
        )),
    };
    let primary = read("DW_MAX_SEARCH_ATTRIBUTES")?.filter(|value| !memo_limits::typed_null(value));
    Ok(match primary.or(read("WORKFLOW_MAX_SEARCH_ATTRIBUTES")?) {
        Some(value) => memo_limits::cast_integer(&value),
        None => DEFAULT_LIMIT,
    })
}

fn system_attributes() -> Value {
    Value::Object(
        SYSTEM_ATTRIBUTES
            .iter()
            .map(|(name, kind)| ((*name).to_owned(), json!(kind)))
            .collect(),
    )
}

fn reserved(name: &str) -> bool {
    SYSTEM_ATTRIBUTES.iter().any(|(system, _)| *system == name)
        || SYSTEM_ALIASES.contains(&name.to_ascii_lowercase().as_str())
        || name.starts_with("__")
}

/// The messages for `required|string|max:128|regex:/^[a-zA-Z][a-zA-Z0-9_]*$/`.
/// A failed `required` stops the remaining rules, as Laravel's implicit rules do.
fn name_messages(value: Option<&Value>) -> Vec<&'static str> {
    if !present(value) {
        return vec!["The name field is required."];
    }
    let mut messages = Vec::new();
    let text = value.and_then(Value::as_str);
    if text.is_none() {
        messages.push("The name field must be a string.");
    }
    // Laravel measures strings in characters and arrays in items; the rule
    // set has no array rule, so the message is the string one either way.
    let size = match value {
        Some(Value::String(text)) => text.chars().count(),
        Some(Value::Array(items)) => items.len(),
        Some(Value::Object(items)) => items.len(),
        _ => 0,
    };
    if size > 128 {
        messages.push("The name field must not be greater than 128 characters.");
    }
    // A number's string form never starts with a letter, and other values
    // fail the regex rule outright.
    let matches = text.is_some_and(|text| {
        let mut chars = text.chars();
        chars.next().is_some_and(|c| c.is_ascii_alphabetic())
            && chars.all(|c| c.is_ascii_alphanumeric() || c == '_')
    });
    if !matches {
        messages.push("The name field format is invalid.");
    }
    messages
}

/// The messages for `required|string|in:<allowed types>`.
fn type_messages(value: Option<&Value>) -> Vec<&'static str> {
    if !present(value) {
        return vec!["The type field is required."];
    }
    let text = value.and_then(Value::as_str);
    let mut messages = Vec::new();
    if text.is_none() {
        messages.push("The type field must be a string.");
    }
    if !text.is_some_and(|text| ALLOWED_TYPES.contains(&text)) {
        messages.push("The selected type is invalid.");
    }
    messages
}

/// Laravel's validation of a registration body: the normalized name and type,
/// or PHP's 422 body.
fn validated(body: &Value) -> Result<(Value, Value)> {
    let name = normalized(body.get("name"));
    let kind = normalized(body.get("type"));
    if let Some(error) = failure(vec![
        ("name", name_messages(name.as_ref())),
        ("type", type_messages(kind.as_ref())),
    ]) {
        return Err(error);
    }
    Ok((name.unwrap_or_default(), kind.unwrap_or_default()))
}

fn conflict(status: StatusCode, response: Value) -> RuntimeError {
    RuntimeError::Protocol { status, response }
}

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
    pub(super) async fn list_search_attributes(&self) -> Result<Value> {
        let rows = Self::query(
            "SELECT name,type FROM search_attribute_definitions WHERE namespace=$1 ORDER BY name",
        )
        .bind(&*self.namespace)
        .fetch_all(&self.pool)
        .await?;
        let mut custom = Map::new();
        for row in &rows {
            custom.insert(DB::string(row, "name")?, json!(DB::string(row, "type")?));
        }
        // PHP renders an empty map as a JSON array.
        let custom = if custom.is_empty() {
            json!([])
        } else {
            Value::Object(custom)
        };
        Ok(json!({"system_attributes":system_attributes(),"custom_attributes":custom}))
    }

    async fn existing_attribute_conflict(&self, name: &str) -> Result<RuntimeError> {
        let row = Self::query(
            "SELECT name,type FROM search_attribute_definitions WHERE namespace=$1 AND name=$2",
        )
        .bind(&*self.namespace)
        .bind(name)
        .fetch_one(&self.pool)
        .await?;
        Ok(self.already_exists(name, &row)?)
    }

    fn already_exists(&self, name: &str, row: &DB::Row) -> Result<RuntimeError> {
        Ok(conflict(
            StatusCode::CONFLICT,
            json!({"message":format!("A custom search attribute [{name}] already exists in namespace [{}].", self.namespace),
                "reason":"attribute_already_exists","name":DB::string(row,"name")?,"type":DB::string(row,"type")?}),
        ))
    }

    pub(super) async fn create_search_attribute(&self, body: Value) -> Result<Value> {
        let (name, kind) = validated(&body)?;
        let name = name.as_str().unwrap_or_default();
        let kind = kind.as_str().unwrap_or_default();
        if reserved(name) {
            return Err(conflict(
                StatusCode::CONFLICT,
                json!({"message":format!("The name [{name}] is reserved for system search attributes."),
                    "reason":"name_reserved"}),
            ));
        }
        let mut tx = self.begin().await?;
        if let Some(row) = Self::query(
            "SELECT name,type FROM search_attribute_definitions WHERE namespace=$1 AND name=$2",
        )
        .bind(&*self.namespace)
        .bind(name)
        .fetch_optional(&mut *tx)
        .await?
        {
            return Err(self.already_exists(name, &row)?);
        }
        let count =
            Self::scalar("SELECT COUNT(*) FROM search_attribute_definitions WHERE namespace=$1")
                .bind(&*self.namespace)
                .fetch_one(&mut *tx)
                .await?;
        if count >= self.search_attribute_limit {
            let limit = self.search_attribute_limit;
            return Err(conflict(
                StatusCode::UNPROCESSABLE_ENTITY,
                json!({"message":format!("Namespace [{}] has reached the maximum of {limit} custom search attributes.", self.namespace),
                    "reason":"search_attribute_limit_reached","limit":limit}),
            ));
        }
        let timestamp = now();
        let inserted = Self::query("INSERT INTO search_attribute_definitions(namespace,name,type,created_at,updated_at) VALUES ($1,$2,$3,$4,$5)")
            .bind(&*self.namespace).bind(name).bind(kind)
            .bind(DB::bind_time(timestamp)).bind(DB::bind_time(timestamp))
            .execute(&mut *tx)
            .await;
        match inserted {
            Ok(_) => tx.commit().await?,
            // A concurrent registration of the same name committed first.
            // PHP surfaces the unique-key failure as a server error; refuse
            // it the same way as a duplicate found before the insert.
            Err(sqlx::Error::Database(error)) if error.is_unique_violation() => {
                drop(tx);
                return Err(self.existing_attribute_conflict(name).await?);
            }
            Err(error) => return Err(error.into()),
        }
        Ok(json!({"name":name,"type":kind,"outcome":"created"}))
    }

    pub(super) async fn delete_search_attribute(&self, name: &str) -> Result<Value> {
        let deleted =
            Self::query("DELETE FROM search_attribute_definitions WHERE namespace=$1 AND name=$2")
                .bind(&*self.namespace)
                .bind(name)
                .execute(&self.pool)
                .await?;
        if DB::affected(deleted) > 0 {
            return Ok(json!({"name":name,"outcome":"deleted"}));
        }
        if SYSTEM_ATTRIBUTES.iter().any(|(system, _)| *system == name) {
            return Err(conflict(
                StatusCode::CONFLICT,
                json!({"message":format!("The system search attribute [{name}] cannot be removed."),
                    "reason":"system_attribute"}),
            ));
        }
        Err(conflict(
            StatusCode::NOT_FOUND,
            json!({"message":format!("Custom search attribute [{name}] not found in namespace [{}].", self.namespace),
                "reason":"attribute_not_found"}),
        ))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn messages(body: Value) -> Option<Value> {
        match validated(&body) {
            Ok(_) => None,
            Err(RuntimeError::Protocol { response, .. }) => Some(response),
            Err(_) => unreachable!(),
        }
    }

    #[test]
    fn validation_messages_follow_laravel_rules() {
        assert_eq!(messages(json!({"name":"orderTotal","type":"double"})), None);
        assert_eq!(
            messages(json!({"name":"  orderTotal\n","type":" int "})),
            None
        );
        let missing = messages(json!({})).unwrap();
        assert_eq!(
            missing["message"],
            "The name field is required. (and 1 more error)"
        );
        assert_eq!(
            missing["errors"],
            json!({"name":["The name field is required."],"type":["The type field is required."]})
        );
        assert_eq!(missing["validation_errors"], missing["errors"]);
        assert_eq!(
            messages(json!({"name":"   ","type":"int"})).unwrap()["errors"],
            json!({"name":["The name field is required."]})
        );
        assert_eq!(
            messages(json!({"name":[],"type":"int"})).unwrap()["errors"],
            json!({"name":["The name field is required."]})
        );
        let numeric = messages(json!({"name":7,"type":5})).unwrap();
        assert_eq!(
            numeric["message"],
            "The name field must be a string. (and 3 more errors)"
        );
        assert_eq!(
            numeric["errors"],
            json!({
            "name":["The name field must be a string.","The name field format is invalid."],
            "type":["The type field must be a string.","The selected type is invalid."]})
        );
        assert_eq!(
            messages(json!({"name":"1st","type":"uuid"})).unwrap()["errors"],
            json!({"name":["The name field format is invalid."],"type":["The selected type is invalid."]})
        );
        let long = "a".repeat(129);
        assert_eq!(
            messages(json!({"name":long,"type":"int"})).unwrap()["message"],
            "The name field must not be greater than 128 characters."
        );
        assert_eq!(messages(json!({"name":"a".repeat(128),"type":"int"})), None);
        assert_eq!(
            messages(json!({"name":"has-dash","type":"keyword_list"})).unwrap()["message"],
            "The name field format is invalid."
        );
    }

    #[test]
    fn reserved_names_cover_system_attributes_and_aliases() {
        for name in ["WorkflowType", "BuildIds", "workflow_ID", "Wf_Id", "status"] {
            assert!(reserved(name), "{name}");
        }
        for name in ["workflowType", "OrderTotal", "Statuses"] {
            assert!(!reserved(name), "{name}");
        }
    }
}
