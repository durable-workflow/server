//! Portable published FailureFactory rules shared by worker commands.
pub(super) fn category(exception_class: &str, message: &str) -> &'static str {
    let class = exception_class
        .trim_start_matches('\\')
        .to_ascii_lowercase();
    match class.as_str() {
        "workflow\\v2\\exceptions\\structurallimitexceededexception" => "structural_limit",
        "workflow\\v2\\exceptions\\unsupportedworkflowyieldexception"
        | "workflow\\v2\\exceptions\\straightlineworkflowrequiredexception" => "task_failure",
        "illuminate\\database\\queryexception"
        | "pdoexception"
        | "illuminate\\queue\\maxattemptsexceededexception" => "internal",
        _ => {
            let lower = message.to_ascii_lowercase();
            if [
                "timed out",
                "timeout exceeded",
                "execution deadline",
                "run deadline",
            ]
            .iter()
            .any(|marker| lower.contains(marker))
            {
                "timeout"
            } else if message.starts_with("Structural limit exceeded:") {
                "structural_limit"
            } else {
                "application"
            }
        }
    }
}

pub(super) fn default_non_retryable(class: &str) -> bool {
    class
        .trim_start_matches('\\')
        .eq_ignore_ascii_case("Workflow\\Exceptions\\NonRetryableException")
}
