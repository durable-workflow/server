//! Shared response metadata for the qualified control-plane read surfaces.
use serde_json::{Value, json};

pub(super) enum ReadOperation {
    List,
    Describe,
    DescribeRun,
    History,
}

impl ReadOperation {
    pub(super) fn response(self, mut body: Value) -> Value {
        let (operation, required, success): (&str, &[&str], &[&str]) = match self {
            Self::List => ("list", &[], &[]),
            Self::Describe => ("describe", &["workflow_id"], &[]),
            Self::DescribeRun => ("describe_run", &["workflow_id"], &["run_id"]),
            Self::History => ("history", &["workflow_id", "run_id"], &["next_page_token"]),
        };
        let mut metadata = json!({"schema":"durable-workflow.v2.control-plane-response", "version":1,
            "operation":operation, "workflow_id":body.get("workflow_id"),
            "contract":{"schema":"durable-workflow.v2.control-plane-response.contract", "version":1,
                "legacy_field_policy":"reject_non_canonical", "legacy_fields":{"query":"query_name","signal":"signal_name","update":"update_name","wait_policy":"wait_for"},
                "required_fields":required, "success_fields":success}});
        if operation != "list" {
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
            "reason",
            "message",
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
