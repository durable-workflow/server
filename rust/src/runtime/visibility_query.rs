//! Published PHP's `WorkflowVisibilityQuery`: a Temporal-style filter over a
//! run's system fields and its typed search attributes. A plain term is not
//! a query; the caller keeps its free-text search for it.
use std::collections::BTreeMap;

use chrono::{DateTime, Utc};

use super::search_attribute_upserts::{Typed, parse_datetime};

/// A parsed literal, as PHP's `parseLiteral` returns it.
#[derive(Clone, Debug, PartialEq)]
pub(super) enum Literal {
    Text(String),
    Bool(bool),
    Int(i64),
    Float(f64),
    List(Vec<Literal>),
}

#[derive(Clone, Debug, PartialEq)]
struct Comparison {
    field: String,
    operator: &'static str,
    literal: Literal,
    bare: bool,
}

/// A query's comparisons: groups joined by `OR`, each joined by `AND`.
#[derive(Clone, Debug, PartialEq)]
pub(super) struct Parsed(Vec<Vec<Comparison>>);

fn invalid(message: impl Into<String>) -> String {
    message.into()
}

fn identifier(text: &str) -> bool {
    let mut chars = text.chars();
    chars.next().is_some_and(|first| first.is_ascii_alphabetic())
        && chars.all(|character| character.is_ascii_alphanumeric() || character == '_')
}

fn word(byte: u8) -> bool {
    byte.is_ascii_alphanumeric() || byte == b'_'
}

/// PHP's `looksLikeVisibilityQuery`.
fn looks_like_query(query: &str) -> bool {
    let bytes = query.as_bytes();
    let starts_identifier = |index: usize| {
        bytes[index].is_ascii_alphabetic() && (index == 0 || !word(bytes[index - 1]))
    };
    for start in 0..bytes.len() {
        if !starts_identifier(start) {
            continue;
        }
        let mut end = start;
        while end < bytes.len() && word(bytes[end]) {
            end += 1;
        }
        let rest = query[end..].trim_start();
        if rest.starts_with(['=', '<', '>']) || rest.starts_with("!=") {
            return true;
        }
        if end < bytes.len() && bytes[end].is_ascii_whitespace() {
            let after = rest;
            if after.len() >= 2
                && after[..2].eq_ignore_ascii_case("IN")
                && after[2..].trim_start().starts_with('(')
            {
                return true;
            }
        }
        let name = &query[start..end];
        if name.eq_ignore_ascii_case("NOT")
            && end < bytes.len()
            && bytes[end].is_ascii_whitespace()
            && rest.chars().next().is_some_and(|first| first.is_ascii_alphabetic())
        {
            let before = query[..start].trim_end();
            if before.is_empty()
                || (before.len() >= 3
                    && before[before.len() - 3..].eq_ignore_ascii_case("AND")
                    && (before.len() == 3 || !word(before.as_bytes()[before.len() - 4]))
                    && start > before.len())
            {
                return true;
            }
        }
    }
    false
}

