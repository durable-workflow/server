//! Inspect a bounded subset of published V2 queue commands without PHP execution.
//! Recognition is not migration authority. A later takeover must validate the
//! outer queue envelope and join the original task/run, deadlines and ownership.
use phpserz::{PhpParser, PhpToken};
use std::collections::BTreeSet;

const MAX_BYTES: usize = 64 * 1024;
const MAX_DEPTH: usize = 8;
const MAX_TOKENS: usize = 256;
const MAX_PROPERTIES: u32 = 32;
const WORKFLOW: &[u8] = b"Workflow\\V2\\Jobs\\RunWorkflowTask";
const ACTIVITY: &[u8] = b"Workflow\\V2\\Jobs\\RunActivityTask";
const TIMER: &[u8] = b"Workflow\\V2\\Jobs\\RunTimerTask";
const CARBON: &[u8] = b"Illuminate\\Support\\Carbon";

#[derive(Debug, PartialEq, Eq)]
pub enum QueueDecodeError {
    TooLarge,
    TooDeep,
    TooManyValues,
    Malformed,
    DuplicateProperty,
    UnsupportedReference,
    UnsupportedCustomObject,
    UnsupportedClass,
    UnsupportedValue,
    UnsupportedProperty,
    InvalidShape,
    InvalidTaskId,
}

#[derive(Debug, PartialEq, Eq)]
pub enum QueueTaskKind {
    Workflow,
    Activity,
    Timer,
}

/// Original serialized fields. These are not a new availability/deadline budget.
#[derive(Debug, PartialEq, Eq)]
pub struct CarbonDelay<'a> {
    pub date: &'a str,
    pub timezone: &'a str,
    pub timezone_type: i64,
}

#[derive(Debug, PartialEq, Eq)]
pub struct QueueCommand<'a> {
    pub kind: QueueTaskKind,
    pub task_id: &'a str,
    pub connection: Option<&'a str>,
    pub queue: Option<&'a str>,
    pub delay: Option<CarbonDelay<'a>>,
}

enum Value<'a> {
    Null,
    Boolean(bool),
    Integer(i64),
    Bytes(&'a [u8]),
    Object {
        class: &'a [u8],
        properties: Vec<(&'a [u8], Value<'a>)>,
    },
}

struct Reader<'a> {
    parser: PhpParser<'a>,
    tokens: usize,
}

impl<'a> Reader<'a> {
    fn token(&mut self) -> Result<PhpToken<'a>, QueueDecodeError> {
        self.tokens += 1;
        if self.tokens > MAX_TOKENS {
            return Err(QueueDecodeError::TooManyValues);
        }
        self.parser
            .read_token()
            .map_err(|_| QueueDecodeError::Malformed)
    }

    fn value(&mut self, depth: usize) -> Result<Value<'a>, QueueDecodeError> {
        if depth > MAX_DEPTH {
            return Err(QueueDecodeError::TooDeep);
        }
        Ok(match self.token()? {
            PhpToken::Null => Value::Null,
            PhpToken::Boolean(value) => Value::Boolean(value),
            PhpToken::Integer(value) => Value::Integer(value),
            PhpToken::String(value) => Value::Bytes(value.as_bytes()),
            PhpToken::Object { class, properties } => {
                let class = class.as_bytes();
                if ![WORKFLOW, ACTIVITY, TIMER, CARBON].contains(&class) {
                    return Err(QueueDecodeError::UnsupportedClass);
                }
                if properties > MAX_PROPERTIES {
                    return Err(QueueDecodeError::TooManyValues);
                }
                let mut values = Vec::new();
                let mut keys = BTreeSet::new();
                for _ in 0..properties {
                    let PhpToken::String(key) = self.token()? else {
                        return Err(QueueDecodeError::InvalidShape);
                    };
                    let key = key.as_bytes();
                    // Preserve the complete property bytes. Stripping PHP's
                    // visibility prefix could alias a different taskId field.
                    if !keys.insert(key) {
                        return Err(QueueDecodeError::DuplicateProperty);
                    }
                    values.push((key, self.value(depth + 1)?));
                }
                if self.token()? != PhpToken::End {
                    return Err(QueueDecodeError::Malformed);
                }
                Value::Object {
                    class,
                    properties: values,
                }
            }
            PhpToken::Reference { .. } => return Err(QueueDecodeError::UnsupportedReference),
            PhpToken::CustomObject { .. } => return Err(QueueDecodeError::UnsupportedCustomObject),
            // Arrays/chains, floating delays and other unqualified values are
            // refused, never flattened into a superficially recognized job.
            PhpToken::Array { .. } | PhpToken::Float(_) => {
                return Err(QueueDecodeError::UnsupportedValue);
            }
            PhpToken::End => return Err(QueueDecodeError::Malformed),
        })
    }
}

