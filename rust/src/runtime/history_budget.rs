//! Canonical history accounting shared with the published PHP reference.
use serde::de::{Deserialize, Deserializer, MapAccess, SeqAccess, Visitor};
use serde_json::Number;
use std::fmt;

pub(super) fn event_size(event_type: &str, payload: &str) -> Result<usize, serde_json::Error> {
    let payload: Encoded = serde_json::from_str(payload)?;
    Ok(event_type.len() + if payload.null { 2 } else { payload.len })
}

struct Encoded {
    len: usize,
    null: bool,
}
impl Encoded {
    fn new(len: usize) -> Self {
        Self { len, null: false }
    }
}
impl<'de> Deserialize<'de> for Encoded {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        deserializer.deserialize_any(EncodedVisitor)
    }
}
struct EncodedVisitor;
impl<'de> Visitor<'de> for EncodedVisitor {
    type Value = Encoded;
    fn expecting(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter.write_str("a JSON history payload")
    }
    fn visit_unit<E>(self) -> Result<Encoded, E> {
        Ok(Encoded { len: 4, null: true })
    }
    fn visit_bool<E>(self, value: bool) -> Result<Encoded, E> {
        Ok(Encoded::new(if value { 4 } else { 5 }))
    }
    fn visit_i64<E>(self, value: i64) -> Result<Encoded, E> {
        Ok(Encoded::new(value.to_string().len()))
    }
    fn visit_u64<E>(self, value: u64) -> Result<Encoded, E> {
        Ok(Encoded::new(number(&Number::from(value)).len()))
    }
    fn visit_f64<E: serde::de::Error>(self, value: f64) -> Result<Encoded, E> {
        let value =
            Number::from_f64(value).ok_or_else(|| E::custom("non-finite history number"))?;
        Ok(Encoded::new(number(&value).len()))
    }
    fn visit_str<E>(self, value: &str) -> Result<Encoded, E> {
        Ok(Encoded::new(string_len(value)))
    }
    fn visit_seq<A: SeqAccess<'de>>(self, mut sequence: A) -> Result<Encoded, A::Error> {
        let mut len = 2;
        let mut count = 0;
        while let Some(value) = sequence.next_element::<Encoded>()? {
            len += value.len + usize::from(count > 0);
            count += 1;
        }
        Ok(Encoded::new(len))
    }
    fn visit_map<A: MapAccess<'de>>(self, mut map: A) -> Result<Encoded, A::Error> {
        // Keep the database's key order. PHP converts an associative object to
        // a list only when its keys, in that order, are exactly 0 through n-1.
        // Repeated keys replace their value without changing that position.
        let mut entries: Vec<(String, usize)> = Vec::new();
        let mut positions: std::collections::BTreeMap<String, usize> =
            std::collections::BTreeMap::new();
        while let Some((key, value)) = map.next_entry::<String, Encoded>()? {
            if let Some(index) = positions.get(&key) {
                entries[*index].1 = value.len;
            } else {
                positions.insert(key.clone(), entries.len());
                entries.push((key, value.len));
            }
        }
        let list = entries
            .iter()
            .enumerate()
            .all(|(index, (key, _))| *key == index.to_string());
        Ok(Encoded::new(
            2 + entries.len().saturating_sub(1)
                + entries
                    .iter()
                    .map(|(key, len)| len + if list { 0 } else { string_len(key) + 1 })
                    .sum::<usize>(),
        ))
    }
}

fn string_len(value: &str) -> usize {
    // JSON_UNESCAPED_UNICODE still escapes the two Unicode line separators
    // unless PHP's separate JSON_UNESCAPED_LINE_TERMINATORS flag is enabled.
    serde_json::to_string(value).expect("JSON string").len()
        + value
            .chars()
            .filter(|c| matches!(c, '\u{2028}' | '\u{2029}'))
            .count()
            * 3
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
    let (sign, unsigned) = shortest
        .strip_prefix('-')
        .map_or(("", shortest.as_str()), |v| ("-", v));
    let (mantissa, exponent) = unsigned.split_once('e').map_or((unsigned, 0), |(m, e)| {
        (m, e.parse::<i32>().expect("JSON exponent"))
    });
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
        return format!(
            "{sign}{}.{tail}e{}{exponent}",
            &digits[..1],
            if exponent >= 0 { "+" } else { "" }
        );
    }
    if point <= 0 {
        format!("{sign}0.{}{digits}", "0".repeat((-point) as usize))
    } else if point as usize >= digits.len() {
        format!(
            "{sign}{digits}{}",
            "0".repeat(point as usize - digits.len())
        )
    } else {
        format!(
            "{sign}{}.{}",
            &digits[..point as usize],
            &digits[point as usize..]
        )
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::Value;

    #[test]
    fn history_bytes_match_the_php_reference_for_native_json_shapes() {
        let cases: Vec<Value> = serde_json::from_str(include_str!(
            "../../../tests/Fixtures/HistoryBudget/canonical-json.json"
        ))
        .unwrap();
        for case in cases {
            assert_eq!(
                event_size(
                    case["event_type"].as_str().unwrap(),
                    case["payload_json"].as_str().unwrap()
                )
                .unwrap(),
                case["history_size_bytes"].as_u64().unwrap() as usize,
                "{}",
                case["name"]
            );
        }
    }
}