/// PHP's `splitConditionsByKeyword`: `None` for an empty part, an
/// unbalanced parenthesis or an unterminated quote.
fn split_by_keyword(query: &str, keyword: &str) -> Option<Vec<String>> {
    if query.is_empty() {
        return None;
    }
    let bytes = query.as_bytes();
    let mut parts = Vec::new();
    let mut current = Vec::new();
    let mut quote = None;
    let mut depth = 0i64;
    let mut index = 0;
    while index < bytes.len() {
        let byte = bytes[index];
        if let Some(open) = quote {
            current.push(byte);
            if byte == b'\\' && index + 1 < bytes.len() {
                index += 1;
                current.push(bytes[index]);
            } else if byte == open {
                quote = None;
            }
            index += 1;
            continue;
        }
        match byte {
            b'(' => depth += 1,
            b')' => {
                depth -= 1;
                if depth < 0 {
                    return None;
                }
            }
            b'"' | b'\'' => quote = Some(byte),
            _ => {}
        }
        let keyword_here = depth == 0
            && !matches!(byte, b'(' | b')' | b'"' | b'\'')
            && bytes.len() >= index + keyword.len()
            && query.is_char_boundary(index)
            && query.is_char_boundary(index + keyword.len())
            && query[index..index + keyword.len()].eq_ignore_ascii_case(keyword)
            && (index == 0 || !word(bytes[index - 1]))
            && bytes.get(index + keyword.len()).is_none_or(|after| !word(*after));
        if keyword_here {
            let part = String::from_utf8_lossy(&current).trim().to_owned();
            if part.is_empty() {
                return None;
            }
            parts.push(part);
            current.clear();
            index += keyword.len();
            continue;
        }
        current.push(byte);
        index += 1;
    }
    let part = String::from_utf8_lossy(&current).trim().to_owned();
    if quote.is_some() || depth != 0 || part.is_empty() {
        return None;
    }
    parts.push(part);
    Some(parts)
}

/// PHP's `stripcslashes` for the escapes a query literal can carry.
fn strip_c_slashes(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    let mut chars = text.chars().peekable();
    while let Some(character) = chars.next() {
        if character != '\\' {
            out.push(character);
            continue;
        }
        match chars.next() {
            Some('n') => out.push('\n'),
            Some('t') => out.push('\t'),
            Some('r') => out.push('\r'),
            Some('a') => out.push('\x07'),
            Some('v') => out.push('\x0b'),
            Some('b') => out.push('\x08'),
            Some('f') => out.push('\x0c'),
            Some('x') => {
                let mut digits = String::new();
                while digits.len() < 2 && chars.peek().is_some_and(char::is_ascii_hexdigit) {
                    digits.push(chars.next().expect("peeked"));
                }
                match u8::from_str_radix(&digits, 16) {
                    Ok(value) if !digits.is_empty() => out.push(char::from(value)),
                    _ => out.push('x'),
                }
            }
            Some(digit @ '0'..='7') => {
                let mut digits = digit.to_string();
                while digits.len() < 3 && chars.peek().is_some_and(|next| ('0'..='7').contains(next)) {
                    digits.push(chars.next().expect("peeked"));
                }
                out.push(char::from(u8::from_str_radix(&digits, 8).unwrap_or(0)));
            }
            Some(other) => out.push(other),
            None => {}
        }
    }
    out
}

/// PHP's `parseLiteral`; `None` is its invalid literal.
fn parse_literal(raw: &str) -> Option<Literal> {
    let raw = raw.trim();
    if raw.is_empty() {
        return None;
    }
    let first = raw.as_bytes()[0];
    if (first == b'"' || first == b'\'') && raw.len() >= 2 && raw.as_bytes()[raw.len() - 1] == first {
        return Some(Literal::Text(strip_c_slashes(&raw[1..raw.len() - 1])));
    }
    if raw.eq_ignore_ascii_case("true") {
        return Some(Literal::Bool(true));
    }
    if raw.eq_ignore_ascii_case("false") {
        return Some(Literal::Bool(false));
    }
    let unsigned = raw.strip_prefix(['+', '-']).unwrap_or(raw);
    if !unsigned.is_empty() && unsigned.bytes().all(|byte| byte.is_ascii_digit()) {
        // PHP's (int) saturates an out-of-range decimal string.
        return Some(Literal::Int(raw.parse::<i64>().unwrap_or(if raw.starts_with('-') {
            i64::MIN
        } else {
            i64::MAX
        })));
    }
    if float_literal(unsigned) {
        return raw.parse::<f64>().ok().map(Literal::Float);
    }
    if raw.bytes().all(|byte| byte.is_ascii_alphanumeric() || b"_.:-".contains(&byte)) {
        return Some(Literal::Text(raw.to_owned()));
    }
    None
}

