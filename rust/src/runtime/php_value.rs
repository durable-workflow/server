//! PHP's view of a decoded JSON request: `json_decode($body, true)` gives
//! ordered arrays whose canonical decimal string keys become integers, with
//! integers beyond int64 read as floats. Laravel's TrimStrings and
//! ConvertEmptyStringsToNull then rewrite every string. The history import
//! verifies, checks and stores exactly that document, so it keeps PHP's
//! order, key types and int/float distinction rather than serde's maps.
use super::laravel_validation::laravel_trim;

#[derive(Clone, Debug)]
pub(super) enum Php {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    Str(String),
    Array(PhpArray),
}

/// A PHP array key: a canonical decimal integer string is an integer.
#[derive(Clone, Debug, PartialEq, Eq, Hash)]
pub(super) enum Key {
    Int(i64),
    Str(String),
}

impl Key {
    /// `ZEND_HANDLE_NUMERIC_STR`: `0` or an optionally negative decimal
    /// without leading zeros that fits in int64 is an integer key.
    pub(super) fn of(text: &str) -> Self {
        let digits = text.strip_prefix('-').unwrap_or(text);
        let canonical = text == "0"
            || (!digits.is_empty() && !digits.starts_with('0') && digits.bytes().all(|byte| byte.is_ascii_digit()));
        match canonical.then(|| text.parse::<i64>().ok()).flatten() {
            Some(number) => Self::Int(number),
            None => Self::Str(text.to_owned()),
        }
    }

    pub(super) fn text(&self) -> String {
        match self {
            Self::Int(number) => number.to_string(),
            Self::Str(text) => text.clone(),
        }
    }
}

/// An ordered PHP array.
#[derive(Clone, Debug, Default)]
pub(super) struct PhpArray(pub(super) Vec<(Key, Php)>);

impl PhpArray {
    pub(super) fn get(&self, key: &str) -> Option<&Php> {
        let key = Key::of(key);
        self.0.iter().find(|(candidate, _)| *candidate == key).map(|(_, value)| value)
    }

    pub(super) fn contains(&self, key: &str) -> bool {
        self.get(key).is_some()
    }

    /// `$array[$key] = $value`: an existing key keeps its position.
    pub(super) fn insert(&mut self, key: Key, value: Php) {
        match self.0.iter_mut().find(|(candidate, _)| *candidate == key) {
            Some((_, slot)) => *slot = value,
            None => self.0.push((key, value)),
        }
    }

    pub(super) fn remove(&mut self, key: &str) {
        let key = Key::of(key);
        self.0.retain(|(candidate, _)| *candidate != key);
    }

    /// `array_is_list`.
    pub(super) fn is_list(&self) -> bool {
        self.0.iter().enumerate().all(|(index, (key, _))| *key == Key::Int(index as i64))
    }

    pub(super) fn len(&self) -> usize {
        self.0.len()
    }

    pub(super) fn is_empty(&self) -> bool {
        self.0.is_empty()
    }

    pub(super) fn values(&self) -> impl Iterator<Item = &Php> {
        self.0.iter().map(|(_, value)| value)
    }
}

impl Php {
    pub(super) fn array(&self) -> Option<&PhpArray> {
        match self {
            Self::Array(array) => Some(array),
            _ => None,
        }
    }

    pub(super) fn str(&self) -> Option<&str> {
        match self {
            Self::Str(text) => Some(text),
            _ => None,
        }
    }

    /// `$value[$key] ?? null` for the named keys PHP code uses: a missing
    /// key, or a value that is not an array, reads as absent.
    pub(super) fn get(&self, key: &str) -> Option<&Php> {
        self.array().and_then(|array| array.get(key))
    }

    pub(super) fn at(&self, path: &[&str]) -> Option<&Php> {
        path.iter().try_fold(self, |value, key| value.get(key))
    }

    pub(super) fn is_array(&self) -> bool {
        matches!(self, Self::Array(_))
    }

    /// PHP's `(bool)` cast.
    pub(super) fn truthy(&self) -> bool {
        match self {
            Self::Null => false,
            Self::Bool(flag) => *flag,
            Self::Int(number) => *number != 0,
            Self::Float(number) => *number != 0.0,
            Self::Str(text) => !(text.is_empty() || text == "0"),
            Self::Array(array) => !array.is_empty(),
        }
    }

