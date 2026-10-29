//! Workflow 2.15.1 `WorkflowQueryContract::validatedArgumentsForRun`: a
//! declared query's arguments, positional or named, matched to its durable
//! parameter contract. The broker sends the worker the normalized positional
//! list and refuses the query with every violation it finds.
use crate::codec::OrderedValue as Value;
use serde_json::{Map, Value as Json};

/// A query's decoded arguments, as PHP holds them after `Avro::fromDatum`:
/// a list, or a string-keyed map of named arguments.
#[derive(Clone)]
pub(super) enum Arguments {
    Positional(Vec<Value>),
    Named(Vec<(String, Value)>),
}

impl Arguments {
    /// An Avro array is a list. A map is a PHP array of named arguments only
    /// when `Avro::fromDatum` makes it one: non-empty, with no key PHP casts
    /// to an integer. Any other map arrives as an `AvroMapValue` object,
    /// which is not an array at all.
    pub(super) fn from_value(value: Value) -> Option<Self> {
        match value {
            Value::Array(values) => Some(Self::Positional(values)),
            Value::Map(entries) if !is_adapter(&entries) => Some(Self::Named(entries)),
            _ => None,
        }
    }
}

/// Whether `Avro::fromDatum` returns this map as an `AvroMapValue` object:
/// it is empty, or one of its keys is a canonical decimal integer, which a
/// PHP array would turn into an integer key.
fn is_adapter(entries: &[(String, Value)]) -> bool {
    entries.is_empty() || entries.iter().any(|(key, _)| integer_key(key))
}

/// PHP's integer array keys: `0`, or an optional minus sign and a digit
/// string without a leading zero that fits in a 64-bit integer.
fn integer_key(key: &str) -> bool {
    let digits = key.strip_prefix('-').unwrap_or(key);
    let canonical = key == "0"
        || (!digits.is_empty() && !digits.starts_with('0') && digits.bytes().all(|byte| byte.is_ascii_digit()));
    canonical && key.parse::<i64>().is_ok()
}

/// Whether PHP holds this decoded value as an array.
fn is_array(value: &Value) -> bool {
    match value {
        Value::Array(_) => true,
        Value::Map(entries) => !is_adapter(entries),
        _ => false,
    }
}

/// Whether PHP holds this decoded value as an object: bytes arrive as an
/// `AvroBinaryValue`, and an adapter map as an `AvroMapValue`.
fn is_object(value: &Value) -> bool {
    match value {
        Value::Bytes(_) => true,
        Value::Map(entries) => is_adapter(entries),
        _ => false,
    }
}

/// The validated arguments, or the violations by argument name, in the
/// order PHP reports them.
pub(super) type Validated = Result<Vec<Value>, Refusal>;

/// Why a declared query's arguments are not routed.
#[derive(Debug, PartialEq)]
pub(super) enum Refusal {
    /// The violations by argument, as PHP's `validation_errors`.
    Invalid(Vec<(String, Vec<String>)>),
    /// A missing argument's registered default that PHP's Avro encoder
    /// cannot write: an array with an integer key that is not a list. PHP
    /// fails with an uncaught `invalid_map_key` (500).
    UnencodableDefault(String),
}

/// `validatedArgumentsForRun` for a run whose durable contract may hold this
/// query's parameter contract. A native server never loads a workflow class,
/// so a query without one takes a list as given and refuses named arguments.
pub(super) fn validate(contract: Option<&Json>, query: &str, arguments: Arguments) -> Validated {
    let Some(contract) = contract else {
        return match arguments {
            Arguments::Positional(values) => Ok(values),
            Arguments::Named(_) => Err(Refusal::Invalid(vec![("arguments".to_owned(),
                vec!["Named arguments require a durable or loadable workflow query contract.".to_owned()])])),
        };
    };
    let parameters = contract["parameters"].as_array().cloned().unwrap_or_default();
    let mut errors = Errors::default();
    let normalized = match arguments {
        Arguments::Positional(values) => positional(&parameters, query, &values, &mut errors),
        Arguments::Named(entries) => named(&parameters, &entries, &mut errors),
    };
    errors.into_result(normalized)
}

/// `normalizePositionalArguments`.
fn positional(parameters: &[Json], query: &str, values: &[Value], errors: &mut Errors) -> Vec<Value> {
    let mut normalized = Vec::new();
    let mut consumed = 0;
    for parameter in parameters {
        let take = if parameter["variadic"] == true { values.len() - consumed } else { usize::from(consumed < values.len()) };
        if take > 0 {
            for value in &values[consumed..consumed + take] {
                errors.check(parameter, value);
                normalized.push(value.clone());
            }
            consumed += take;
        } else if parameter["variadic"] != true {
            missing(parameter, &mut normalized, errors);
        }
    }
    if consumed < values.len() {
        errors.push("arguments", format!("Too many arguments were provided for query [{query}]."));
    }
    normalized
}