/// `\d+\.\d*`, `\.\d+`, and either with an exponent, or `\d+[eE][+-]?\d+`.
fn float_literal(text: &str) -> bool {
    let (mantissa, exponent) = match text.find(['e', 'E']) {
        Some(at) => (&text[..at], Some(&text[at + 1..])),
        None => (text, None),
    };
    let digits = |part: &str| !part.is_empty() && part.bytes().all(|byte| byte.is_ascii_digit());
    let mantissa_ok = match mantissa.split_once('.') {
        Some((whole, fraction)) => {
            (digits(whole) && (fraction.is_empty() || digits(fraction))) || (whole.is_empty() && digits(fraction))
        }
        None => exponent.is_some() && digits(mantissa),
    };
    mantissa_ok && exponent.is_none_or(|exponent| digits(exponent.strip_prefix(['+', '-']).unwrap_or(exponent)))
}

/// PHP's `splitCommaList`: `None` for an empty item or an unterminated quote.
fn split_commas(raw: &str) -> Option<Vec<String>> {
    let bytes = raw.as_bytes();
    let mut parts = Vec::new();
    let mut current = Vec::new();
    let mut quote = None;
    let mut index = 0;
    while index < bytes.len() {
        let byte = bytes[index];
        if let Some(open) = quote {
            current.push(byte);
            if byte == b'\\' && index + 1 < bytes.len() {
                index += 1;
                current.push(bytes[index]);
            } else if byte == open {
                quote = None;
            }
        } else if byte == b'"' || byte == b'\'' {
            quote = Some(byte);
            current.push(byte);
        } else if byte == b',' {
            let part = String::from_utf8_lossy(&current).trim().to_owned();
            if part.is_empty() {
                return None;
            }
            parts.push(part);
            current.clear();
        } else {
            current.push(byte);
        }
        index += 1;
    }
    let part = String::from_utf8_lossy(&current).trim().to_owned();
    if quote.is_some() || part.is_empty() {
        return None;
    }
    parts.push(part);
    Some(parts)
}

const OPERATORS: [&str; 6] = ["!=", ">=", "<=", "=", ">", "<"];

fn parse_predicate(part: &str) -> Result<Option<Comparison>, String> {
    let part = part.trim();
    if part.len() > 4 && part[..3].eq_ignore_ascii_case("NOT") && part.as_bytes()[3].is_ascii_whitespace() {
        return Ok(parse_predicate(part[3..].trim_start())?.map(invert));
    }
    let name_end = part.find(|character: char| !(character.is_ascii_alphanumeric() || character == '_')).unwrap_or(part.len());
    let name = &part[..name_end];
    if !identifier(name) {
        return Ok(None);
    }
    let rest = part[name_end..].trim_start();
    if let Some(operator) = OPERATORS.iter().find(|operator| rest.starts_with(**operator)) {
        let literal_text = rest[operator.len()..].trim_start();
        if literal_text.is_empty() {
            return Ok(None);
        }
        let literal = parse_literal(literal_text)
            .ok_or_else(|| invalid(format!("Visibility query literal [{}] is not valid.", literal_text.trim())))?;
        return Ok(Some(Comparison { field: name.to_owned(), operator, literal, bare: false }));
    }
    if name_end < part.len()
        && part.as_bytes()[name_end].is_ascii_whitespace()
        && rest.len() >= 2
        && rest[..2].eq_ignore_ascii_case("IN")
    {
        let list = rest[2..].trim_start();
        if let Some(inner) = list.strip_prefix('(').and_then(|list| list.strip_suffix(')')) {
            let literals = split_commas(inner)
                .and_then(|parts| parts.iter().map(|part| parse_literal(part)).collect::<Option<Vec<_>>>())
                .filter(|literals| !literals.is_empty())
                .ok_or_else(|| invalid(format!("Visibility query literal list [{}] is not valid.", inner.trim())))?;
            return Ok(Some(Comparison { field: name.to_owned(), operator: "IN", literal: Literal::List(literals), bare: false }));
        }
    }
    if rest.is_empty() {
        return Ok(Some(Comparison { field: name.to_owned(), operator: "=", literal: Literal::Bool(true), bare: true }));
    }
    Ok(None)
}