    /// `get_debug_type`.
    pub(super) fn debug_type(&self) -> &'static str {
        match self {
            Self::Null => "null",
            Self::Bool(_) => "bool",
            Self::Int(_) => "int",
            Self::Float(_) => "float",
            Self::Str(_) => "string",
            Self::Array(_) => "array",
        }
    }

    /// Laravel's global TrimStrings and ConvertEmptyStringsToNull, applied to
    /// every string at any depth (no nested key is excepted).
    pub(super) fn normalize(&mut self) {
        match self {
            Self::Str(text) => {
                let trimmed = laravel_trim(text);
                if trimmed.is_empty() {
                    *self = Self::Null;
                } else if trimmed.len() != text.len() {
                    *text = trimmed.to_owned();
                }
            }
            Self::Array(array) => array.0.iter_mut().for_each(|(_, value)| value.normalize()),
            _ => {}
        }
    }

    /// PHP's `===`: the same type and value, arrays with the same keys in
    /// the same order.
    pub(super) fn identical(&self, other: &Self) -> bool {
        match (self, other) {
            (Self::Null, Self::Null) => true,
            (Self::Bool(left), Self::Bool(right)) => left == right,
            (Self::Int(left), Self::Int(right)) => left == right,
            (Self::Float(left), Self::Float(right)) => left == right,
            (Self::Str(left), Self::Str(right)) => left == right,
            (Self::Array(left), Self::Array(right)) => {
                left.len() == right.len()
                    && left.0.iter().zip(&right.0).all(|((lk, lv), (rk, rv))| lk == rk && lv.identical(rv))
            }
            _ => false,
        }
    }

    /// `json_encode` with the given flags.
    pub(super) fn encode(&self, flags: Flags) -> String {
        let mut out = String::new();
        self.write(&mut out, flags);
        out
    }

    fn write(&self, out: &mut String, flags: Flags) {
        match self {
            Self::Null => out.push_str("null"),
            Self::Bool(flag) => out.push_str(if *flag { "true" } else { "false" }),
            Self::Int(number) => out.push_str(&number.to_string()),
            Self::Float(number) => out.push_str(&float(*number, flags.zero_fraction)),
            Self::Str(text) => string(out, text, flags),
            Self::Array(array) if array.is_list() => {
                out.push('[');
                for (index, value) in array.values().enumerate() {
                    if index > 0 {
                        out.push(',');
                    }
                    value.write(out, flags);
                }
                out.push(']');
            }
            Self::Array(array) => {
                out.push('{');
                for (index, (key, value)) in array.0.iter().enumerate() {
                    if index > 0 {
                        out.push(',');
                    }
                    string(out, &key.text(), flags);
                    out.push(':');
                    value.write(out, flags);
                }
                out.push('}');
            }
        }
    }

    /// `BundleIntegrityVerifier::canonicalize`: lists keep their order,
    /// other arrays are key-sorted as strings, recursively.
    pub(super) fn canonical(&self) -> Self {
        match self {
            Self::Array(array) if array.is_list() => {
                Self::Array(PhpArray(array.0.iter().map(|(key, value)| (key.clone(), value.canonical())).collect()))
            }
            Self::Array(array) => {
                let mut entries: Vec<(Key, Php)> =
                    array.0.iter().map(|(key, value)| (key.clone(), value.canonical())).collect();
                entries.sort_by(|(left, _), (right, _)| left.text().cmp(&right.text()));
                Self::Array(PhpArray(entries))
            }
            other => other.clone(),
        }
    }

    /// The document PHP stores and reads back for this value through a JSON
    /// column (`json_encode` without flags, then `json_decode`).
    pub(super) fn to_json(&self) -> serde_json::Value {
        serde_json::from_str(&self.encode(Flags::default())).unwrap_or(serde_json::Value::Null)
    }
}

