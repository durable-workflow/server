use super::{Result, refuse};
use axum::http::StatusCode;
use serde_json::Value;
use std::{env, sync::Arc, time::Duration};
use tokio::sync::Semaphore;

#[derive(Clone)]
pub struct ExternalPayloadConfig {
    pub max_bytes: u64,
    pub completion_max_bytes: Option<u64>,
    pub timeout: Duration,
    pub abandoned_seconds: i64,
    pub max_bytes_per_namespace: Value,
    pub max_objects_per_namespace: Value,
    pub hard_max_bytes_per_namespace: Value,
    pub hard_max_objects_per_namespace: Value,
    pub namespace_overrides: Value,
    pub(super) workers: Arc<Semaphore>,
}

#[derive(Clone, Copy, Default)]
pub(crate) struct Quota {
    pub(super) bytes: Option<i64>,
    pub(super) objects: Option<i64>,
}

impl Default for ExternalPayloadConfig {
    fn default() -> Self {
        Self {
            max_bytes: 64 * 1024 * 1024,
            completion_max_bytes: None,
            timeout: Duration::from_secs(30),
            abandoned_seconds: 3600,
            max_bytes_per_namespace: Value::Null,
            max_objects_per_namespace: Value::Null,
            hard_max_bytes_per_namespace: Value::Null,
            hard_max_objects_per_namespace: Value::Null,
            namespace_overrides: serde_json::json!({}),
            workers: Arc::new(Semaphore::new(1)),
        }
    }
}

fn configuration_error() -> super::RuntimeError {
    refuse(
        StatusCode::SERVICE_UNAVAILABLE,
        "external_payload_namespace_quota_unavailable",
    )
}

fn normalized(raw: &str) -> Value {
    match raw.to_ascii_lowercase().as_str() {
        "null" | "(null)" => Value::Null,
        "true" | "(true)" => Value::Bool(true),
        "false" | "(false)" => Value::Bool(false),
        "empty" | "(empty)" => Value::String(String::new()),
        _ => Value::String(
            raw.strip_prefix('"')
                .and_then(|value| value.strip_suffix('"'))
                .or_else(|| {
                    raw.strip_prefix('\'')
                        .and_then(|value| value.strip_suffix('\''))
                })
                .unwrap_or(raw)
                .to_owned(),
        ),
    }
}

fn process_value(suffix: &str, default: Value) -> Result<Value> {
    let read = |key: String| match env::var(key) {
        Ok(value) => Ok(Some(normalized(&value))),
        Err(env::VarError::NotPresent) => Ok(None),
        Err(env::VarError::NotUnicode(_)) => Err(configuration_error()),
    };
    let primary = read(format!("DW_EXTERNAL_PAYLOAD_{suffix}"))?;
    if let Some(value) = primary.filter(|value| !value.is_null()) {
        return Ok(value);
    }
    Ok(read(format!("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_{suffix}"))?.unwrap_or(default))
}

fn integer(value: &Value) -> i64 {
    match value {
        Value::Null => 0,
        Value::Bool(value) => i64::from(*value),
        Value::String(value) => super::memo_limits::cast_numeric_string(value),
        Value::Number(value) => value.as_i64().unwrap_or(0),
        _ => 0,
    }
}

fn limit(value: &Value) -> Result<Option<i64>> {
    if value.is_null() || value == "" {
        return Ok(None);
    }
    if let Some(number) = value.as_i64().filter(|number| *number >= 0) {
        return Ok(Some(number));
    }
    if let Some(value) = value.as_str()
        && (value == "0" || !value.starts_with('0') && !value.is_empty())
        && value.bytes().all(|byte| byte.is_ascii_digit())
        && let Ok(number) = value.parse::<i64>()
    {
        return Ok(Some(number));
    }
    Err(configuration_error())
}

fn member<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
    match value {
        Value::Object(map) => map.get(key),
        Value::Array(items) => key
            .parse::<usize>()
            .ok()
            .filter(|index| index.to_string() == key)
            .and_then(|index| items.get(index)),
        _ => None,
    }
}