fn invert(mut comparison: Comparison) -> Comparison {
    if comparison.bare && comparison.operator == "=" {
        comparison.literal = Literal::Bool(false);
        return comparison;
    }
    comparison.operator = match comparison.operator {
        "=" => "!=",
        "!=" => "=",
        ">" => "<=",
        ">=" => "<",
        "<" => ">=",
        "<=" => ">",
        "IN" => "NOT IN",
        "NOT IN" => "IN",
        other => other,
    };
    comparison
}

/// PHP's `parse`: `Ok(None)` for a plain search term.
pub(super) fn parse(query: &str) -> Result<Option<Parsed>, String> {
    let query = query.trim();
    if !looks_like_query(query) {
        return Ok(None);
    }
    let predicates = || invalid("Visibility query predicates must use: Field = literal.");
    let mut groups = Vec::new();
    for group in split_by_keyword(query, "OR").ok_or_else(predicates)? {
        let mut comparisons = Vec::new();
        for part in split_by_keyword(&group, "AND").ok_or_else(predicates)? {
            comparisons.push(parse_predicate(&part)?.ok_or_else(predicates)?);
        }
        groups.push(comparisons);
    }
    Ok(Some(Parsed(groups)))
}

/// A system column of a run, as `SYSTEM_COLUMNS` maps it.
#[derive(Clone, Copy, Debug, PartialEq)]
enum Column {
    WorkflowType,
    WorkflowId,
    RunId,
    TaskQueue,
    Compatibility,
    StartedAt,
    ClosedAt,
}

impl Column {
    fn named(field: &str) -> Option<(Self, &'static str)> {
        Some(match field {
            "WorkflowType" => (Self::WorkflowType, "keyword"),
            "WorkflowId" => (Self::WorkflowId, "keyword"),
            "RunId" => (Self::RunId, "keyword"),
            "TaskQueue" => (Self::TaskQueue, "keyword"),
            "BuildId" | "BuildIds" => (Self::Compatibility, "keyword"),
            "StartTime" | "ExecutionTime" => (Self::StartedAt, "datetime"),
            "CloseTime" => (Self::ClosedAt, "datetime"),
            _ => return None,
        })
    }
}

/// A coerced comparison value.
#[derive(Clone, Debug, PartialEq)]
enum Coerced {
    Text(String),
    Int(i64),
    Float(f64),
    Bool(bool),
    Time(DateTime<Utc>),
}

#[derive(Clone, Debug, PartialEq)]
enum Check {
    /// `status` or `status_bucket` compared for equality.
    Status { bucket: bool, value: String, equal: bool },
    Column { column: Column, operator: &'static str, values: Vec<Coerced> },
    Attribute { key: String, kind: &'static str, operator: &'static str, values: Vec<Coerced> },
}

/// A query checked against the namespace's definitions.
#[derive(Clone, Debug, PartialEq)]
pub(super) struct Compiled(Vec<Vec<Check>>);

fn value_error(field: &str, expected: &str) -> String {
    format!("[{field}] must be compared with {expected}.")
}

fn equality(operator: &str, field: &str) -> Result<(), String> {
    if matches!(operator, "=" | "!=") {
        Ok(())
    } else {
        Err(format!("[{field}] only supports equality comparisons."))
    }
}

/// PHP's `normalizeSearchAttributeType`.
fn query_type(kind: &str) -> Result<&'static str, String> {
    Ok(match kind {
        "keyword" => "keyword",
        "keyword_list" => "keyword_list",
        "string" | "text" => "text",
        "int" => "int",
        "bool" => "bool",
        "datetime" => "datetime",
        "double" | "float" => "double",
        other => return Err(format!("Search attribute type [{other}] is not supported in visibility queries.")),
    })
}

