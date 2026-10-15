//! Canonical history accounting shared with the published PHP reference.
use serde_json::{Number, Value};

pub(super) fn event_size(event_type: &str, payload: &Value) -> usize {
    event_type.len() + if payload.is_null() { 2 } else { encoded_len(payload) }
}

fn encoded_len(value: &Value) -> usize {
    match value {
        Value::Null => 4,
        Value::Bool(true) => 4,
        Value::Bool(false) => 5,
        Value::String(value) => string_len(value),
        Value::Number(value) => number(value).len(),
        Value::Array(values) => 2 + values.len().saturating_sub(1)
            + values.iter().map(encoded_len).sum::<usize>(),
        Value::Object(values) => {
            // PHP's associative JSON decoder emits a list for sequential integer
            // keys. Inspect the order actually emitted by native history JSON.
            let list = values.keys().enumerate().all(|(index, key)| *key == index.to_string());
            2 + values.len().saturating_sub(1) + values.iter().map(|(key, value)|
                encoded_len(value) + if list { 0 } else { string_len(key) + 1 }).sum::<usize>()
        }
    }
}

fn string_len(value: &str) -> usize {
    // JSON_UNESCAPED_UNICODE still escapes the two Unicode line separators
    // unless PHP's separate JSON_UNESCAPED_LINE_TERMINATORS flag is enabled.
    serde_json::to_string(value).expect("JSON string").len()
        + value.chars().filter(|c| matches!(c, '\u{2028}' | '\u{2029}')).count() * 3
}

fn number(value: &Number) -> String {
    if value.is_i64() {
        return value.to_string();
    }
    // PHP decodes positive integers outside signed int64 as doubles.
    let value = value.as_f64().expect("finite JSON number");
    if value == 0.0 {
        return if value.is_sign_negative() { "-0" } else { "0" }.into();
    }
    let shortest = serde_json::to_string(&value).expect("finite JSON double");
    let (sign, unsigned) = shortest.strip_prefix('-').map_or(("", shortest.as_str()), |v| ("-", v));
    let (mantissa, exponent) = unsigned.split_once('e')
        .map_or((unsigned, 0), |(m, e)| (m, e.parse::<i32>().expect("JSON exponent")));
    let mut point = mantissa.find('.').unwrap_or(mantissa.len()) as i32 + exponent;
    let mut digits = mantissa.replace('.', "");
    let leading = digits.bytes().take_while(|byte| *byte == b'0').count();
    digits.drain(..leading);
    point -= leading as i32;
    while digits.len() > 1 && digits.ends_with('0') {
        digits.pop();
    }
    let exponent = point - 1;
    if !(-4..17).contains(&exponent) {
        let tail = if digits.len() == 1 { "0" } else { &digits[1..] };
        return format!("{sign}{}.{tail}e{}{exponent}", &digits[..1], if exponent >= 0 { "+" } else { "" });
    }
    if point <= 0 {
        format!("{sign}0.{}{digits}", "0".repeat((-point) as usize))
    } else if point as usize >= digits.len() {
        format!("{sign}{digits}{}", "0".repeat(point as usize - digits.len()))
    } else {
        format!("{sign}{}.{}", &digits[..point as usize], &digits[point as usize..])
    }
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
