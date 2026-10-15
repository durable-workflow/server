//! Canonical history accounting shared with the published PHP reference.
use serde_json::Value;

pub(super) fn event_size(event_type: &str, payload: &Value) -> usize {
    event_type.len() + serde_json::to_string(payload).expect("JSON value").len()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn history_bytes_match_the_php_reference_for_native_json_shapes() {
        let cases: Vec<Value> = serde_json::from_str(include_str!(
            "../../../tests/Fixtures/HistoryBudget/canonical-json.json"
        )).unwrap();
        for case in cases {
            let payload: Value = serde_json::from_str(case["payload_json"].as_str().unwrap()).unwrap();
            assert_eq!(event_size(case["event_type"].as_str().unwrap(), &payload),
                case["history_size_bytes"].as_u64().unwrap() as usize, "{}", case["name"]);
        }
    }
}