fn coerce(literal: &Literal, kind: &str, field: &str) -> Result<Coerced, String> {
    Ok(match kind {
        "keyword" | "keyword_list" | "text" => Coerced::Text(match literal {
            Literal::Bool(flag) => if *flag { "true" } else { "false" }.to_owned(),
            Literal::Text(text) => text.clone(),
            Literal::Int(number) => number.to_string(),
            Literal::Float(number) => php_float_string(*number),
            Literal::List(_) => return Err(value_error(field, "a string literal")),
        }),
        "int" => match literal {
            Literal::Int(number) => Coerced::Int(*number),
            _ => return Err(value_error(field, "an integer literal")),
        },
        "double" => match literal {
            Literal::Int(number) => Coerced::Float(*number as f64),
            Literal::Float(number) => Coerced::Float(*number),
            _ => return Err(value_error(field, "a numeric literal")),
        },
        "bool" => match literal {
            Literal::Bool(flag) => Coerced::Bool(*flag),
            _ => return Err(value_error(field, "a boolean literal")),
        },
        "datetime" => match literal {
            Literal::Text(text) => Coerced::Time(parse_datetime(text).ok_or_else(|| value_error(field, "a datetime literal"))?),
            _ => return Err(value_error(field, "a datetime literal")),
        },
        other => return Err(format!("Search attribute type [{other}] is not supported in visibility queries.")),
    })
}

/// PHP's `(string)` of a float: up to 14 significant digits.
fn php_float_string(number: f64) -> String {
    if number.fract() == 0.0 && number.abs() < 1e15 {
        return format!("{}", number as i64);
    }
    let text = format!("{:.*e}", 13, number);
    let parsed: f64 = text.parse().unwrap_or(number);
    format!("{parsed}")
}

fn coerce_list(literal: &Literal, kind: &str, field: &str) -> Result<Vec<Coerced>, String> {
    match literal {
        Literal::List(items) if !items.is_empty() => items.iter().map(|item| coerce(item, kind, field)).collect(),
        _ => Err(value_error(field, "a non-empty literal list")),
    }
}

/// PHP's `applyComparison` checks, in query order. `definitions` holds the
/// namespace's custom registered types.
pub(super) fn compile(parsed: &Parsed, definitions: &BTreeMap<String, String>, namespace: &str) -> Result<Compiled, String> {
    let mut groups = Vec::new();
    for group in &parsed.0 {
        let mut checks = Vec::new();
        for comparison in group {
            let field = comparison.field.as_str();
            let operator = comparison.operator;
            let list = matches!(operator, "IN" | "NOT IN");
            if field == "Status" || field == "ExecutionStatus" {
                let value = match &comparison.literal {
                    Literal::List(_) => return Err(value_error("Status", "a string literal")),
                    literal => match coerce(literal, "keyword", "Status")? {
                        Coerced::Text(text) => text.to_lowercase(),
                        _ => unreachable!("keyword coercion is text"),
                    },
                };
                equality(operator, "Status")?;
                let bucket = matches!(value.as_str(), "running" | "completed" | "failed");
                checks.push(Check::Status { bucket, value, equal: operator == "=" });
                continue;
            }
            if let Some((column, kind)) = Column::named(field) {
                let values = if list {
                    coerce_list(&comparison.literal, kind, field)?
                } else {
                    let value = coerce(&comparison.literal, kind, field)?;
                    if kind == "keyword" {
                        equality(operator, field)?;
                    }
                    vec![value]
                };
                checks.push(Check::Column { column, operator, values });
                continue;
            }
            let Some(definition) = definitions.get(field) else {
                return Err(format!("Search attribute [{field}] is not defined in namespace [{namespace}]."));
            };
            let kind = query_type(definition)?;
            if comparison.bare && kind != "bool" {
                return Err(value_error(field, "an explicit comparison"));
            }
            let values = if list {
                let values = coerce_list(&comparison.literal, kind, field)?;
                if kind == "keyword_list" && operator == "NOT IN" {
                    return Err("keyword_list search attributes do not support NOT IN comparisons.".to_owned());
                }
                values
            } else {
                let value = coerce(&comparison.literal, kind, field)?;
                match kind {
                    "keyword" => equality(operator, "keyword search attribute")?,
                    "text" => equality(operator, "text search attribute")?,
                    "keyword_list" if operator != "=" => {
                        return Err("keyword_list search attributes only support membership equality comparisons.".to_owned());
                    }
                    _ => {}
                }
                vec![value]
            };
            checks.push(Check::Attribute { key: field.to_owned(), kind, operator, values });
        }
        groups.push(checks);
    }
    Ok(Compiled(groups))
}

