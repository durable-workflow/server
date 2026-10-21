//! Shared response metadata for qualified reads and admission refusals.
use axum::extract::{FromRequestParts, Path, Request};
use serde_json::{Value, json};
use std::collections::HashMap;

pub(super) enum Operation<'a> {
    Start,
    List,
    ListRuns,
    Describe,
    DescribeRun,
    Observe,
    History,
    Cancel,
    Terminate,
    Repair,
    Debug,
    Update,
    Signal(&'a str),
    Query(&'a str),
}

impl Operation<'_> {
    pub(super) fn refusal(
        self,
        error: super::RuntimeError,
        workflow_id: &str,
        run_id: Option<&str>,
    ) -> super::RuntimeError {
        let (status, mut body) = match error {
            super::RuntimeError::Refused { status, reason }
                if status == axum::http::StatusCode::NOT_FOUND
                    && matches!(reason, "instance_not_found" | "run_not_found") =>
            {
                (
                    status,
                    json!({"reason":reason,"message":if reason == "run_not_found" {
                    "Workflow run not found."
                } else { "Workflow not found." },"workflow_id":workflow_id}),
                )
            }
            super::RuntimeError::Refused { status, reason }
                if matches!(self, Self::Signal(_) | Self::Query(_))
                    && reason == "historical_run_command_rejected" =>
            {
                (
                    status,
                    json!({"reason":reason,"workflow_id":workflow_id,"target_scope":"run",
                    "message":"Commands cannot target historical runs. Use the instance-level endpoint to command the current run, or omit the run ID."}),
                )
            }
            super::RuntimeError::Protocol { status, response }
                if matches!(self, Self::Signal(_) | Self::Query(_)) =>
            {
                (status, response)
            }
            super::RuntimeError::Protocol { status, response }
                if matches!(self, Self::Start)
                    && response["reason"] == "workflow_id_reserved_in_namespace" =>
            {
                (status, response)
            }
            other => return other,
        };
        if let Some(run_id) = run_id {
            body["run_id"] = json!(run_id);
        }
        super::RuntimeError::Protocol {
            status,
            response: self.response(body),
        }
    }

    pub(super) fn response(self, mut body: Value) -> Value {
        let (operation, required, success): (&str, &[&str], &[&str]) = match self {
            Self::Start => ("start", &[], &["workflow_id", "outcome"]),
            Self::List => ("list", &[], &[]),
            Self::ListRuns => ("list_runs", &["workflow_id"], &[]),
            Self::Describe => ("describe", &["workflow_id"], &[]),
            Self::DescribeRun => ("describe_run", &["workflow_id"], &["run_id"]),
            Self::Observe => (
                "observe_workflow",
                &["workflow_id"],
                &[
                    "run_id",
                    "read_mode",
                    "observed_at",
                    "current_waits",
                    "parents",
                    "children",
                    "recent_failures",
                    "history",
                ],
            ),
            Self::History => ("history", &["workflow_id", "run_id"], &["next_page_token"]),
            Self::Cancel => ("cancel", &["workflow_id"], &["outcome"]),
            Self::Terminate => ("terminate", &["workflow_id"], &["outcome"]),
            Self::Repair => ("repair", &["workflow_id"], &["outcome"]),
            Self::Debug => ("debug_workflow", &["workflow_id"], &["run_id", "diagnostic_status"]),
            Self::Update => (
                "update",
                &["workflow_id", "operation_name", "operation_name_field"],
                &["outcome"],
            ),
            Self::Query(_) => (
                "query",
                &["workflow_id", "operation_name", "operation_name_field"],
                &["result", "result_envelope"],
            ),
            Self::Signal(_) => (
                "signal",
                &["workflow_id", "operation_name", "operation_name_field"],
                &["outcome"],
            ),
        };
        let mut metadata = json!({"schema":"durable-workflow.v2.control-plane-response", "version":1,
            "operation":operation, "workflow_id":body.get("workflow_id"),
            "contract":{"schema":"durable-workflow.v2.control-plane-response.contract", "version":1,
                "legacy_field_policy":"reject_non_canonical", "legacy_fields":{"query":"query_name","signal":"signal_name","update":"update_name","wait_policy":"wait_for"},
                "required_fields":required, "success_fields":success}});
        if matches!(self, Self::Start) {
            metadata["contract"]["rejection_fields"] = json!([
                "workflow_id",
                "command_status",
                "command_source",
                "outcome",
                "reason",
                "rejection_reason",
                "message"
            ]);
            metadata["contract"]["rejection_reasons"] = json!([
                "workflow_id_reserved_in_namespace",
                "task_queue_draining",
                "compatibility_blocked"
            ]);
        }
        if matches!(self, Self::DescribeRun | Self::History) {
            metadata["contract"]["rejection_fields"] = json!([
                "workflow_id",
                "run_id",
                "reason",
                "message",
                "retryable",
                "error_id",
                "exception"
            ]);
            metadata["contract"]["rejection_reasons"] = json!(["control_plane_internal_error"]);
        }
        if matches!(self, Self::Cancel | Self::Terminate | Self::Repair) {
            metadata["contract"]["rejection_fields"] =
                json!(["workflow_id", "run_id", "reason", "message", "remediation"]);
            metadata["contract"]["rejection_reasons"] = json!(["v1_projection_read_only"]);
        }
        if matches!(self, Self::Update) {
            metadata["operation_name"] = body["update_name"].clone();
            metadata["operation_name_field"] = json!("update_name");
            metadata["contract"]["rejection_fields"] = json!([
                "workflow_id",
                "run_id",
                "update_id",
                "update_name",
                "update_status",
                "outcome",
                "reason",
                "rejection_reason",
                "validation_errors",
                "retryable",
                "message",
                "remediation"
            ]);
            metadata["contract"]["rejection_reasons"] = json!([
                "update_validator_rejected",
                "update_validator_contract_missing",
                "update_validation_request_id_required",
                "update_validation_idempotency_conflict",
                "update_validation_capability_unsupported",
                "update_validator_worker_unavailable",
                "update_validator_worker_incompatible",
                "update_validator_worker_lost",
                "update_validation_task_not_claimed",
                "update_validation_execution_timeout",
                "duplicate_update_validation_completion",
                "stale_update_validation_completion",
                "invalid_update_arguments",
                "v1_projection_read_only"
            ]);
            for field in [
                "command_id",
                "target_scope",
                "result",
                "result_envelope",
                "update_id",
                "update_status",
                "wait_for",
                "wait_timed_out",
                "wait_timeout_seconds",
            ] {
                metadata[field] = body[field].clone();
            }
        }
        if let Self::Query(name) = self {
            metadata["operation_name"] = json!(name);
            metadata["operation_name_field"] = json!("query_name");
            metadata["contract"]["rejection_fields"] = json!([
                "workflow_id",
                "run_id",
                "query_name",
                "target_scope",
                "result",
                "reason",
                "message",
                "run_status",
                "is_terminal",
                "blocked_reason",
                "validation_errors"
            ]);
            metadata["contract"]["rejection_reasons"] = json!([
                "instance_not_found",
                "historical_run_command_rejected",
                "run_not_active",
                "query_not_found",
                "rejected_unknown_query",
                "invalid_query_arguments",
                "workflow_definition_unavailable",
                "query_rejected",
                "query_worker_unavailable",
                "query_worker_incompatible",
                "query_task_queue_full",
                "query_task_queue_unavailable",
                "query_task_not_claimed",
                "query_worker_execution_timeout",
                "query_worker_timeout",
                "v1_projection_read_only"
            ]);
        }
        if let Self::Signal(name) = self {
            metadata["operation_name"] = json!(name);
            metadata["operation_name_field"] = json!("signal_name");
            metadata["contract"]["rejection_fields"] = json!([
                "workflow_id",
                "run_id",
                "signal_name",
                "command_status",
                "command_source",
                "target_scope",
                "outcome",
                "reason",
                "rejection_reason",
                "rejection_category",
                "message",
                "command_contract_source",
                "command_contract_backfill_needed",
                "command_contract_backfill_available",
                "declared_signals",
                "signal_admission",
                "retryable",
                "error_id"
            ]);
            metadata["contract"]["rejection_reasons"] = json!([
                "instance_not_found",
                "historical_run_command_rejected",
                "unknown_signal",
                "invalid_signal_arguments",
                "run_not_active",
                "instance_not_started",
                "selected_run_not_current",
                "configured_workflow_type_invalid",
                "v1_projection_read_only",
                "backend_lock_pressure",
                "control_plane_internal_error"
            ]);
            // PHP's ControlPlaneResponseContract::PROJECTED_FIELDS for a signal.
            for field in [
                "command_id",
                "target_scope",
                "command_contract_source",
                "command_contract_backfill_needed",
                "command_contract_backfill_available",
                "declared_signals",
                "signal_admission",
            ] {
                if let Some(value) = body.get(field) {
                    metadata[field] = value.clone();
                }
            }
        }
        // ControlPlaneResponseContract::PROJECTED_FIELDS: each present field.
        for field in [
            "run_id",
            "workflow_type",
            "namespace",
            "business_key",
            "status",
            "status_bucket",
            "run_status",
            "is_terminal",
            "task_queue",
            "run_number",
            "run_count",
            "workflow_count",
            "is_current_run",
            "compatibility",
            "started_at",
            "closed_at",
            "last_progress_at",
            "wait_kind",
            "wait_reason",
            "next_page_token",
            "command_id",
            "command_status",
            "command_source",
            "target_scope",
            "query_name",
            "outcome",
            "reason",
            "rejection_reason",
            "rejection_category",
            "validation_errors",
            "result",
            "result_envelope",
            "update_id",
            "update_status",
            "principal",
            "wait_for",
            "wait_timed_out",
            "wait_timeout_seconds",
            "blocked_reason",
            "message",
            "remediation",
            "execution_owner",
            "identity",
            "command_contract_source",
            "command_contract_backfill_needed",
            "command_contract_backfill_available",
            "declared_signals",
            "signal_admission",
            "diagnostic_status",
            "retryable",
            "error_id",
            "exception",
        ] {
            if let Some(value) = body.get(field) {
                metadata[field] = value.clone();
            }
        }
        body["control_plane"] = metadata;
        body
    }
}

