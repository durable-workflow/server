//! Bounded operator reads keep an original history window and never repair state.
use super::{Result, Runtime, RuntimeError, control_plane::Operation};
use axum::{
    Extension, Json,
    extract::{Path, RawQuery},
    http::StatusCode,
};
use base64::{Engine, engine::general_purpose::URL_SAFE_NO_PAD};
use hmac::{Hmac, KeyInit, Mac};
use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use sha2::Sha256;
use std::collections::{BTreeMap, BTreeSet};

pub(crate) struct Options {
    pub keys: Vec<String>,
    pub limit: i64,
    pub cursor: Option<String>,
}

pub(super) fn validation(field: &str, message: &str) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::UNPROCESSABLE_ENTITY,
        response: Operation::Observe
            .response(json!({"reason":"validation_failed", "message":message,
            "errors":{field:[message]}, "validation_errors":{field:[message]}})),
    }
}

impl Options {
    fn parse(raw: Option<&str>) -> Result<Self> {
        let mut result = Self {
            keys: vec![],
            limit: 200,
            cursor: None,
        };
        for (key, value) in form_urlencoded::parse(raw.unwrap_or_default().as_bytes()) {
            match key.as_ref() {
                "history_page_size" if !value.trim().is_empty() => {
                    result.limit = value
                        .trim()
                        .parse()
                        .ok()
                        .filter(|n| (1..=1000).contains(n))
                        .ok_or_else(|| {
                            validation(
                                "history_page_size",
                                "History page size must be an integer from 1 to 1000.",
                            )
                        })?;
                }
                "history_page_token" if !value.is_empty() => {
                    if value.len() > 4096 {
                        return Err(validation(
                            "history_page_token",
                            "History cursor exceeds its size limit.",
                        ));
                    }
                    result.cursor = Some(value.into_owned());
                }
                "search_attribute_keys" if !value.is_empty() => {
                    return Err(validation(
                        "search_attribute_keys",
                        "Search attribute keys must be an array.",
                    ));
                }
                _ if key.starts_with("search_attribute_keys[") && key.ends_with(']') => {
                    if key[22..key.len() - 1].contains(['[', ']']) {
                        return Err(validation(
                            "search_attribute_keys",
                            "Search attribute keys must be strings.",
                        ));
                    }
                    let value = value.trim().to_owned();
                    if value.is_empty()
                        || value.chars().count() > 255
                        || result.keys.contains(&value)
                        || result.keys.len() >= 20
                    {
                        return Err(validation(
                            "search_attribute_keys",
                            "Select at most 20 distinct nonempty attribute keys of at most 255 characters.",
                        ));
                    }
                    result.keys.push(value);
                }
                _ if key.starts_with("history_page_size[")
                    || key.starts_with("history_page_token[") =>
                {
                    return Err(validation(
                        key.split('[').next().unwrap(),
                        "History page options must be scalar values.",
                    ));
                }
                _ => {}
            }
        }
        Ok(result)
    }
}

pub(super) async fn current(
    Extension(runtime): Extension<Runtime>,
    Path(workflow): Path<String>,
    RawQuery(query): RawQuery,
) -> Result<Json<Value>> {
    read(runtime, workflow, None, query).await
}

pub(super) async fn selected(
    Extension(runtime): Extension<Runtime>,
    Path((workflow, run)): Path<(String, String)>,
    RawQuery(query): RawQuery,
) -> Result<Json<Value>> {
    read(runtime, workflow, Some(run), query).await
}

async fn read(
    runtime: Runtime,
    workflow: String,
    run: Option<String>,
    query: Option<String>,
) -> Result<Json<Value>> {
    let options = Options::parse(query.as_deref())?;
    runtime
        .run_observation(&workflow, run.as_deref(), &options, &runtime.token)
        .await
        .map(|body| Json(Operation::Observe.response(body)))
}

#[derive(Serialize, Deserialize)]
pub(super) struct Window {
    version: u8,
    namespace: String,
    workflow_id: String,
    run_id: String,
    pub after: i64,
    pub through: i64,
}

impl Window {
    pub fn new(namespace: &str, workflow: &str, run: &str, after: i64, through: i64) -> Self {
        Self {
            version: 1,
            namespace: namespace.into(),
            workflow_id: workflow.into(),
            run_id: run.into(),
            after,
            through,
        }
    }
    fn mac(key: &str, body: &[u8]) -> Hmac<Sha256> {
        let mut mac =
            Hmac::<Sha256>::new_from_slice(key.as_bytes()).expect("HMAC accepts any key length");
        mac.update(b"durable-workflow/run-observation/v1\0");
        mac.update(body);
        mac
    }
    pub fn encode(&self, key: &str) -> Result<String> {
        let body = serde_json::to_vec(self)?;
        Ok(format!(
            "{}.{}",
            URL_SAFE_NO_PAD.encode(&body),
            URL_SAFE_NO_PAD.encode(Self::mac(key, &body).finalize().into_bytes())
        ))
    }
    pub fn decode(
        token: &str,
        key: &str,
        namespace: &str,
        workflow: &str,
        run: &str,
    ) -> Result<Self> {
        let invalid = || {
            validation(
                "history_page_token",
                "Invalid observation history cursor for this run.",
            )
        };
        let (body, tag) = token.split_once('.').ok_or_else(invalid)?;
        let body = URL_SAFE_NO_PAD.decode(body).map_err(|_| invalid())?;
        let tag = URL_SAFE_NO_PAD.decode(tag).map_err(|_| invalid())?;
        Self::mac(key, &body)
            .verify_slice(&tag)
            .map_err(|_| invalid())?;
        let window: Self = serde_json::from_slice(&body).map_err(|_| invalid())?;
        if window.version != 1
            || window.namespace != namespace
            || window.workflow_id != workflow
            || window.run_id != run
            || window.after < 0
            || window.through < window.after
        {
            return Err(invalid());
        }
        Ok(window)
    }
}