/// `json_encode` options. The default escapes slashes and non-ASCII text
/// and prints integral floats without a fraction, as PHP does.
#[derive(Clone, Copy, Default)]
pub(super) struct Flags {
    pub(super) unescaped_slashes: bool,
    pub(super) unescaped_unicode: bool,
    pub(super) zero_fraction: bool,
}

/// `JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION`, the history
/// bundle's canonical encoding.
pub(super) const CANONICAL: Flags = Flags { unescaped_slashes: true, unescaped_unicode: false, zero_fraction: true };

/// PHP's float output under `serialize_precision = -1`: the shortest digits
/// that read back exactly, in fixed notation unless the decimal point falls
/// before four leading zeros or after fifteen digits; `.0` is added to an
/// integral fixed value only with JSON_PRESERVE_ZERO_FRACTION.
pub(super) fn float(value: f64, zero_fraction: bool) -> String {
    if value == 0.0 {
        let sign = if value.is_sign_negative() { "-" } else { "" };
        return format!("{sign}0{}", if zero_fraction { ".0" } else { "" });
    }
    let scientific = format!("{:e}", value.abs());
    let (mantissa, exponent) = scientific.split_once('e').unwrap_or((&scientific, "0"));
    let digits = mantissa.replace('.', "");
    let exponent: i32 = exponent.parse().unwrap_or(0);
    let point = exponent + 1;
    let sign = if value.is_sign_negative() { "-" } else { "" };
    if point < -3 || point > 15 {
        let mantissa = if digits.len() == 1 { format!("{digits}.0") } else { format!("{}.{}", &digits[..1], &digits[1..]) };
        format!("{sign}{mantissa}e{}{}", if exponent < 0 { '-' } else { '+' }, exponent.abs())
    } else if point <= 0 {
        format!("{sign}0.{}{digits}", "0".repeat((-point) as usize))
    } else if digits.len() as i32 <= point {
        let fraction = if zero_fraction { ".0" } else { "" };
        format!("{sign}{digits}{}{fraction}", "0".repeat((point - digits.len() as i32) as usize))
    } else {
        format!("{sign}{}.{}", &digits[..point as usize], &digits[point as usize..])
    }
}

fn string(out: &mut String, text: &str, flags: Flags) {
    out.push('"');
    for ch in text.chars() {
        match ch {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '/' if !flags.unescaped_slashes => out.push_str("\\/"),
            '\u{8}' => out.push_str("\\b"),
            '\u{c}' => out.push_str("\\f"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            ch if (ch as u32) < 0x20 => out.push_str(&format!("\\u{:04x}", ch as u32)),
            // Line and paragraph separators stay escaped without
            // JSON_UNESCAPED_LINE_TERMINATORS.
            '\u{2028}' | '\u{2029}' => out.push_str(&format!("\\u{:04x}", ch as u32)),
            ch if (ch as u32) < 0x80 || flags.unescaped_unicode => out.push(ch),
            ch => {
                let mut units = [0u16; 2];
                for unit in ch.encode_utf16(&mut units) {
                    out.push_str(&format!("\\u{unit:04x}"));
                }
            }
        }
    }
    out.push('"');
}

/// `json_decode($text, true)` with PHP's number and key rules. Floats are
/// read with Rust's correctly rounded parser, as PHP's `zend_strtod` reads
/// them. `None` for text that is not one JSON document.
pub(super) fn decode(text: &[u8]) -> Option<Php> {
    let mut parser = Parser { bytes: text, at: 0, depth: 0 };
    let value = parser.value()?;
    parser.space();
    (parser.at == parser.bytes.len()).then_some(value)
}

struct Parser<'a> {
    bytes: &'a [u8],
    at: usize,
    depth: usize,
}