pub(super) async fn admission_response(request: Request, mut body: Value) -> Value {
    let segments: Vec<_> = request
        .uri()
        .path()
        .trim_start_matches('/')
        .split('/')
        .collect();
    let operation = match (request.method().as_str(), segments.as_slice()) {
        ("GET", ["api", "workflows"]) => Operation::List,
        ("GET", ["api", "workflows", _]) => Operation::Describe,
        ("GET", ["api", "workflows", _, "runs"]) => Operation::ListRuns,
        ("GET", ["api", "workflows", _, "runs", _]) => Operation::DescribeRun,
        ("GET", ["api", "workflows", _, "runs", _, "history"]) => Operation::History,
        ("GET", ["api", "workflows", _, "observation"])
        | ("GET", ["api", "workflows", _, "runs", _, "observation"]) => Operation::Observe,
        ("POST", ["api", "workflows", _, "cancel"])
        | ("POST", ["api", "workflows", _, "runs", _, "cancel"]) => Operation::Cancel,
        ("POST", ["api", "workflows", _, "terminate"])
        | ("POST", ["api", "workflows", _, "runs", _, "terminate"]) => Operation::Terminate,
        ("POST", ["api", "workflows", _, "repair"])
        | ("POST", ["api", "workflows", _, "runs", _, "repair"]) => Operation::Repair,
        ("GET", ["api", "workflows", _, "debug"])
        | ("GET", ["api", "workflows", _, "runs", _, "debug"]) => Operation::Debug,
        // Additional control-plane operations require their own shared contracts.
        _ => return body,
    };
    let (mut parts, _) = request.into_parts();
    if let Ok(Path(parameters)) =
        Path::<HashMap<String, String>>::from_request_parts(&mut parts, &()).await
    {
        for field in ["workflow_id", "run_id"] {
            if let Some(value) = parameters.get(field) {
                body[field] = json!(value);
            }
        }
    }
    operation.response(body)
}