/// `normalizeNamedArguments`.
fn named(parameters: &[Json], entries: &[(String, Value)], errors: &mut Errors) -> Vec<Value> {
    let mut normalized = Vec::new();
    let value_of = |name: &str| entries.iter().find(|(key, _)| key == name).map(|(_, value)| value);
    for parameter in parameters {
        let name = parameter["name"].as_str().unwrap_or_default();
        match (value_of(name), parameter["variadic"] == true) {
            (Some(value), true) => {
                for value in spread(value) {
                    errors.check(parameter, value);
                    normalized.push(value.clone());
                }
            }
            (Some(value), false) => {
                errors.check(parameter, value);
                normalized.push(value.clone());
            }
            (None, true) => {}
            (None, false) => missing(parameter, &mut normalized, errors),
        }
    }
    for (key, _) in entries {
        if !parameters.iter().any(|parameter| parameter["name"] == key.as_str()) {
            errors.push(key, format!("Unknown argument [{key}]."));
        }
    }
    normalized
}

/// A variadic named value spreads a PHP array's values; any other value,
/// an object included, is one argument.
fn spread(value: &Value) -> Vec<&Value> {
    match value {
        Value::Array(values) => values.iter().collect(),
        Value::Map(values) if !is_adapter(values) => values.iter().map(|(_, value)| value).collect(),
        value => vec![value],
    }
}

/// A missing argument takes its default, or is reported if required.
fn missing(parameter: &Json, normalized: &mut Vec<Value>, errors: &mut Errors) {
    if parameter["default_available"] == true {
        match from_json(&parameter["default"]) {
            Some(value) => normalized.push(value),
            None => {
                errors.unencodable.get_or_insert_with(|| parameter["name"].as_str().unwrap_or_default().to_owned());
            }
        }
    } else if parameter["required"] == true {
        errors.required(parameter);
    }
}

/// The violations by argument name, keeping PHP's first-seen key order.
#[derive(Default)]
struct Errors {
    found: Vec<(String, Vec<String>)>,
    /// The first parameter whose default PHP cannot encode.
    unencodable: Option<String>,
}

impl Errors {
    fn push(&mut self, key: &str, message: String) {
        match self.found.iter_mut().find(|(name, _)| name == key) {
            Some((_, messages)) => messages.push(message),
            None => self.found.push((key.to_owned(), vec![message])),
        }
    }

    fn required(&mut self, parameter: &Json) {
        let name = parameter["name"].as_str().unwrap_or_default();
        self.push(name, format!("The {name} argument is required."));
    }

    /// `appendParameterValidationErrors`.
    fn check(&mut self, parameter: &Json, value: &Value) {
        let name = parameter["name"].as_str().unwrap_or("argument");
        if let Some(message) = parameter_error(parameter, name, value) {
            self.push(name, message);
        }
    }

    /// Violations answer first; PHP encodes the arguments only after they
    /// validate.
    fn into_result(self, normalized: Vec<Value>) -> Validated {
        match (self.found.is_empty(), self.unencodable) {
            (false, _) => Err(Refusal::Invalid(self.found)),
            (true, Some(parameter)) => Err(Refusal::UnencodableDefault(parameter)),
            (true, None) => Ok(normalized),
        }
    }
}

/// `validationErrorsForParameterValue`.
fn parameter_error(parameter: &Json, name: &str, value: &Value) -> Option<String> {
    let declared = parameter["type"].as_str().map(str::trim);
    if matches!(value, Value::Null) {
        let allows = match parameter["allows_null"].as_bool() {
            Some(allows) => allows,
            None => declared.is_none_or(|kind| {
                kind.is_empty() || kind.starts_with('?') || split(kind, '|').iter().any(|part| *part == "null")
            }),
        };
        return (!allows).then(|| format!("The {name} argument cannot be null."));
    }
    let kind = declared.filter(|kind| !kind.is_empty() && *kind != "mixed")?;
    (!matches_type(value, kind)).then(|| format!("The {name} argument must be of type {kind}."))
}

