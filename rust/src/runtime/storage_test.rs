//! The external payload storage diagnostic, as PHP's StorageController::test
//! runs it: a generated small and large payload written to, read from and
//! deleted from the namespace's configured driver. Native serves the local
//! driver; other drivers are refused as unavailable in this runtime.
use super::{
    Result, Runtime, RuntimeError,
    laravel_validation::{failure, normalized, present},
    store::id,
};
use crate::external_payload::{REFERENCE_SCHEMA, Reference};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sha2::{Digest, Sha256};

const DRIVERS: [&str; 5] = ["local", "s3", "gcs", "azure", "custom"];

/// One `required|integer|min:1|max:N` field and its Laravel messages.
struct Bytes {
    required: &'static str,
    integer: &'static str,
    min: &'static str,
    max: &'static str,
    limit: i64,
}

const SMALL: Bytes = Bytes {
    required: "The small payload bytes field is required.",
    integer: "The small payload bytes field must be an integer.",
    min: "The small payload bytes field must be at least 1.",
    max: "The small payload bytes field must not be greater than 1048576.",
    limit: 1_048_576,
};

const LARGE: Bytes = Bytes {
    required: "The large payload bytes field is required.",
    integer: "The large payload bytes field must be an integer.",
    min: "The large payload bytes field must be at least 1.",
    max: "The large payload bytes field must not be greater than 16777216.",
    limit: 16_777_216,
};

/// PHP's `filter_var($value, FILTER_VALIDATE_INT)`, which Laravel's
/// `integer` rule uses: canonical decimal strings, integral numbers and true.
fn integer(value: &Value) -> Option<i64> {
    match value {
        Value::Number(number) => number.as_i64().or_else(|| {
            number
                .as_f64()
                .filter(|float| float.fract() == 0.0 && float.abs() < 9.0e18)
                .map(|float| float as i64)
        }),
        Value::String(text) => {
            let digits = text.strip_prefix(['+', '-']).unwrap_or(text);
            let canonical = digits == "0"
                || !digits.is_empty()
                    && !digits.starts_with('0')
                    && digits.bytes().all(|byte| byte.is_ascii_digit());
            canonical.then(|| text.parse().ok()).flatten()
        }
        Value::Bool(true) => Some(1),
        _ => None,
    }
}

/// Laravel's size under a numeric rule set: a numeric value's magnitude,
/// otherwise a string's length or an array's count.
fn size(value: &Value) -> f64 {
    match value {
        Value::Number(number) => number.as_f64().unwrap_or_default(),
        Value::String(text) => {
            let numeric = text
                .bytes()
                .all(|byte| byte.is_ascii_digit() || b"+-.eE".contains(&byte))
                && text.bytes().any(|byte| byte.is_ascii_digit());
            numeric
                .then(|| text.parse::<f64>().ok())
                .flatten()
                .unwrap_or(text.chars().count() as f64)
        }
        Value::Array(items) => items.len() as f64,
        Value::Object(items) => items.len() as f64,
        Value::Bool(true) => 1.0,
        Value::Bool(false) | Value::Null => 0.0,
    }
}

/// A failed `required` stops the field's remaining rules.
fn bytes_messages(value: Option<&Value>, rules: &Bytes) -> Vec<&'static str> {
    let Some(value) = value.filter(|value| present(Some(value))) else {
        return vec![rules.required];
    };
    let mut messages = Vec::new();
    if integer(value).is_none() {
        messages.push(rules.integer);
    }
    let size = size(value);
    if size < 1.0 {
        messages.push(rules.min);
    }
    if size > rules.limit as f64 {
        messages.push(rules.max);
    }
    messages
}

/// `nullable|string|in:local,s3,gcs,azure,custom`.
fn driver_messages(value: Option<&Value>) -> Vec<&'static str> {
    match value {
        None | Some(Value::Null) => Vec::new(),
        Some(Value::String(driver)) if DRIVERS.contains(&driver.as_str()) => Vec::new(),
        Some(Value::String(_)) => vec!["The selected driver is invalid."],
        Some(_) => vec![
            "The driver field must be a string.",
            "The selected driver is invalid.",
        ],
    }
}

fn diagnostic(
    reason: &str,
    message: &str,
    namespace: &str,
    driver: Option<&str>,
    extra: Value,
) -> RuntimeError {
    let mut response = json!({"status":"failed","reason":reason,"message":message,
        "namespace":namespace,"driver":driver});
    if let (Some(response), Value::Object(extra)) = (response.as_object_mut(), extra) {
        response.extend(extra);
    }
    RuntimeError::Protocol {
        status: StatusCode::UNPROCESSABLE_ENTITY,
        response,
    }
}