impl Parser<'_> {
    fn space(&mut self) {
        while matches!(self.bytes.get(self.at), Some(b' ' | b'\t' | b'\n' | b'\r')) {
            self.at += 1;
        }
    }

    fn literal(&mut self, word: &[u8], value: Php) -> Option<Php> {
        self.bytes[self.at..].starts_with(word).then(|| {
            self.at += word.len();
            value
        })
    }

    fn value(&mut self) -> Option<Php> {
        self.space();
        match *self.bytes.get(self.at)? {
            b'n' => self.literal(b"null", Php::Null),
            b't' => self.literal(b"true", Php::Bool(true)),
            b'f' => self.literal(b"false", Php::Bool(false)),
            b'"' => self.string().map(Php::Str),
            b'[' => self.nested(Self::json_list),
            b'{' => self.nested(Self::json_object),
            _ => self.number(),
        }
    }

    /// A JSON array as a PHP list, keys 0..n.
    fn json_list(parser: &mut Self) -> Option<PhpArray> {
        let mut array = PhpArray::default();
        parser.space();
        if parser.bytes.get(parser.at) == Some(&b']') {
            parser.at += 1;
            return Some(array);
        }
        loop {
            let value = parser.value()?;
            array.0.push((Key::Int(array.0.len() as i64), value));
            parser.space();
            match parser.bytes.get(parser.at)? {
                b',' => parser.at += 1,
                b']' => {
                    parser.at += 1;
                    return Some(array);
                }
                _ => return None,
            }
        }
    }

    /// A JSON object as a PHP array, keeping each key's first position.
    fn json_object(parser: &mut Self) -> Option<PhpArray> {
        let mut array = PhpArray::default();
        // Each key's position, so a large object parses in linear time.
        let mut positions: std::collections::HashMap<Key, usize> = std::collections::HashMap::new();
        parser.space();
        if parser.bytes.get(parser.at) == Some(&b'}') {
            parser.at += 1;
            return Some(array);
        }
        loop {
            parser.space();
            if parser.bytes.get(parser.at) != Some(&b'"') {
                return None;
            }
            let key = parser.string()?;
            parser.space();
            if parser.bytes.get(parser.at) != Some(&b':') {
                return None;
            }
            parser.at += 1;
            let value = parser.value()?;
            // A repeated key replaces the earlier value in place.
            let key = Key::of(&key);
            match positions.get(&key) {
                Some(&index) => array.0[index].1 = value,
                None => {
                    positions.insert(key.clone(), array.0.len());
                    array.0.push((key, value));
                }
            }
            parser.space();
            match parser.bytes.get(parser.at)? {
                b',' => parser.at += 1,
                b'}' => {
                    parser.at += 1;
                    return Some(array);
                }
                _ => return None,
            }
        }
    }

    /// PHP's default decoding depth is 512.
    fn nested(&mut self, body: impl FnOnce(&mut Self) -> Option<PhpArray>) -> Option<Php> {
        self.depth += 1;
        if self.depth > 512 {
            return None;
        }
        self.at += 1;
        let array = body(self)?;
        self.depth -= 1;
        Some(Php::Array(array))
    }

    fn number(&mut self) -> Option<Php> {
        let start = self.at;
        let digits = |parser: &mut Self| {
            let begin = parser.at;
            while parser.bytes.get(parser.at).is_some_and(u8::is_ascii_digit) {
                parser.at += 1;
            }
            parser.at > begin
        };
        if self.bytes.get(self.at) == Some(&b'-') {
            self.at += 1;
        }
        let first = *self.bytes.get(self.at)?;
        if first == b'0' {
            self.at += 1;
        } else if !digits(self) {
            return None;
        }
        let mut integral = true;
        if self.bytes.get(self.at) == Some(&b'.') {
            self.at += 1;
            integral = false;
            if !digits(self) {
                return None;
            }
        }
        if matches!(self.bytes.get(self.at), Some(b'e' | b'E')) {
            self.at += 1;
            integral = false;
            if matches!(self.bytes.get(self.at), Some(b'+' | b'-')) {
                self.at += 1;
            }
            if !digits(self) {
                return None;
            }
        }
        let text = std::str::from_utf8(&self.bytes[start..self.at]).ok()?;
        // An integer beyond int64 decodes as a float.
        if integral && let Ok(number) = text.parse::<i64>() {
            return Some(Php::Int(number));
        }
        text.parse::<f64>().ok().filter(|value| value.is_finite()).map(Php::Float)
    }

    fn hex(&mut self) -> Option<u32> {
        let text = std::str::from_utf8(self.bytes.get(self.at..self.at + 4)?).ok()?;
        self.at += 4;
        u32::from_str_radix(text, 16).ok()
    }

    fn string(&mut self) -> Option<String> {
        self.at += 1;
        let mut out = String::new();
        loop {
            let start = self.at;
            while !matches!(self.bytes.get(self.at), Some(b'"' | b'\\') | None) {
                if self.bytes[self.at] < 0x20 {
                    return None;
                }
                self.at += 1;
            }
            out.push_str(std::str::from_utf8(&self.bytes[start..self.at]).ok()?);
            match *self.bytes.get(self.at)? {
                b'"' => {
                    self.at += 1;
                    return Some(out);
                }
                _ => {
                    self.at += 1;
                    let escape = *self.bytes.get(self.at)?;
                    self.at += 1;
                    match escape {
                        b'"' => out.push('"'),
                        b'\\' => out.push('\\'),
                        b'/' => out.push('/'),
                        b'b' => out.push('\u{8}'),
                        b'f' => out.push('\u{c}'),
                        b'n' => out.push('\n'),
                        b'r' => out.push('\r'),
                        b't' => out.push('\t'),
                        b'u' => {
                            let unit = self.hex()?;
                            let code = if (0xd800..0xdc00).contains(&unit) {
                                // A high surrogate needs its low half.
                                if self.bytes.get(self.at..self.at + 2) != Some(&b"\\u"[..]) {
                                    return None;
                                }
                                self.at += 2;
                                let low = self.hex()?;
                                if !(0xdc00..0xe000).contains(&low) {
                                    return None;
                                }
                                0x10000 + ((unit - 0xd800) << 10) + (low - 0xdc00)
                            } else if (0xdc00..0xe000).contains(&unit) {
                                return None;
                            } else {
                                unit
                            };
                            out.push(char::from_u32(code)?);
                        }
                        _ => return None,
                    }
                }
            }
        }
    }
}