/// `splitDeclaredType`: the parts of a union or intersection, outside
/// parentheses.
fn split(kind: &str, delimiter: char) -> Vec<&str> {
    let mut parts = Vec::new();
    let mut depth = 0i32;
    let mut start = 0;
    for (index, character) in kind.char_indices() {
        match character {
            '(' => depth += 1,
            ')' => depth -= 1,
            _ if character == delimiter && depth == 0 => {
                parts.push(kind[start..index].trim());
                start = index + 1;
            }
            _ => {}
        }
    }
    parts.push(kind[start..].trim());
    parts.into_iter().filter(|part| !part.is_empty()).collect()
}

/// `valueMatchesDeclaredType` over the decoded Avro values PHP sees: bytes
/// and adapter maps arrive as objects, every other value as a PHP scalar or
/// array.
fn matches_type(value: &Value, kind: &str) -> bool {
    let kind = kind.trim();
    if kind.is_empty() || kind == "mixed" {
        return true;
    }
    if let Some(inner) = kind.strip_prefix('?') {
        return matches!(value, Value::Null) || matches_type(value, inner);
    }
    let union = split(kind, '|');
    if union.len() > 1 {
        return union.iter().any(|part| matches_type(value, part));
    }
    let intersection = split(kind, '&');
    if intersection.len() > 1 {
        return intersection.iter().all(|part| matches_type(value, part));
    }
    let kind = kind.trim_matches(|character: char| character == '(' || character == ')' || character.is_whitespace() || character == '\0');
    let object = is_object(value);
    match kind {
        "int" => matches!(value, Value::Long(_)),
        "float" => matches!(value, Value::Double(_) | Value::Long(_)),
        "string" => matches!(value, Value::String(_)),
        "bool" => matches!(value, Value::Boolean(_)),
        "array" | "iterable" => is_array(value),
        "scalar" => matches!(value, Value::Long(_) | Value::Double(_) | Value::String(_) | Value::Boolean(_)),
        "true" => matches!(value, Value::Boolean(true)),
        "false" => matches!(value, Value::Boolean(false)),
        "null" => matches!(value, Value::Null),
        "mixed" => true,
        "never" | "void" => false,
        // Neither decoded object (`AvroBinaryValue`, `AvroMapValue`) is
        // invokable. A string naming a PHP function would be callable, but a
        // native server has no PHP function table.
        "callable" => false,
        // A named class: the server loads no class, so any object passes
        // (`! class_exists($type)`), as do `object` and the relative types.
        _ => object,
    }
}

/// A registered JSON default as the Avro value PHP's serializer encodes, or
/// `None` where it cannot. PHP decodes the registration into arrays whose
/// canonical integer keys are integers. A list (keys 0 to n-1, including an
/// empty object) is an Avro array; an array with only string keys is a map;
/// an integer key in any other array fails PHP's encoder.
///
/// Native's JSON objects keep their keys sorted, not in the registered
/// order, so integer keys are read in numeric order and a map's entries in
/// key order.
fn from_json(value: &Json) -> Option<Value> {
    Some(match value {
        Json::Null => Value::Null,
        Json::Bool(flag) => Value::Boolean(*flag),
        Json::Number(number) => number.as_i64().map(Value::Long).unwrap_or_else(|| Value::Double(number.as_f64().unwrap_or_default())),
        Json::String(text) => Value::String(text.clone()),
        Json::Array(items) => Value::Array(items.iter().map(from_json).collect::<Option<_>>()?),
        Json::Object(entries) => {
            let integers: Option<Vec<(i64, &Json)>> = entries.iter()
                .map(|(key, value)| integer_key(key).then(|| (key.parse::<i64>().ok().map(|key| (key, value)))).flatten())
                .collect();
            match integers {
                Some(mut items) => {
                    items.sort_by_key(|(key, _)| *key);
                    if !items.iter().enumerate().all(|(position, (key, _))| i64::try_from(position) == Ok(*key)) {
                        return None;
                    }
                    Value::Array(items.into_iter().map(|(_, value)| from_json(value)).collect::<Option<_>>()?)
                }
                None if entries.keys().any(|key| integer_key(key)) => return None,
                None => Value::Map(entries.iter().map(|(key, value)| Some((key.clone(), from_json(value)?))).collect::<Option<_>>()?),
            }
        }
    })
}