impl Compiled {
    /// The search attribute keys the query reads.
    pub(super) fn keys(&self) -> Vec<String> {
        let mut keys: Vec<String> = self
            .0
            .iter()
            .flatten()
            .filter_map(|check| match check {
                Check::Attribute { key, .. } => Some(key.clone()),
                _ => None,
            })
            .collect();
        keys.sort();
        keys.dedup();
        keys
    }

    pub(super) fn matches(&self, run: &RunFields, attributes: &BTreeMap<String, Typed>) -> bool {
        self.0.iter().any(|group| group.iter().all(|check| check.matches(run, attributes)))
    }
}

/// The run columns PHP's summary query compares.
pub(super) struct RunFields {
    pub(super) workflow_type: String,
    pub(super) workflow_id: String,
    pub(super) run_id: String,
    pub(super) queue: String,
    pub(super) compatibility: Option<String>,
    pub(super) started_at: Option<DateTime<Utc>>,
    pub(super) closed_at: Option<DateTime<Utc>>,
    pub(super) status: String,
    pub(super) status_bucket: String,
}

fn ordered<T: PartialOrd>(left: &T, operator: &str, right: &T) -> bool {
    match operator {
        "=" => left == right,
        "!=" => left != right,
        ">" => left > right,
        ">=" => left >= right,
        "<" => left < right,
        "<=" => left <= right,
        _ => false,
    }
}

/// SQL `IN` or `NOT IN` with a non-null value.
fn membership(found: bool, operator: &str) -> bool {
    if operator == "NOT IN" { !found } else { found }
}

