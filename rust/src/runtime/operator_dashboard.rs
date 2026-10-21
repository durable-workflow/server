//! Request rules for published PHP's operator dashboard by workflow type.
//! The dashboard itself is built with the operator metrics.
use super::{RuntimeError, laravel_validation::failure};

/// `workflowTypeOperatorDashboard`'s validation: a JSON list of at most 4096
/// encoded bytes whose items are nonempty strings of at most 255 characters.
pub(super) fn workflow_types(value: Option<&str>) -> Result<Vec<String>, RuntimeError> {
    let refuse = |message: &'static str| failure(vec![("workflow_types", vec![message])]).expect("one message");
    let text = value.map(str::trim).filter(|text| !text.is_empty())
        .ok_or_else(|| refuse("The workflow types field is required."))?;
    if text.chars().count() > 4096 {
        return Err(refuse("The workflow types field must not be greater than 4096 characters."));
    }
    let decoded: serde_json::Value =
        serde_json::from_str(text).map_err(|_| refuse("The workflow types field must be a valid JSON string."))?;
    let encoded_len = url_encoded_len(text);
    let Some(items) = decoded.as_array().filter(|_| encoded_len <= 4096) else {
        return Err(refuse("Provide a JSON list of workflow types with at most 4096 encoded bytes."));
    };
    items
        .iter()
        .map(|item| {
            item.as_str()
                .filter(|kind| !kind.is_empty() && kind.chars().count() <= 255)
                .map(str::to_owned)
                .ok_or_else(|| refuse("Workflow types must be nonempty strings of at most 255 characters."))
        })
        .collect()
}

/// `strlen(rawurlencode($value))`.
fn url_encoded_len(text: &str) -> usize {
    text.bytes()
        .map(|byte| if byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_' | b'.' | b'~') { 1 } else { 3 })
        .sum()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn message(value: Option<&str>) -> String {
        match workflow_types(value) {
            Err(RuntimeError::Protocol { response, .. }) => response["message"].as_str().unwrap().to_owned(),
            other => panic!("{other:?}"),
        }
    }

    #[test]
    fn workflow_types_follow_php_rules() {
        assert_eq!(workflow_types(Some(r#"["orders","billing"]"#)).unwrap(), vec!["orders", "billing"]);
        assert_eq!(message(None), "The workflow types field is required.");
        assert_eq!(message(Some("{")), "The workflow types field must be a valid JSON string.");
        assert_eq!(message(Some(r#"{"a":1}"#)), "Provide a JSON list of workflow types with at most 4096 encoded bytes.");
        assert_eq!(message(Some(r#"["",1]"#)), "Workflow types must be nonempty strings of at most 255 characters.");
    }
}
