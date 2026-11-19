//! `signal_external_workflow` (Server #1134, .github#223): workflow code
//! signals another workflow in its namespace as a durable command. The
//! completion that carries it records `ExternalSignalRequested`, admits the
//! signal on the target exactly as an HTTP signal is admitted, and records
//! the outcome as `ExternalSignalDelivered` or `ExternalSignalFailed` at the
//! same sequence, then gives the sender one turn to read it
//! (docs/contracts/signal-external-workflow.md).
use super::store::{PreparedControlArguments, Store, prepare_control_arguments, reject_fields};
use super::transaction::Transaction;
use super::{Result, RuntimeError, backend::Backend, envelope, refuse, text, wire};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::sync::Arc;
use tokio::sync::Semaphore;

/// The worker command type.
pub(super) const COMMAND: &str = "signal_external_workflow";
/// Advertised in cluster `capabilities` and worker `server_capabilities`.
pub(super) const CAPABILITY: &str = "signal_external_workflow";
/// A workflow instance id is stored in varchar(191) columns, and the
/// command field rules bound a signal name to the same.
const MAX_ID_CHARS: usize = 191;

/// The command's own field rules, before the batch applies.
pub(super) fn validate(command: &Value) -> Result<()> {
    reject_fields(command, &["type", "workflow_id", "run_id", "signal_name", "arguments"])?;
    let id = |value: &Value| value.as_str().is_some_and(|id| !id.trim().is_empty() && id.chars().count() <= MAX_ID_CHARS);
    if !id(&command["workflow_id"]) {
        return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_signal_external_target"));
    }
    if !command["run_id"].is_null() && !id(&command["run_id"]) {
        return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_signal_external_target"));
    }
    // An HTTP signal's name rules (`operation_name`: not empty, at most 255
    // bytes, no control characters), then not blank and at most 191
    // characters, as the contract states.
    let Some(name) = command["signal_name"].as_str() else {
        return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_signal_name"));
    };
    super::laravel_validation::operation_name(name, "signal")?;
    if name.trim().is_empty() || name.chars().count() > MAX_ID_CHARS {
        return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_signal_name"));
    }
    envelope(command, "arguments")?;
    Ok(())
}

/// The caller key a delivery reserves on its target instance (Server #934):
/// one per sending run and command sequence.
pub(super) fn command_key(run_id: &str, sequence: i64) -> String {
    format!("{}{run_id}:{sequence}", super::command_intent::WORKFLOW_SIGNAL_KEY_PREFIX)
}

/// The recorded outcome of one signal-external command.
struct Resolution {
    outcome: &'static str,
    reason: Option<String>,
    message: Option<String>,
    target_run: Option<String>,
    target_command: Option<String>,
    target_signal: Option<String>,
    retained: bool,
}

impl Resolution {
    fn failed(outcome: &'static str, reason: &str, message: String) -> Self {
        Self { outcome, reason: Some(reason.to_owned()), message: Some(message), target_run: None, target_command: None,
            target_signal: None, retained: false }
    }
}

