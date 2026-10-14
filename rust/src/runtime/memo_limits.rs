//! The package's DW-primary, WORKFLOW-legacy structural memo limit.
use super::{Result, refuse};
use axum::http::StatusCode;
use regex_lite::Regex;
use std::{env, sync::LazyLock};

const DEFAULT_BYTES: usize = 262_144;
static NUMERIC_PREFIX: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"^[ \t\r\n\x0b\x0c]*([+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?)")
        .expect("static PHP numeric prefix")
});

fn typed_null(value: &str) -> bool {
    matches!(value.to_ascii_lowercase().as_str(), "null" | "(null)")
}

fn limit(primary: Option<&str>, legacy: Option<&str>) -> Option<usize> {
    let Some(value) = primary.filter(|value| !typed_null(value)).or(legacy) else {
        return Some(DEFAULT_BYTES);
    };
    let value = match value.to_ascii_lowercase().as_str() {
        "true" | "(true)" => "1",
        "false" | "(false)" | "null" | "(null)" | "empty" | "(empty)" => "0",
        _ => value,
    };
    // Laravel unwraps matching quotes before the workflow config casts to int.
    let value = value
        .strip_prefix('"').and_then(|value| value.strip_suffix('"'))
        .or_else(|| value.strip_prefix('\'').and_then(|value| value.strip_suffix('\'')))
        .unwrap_or(value);
    let numeric = NUMERIC_PREFIX.captures(value).and_then(|value| value.get(1));
    let bytes = numeric.map_or(0, |value| {
        value.as_str().parse::<i64>().unwrap_or_else(|_| {
            value.as_str().parse::<f64>().unwrap_or(0.0) as i64
        })
    });
    (bytes > 0).then_some(bytes.max(0) as usize)
}

pub(super) fn configured_limit() -> Result<Option<usize>> {
    let read = |key| match env::var(key) {
        Ok(value) => Ok(Some(value)),
        Err(env::VarError::NotPresent) => Ok(None),
        Err(env::VarError::NotUnicode(_)) => Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY, "invalid_memo_limit_configuration",
        )),
    };
    let primary = read("DW_V2_LIMIT_MEMO_SIZE_BYTES")?;
    let legacy = if primary.as_deref().is_none_or(typed_null) {
        read("WORKFLOW_V2_LIMIT_MEMO_SIZE_BYTES")?
    } else {
        None
    };
    Ok(limit(primary.as_deref(), legacy.as_deref()))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn published_php_casts_and_alias_priority_are_preserved() {
        let cases: Vec<(Option<&str>, Option<usize>)> = vec![
            (None, Some(73)), (Some(""), None), (Some("0"), None),
            (Some("-1"), None), (Some("15"), Some(15)),
            (Some("  15 "), Some(15)), (Some("15.9"), Some(15)),
            (Some("1e2"), Some(100)), (Some("junk"), None),
            (Some("12junk"), Some(12)), (Some("true"), Some(1)),
            (Some("false"), None), (Some("null"), Some(73)),
            (Some("(null)"), Some(73)), (Some("empty"), None),
            (Some("(empty)"), None), (Some("\"21\""), Some(21)),
            (Some("9223372036854775808"), Some(i64::MAX as usize)),
            (Some("1e100"), Some(i64::MAX as usize)),
        ];
        for (primary, expected) in cases {
            assert_eq!(limit(primary, Some("73")), expected, "{primary:?}");
        }
        assert_eq!(limit(None, None), Some(DEFAULT_BYTES));
        assert_eq!(limit(Some("(null)"), None), Some(DEFAULT_BYTES));
        assert_eq!(limit(Some("25"), Some("9")), Some(25));
        assert_eq!(limit(None, Some("null")), None);
    }
}
