//! Native's JSON objects keep their keys in document order (`serde_json`
//! with `preserve_order`), as PHP's arrays keep theirs. A digest that must
//! not depend on the order a client or database wrote the keys in hashes this
//! form instead, which is what native hashed before objects kept their order.
use serde_json::{Map, Value};

/// The value with every object's keys in byte order, recursively.
pub(super) fn sorted_keys(value: &Value) -> Value {
    match value {
        Value::Object(map) => {
            let mut entries: Vec<(&String, &Value)> = map.iter().collect();
            entries.sort_by(|left, right| left.0.cmp(right.0));
            Value::Object(entries.into_iter().map(|(key, value)| (key.clone(), sorted_keys(value))).collect::<Map<_, _>>())
        }
        Value::Array(items) => Value::Array(items.iter().map(sorted_keys).collect()),
        other => other.clone(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn keys_sort_recursively_and_lists_keep_their_order() {
        let value: Value = serde_json::from_str(r#"{"z":{"b":1,"a":2},"a":[{"y":1,"x":2},3]}"#).unwrap();
        assert_eq!(serde_json::to_string(&sorted_keys(&value)).unwrap(), r#"{"a":[{"x":2,"y":1},3],"z":{"a":2,"b":1}}"#);
        assert_eq!(serde_json::to_string(&value).unwrap(), r#"{"z":{"b":1,"a":2},"a":[{"y":1,"x":2},3]}"#);
    }
}