fn text_of(value: &Value) -> Option<String> {
    value.as_str().filter(|text| !text.is_empty()).map(str::to_owned)
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
    /// Applies one `signal_external_workflow` command at `sequence` and
    /// returns the sender's turn payload.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn signal_external(
        &self,
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        task_id: &str,
        run_id: &str,
        command: &Value,
        sequence: i64,
        codec: Arc<Semaphore>,
        limits: super::memo_limits::StructuralLimits,
    ) -> Result<Value> {
        let target = text(command, "workflow_id")?;
        let requested_run = command["run_id"].as_str();
        let name = text(command, "signal_name")?;
        let arguments = envelope(command, "arguments")?;
        let sender = DB::string(run, "workflow_instance_id")?;
        let key = command_key(run_id, sequence);
        // The arguments must be a list (or a non-empty map) of values, as a
        // signal request's are; anything else refuses the completion.
        let mut prepared = prepare_control_arguments(codec.clone(), name.to_owned(), arguments.clone(), "invalid_signal_arguments", true)
            .await?;
        Self::append(tx, run_id, "ExternalSignalRequested", json!({"sequence": sequence,
            "target_workflow_instance_id": target, "target_workflow_run_id": requested_run, "signal_name": name,
            "arguments": wire(&arguments), "payload_codec": "avro", "request_id": key}), Some(task_id), None).await?;
        let resolution = if target == sender {
            Resolution::failed("rejected", "self_target",
                "A workflow cannot signal its own instance with signal_external_workflow.".into())
        } else {
            // `CommandContext::workflow`: the sending run and its sequence.
            let context = json!({"caller": {"type": "workflow", "label": "Workflow"},
                "auth": {"status": "not_applicable", "method": "none"},
                "workflow": {"parent_instance_id": sender, "parent_run_id": run_id, "sequence": sequence}});
            self.deliver_external(tx, target, requested_run, name, &mut prepared, codec, limits, &context, &key).await?
        };
        let mut payload = json!({"sequence": sequence, "target_workflow_instance_id": target,
            "target_workflow_run_id": resolution.target_run, "signal_name": name, "request_id": key,
            "outcome": resolution.outcome});
        let event = if resolution.outcome == "delivered" {
            payload["target_command_id"] = json!(resolution.target_command);
            payload["target_signal_id"] = json!(resolution.target_signal);
            payload["retained"] = json!(resolution.retained);
            "ExternalSignalDelivered"
        } else {
            payload["reason"] = json!(resolution.reason);
            payload["message"] = json!(resolution.message);
            payload["target_command_id"] = json!(resolution.target_command);
            "ExternalSignalFailed"
        };
        Self::append(tx, run_id, event, payload, Some(task_id), None).await?;
        Ok(json!({"workflow_wait_kind": "external_signal", "resume_source_kind": "external_signal",
            "resume_source_id": key, "workflow_sequence": sequence, "workflow_event_type": event}))
    }

    /// The target's admission, as an HTTP signal's (`Store::signal`): the
    /// instance, the command key's receipt, the run gate and the reserved
    /// name, then `admit_signal_from` under the workflow source. A refusal
    /// that writes nothing on the target is still an outcome.
    #[allow(clippy::too_many_arguments)]
    async fn deliver_external(
        &self,
        tx: &mut Transaction<'_, DB>,
        target: &str,
        requested_run: Option<&str>,
        name: &str,
        prepared: &mut PreparedControlArguments,
        codec: Arc<Semaphore>,
        limits: super::memo_limits::StructuralLimits,
        context: &Value,
        key: &str,
    ) -> Result<Resolution> {
        let Some(instance) = Self::query("SELECT current_run_id,last_message_sequence FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(target).bind(self.namespace.as_ref()).fetch_optional(&mut **tx).await?
        else {
            return Ok(Resolution::failed("target_not_found", "instance_not_found",
                format!("No workflow instance [{target}] exists in this namespace.")));
        };
        let current_run = DB::string(&instance, "current_run_id")?;
        // Server #934: the delivery's key is answered from its receipt
        // before any run gate.
        let digest = super::command_intent::digest("signal", name, &prepared.values, requested_run);
        if let Some((command, retained)) = Self::retained_command(tx, target, "signal", key).await? {
            return match Self::retained_signal(tx, target, name, requested_run, &digest, &command, retained.as_deref()).await {
                Ok(response) => Ok(self.delivered(tx, &response, true).await?),
                Err(RuntimeError::Protocol { response, .. }) => Ok(Self::refused(target, name, requested_run, &response)),
                Err(error) => Err(error),
            };
        }
        if let Some(selected) = requested_run.filter(|selected| *selected != current_run) {
            let known = Self::scalar("SELECT COUNT(*) FROM workflow_runs WHERE id=$1 AND workflow_instance_id=$2")
                .bind(selected).bind(target).fetch_one(&mut **tx).await?;
            return Ok(if known > 0 {
                let mut failed = Resolution::failed("target_closed", "historical_run",
                    format!("Run [{selected}] of workflow [{target}] is no longer its current run."));
                failed.target_run = Some(selected.to_owned());
                failed
            } else {
                Resolution::failed("target_not_found", "run_not_found", format!("Workflow [{target}] has no run [{selected}]."))
            });
        }
        if name == super::message_streams::INTERNAL_SIGNAL {
            return Ok(Resolution::failed("rejected", "runtime_reserved_signal",
                "This signal name is reserved for the durable runtime transport.".into()));
        }
        let token = super::command_intent::key_token("signal", key);
        let admitted = self
            .admit_signal_from(tx, target, &current_run, DB::number(&instance, "last_message_sequence")?, name, prepared, codec,
                context, "workflow", limits, Some(&token), Some(&digest), true)
            .await;
        match admitted {
            Ok(Ok(response)) => self.delivered(tx, &response, false).await,
            Ok(Err(RuntimeError::Protocol { response, .. })) => Ok(Self::refused(target, name, requested_run, &response)),
            Ok(Err(RuntimeError::Refused { reason, .. })) | Err(RuntimeError::Refused { reason, .. }) => {
                Ok(Self::refused(target, name, requested_run, &json!({"reason": reason, "run_id": current_run})))
            }
            Ok(Err(error)) | Err(error) => Err(error),
        }
    }

    /// A delivered outcome naming the target's command and signal record.
    async fn delivered(&self, tx: &mut Transaction<'_, DB>, response: &Value, retained: bool) -> Result<Resolution> {
        let command = text_of(&response["command_id"]);
        let signal = match &command {
            Some(command) => Self::query("SELECT id FROM workflow_signal_records WHERE workflow_command_id=$1")
                .bind(command.as_str()).fetch_optional(&mut **tx).await?
                .map(|row| DB::string(&row, "id")).transpose()?,
            None => None,
        };
        Ok(Resolution { outcome: "delivered", reason: None, message: None, target_run: text_of(&response["run_id"]),
            target_command: command, target_signal: signal, retained })
    }

    /// A target's refusal as an outcome: a closed run is `target_closed`,
    /// anything else `rejected` with the admission's reason.
    fn refused(target: &str, name: &str, requested_run: Option<&str>, response: &Value) -> Resolution {
        let reason = response["reason"].as_str().unwrap_or("signal_rejected");
        let mut resolution = match reason {
            "run_not_active" | "run_closed" => Resolution::failed("target_closed", "run_not_active",
                format!("The current run of workflow [{target}] is closed.")),
            other => Resolution::failed("rejected", other, response["message"].as_str().map_or_else(
                || format!("Workflow [{target}] refused signal [{name}]: {other}."), str::to_owned)),
        };
        resolution.target_run = text_of(&response["run_id"]).or_else(|| requested_run.map(str::to_owned));
        resolution.target_command = text_of(&response["command_id"]);
        resolution
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn command(fields: Value) -> Value {
        let mut command = json!({"type": COMMAND, "workflow_id": "target", "signal_name": "go",
            "arguments": {"codec": "avro", "blob": "AA=="}});
        for (key, value) in fields.as_object().unwrap() {
            command[key] = value.clone();
        }
        command
    }

    fn reason(command: &Value) -> Option<&'static str> {
        match validate(command) {
            Ok(()) => None,
            Err(RuntimeError::Refused { reason, .. }) => Some(reason),
            Err(error) => panic!("{error:?}"),
        }
    }

    #[test]
    fn validates_the_command_shape() {
        assert_eq!(reason(&command(json!({}))), None);
        assert_eq!(reason(&command(json!({"run_id": "run"}))), None);
        assert_eq!(reason(&command(json!({"run_id": null}))), None);
        assert_eq!(reason(&command(json!({"queue": "q"}))), Some("unsupported_request_field"));
        assert_eq!(reason(&command(json!({"workflow_id": ""}))), Some("invalid_signal_external_target"));
        assert_eq!(reason(&command(json!({"workflow_id": 5}))), Some("invalid_signal_external_target"));
        assert_eq!(reason(&command(json!({"workflow_id": "w".repeat(192)}))), Some("invalid_signal_external_target"));
        assert_eq!(reason(&command(json!({"workflow_id": "é".repeat(191)}))), None);
        assert_eq!(reason(&command(json!({"run_id": " "}))), Some("invalid_signal_external_target"));
        assert_eq!(reason(&command(json!({"signal_name": " "}))), Some("invalid_signal_name"));
        assert_eq!(reason(&command(json!({"signal_name": "n".repeat(192)}))), Some("invalid_signal_name"));
        assert_eq!(reason(&command(json!({"signal_name": "n".repeat(191)}))), None);
        assert!(matches!(validate(&command(json!({"signal_name": "n".repeat(256)}))), Err(RuntimeError::Protocol { .. })));
        assert!(matches!(validate(&command(json!({"signal_name": "bad\u{7}name"}))), Err(RuntimeError::Protocol { .. })));
        assert_eq!(reason(&command(json!({"arguments": {"codec": "json", "blob": "[]"}}))), Some("unsupported_payload_codec"));
        let mut missing = command(json!({}));
        missing.as_object_mut().unwrap().remove("arguments");
        assert_eq!(reason(&missing), Some("unsupported_payload_codec"));
    }

    #[test]
    fn keys_each_delivery_by_its_sending_run_and_sequence() {
        assert_eq!(command_key("01RUN", 4), "workflow-signal:01RUN:4");
        assert_ne!(command_key("01RUN", 4), command_key("01RUN", 5));
    }
}