fn php_array(value: &Value) -> Option<Vec<&Value>> {
    match value {
        Value::Array(values) => Some(values.iter().collect()),
        Value::Object(values) => Some(values.values().collect()),
        _ => None,
    }
}

/// Project retained PHP declaration fields. This read cannot backfill PHP classes.
pub(super) fn command_contract(started: Option<&Value>) -> Value {
    let mut result = json!({});
    let mut strict = started.is_some();
    for (names, handlers, targets) in [
        ("queries", "query_contracts", "query_targets"),
        ("signals", "signal_contracts", "signal_targets"),
        ("updates", "update_contracts", "update_targets"),
    ] {
        let value = started.and_then(|s| s.get(format!("declared_{names}")));
        let values = value.and_then(php_array);
        strict &= values.is_some();
        let names_set: BTreeSet<String> = values
            .into_iter()
            .flatten()
            .filter_map(Value::as_str)
            .filter(|v| !v.is_empty())
            .map(str::to_owned)
            .collect();
        let value = started.and_then(|s| s.get(format!("declared_{handlers}")));
        let valid = value.and_then(php_array).is_some_and(|values| {
            values.iter().all(|v| {
                v["name"].is_string()
                    && v.get("parameters")
                        .is_none_or(|p| p.is_null() || p.is_array() || p.is_object())
            })
        });
        strict &= valid;
        let mut contracts: Vec<Value> = if valid {
            php_array(value.unwrap())
                .unwrap()
                .into_iter()
                .map(|v| {
                    let parameters: Vec<Value> = match v.get("parameters") {
                        Some(Value::Array(values)) => values
                            .iter()
                            .filter(|p| p.is_object() || p.is_array())
                            .cloned()
                            .collect(),
                        Some(Value::Object(values)) => values
                            .values()
                            .filter(|p| p.is_object() || p.is_array())
                            .cloned()
                            .collect(),
                        _ => vec![],
                    };
                    json!({"name":v["name"],"parameters":parameters})
                })
                .collect()
        } else {
            vec![]
        };
        contracts.sort_by(|a, b| a["name"].as_str().cmp(&b["name"].as_str()));
        if names != "signals" {
            strict &= names_set
                .iter()
                .all(|name| contracts.iter().any(|c| c["name"] == *name));
        }
        let mut target_map: BTreeMap<String, Value> = names_set
            .iter()
            .map(|name| {
                (
                    name.clone(),
                    json!({"name":name,"parameters":[],"has_contract":false}),
                )
            })
            .collect();
        for contract in &contracts {
            target_map.insert(contract["name"].as_str().unwrap().into(), json!({"name":contract["name"],"parameters":contract["parameters"],"has_contract":true}));
        }
        result[names] = json!(names_set);
        result[handlers] = json!(contracts);
        result[targets] = json!(target_map.into_values().collect::<Vec<_>>());
    }
    for (field, expected) in [
        ("entry_method", Some("handle")),
        ("entry_mode", Some("canonical")),
        ("entry_declaring_class", None),
    ] {
        let value = started
            .and_then(|s| s.get(format!("declared_{field}")))
            .and_then(Value::as_str)
            .filter(|s| !s.is_empty() && expected.is_none_or(|e| e == *s));
        strict &= value.is_some();
        result[field] = json!(value);
    }
    result["source"] = json!(if strict {
        "durable_history"
    } else {
        "unavailable"
    });
    result["backfill_needed"] = json!(started.is_some() && !strict);
    result["backfill_available"] = json!(false);
    result
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn retained_contract_requires_complete_entry_and_query_update_declarations() {
        // Retained PHP arrays may serialize as objects when their keys are not
        // consecutive. Signal names do not require parameter contracts.
        let mut started = json!({"declared_queries":{"first":"state"},
            "declared_query_contracts":{"entry":{"name":"state","parameters":null}},
            "declared_signals":["wake","wake"],"declared_signal_contracts":[],
            "declared_updates":["set"],"declared_update_contracts":[{"name":"set","parameters":[{"name":"value","required":true}]}],
            "declared_entry_method":"handle","declared_entry_mode":"canonical","declared_entry_declaring_class":"ExampleWorkflow"});
        let complete = command_contract(Some(&started));
        assert_eq!(complete["source"], "durable_history");
        assert_eq!(complete["backfill_needed"], false);
        assert_eq!(
            complete["query_targets"],
            json!([{"name":"state","parameters":[],"has_contract":true}])
        );
        assert_eq!(
            complete["signal_targets"],
            json!([{"name":"wake","parameters":[],"has_contract":false}])
        );
        started["declared_update_contracts"] = json!([]);
        let missing = command_contract(Some(&started));
        assert_eq!(missing["source"], "unavailable");
        assert_eq!(missing["backfill_needed"], true);
        assert_eq!(missing["backfill_available"], false);
        assert_eq!(
            missing["update_targets"],
            json!([{"name":"set","parameters":[],"has_contract":false}])
        );
        assert_eq!(command_contract(None)["backfill_needed"], false);
        assert_eq!(
            command_contract(Some(&Value::Null))["backfill_needed"],
            true
        );
    }
}