/// The violations as PHP's `validation_errors` object.
pub(super) fn errors_json(errors: &[(String, Vec<String>)]) -> Json {
    let mut object = Map::new();
    for (key, messages) in errors {
        object.insert(key.clone(), Json::from(messages.clone()));
    }
    Json::Object(object)
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn invalid(validated: Validated) -> Option<Vec<(String, Vec<String>)>> {
        match validated {
            Err(Refusal::Invalid(errors)) => Some(errors),
            _ => None,
        }
    }

    #[test]
    fn a_registered_default_takes_phps_array_shape() {
        let with_default = |default: Json| json!({"name":"q","parameters":[{"name":"value","type":"mixed","required":false,
            "variadic":false,"default_available":true,"allows_null":false,"default":default}]});
        let filled = |default: Json| validate(Some(&with_default(default)), "q", Arguments::Positional(vec![]));
        let strings = |items: &[&str]| Value::Array(items.iter().map(|item| Value::String((*item).into())).collect());
        // A list, in numeric key order even past ten keys; an empty object.
        assert_eq!(filled(json!({"0":"x","1":"y"})), Ok(vec![strings(&["x", "y"])]));
        let eleven: serde_json::Map<String, Json> = (0..11).map(|key| (key.to_string(), json!(key.to_string()))).collect();
        let expected: Vec<String> = (0..11).map(|key| key.to_string()).collect();
        assert_eq!(filled(Json::Object(eleven)), Ok(vec![strings(&expected.iter().map(String::as_str).collect::<Vec<_>>())]));
        assert_eq!(filled(json!({})), Ok(vec![Value::Array(vec![])]));
        assert_eq!(filled(json!({"a":{"0":1}})), Ok(vec![Value::Map(vec![("a".into(), Value::Array(vec![Value::Long(1)]))])]));
        // An integer key outside a list fails PHP's encoder, nested or not.
        for default in [json!({"0":"x","k":"y"}), json!({"1":"x"}), json!({"a":{"1":"x"}}), json!([{"0":"x","2":"y"}])] {
            assert_eq!(filled(default.clone()), Err(Refusal::UnencodableDefault("value".into())), "{default}");
        }
        // Violations answer before the encoder runs.
        let mut both = with_default(json!({"1":"x"}));
        both["parameters"].as_array_mut().unwrap().push(json!({"name":"other","type":"int","required":true,"variadic":false,
            "default_available":false,"allows_null":false}));
        assert_eq!(invalid(validate(Some(&both), "q", Arguments::Positional(vec![]))),
            Some(vec![("other".to_owned(), vec!["The other argument is required.".to_owned()])]));
    }

    fn state() -> Json {
        json!({"name":"state","parameters":[{"name":"prefix","type":"string","required":true,"variadic":false,
            "default_available":false,"allows_null":false}]})
    }

    fn named(pairs: &[(&str, Value)]) -> Arguments {
        Arguments::Named(pairs.iter().map(|(key, value)| ((*key).to_owned(), value.clone())).collect())
    }

    #[test]
    fn named_and_positional_arguments_normalize_to_the_contract_order() {
        assert_eq!(validate(Some(&state()), "state", named(&[("prefix", Value::String("ok".into()))])).ok(),
            Some(vec![Value::String("ok".into())]));
        assert_eq!(validate(Some(&state()), "state", Arguments::Positional(vec![Value::String("ok".into())])).ok(),
            Some(vec![Value::String("ok".into())]));
        let greet = json!({"name":"greet","parameters":[{"name":"prefix","type":"string","required":false,"variadic":false,
            "default_available":true,"default":"dflt","allows_null":false}]});
        assert_eq!(validate(Some(&greet), "greet", Arguments::Positional(vec![])).ok(), Some(vec![Value::String("dflt".into())]));
    }

    #[test]
    fn violations_carry_phps_messages() {
        let refused = |arguments| invalid(validate(Some(&state()), "state", arguments)).unwrap();
        assert_eq!(refused(named(&[("other", Value::String("x".into()))])), vec![
            ("prefix".to_owned(), vec!["The prefix argument is required.".to_owned()]),
            ("other".to_owned(), vec!["Unknown argument [other].".to_owned()])]);
        assert_eq!(refused(Arguments::Positional(vec![Value::Long(5)])),
            vec![("prefix".to_owned(), vec!["The prefix argument must be of type string.".to_owned()])]);
        assert_eq!(refused(Arguments::Positional(vec![Value::Null])),
            vec![("prefix".to_owned(), vec!["The prefix argument cannot be null.".to_owned()])]);
        assert_eq!(refused(Arguments::Positional(vec![Value::String("a".into()), Value::String("b".into())])),
            vec![("arguments".to_owned(), vec!["Too many arguments were provided for query [state].".to_owned()])]);
        assert_eq!(invalid(validate(None, "plain", named(&[("prefix", Value::Null)]))).unwrap(),
            vec![("arguments".to_owned(), vec!["Named arguments require a durable or loadable workflow query contract.".to_owned()])]);
    }

    #[test]
    fn declared_types_follow_php() {
        for (kind, value, expected) in [
            ("?string", Value::Null, true), ("int|string", Value::String("x".into()), true), ("int|string", Value::Boolean(true), false),
            ("float", Value::Long(1), true), ("array", Value::Map(vec![]), false), ("scalar", Value::Array(vec![]), false),
            ("object", Value::Bytes(vec![1]), true), ("App\\Thing", Value::String("x".into()), false), ("true", Value::Boolean(false), false),
        ] {
            assert_eq!(matches_type(&value, kind), expected, "{kind}");
        }
    }

    #[test]
    fn variadics_take_the_rest_or_a_named_list() {
        let items = json!({"name":"items","parameters":[
            {"name":"first","type":"int","required":true,"variadic":false,"default_available":false,"allows_null":false},
            {"name":"rest","type":"int","required":false,"variadic":true,"default_available":false,"allows_null":false}]});
        let longs = |values: &[i64]| values.iter().map(|value| Value::Long(*value)).collect::<Vec<_>>();
        assert_eq!(validate(Some(&items), "items", Arguments::Positional(longs(&[1, 2, 3]))).ok(), Some(longs(&[1, 2, 3])));
        assert_eq!(validate(Some(&items), "items", Arguments::Positional(longs(&[1]))).ok(), Some(longs(&[1])));
        assert_eq!(validate(Some(&items), "items", named(&[("first", Value::Long(1)), ("rest", Value::Array(longs(&[2, 3])))])).ok(),
            Some(longs(&[1, 2, 3])));
        assert_eq!(invalid(validate(Some(&items), "items", Arguments::Positional(vec![Value::Long(1), Value::String("x".into())]))),
            Some(vec![("rest".to_owned(), vec!["The rest argument must be of type int.".to_owned()])]));
    }

    #[test]
    fn empty_and_integer_keyed_maps_are_objects_not_arrays() {
        let map = |pairs: &[(&str, Value)]| Value::Map(pairs.iter().map(|(key, value)| ((*key).to_owned(), value.clone())).collect());
        for value in [map(&[]), map(&[("0", Value::String("ok".into()))]), map(&[("0", Value::Long(1)), ("prefix", Value::Long(2))]),
            map(&[("-7", Value::Null)])] {
            assert!(Arguments::from_value(value.clone()).is_none(), "{value:?}");
            assert!(is_object(&value) && !is_array(&value), "{value:?}");
        }
        for key in ["00", "-0", "01", "1.0", " 1", "9223372036854775808", "prefix"] {
            let value = map(&[(key, Value::Long(1))]);
            assert!(matches!(Arguments::from_value(value.clone()), Some(Arguments::Named(_))), "{key}");
            assert!(is_array(&value), "{key}");
        }
        assert!(Arguments::from_value(Value::String("x".into())).is_none());
    }

    #[test]
    fn a_named_variadic_map_spreads_in_wire_order() {
        let items = json!({"name":"items","parameters":[{"name":"rest","type":"int","required":false,"variadic":true,
            "default_available":false,"allows_null":false}]});
        let rest = named(&[("rest", Value::Map(vec![("z".into(), Value::Long(20)), ("a".into(), Value::Long(10))]))]);
        assert_eq!(validate(Some(&items), "items", rest).ok(), Some(vec![Value::Long(20), Value::Long(10)]));
        assert!(!matches_type(&Value::Bytes(vec![1]), "callable"));
        assert!(!matches_type(&Value::Map(vec![]), "callable"));
    }

    #[test]
    fn a_nested_adapter_map_is_an_object_and_one_variadic_argument() {
        let nested = named(&[("value", Value::Map(vec![]))]);
        let contract = |kind: &str, variadic: bool| json!({"name":"q","parameters":[{"name":"value","type":kind,"required":!variadic,
            "variadic":variadic,"default_available":false,"allows_null":false}]});
        assert_eq!(invalid(validate(Some(&contract("array", false)), "q", nested.clone())),
            Some(vec![("value".to_owned(), vec!["The value argument must be of type array.".to_owned()])]));
        assert_eq!(validate(Some(&contract("object", false)), "q", nested.clone()).ok(), Some(vec![Value::Map(vec![])]));
        assert_eq!(validate(Some(&contract("mixed", true)), "q", nested).ok(), Some(vec![Value::Map(vec![])]));
    }
}