/// Laravel's `$request->json()`: the body decoded as a PHP array (a scalar
/// body is cast to a one-element array), then normalized.
pub(super) fn request_body(bytes: &[u8]) -> PhpArray {
    let decoded = if bytes.iter().all(u8::is_ascii_whitespace) {
        Php::Array(PhpArray::default())
    } else {
        decode(bytes).unwrap_or(Php::Null)
    };
    let mut body = match decoded {
        Php::Array(array) => array,
        Php::Null => PhpArray::default(),
        scalar => PhpArray(vec![(Key::Int(0), scalar)]),
    };
    body.0.iter_mut().for_each(|(_, value)| value.normalize());
    body
}

/// PHP's `is_numeric` for a string: optional leading whitespace, a sign,
/// digits with an optional fraction and exponent, optional trailing
/// whitespace.
pub(super) fn numeric(text: &str) -> Option<f64> {
    let trimmed = text.trim_start_matches([' ', '\t', '\n', '\r', '\x0b', '\x0c']).trim_end_matches([' ', '\t', '\n', '\r', '\x0b', '\x0c']);
    let body = trimmed.strip_prefix(['+', '-']).unwrap_or(trimmed);
    let (mantissa, exponent) = match body.find(['e', 'E']) {
        Some(at) => (&body[..at], Some(&body[at + 1..])),
        None => (body, None),
    };
    let (whole, fraction) = mantissa.split_once('.').unwrap_or((mantissa, ""));
    let digits = |part: &str| part.bytes().all(|byte| byte.is_ascii_digit());
    if (whole.is_empty() && fraction.is_empty()) || !digits(whole) || !digits(fraction) {
        return None;
    }
    if let Some(exponent) = exponent {
        let exponent = exponent.strip_prefix(['+', '-']).unwrap_or(exponent);
        if exponent.is_empty() || !digits(exponent) {
            return None;
        }
    }
    trimmed.parse::<f64>().ok()
}

