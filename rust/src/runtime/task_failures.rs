//! Ordinary task retry admission. Replay blockage and manual repair remain
//! unqualified; never turn a published replay failure into an automatic retry.
use serde_json::Value;

// Frozen Server 2.5.14 WorkerController::STRUCTURED_REPLAY_FAILURE_REASONS.
const REPLAY_REASONS: &[&str] = &[
    "activity_execution_mode_mismatch",
    "activity_identity_mismatch",
    "activity_retry_policy_mismatch",
    "activity_task_queue_mismatch",
    "child_workflow_identity_mismatch",
    "condition_wait_definition_history_mismatch",
    "condition_wait_definition_invalid",
    "condition_wait_id_mismatch",
    "condition_wait_id_missing",
    "condition_wait_key_mismatch",
    "condition_wait_occurrence_history_mismatch",
    "condition_wait_occurrence_id_missing",
    "condition_wait_occurrence_mismatch",
    "condition_wait_predicate_fingerprint_missing",
    "condition_wait_predicate_mismatch",
    "condition_wait_reopened_after_timeout",
    "condition_wait_terminal_conflict",
    "condition_wait_timeout_delay_mismatch",
    "condition_wait_timeout_history_invalid",
    "condition_wait_timeout_identity_mismatch",
    "condition_wait_timeout_mismatch",
    "continue_as_new_sequence_missing",
    "duplicate_memo_upsert_record",
    "duplicate_version_marker",
    "duplicate_version_marker_record",
    "durable_command_sequence_collision",
    "durable_command_sequence_invalid",
    "durable_command_sequence_mismatch",
    "durable_command_sequence_missing",
    "memo_entries_invalid",
    "memo_entries_missing",
    "memo_merged_projection_invalid",
    "memo_merged_projection_missing",
    "memo_sequence_invalid",
    "memo_sequence_missing",
    "memo_update_mismatch",
    "parallel_group_history_conflict",
    "parallel_group_metadata_invalid",
    "parallel_group_metadata_missing",
    "parallel_group_missing_from_workflow",
    "parallel_group_partially_scheduled",
    "parallel_group_shape_mismatch",
    "recorded_command_detail_mismatch",
    "recorded_command_mismatch",
    "recorded_commands_unconsumed",
    "recorded_continue_as_new_unconsumed",
    "search_attribute_type_mismatch",
    "search_attribute_update_missing",
    "search_attribute_value_mismatch",
    "side_effect_result_missing",
    "side_effect_type_mismatch",
    "signal_wait_identity_mismatch",
    "signal_wait_name_missing",
    "timer_delay_mismatch",
    "timer_history_delay_mismatch",
    "timer_history_field_missing",
    "timer_identity_mismatch",
    "unsupported_payload_codec",
    "version_change_id_invalid",
    "version_change_id_mismatch",
    "version_marker_field_missing",
    "version_marker_history_range_invalid",
    "version_marker_incompatible_range",
    "version_marker_kind_mismatch",
    "version_marker_sequence_invalid",
    "version_range_invalid",
    "workflow_nondeterministic",
];

pub(super) fn php_trim(value: &str) -> &str {
    value.trim_matches(|c| matches!(c, ' ' | '\t' | '\n' | '\r' | '\0' | '\x0b'))
}

pub(super) fn blocks_replay(failure: &Value) -> bool {
    let reason = php_trim(failure["reason"].as_str().unwrap_or(""));
    if REPLAY_REASONS
        .iter()
        .any(|known| reason.eq_ignore_ascii_case(known))
    {
        return true;
    }
    // PHP substr() truncates bytes, including a partial UTF-8 character.
    // Searching bytes preserves that boundary without broadening the prefix.
    let mut text = Vec::with_capacity(4609);
    for (field, limit) in [("type", 512), ("message", 4096)] {
        if field == "message" {
            text.push(b' ');
        }
        text.extend(
            failure[field]
                .as_str()
                .unwrap_or("")
                .bytes()
                .take(limit)
                .map(|byte| byte.to_ascii_lowercase()),
        );
    }
    [
        "nondetermin",
        "non-determin",
        "determinism",
        "replay error",
        "replay failed",
        "history shape",
        "history mismatch",
        "invalidargument",
        "servererror",
        "unexpected history",
        "validationexception",
        "cannot decode workflow start input",
        "cannot replay workflow history",
        "unsupported payload codec",
        "unsupported_payload_codec",
        "workflow task completion failed after commands were produced",
        "no workflow registered",
    ]
    .iter()
    .any(|needle| {
        text.windows(needle.len())
            .any(|window| window == needle.as_bytes())
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn replay_admission_preserves_php_byte_prefixes_and_ascii_trimming() {
        assert!(blocks_replay(
            &json!({"reason":" \tDUPLICATE_VERSION_MARKER\n","message":"ordinary"})
        ));
        assert!(!blocks_replay(
            &json!({"reason":"\u{2003}duplicate_version_marker\u{2003}","message":"ordinary"})
        ));
        assert!(blocks_replay(&json!({"type":"REPLAY","message":"FAILED"})));
        assert!(blocks_replay(
            &json!({"message":format!("{}replay failed", "λ".repeat(2041))})
        ));
        assert!(!blocks_replay(
            &json!({"message":format!("{}replay failed", "λ".repeat(2048))})
        ));
        assert!(!blocks_replay(
            &json!({"type":format!("{}ServerError", "λ".repeat(256)),"message":"ordinary"})
        ));
        assert!(blocks_replay(
            &json!({"type":"ServerError","message":"ordinary"})
        ));
        assert!(!blocks_replay(
            &json!({"type":"RuntimeException","message":"temporary processor unavailable λ"})
        ));
    }
}