impl ExternalPayloadConfig {
    pub(super) fn from_env() -> Result<Self> {
        let completion = process_value("COMPLETION_MAX_BYTES", Value::Null)?;
        let overrides = process_value("NAMESPACE_OVERRIDES", Value::String("{}".into()))?;
        let overrides = overrides
            .as_str()
            .and_then(|value| serde_json::from_str(value).ok())
            .unwrap_or(Value::Null);
        Ok(Self {
            completion_max_bytes: (!completion.is_null())
                .then(|| integer(&completion).max(1) as u64),
            max_bytes: integer(&process_value(
                "MAX_BYTES",
                serde_json::json!(64 * 1024 * 1024),
            )?)
            .max(1) as u64,
            timeout: Duration::from_secs(
                integer(&process_value("REQUEST_TIMEOUT", serde_json::json!(30))?).max(1) as u64,
            ),
            abandoned_seconds: integer(&process_value("UPLOAD_EXPIRY", serde_json::json!(3600))?)
                .max(1),
            max_bytes_per_namespace: process_value("MAX_BYTES_PER_NAMESPACE", Value::Null)?,
            max_objects_per_namespace: process_value("MAX_OBJECTS_PER_NAMESPACE", Value::Null)?,
            hard_max_bytes_per_namespace: process_value(
                "HARD_MAX_BYTES_PER_NAMESPACE",
                Value::Null,
            )?,
            hard_max_objects_per_namespace: process_value(
                "HARD_MAX_OBJECTS_PER_NAMESPACE",
                Value::Null,
            )?,
            namespace_overrides: overrides,
            ..Self::default()
        })
    }

    pub(super) fn quota(&self, namespace: &str) -> Result<Quota> {
        if !self.namespace_overrides.is_object() && !self.namespace_overrides.is_array() {
            return Err(configuration_error());
        }
        let selected = member(&self.namespace_overrides, namespace);
        if selected.is_some_and(|value| !value.is_object() && !value.is_array()) {
            return Err(configuration_error());
        }
        let effective =
            |field: &str, default: &Value, hard: &Value, absolute: i64| -> Result<Option<i64>> {
                let default = limit(default)?;
                let hard = limit(hard)?;
                let selected = selected
                    .and_then(|value| member(value, field))
                    .map(limit)
                    .transpose()?
                    .flatten();
                Ok(selected
                    .or(default)
                    .into_iter()
                    .chain(hard)
                    .min()
                    .map(|value| value.min(absolute)))
            };
        Ok(Quota {
            bytes: effective(
                "max_bytes",
                &self.max_bytes_per_namespace,
                &self.hard_max_bytes_per_namespace,
                1_125_899_906_842_624,
            )?,
            objects: effective(
                "max_objects",
                &self.max_objects_per_namespace,
                &self.hard_max_objects_per_namespace,
                100_000_000,
            )?,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn quotas_keep_strict_php_types_default_override_and_hard_ceiling() {
        for value in [
            json!(null),
            json!(""),
            json!(0),
            json!("0"),
            json!(7),
            json!("17"),
        ] {
            assert!(limit(&value).is_ok());
        }
        for value in [
            json!(false),
            json!(true),
            json!(-1),
            json!(1.0),
            json!("01"),
            json!(" 1"),
            json!("+1"),
            json!("1.0"),
            json!("9223372036854775808"),
        ] {
            assert!(limit(&value).is_err(), "{value}");
        }
        let config = ExternalPayloadConfig {
            max_bytes_per_namespace: json!(10),
            hard_max_bytes_per_namespace: json!(8),
            max_objects_per_namespace: json!(3),
            namespace_overrides: json!({"other":{"max_bytes":5,"max_objects":null}}),
            ..Default::default()
        };
        assert_eq!(config.quota("default").unwrap().bytes, Some(8));
        assert_eq!(config.quota("other").unwrap().bytes, Some(5));
        assert_eq!(config.quota("other").unwrap().objects, Some(3));
        let config = ExternalPayloadConfig {
            namespace_overrides: json!({"other":null}),
            ..config
        };
        assert!(config.quota("other").is_err());
        assert!(config.quota("default").is_ok());
    }
}