/// PHP's `(int)` cast of a numeric string.
pub(super) fn int_cast(text: &str) -> Option<i64> {
    let value = numeric(text)?;
    let trimmed = text.trim_matches([' ', '\t', '\n', '\r', '\x0b', '\x0c']);
    if let Ok(number) = trimmed.strip_prefix('+').unwrap_or(trimmed).parse::<i64>() {
        return Some(number);
    }
    // Out-of-range or fractional values truncate; PHP 8 saturates neither,
    // and an out-of-range float cast is undefined, so refuse it.
    (value.is_finite() && value.abs() < 9.2e18).then(|| value.trunc() as i64)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn decode(text: &str) -> Php {
        super::decode(text.as_bytes()).unwrap()
    }

    #[test]
    fn decoding_keeps_php_key_types_order_and_numbers() {
        let value = decode(r#"{"b":1,"0":2,"a":{"x":1},"b":3,"01":4,"-0":5,"-7":6,"big":18446744073709551615}"#);
        let array = value.array().unwrap();
        let keys: Vec<Key> = array.0.iter().map(|(key, _)| key.clone()).collect();
        assert_eq!(keys, [Key::Str("b".into()), Key::Int(0), Key::Str("a".into()), Key::Str("01".into()),
            Key::Str("-0".into()), Key::Int(-7), Key::Str("big".into())]);
        assert!(matches!(array.get("b"), Some(Php::Int(3))));
        assert!(matches!(array.get("big"), Some(Php::Float(_))));
        assert!(decode("[1,2]").array().unwrap().is_list());
        assert!(decode(r#"{"0":1,"1":2}"#).array().unwrap().is_list());
        assert!(!decode(r#"{"1":1,"0":2}"#).array().unwrap().is_list());
    }

    #[test]
    fn canonical_encoding_matches_php() {
        // Published PHP printed {"k":"\u03bb/\u00e9","f":1.0,...} for these values under
        // JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION, keys in insertion order
        // (canonicalization sorts them).
        let value = decode(r#"{"k":"λ/é","f":1.0,"e":1e25,"o":{},"n":["a","b"],"big":18446744073709551615}"#);
        assert_eq!(value.encode(CANONICAL),
            r#"{"k":"\u03bb/\u00e9","f":1.0,"e":1.0e+25,"o":[],"n":["a","b"],"big":1.8446744073709552e+19}"#);
        assert_eq!(value.canonical().encode(CANONICAL),
            r#"{"big":1.8446744073709552e+19,"e":1.0e+25,"f":1.0,"k":"\u03bb/\u00e9","n":["a","b"],"o":[]}"#);
        // Sorted keys 0..n-1 encode as a list; string order puts "10" first.
        assert_eq!(decode(r#"{"1":"a","0":"b"}"#).canonical().encode(CANONICAL), r#"["b","a"]"#);
        assert_eq!(decode(r#"{"x":{"2":1,"10":2}}"#).canonical().encode(CANONICAL), r#"{"x":{"10":2,"2":1}}"#);
        // Published PHP printed "a\/b\u0001\u2028\ud83d\ude00" for this value.
        assert_eq!(decode(r#""a/b\u0001\u2028\ud83d\ude00""#).encode(Flags::default()), r#""a\/b\u0001\u2028\ud83d\ude00""#);
        assert_eq!(float(1.0, false), "1");
        assert_eq!(float(-0.0, true), "-0.0");
        assert_eq!(float(0.1, false), "0.1");
        assert_eq!(float(1e-7, false), "1.0e-7");
    }

    #[test]
    fn requests_are_normalized_like_laravel() {
        let body = request_body(br#"{"a":"  x  ","b":"   ","c":["",{"d":"\u00a0y"}],"e":5}"#);
        assert_eq!(Php::Array(body).encode(Flags::default()), r#"{"a":"x","b":null,"c":[null,{"d":"y"}],"e":5}"#);
        assert_eq!(Php::Array(request_body(b"\"x\"")).encode(Flags::default()), r#"["x"]"#);
        assert!(request_body(b"null").is_empty());
        assert!(Php::Int(1).identical(&Php::Int(1)) && !Php::Int(1).identical(&Php::Float(1.0)));
        assert!(!decode(r#"{"a":1,"b":2}"#).identical(&decode(r#"{"b":2,"a":1}"#)));
        assert_eq!(numeric(" 12 "), Some(12.0));
        assert_eq!(numeric("1e3"), Some(1000.0));
        assert_eq!(numeric("1e"), None);
        assert_eq!(numeric("."), None);
        assert_eq!(int_cast("12.9"), Some(12));
    }
}