impl Check {
    fn matches(&self, run: &RunFields, attributes: &BTreeMap<String, Typed>) -> bool {
        match self {
            Self::Status { bucket, value, equal } => {
                let stored = if *bucket { &run.status_bucket } else { &run.status };
                (stored == value) == *equal
            }
            Self::Column { column, operator, values } => {
                let text = |text: &str| -> bool {
                    let candidate = Coerced::Text(text.to_owned());
                    match *operator {
                        "IN" | "NOT IN" => membership(values.contains(&candidate), operator),
                        _ => values.first().is_some_and(|value| compare(&candidate, operator, value)),
                    }
                };
                let time = |time: Option<DateTime<Utc>>| -> bool {
                    // SQL comparisons with a NULL column never match.
                    let Some(time) = time else { return false };
                    let candidate = Coerced::Time(time);
                    match *operator {
                        "IN" | "NOT IN" => membership(values.contains(&candidate), operator),
                        _ => values.first().is_some_and(|value| compare(&candidate, operator, value)),
                    }
                };
                match column {
                    Column::WorkflowType => text(&run.workflow_type),
                    Column::WorkflowId => text(&run.workflow_id),
                    Column::RunId => text(&run.run_id),
                    Column::TaskQueue => text(&run.queue),
                    Column::Compatibility => run.compatibility.as_deref().is_some_and(text),
                    Column::StartedAt => time(run.started_at),
                    Column::ClosedAt => time(run.closed_at),
                }
            }
            Self::Attribute { key, kind, operator, values } => {
                // `whereHas`: the run needs a row for the key whose value matches.
                let Some(row) = attributes.get(key) else { return false };
                let candidates: Vec<Coerced> = match (*kind, row) {
                    ("keyword", Typed::Keyword(text)) => vec![Coerced::Text(text.clone())],
                    ("text", Typed::String(text) | Typed::Keyword(text)) => vec![Coerced::Text(text.clone())],
                    ("int", Typed::Int(number)) => vec![Coerced::Int(*number)],
                    ("double", Typed::Float(number)) => vec![Coerced::Float(*number)],
                    ("double", Typed::Int(number)) => vec![Coerced::Float(*number as f64)],
                    ("bool", Typed::Bool(flag)) => vec![Coerced::Bool(*flag)],
                    ("datetime", Typed::Datetime(time)) => vec![Coerced::Time(*time)],
                    ("keyword_list", Typed::KeywordList(items)) => {
                        let items: Vec<Coerced> = items.iter().cloned().map(Coerced::Text).collect();
                        // `whereJsonContains`: the list holds the value.
                        return match *operator {
                            "IN" => values.iter().any(|value| items.contains(value)),
                            _ => values.first().is_some_and(|value| items.contains(value)),
                        };
                    }
                    _ => return false,
                };
                candidates.iter().any(|candidate| match *operator {
                    "IN" | "NOT IN" => membership(values.contains(candidate), operator),
                    _ => values.first().is_some_and(|value| compare(candidate, operator, value)),
                })
            }
        }
    }
}

