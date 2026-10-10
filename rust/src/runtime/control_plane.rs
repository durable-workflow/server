//! Shared response metadata for qualified reads and admission refusals.
use axum::extract::{FromRequestParts, Path, Request};
use serde_json::{Value, json};
use std::collections::HashMap;

pub(super) enum Operation {
    Start,
    List,
    Describe,
    DescribeRun,
    History,
    Cancel,
}

impl Operation {
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
            Self::Describe => ("describe", &["workflow_id"], &[]),
            Self::DescribeRun => ("describe_run", &["workflow_id"], &["run_id"]),
            Self::History => ("history", &["workflow_id", "run_id"], &["next_page_token"]),
            Self::Cancel => ("cancel", &["workflow_id"], &["outcome"]),
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
        if matches!(self, Self::Cancel) {
            metadata["contract"]["rejection_fields"] =
                json!(["workflow_id", "run_id", "reason", "message", "remediation"]);
            metadata["contract"]["rejection_reasons"] = json!(["v1_projection_read_only"]);
        }
        // Copy scalar read identities/state, never input/output/event payloads.
        for field in [
            "run_id",
            "workflow_type",
            "namespace",
            "business_key",
            "status",
            "status_bucket",
            "is_terminal",
            "task_queue",
            "run_number",
            "run_count",
            "is_current_run",
            "compatibility",
            "started_at",
            "closed_at",
            "last_progress_at",
            "wait_kind",
            "wait_reason",
            "workflow_count",
            "next_page_token",
            "command_status",
            "command_source",
            "outcome",
            "reason",
            "rejection_reason",
            "message",
            "remediation",
            "validation_errors",
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
        ("GET", ["api", "workflows", _, "runs", _]) => Operation::DescribeRun,
        ("GET", ["api", "workflows", _, "runs", _, "history"]) => Operation::History,
        ("POST", ["api", "workflows", _, "cancel"])
        | ("POST", ["api", "workflows", _, "runs", _, "cancel"]) => Operation::Cancel,
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