fn text(value: Value<'_>) -> Result<&str, QueueDecodeError> {
    let Value::Bytes(value) = value else {
        return Err(QueueDecodeError::InvalidShape);
    };
    let value = std::str::from_utf8(value).map_err(|_| QueueDecodeError::InvalidShape)?;
    if value.contains('\0') || value.chars().count() > 255 {
        return Err(QueueDecodeError::InvalidShape);
    }
    Ok(value)
}

fn optional_text(value: Value<'_>) -> Result<Option<&str>, QueueDecodeError> {
    match value {
        Value::Null => Ok(None),
        value => text(value).map(Some),
    }
}

fn carbon(value: Value<'_>) -> Result<Option<CarbonDelay<'_>>, QueueDecodeError> {
    if matches!(value, Value::Null) {
        return Ok(None);
    }
    let Value::Object {
        class: CARBON,
        properties,
    } = value
    else {
        return Err(QueueDecodeError::InvalidShape);
    };
    let (mut date, mut timezone, mut timezone_type) = (None, None, None);
    for (key, value) in properties {
        match key {
            b"date" => date = Some(text(value)?),
            b"timezone" => timezone = Some(text(value)?),
            b"timezone_type" => {
                let Value::Integer(kind @ 1..=3) = value else {
                    return Err(QueueDecodeError::InvalidShape);
                };
                timezone_type = Some(kind);
            }
            _ => return Err(QueueDecodeError::UnsupportedProperty),
        }
    }
    Ok(Some(CarbonDelay {
        date: date.ok_or(QueueDecodeError::InvalidShape)?,
        timezone: timezone.ok_or(QueueDecodeError::InvalidShape)?,
        timezone_type: timezone_type.ok_or(QueueDecodeError::InvalidShape)?,
    }))
}

pub fn inspect_queue_command(input: &[u8]) -> Result<QueueCommand<'_>, QueueDecodeError> {
    if input.len() > MAX_BYTES {
        return Err(QueueDecodeError::TooLarge);
    }
    let mut reader = Reader {
        parser: PhpParser::new(input),
        tokens: 0,
    };
    let value = reader.value(0)?;
    if reader.parser.position() != input.len() {
        return Err(QueueDecodeError::Malformed);
    }
    let Value::Object { class, properties } = value else {
        return Err(QueueDecodeError::InvalidShape);
    };
    let kind = match class {
        WORKFLOW => QueueTaskKind::Workflow,
        ACTIVITY => QueueTaskKind::Activity,
        TIMER => QueueTaskKind::Timer,
        _ => return Err(QueueDecodeError::UnsupportedClass),
    };
    let (mut task_id, mut after_commit) = (None, false);
    let (mut connection, mut queue, mut delay) = (None, None, None);
    for (key, value) in properties {
        match key {
            b"taskId" => task_id = Some(text(value)?),
            b"afterCommit" => {
                if !matches!(value, Value::Boolean(true)) {
                    return Err(QueueDecodeError::InvalidShape);
                }
                after_commit = true;
            }
            b"connection" => connection = optional_text(value)?,
            b"queue" => queue = optional_text(value)?,
            b"delay" => delay = carbon(value)?,
            _ => return Err(QueueDecodeError::UnsupportedProperty),
        }
    }
    let task_id = task_id.ok_or(QueueDecodeError::InvalidTaskId)?;
    if task_id.is_empty()
        || !task_id
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || b"_-".contains(&byte))
    {
        return Err(QueueDecodeError::InvalidTaskId);
    }
    if !after_commit {
        return Err(QueueDecodeError::InvalidShape);
    }
    Ok(QueueCommand {
        kind,
        task_id,
        connection,
        queue,
        delay,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    fn object(class: &str, properties: &[(&str, String)]) -> Vec<u8> {
        let body = properties
            .iter()
            .map(|(key, value)| format!("s:{}:\"{}\";{}", key.len(), key, value))
            .collect::<String>();
        format!(
            "O:{}:\"{}\":{}:{{{}}}",
            class.len(),
            class,
            properties.len(),
            body
        )
        .into_bytes()
    }

    fn job(properties: &[(&str, String)]) -> Vec<u8> {
        object("Workflow\\V2\\Jobs\\RunTimerTask", properties)
    }

    #[test]
    fn published_class_fixtures_preserve_original_identity_queue_and_carbon_fields() {
        let fixture: serde_json::Value = serde_json::from_str(include_str!(
            "../../../../tests/Fixtures/ServerUpgrade/php-2.8.0-queue-commands.json"
        ))
        .unwrap();
        for case in fixture["cases"].as_array().unwrap() {
            let result =
                inspect_queue_command(case["command"].as_str().unwrap().as_bytes()).unwrap();
            assert_eq!(result.task_id, case["task_id"].as_str().unwrap());
            match case["kind"].as_str().unwrap() {
                "workflow" => assert_eq!(result.kind, QueueTaskKind::Workflow),
                "activity" => {
                    assert_eq!(result.kind, QueueTaskKind::Activity);
                    assert_eq!(result.connection, Some("database"));
                    assert_eq!(result.queue, Some("upgradeλ"));
                }
                "timer" => {
                    assert_eq!(result.kind, QueueTaskKind::Timer);
                    assert_eq!(
                        result.delay,
                        Some(CarbonDelay {
                            date: "2026-10-15 07:15:26.289171",
                            timezone: "Z",
                            timezone_type: 2
                        })
                    );
                }
                _ => panic!("unexpected fixture kind"),
            }
        }
    }

    #[test]
    fn duplicate_and_visibility_aliased_properties_cannot_replace_task_identity() {
        let duplicate = job(&[
            ("taskId", "s:1:\"a\";".into()),
            ("taskId", "s:1:\"b\";".into()),
        ]);
        assert_eq!(
            inspect_queue_command(&duplicate),
            Err(QueueDecodeError::DuplicateProperty)
        );
        for key in ["\0*\0taskId", "\0Fake\0taskId"] {
            let hidden = job(&[(key, "s:1:\"a\";".into()), ("afterCommit", "b:1;".into())]);
            assert_eq!(
                inspect_queue_command(&hidden),
                Err(QueueDecodeError::UnsupportedProperty)
            );
        }
    }

    #[test]
    fn malformed_lengths_counts_trailing_values_and_every_truncation_are_refused() {
        let valid = job(&[
            ("taskId", "s:1:\"a\";".into()),
            ("afterCommit", "b:1;".into()),
        ]);
        for length in 0..valid.len() {
            assert!(
                inspect_queue_command(&valid[..length]).is_err(),
                "prefix {length}"
            );
        }
        let mut trailing = valid.clone();
        trailing.extend_from_slice(b"N;");
        assert_eq!(
            inspect_queue_command(&trailing),
            Err(QueueDecodeError::Malformed)
        );
        for value in [
            "s:2:\"a\";",
            "s:4294967295:\"a\";",
            "s:4294967296:\"a\";",
            "s:184467440737095516160:\"a\";",
        ] {
            assert!(inspect_queue_command(&job(&[("taskId", value.into())])).is_err());
        }
        let missing = String::from_utf8(valid)
            .unwrap()
            .replacen(":2:{", ":3:{", 1);
        assert!(inspect_queue_command(missing.as_bytes()).is_err());
    }

    #[test]
    fn binary_strings_and_overflowing_numbers_are_not_repaired_or_coerced() {
        let bytes = job(&[
            ("taskId", "s:1:\"x\";".into()),
            ("afterCommit", "b:1;".into()),
        ]);
        let mut binary = bytes.clone();
        let index = binary
            .windows(8)
            .position(|value| value == b"s:1:\"x\";")
            .unwrap();
        binary[index + 5] = 255;
        assert_eq!(
            inspect_queue_command(&binary),
            Err(QueueDecodeError::InvalidShape)
        );
        let wrong_unicode_length = job(&[("queue", "s:8:\"upgradeλ\";".into())]);
        assert_eq!(
            inspect_queue_command(&wrong_unicode_length),
            Err(QueueDecodeError::Malformed)
        );
        for number in ["i:184467440737095516160;", "i:-184467440737095516160;"] {
            assert_eq!(
                inspect_queue_command(&job(&[("delay", number.into())])),
                Err(QueueDecodeError::Malformed)
            );
        }
    }

    #[test]
    fn references_custom_objects_unknown_classes_and_unqualified_values_are_refused() {
        for reference in ["r:1;", "R:1;"] {
            assert_eq!(
                inspect_queue_command(&job(&[("delay", reference.into())])),
                Err(QueueDecodeError::UnsupportedReference)
            );
        }
        assert_eq!(
            inspect_queue_command(b"C:4:\"Evil\":0:{}"),
            Err(QueueDecodeError::UnsupportedCustomObject)
        );
        assert_eq!(
            inspect_queue_command(&object("Laravel\\SerializableClosure", &[])),
            Err(QueueDecodeError::UnsupportedClass)
        );
        let nested = String::from_utf8(object("Unknown", &[])).unwrap();
        assert_eq!(
            inspect_queue_command(&job(&[("delay", nested)])),
            Err(QueueDecodeError::UnsupportedClass)
        );
        for value in ["a:0:{}", "d:1.0;"] {
            assert_eq!(
                inspect_queue_command(&job(&[("delay", value.into())])),
                Err(QueueDecodeError::UnsupportedValue)
            );
        }
    }

    #[test]
    fn input_depth_and_declared_collection_sizes_are_bounded() {
        assert_eq!(
            inspect_queue_command(&vec![b'x'; MAX_BYTES + 1]),
            Err(QueueDecodeError::TooLarge)
        );
        let oversized = b"O:29:\"Workflow\\V2\\Jobs\\RunTimerTask\":4294967295:{}";
        assert_eq!(
            inspect_queue_command(oversized),
            Err(QueueDecodeError::TooManyValues)
        );
        let mut nested = "N;".to_string();
        for _ in 0..MAX_DEPTH + 2 {
            nested = String::from_utf8(object("Illuminate\\Support\\Carbon", &[("date", nested)]))
                .unwrap();
        }
        assert_eq!(
            inspect_queue_command(nested.as_bytes()),
            Err(QueueDecodeError::TooDeep)
        );
        let child = String::from_utf8(object(
            "Illuminate\\Support\\Carbon",
            &[
                ("date", "N;".into()),
                ("timezone", "N;".into()),
                ("timezone_type", "N;".into()),
            ],
        ))
        .unwrap();
        let keys: Vec<_> = (0..32).map(|index| format!("property{index}")).collect();
        let properties: Vec<_> = keys
            .iter()
            .map(|key| (key.as_str(), child.clone()))
            .collect();
        assert_eq!(
            inspect_queue_command(&job(&properties)),
            Err(QueueDecodeError::TooManyValues)
        );
    }

    #[test]
    fn root_shape_and_transport_fields_are_not_silently_coerced() {
        for properties in [
            vec![("taskId", "s:1:\"a\";".into())],
            vec![
                ("taskId", "s:1:\"a\";".into()),
                ("afterCommit", "i:1;".into()),
            ],
            vec![
                ("taskId", "s:1:\"a\";".into()),
                ("afterCommit", "b:1;".into()),
                ("chained", "s:1:\"x\";".into()),
            ],
        ] {
            assert!(inspect_queue_command(&job(&properties)).is_err());
        }
        let null_id = job(&[("taskId", "N;".into()), ("afterCommit", "b:1;".into())]);
        assert_eq!(
            inspect_queue_command(&null_id),
            Err(QueueDecodeError::InvalidShape)
        );
        let invalid = job(&[
            ("taskId", "s:1:\"/\";".into()),
            ("afterCommit", "b:1;".into()),
        ]);
        assert_eq!(
            inspect_queue_command(&invalid),
            Err(QueueDecodeError::InvalidTaskId)
        );
    }
}
