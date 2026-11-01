//! Plain command input as published PHP encodes it. `PayloadEnvelopeResolver`
//! takes the request's values in document order (`array_values`), and
//! `Avro::toDatum` encodes each one: a PHP list (keys 0 to n-1 in that
//! order) is an Avro array, any other array is a map in document order,
//! and an array with an integer key that is not a list fails with
//! `invalid_map_key`. `json_decode` turns a canonical integer string key into
//! an integer, so `{"1": "a", "0": "b"}` is not a list. Native's JSON objects
//! keep document order (`serde_json` with `preserve_order`).
use crate::codec::OrderedValue;
use serde_json::Value;

/// `Avro::toDatum`'s refusal of an integer key in a map.
pub(super) const INVALID_MAP_KEY: &str =
    "invalid_map_key: Avro Value maps require string keys; keys are never stringified.";

/// A key `json_decode` stores as an integer: a canonical decimal in range.
fn integer_key(key: &str) -> bool {
    let digits = key.strip_prefix('-').unwrap_or(key);
    let canonical = key == "0" || (!digits.is_empty() && !digits.starts_with('0') && digits.bytes().all(|byte| byte.is_ascii_digit()));
    canonical && key.parse::<i64>().is_ok()
}

/// One value as PHP's serializer encodes it, or `None` for `invalid_map_key`.
pub(super) fn ordered(value: &Value) -> Option<OrderedValue> {
    Some(match value {
        Value::Null => OrderedValue::Null,
        Value::Bool(flag) => OrderedValue::Boolean(*flag),
        Value::Number(number) => match number.as_i64() {
            Some(integer) => OrderedValue::Long(integer),
            None => OrderedValue::Double(number.as_f64()?),
        },
        Value::String(text) => OrderedValue::String(text.clone()),
        Value::Array(items) => OrderedValue::Array(items.iter().map(ordered).collect::<Option<_>>()?),
        Value::Object(entries) => {
            if entries.keys().enumerate().all(|(position, key)| *key == position.to_string()) {
                OrderedValue::Array(entries.values().map(ordered).collect::<Option<_>>()?)
            } else if entries.keys().any(|key| integer_key(key)) {
                return None;
            } else {
                OrderedValue::Map(entries.iter().map(|(key, value)| Some((key.clone(), ordered(value)?))).collect::<Option<_>>()?)
            }
        }
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn map(entries: &[(&str, OrderedValue)]) -> OrderedValue {
        OrderedValue::Map(entries.iter().map(|(key, value)| ((*key).to_owned(), value.clone())).collect())
    }

    #[test]
    fn objects_keep_document_order_and_lists_need_ordered_integer_keys() {
        let string = |text: &str| OrderedValue::String(text.to_owned());
        assert_eq!(ordered(&json!({"zeta": 1, "alpha": {"yak": 2, "bee": 3}})),
            Some(map(&[("zeta", OrderedValue::Long(1)), ("alpha", map(&[("yak", OrderedValue::Long(2)), ("bee", OrderedValue::Long(3))]))])));
        assert_eq!(ordered(&json!({"0": "a", "1": "b"})), Some(OrderedValue::Array(vec![string("a"), string("b")])));
        assert_eq!(ordered(&json!({})), Some(OrderedValue::Array(vec![])));
        // Integer keys out of list order, or with a gap, are not a list.
        assert_eq!(ordered(&json!({"1": "a", "0": "b"})), None);
        assert_eq!(ordered(&json!({"1": "a"})), None);
        assert_eq!(ordered(&json!([{"a": 1, "-1": 2}])), None);
        // Keys json_decode keeps as strings.
        assert_eq!(ordered(&json!({"01": 1, "-0": 2, "+1": 3})),
            Some(map(&[("01", OrderedValue::Long(1)), ("-0", OrderedValue::Long(2)), ("+1", OrderedValue::Long(3))])));
        assert_eq!(ordered(&json!(1.5)), Some(OrderedValue::Double(1.5)));
    }
}