fn hex(digest: impl AsRef<[u8]>) -> String {
    digest
        .as_ref()
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

async fn round_trip(
    runtime: &Runtime,
    local: &crate::external_payload::LocalStorage,
    fill: u8,
    bytes: i64,
) -> Result<Value> {
    let payload = vec![fill; usize::try_from(bytes).unwrap_or_default()];
    let sha256 = hex(Sha256::digest(&payload));
    let reference = Reference {
        schema: REFERENCE_SCHEMA,
        reference_id: format!("ep_{}", id()),
        codec: "avro",
        size_bytes: bytes,
        sha256: sha256.clone(),
    };
    let uri = local
        .uri_for(&reference)
        .map_err(super::external_transport::snapshot_error)?;
    runtime
        .storage_test_round_trip(
            local,
            reference,
            payload,
            runtime.external.max_bytes,
            runtime.external.workers.clone(),
        )
        .await?;
    Ok(json!({"status":"passed","bytes":bytes,"sha256":sha256,
        "reference_identity_sha256":hex(Sha256::digest(uri.as_bytes()))}))
}

pub(super) async fn storage_test(runtime: &Runtime, body: Value) -> Result<Value> {
    let requested = normalized(body.get("driver"));
    let small = normalized(body.get("small_payload_bytes"));
    let large = normalized(body.get("large_payload_bytes"));
    if let Some(error) = failure(vec![
        ("driver", driver_messages(requested.as_ref())),
        (
            "small_payload_bytes",
            bytes_messages(small.as_ref(), &SMALL),
        ),
        (
            "large_payload_bytes",
            bytes_messages(large.as_ref(), &LARGE),
        ),
    ]) {
        return Err(error);
    }
    let namespace = runtime.namespace().to_owned();
    let metadata = runtime.describe_namespace(&namespace).await?;
    let policy = &metadata["external_payload_storage"];
    let configured = policy["driver"].as_str();
    let Some(driver) = requested
        .as_ref()
        .and_then(Value::as_str)
        .or(configured)
        .filter(|driver| !driver.is_empty())
    else {
        return Err(diagnostic(
            "external_storage_not_configured",
            "External payload storage is not configured for this namespace.",
            &namespace,
            None,
            json!({}),
        ));
    };
    if policy["enabled"] == false {
        return Err(diagnostic(
            "external_storage_disabled",
            "External payload storage is disabled for this namespace.",
            &namespace,
            Some(driver),
            json!({}),
        ));
    }
    if Some(driver) != configured {
        return Err(diagnostic(
            "storage_driver_unavailable",
            "The requested external payload storage driver is not configured for this namespace.",
            &namespace,
            Some(driver),
            json!({"supported_diagnostic_drivers":["local"]}),
        ));
    }
    let unavailable = || {
        diagnostic(
            "storage_driver_unavailable",
            "The server can persist this storage policy, but the configured storage driver is not available in this runtime.",
            &namespace,
            Some(driver),
            json!({"configuration_error":null,"supported_diagnostic_drivers":DRIVERS}),
        )
    };
    if driver != "local" {
        return Err(unavailable());
    }
    let local = super::external_transport::local(runtime)
        .await
        .map_err(|_| unavailable())?;
    // Validation accepted both sizes as integers.
    let size = |value: &Option<Value>| value.as_ref().and_then(integer).unwrap_or_default();
    let small = round_trip(runtime, &local, b's', size(&small)).await?;
    let large = round_trip(runtime, &local, b'l', size(&large)).await?;
    Ok(
        json!({"status":"passed","namespace":namespace,"driver":driver,
        "small_payload":small,"large_payload":large}),
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    fn messages(body: Value) -> Option<Value> {
        let errors = failure(vec![
            (
                "driver",
                driver_messages(normalized(body.get("driver")).as_ref()),
            ),
            (
                "small_payload_bytes",
                bytes_messages(normalized(body.get("small_payload_bytes")).as_ref(), &SMALL),
            ),
            (
                "large_payload_bytes",
                bytes_messages(normalized(body.get("large_payload_bytes")).as_ref(), &LARGE),
            ),
        ])?;
        match errors {
            RuntimeError::Protocol { response, .. } => Some(response),
            _ => unreachable!(),
        }
    }

    #[test]
    fn validation_follows_laravel_rules() {
        assert_eq!(
            messages(json!({"small_payload_bytes":1,"large_payload_bytes":16777216})),
            None
        );
        assert_eq!(
            messages(json!({"driver":"","small_payload_bytes":" 7 ","large_payload_bytes":9.0})),
            None
        );
        let missing = messages(json!({})).unwrap();
        assert_eq!(
            missing["message"],
            "The small payload bytes field is required. (and 1 more error)"
        );
        assert_eq!(
            missing["errors"],
            json!({"small_payload_bytes":["The small payload bytes field is required."],
            "large_payload_bytes":["The large payload bytes field is required."]})
        );
        assert_eq!(
            messages(
                json!({"driver":"ftp","small_payload_bytes":0,"large_payload_bytes":16777217})
            )
            .unwrap()["errors"],
            json!({"driver":["The selected driver is invalid."],
                "small_payload_bytes":["The small payload bytes field must be at least 1."],
                "large_payload_bytes":["The large payload bytes field must not be greater than 16777216."]})
        );
        assert_eq!(
            messages(json!({"driver":5,"small_payload_bytes":"abc","large_payload_bytes":1.5}))
                .unwrap()["errors"],
            json!({"driver":["The driver field must be a string.","The selected driver is invalid."],
                "small_payload_bytes":["The small payload bytes field must be an integer."],
                "large_payload_bytes":["The large payload bytes field must be an integer."]})
        );
        assert_eq!(
            messages(json!({"small_payload_bytes":"05","large_payload_bytes":1})).unwrap()["errors"],
            json!({"small_payload_bytes":["The small payload bytes field must be an integer."]})
        );
    }
}