fn compare(left: &Coerced, operator: &str, right: &Coerced) -> bool {
    match (left, right) {
        (Coerced::Text(left), Coerced::Text(right)) => ordered(left, operator, right),
        (Coerced::Int(left), Coerced::Int(right)) => ordered(left, operator, right),
        (Coerced::Float(left), Coerced::Float(right)) => ordered(left, operator, right),
        (Coerced::Bool(left), Coerced::Bool(right)) => ordered(left, operator, right),
        (Coerced::Time(left), Coerced::Time(right)) => ordered(left, operator, right),
        _ => false,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn definitions() -> BTreeMap<String, String> {
        [("SearchKeyword", "keyword"), ("SearchCount", "int"), ("SearchRatio", "double"), ("SearchFlag", "bool"),
            ("SearchTags", "keyword_list"), ("SearchTime", "datetime"), ("SearchText", "string")]
            .into_iter()
            .map(|(name, kind)| (name.to_owned(), kind.to_owned()))
            .collect()
    }

    fn compiled(query: &str) -> Result<Compiled, String> {
        compile(&parse(query)?.expect("a visibility query"), &definitions(), "default")
    }

    fn run() -> RunFields {
        RunFields {
            workflow_type: "sample".into(),
            workflow_id: "wf-1".into(),
            run_id: "run-1".into(),
            queue: "q".into(),
            compatibility: None,
            started_at: parse_datetime("2026-10-08T12:00:00Z"),
            closed_at: None,
            status: "waiting".into(),
            status_bucket: "running".into(),
        }
    }

    fn attributes() -> BTreeMap<String, Typed> {
        BTreeMap::from([
            ("SearchKeyword".into(), Typed::Keyword("界界".into())),
            ("SearchCount".into(), Typed::Int(9_007_199_254_740_993)),
            ("SearchRatio".into(), Typed::Float(1.25)),
            ("SearchFlag".into(), Typed::Bool(true)),
            ("SearchTags".into(), Typed::KeywordList(vec!["urgent".into(), "x".into()])),
            ("SearchTime".into(), Typed::Datetime(parse_datetime("2026-10-08T12:34:56.123456Z").unwrap())),
            ("SearchText".into(), Typed::String("two words".into())),
        ])
    }

    #[test]
    fn plain_terms_are_not_queries() {
        assert_eq!(parse("order-123").unwrap(), None);
        assert_eq!(parse("  ").unwrap(), None);
        assert!(parse("NOT SearchFlag").unwrap().is_some());
        assert_eq!(parse("SearchFlag").unwrap(), None);
        assert!(parse("SearchCount IN (1, 2)").unwrap().is_some());
    }

    #[test]
    fn the_sample_app_queries_match_like_php() {
        for query in [
            "SearchKeyword = \"界界\"",
            "SearchCount > 9007199254740992 AND SearchCount <= 9007199254740993",
            "SearchRatio > 1.0 AND SearchRatio < 2.0",
            "SearchFlag = true",
            // A bare field is a boolean comparison only inside a query; on
            // its own PHP treats it as a free-text term.
            "SearchFlag AND SearchCount > 1",
            "SearchTags = \"urgent\"",
            "SearchTime >= \"2026-10-08T12:34:56Z\" AND SearchTime < \"2026-10-08T12:34:57Z\"",
            "SearchText = 'two words' OR SearchKeyword = missing",
            "Status = Running AND WorkflowType = sample",
            "SearchCount IN (1, 9007199254740993)",
            "StartTime < \"2026-10-09\"",
        ] {
            let compiled = compiled(query).unwrap_or_else(|error| panic!("{query}: {error}"));
            assert!(compiled.matches(&run(), &attributes()), "{query}");
        }
        for query in [
            "SearchKeyword = \"missing\"",
            "NOT SearchFlag",
            "SearchTags = \"done\"",
            "CloseTime < \"2030-01-01\"",
            "NOT SearchRatio IN (1.25)",
            "Status = completed",
        ] {
            let compiled = compiled(query).unwrap_or_else(|error| panic!("{query}: {error}"));
            assert!(!compiled.matches(&run(), &attributes()), "{query}");
        }
        // A run without the attribute never matches, not even `!=`.
        assert!(!compiled("SearchKeyword != \"x\"").unwrap().matches(&run(), &BTreeMap::new()));
    }

    #[test]
    fn refusals_use_php_messages() {
        for (query, message) in [
            ("SearchCount = ", "Visibility query predicates must use: Field = literal."),
            ("SearchCount = 1 AND", "Visibility query predicates must use: Field = literal."),
            ("SearchCount = $5", "Visibility query literal [$5] is not valid."),
            ("SearchCount IN ()", "Visibility query literal list [] is not valid."),
            ("Unknown = 1", "Search attribute [Unknown] is not defined in namespace [default]."),
            ("SearchKeyword > a", "[keyword search attribute] only supports equality comparisons."),
            ("SearchTags != a", "keyword_list search attributes only support membership equality comparisons."),
            // PHP has no `Field NOT IN (...)` form; `NOT Field IN (...)` negates.
            ("SearchTags NOT IN (a)", "Visibility query predicates must use: Field = literal."),
            ("NOT SearchTags IN (a)", "keyword_list search attributes do not support NOT IN comparisons."),
            ("SearchCount = 1.5", "[SearchCount] must be compared with an integer literal."),
            ("SearchRatio = x", "[SearchRatio] must be compared with a numeric literal."),
            ("SearchFlag = 1", "[SearchFlag] must be compared with a boolean literal."),
            ("SearchTime > soon", "[SearchTime] must be compared with a datetime literal."),
            ("SearchCount AND SearchFlag = true", "[SearchCount] must be compared with an explicit comparison."),
            ("Status > running", "[Status] only supports equality comparisons."),
            ("WorkflowType > a", "[WorkflowType] only supports equality comparisons."),
        ] {
            assert_eq!(compiled(query).unwrap_err(), message, "{query}");
        }
    }
}
