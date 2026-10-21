//! `POST /api/schedules/{id}/backfill` as published PHP's
//! `ScheduleController::backfill` and `ScheduleManager::backfill` run it.
use super::laravel_rules::{Rule, Rules, normalize, validate};
use super::{
    Result, RuntimeError,
    backend::Backend,
    schedules,
    store::Store,
};
use axum::http::StatusCode;
use chrono::{DateTime, Duration, NaiveDate, Utc};
use serde_json::{Map, Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

/// The most occurrences one backfill request starts. PHP enumerates without
/// a limit; native refuses a larger range so it can be split.
pub(crate) const MAX_OCCURRENCES: usize = 10_000;

const RULES: &Rules = &[
    ("start_time", &[Rule::Required, Rule::Date]),
    ("end_time", &[Rule::Required, Rule::Date]),
    (
        "overlap_policy",
        &[Rule::Nullable, Rule::String, Rule::In(schedules::OVERLAP_POLICIES)],
    ),
];

/// The instant PHP's `new DateTimeImmutable($text)` reads, under the
/// server's UTC default, for an ISO-8601 date or date-time that Laravel's
/// `date` rule accepts:
///
/// `YYYY-MM-DD`, optionally followed by `T`, `t` or a space and
/// `HH:MM[:SS[.fraction]]`, optionally followed by an optional space and
/// `Z`, `z` or `±HH[[:]MM]`. As in PHP, hour 24 and second 60 carry into the
/// next day or minute, a fraction keeps six digits, and the calendar date
/// must exist. PHP's other spellings (relative words, other orders, zone
/// names, timestamps) are refused.
pub(crate) fn php_date(text: &str) -> Option<DateTime<Utc>> {
    let bytes = text.as_bytes();
    let number = |from: usize, width: usize| -> Option<i64> {
        let digits = bytes.get(from..from + width)?;
        digits
            .iter()
            .all(u8::is_ascii_digit)
            .then(|| digits.iter().fold(0, |value, digit| value * 10 + i64::from(digit - b'0')))
    };
    let year = number(0, 4)?;
    let (month, day) = (number(5, 2)?, number(8, 2)?);
    if bytes[4] != b'-' || bytes[7] != b'-' || year == 0 {
        return None;
    }
    let date = NaiveDate::from_ymd_opt(year as i32, month as u32, day as u32)?;
    let midnight = date.and_hms_opt(0, 0, 0)?.and_utc();
    if bytes.len() == 10 {
        return Some(midnight);
    }
    if !matches!(bytes[10], b'T' | b't' | b' ') || bytes.get(13) != Some(&b':') {
        return None;
    }
    let (hour, minute) = (number(11, 2)?, number(14, 2)?);
    if hour > 24 || minute > 59 {
        return None;
    }
    let mut at = 16;
    let mut second = 0;
    let mut micros = 0;
    if bytes.get(at) == Some(&b':') {
        second = number(at + 1, 2)?;
        if second > 60 {
            return None;
        }
        at += 3;
        if bytes.get(at) == Some(&b'.') {
            let digits = bytes[at + 1..]
                .iter()
                .take_while(|byte| byte.is_ascii_digit())
                .count();
            if !(1..=9).contains(&digits) {
                return None;
            }
            micros = bytes[at + 1..at + 1 + digits.min(6)]
                .iter()
                .fold(0, |value, digit| value * 10 + i64::from(digit - b'0'))
                * 10_i64.pow(6 - digits.min(6) as u32);
            at += 1 + digits;
        }
    }
    if bytes.get(at) == Some(&b' ') && at + 1 < bytes.len() {
        at += 1;
    }
    let offset = match bytes.get(at) {
        None => 0,
        Some(b'Z' | b'z') if at + 1 == bytes.len() => 0,
        Some(sign @ (b'+' | b'-')) => {
            let hours = number(at + 1, 2)?;
            let rest = &bytes[at + 3..];
            let minutes = match rest {
                [] => 0,
                [b':', ..] if rest.len() == 3 => number(at + 4, 2)?,
                _ if rest.len() == 2 => number(at + 3, 2)?,
                _ => return None,
            };
            if hours > 23 || minutes > 59 {
                return None;
            }
            let seconds = hours * 3600 + minutes * 60;
            if *sign == b'-' { -seconds } else { seconds }
        }
        _ => return None,
    };
    let seconds = hour * 3600 + minute * 60 + second - offset;
    Some(midnight + Duration::seconds(seconds) + Duration::microseconds(micros))
}

/// PHP's `format('Y-m-d\TH:i:sP')` of an occurrence at `offset` seconds.
fn fire_time(at: DateTime<Utc>, offset: i64) -> String {
    let zone = chrono::FixedOffset::east_opt(offset as i32).unwrap_or(chrono::FixedOffset::east_opt(0).expect("UTC"));
    at.with_timezone(&zone).format("%Y-%m-%dT%H:%M:%S%:z").to_string()
}

/// `enumerateOccurrences`: each fire time at or after `from` and before
/// `to`, with its PHP text.
pub(crate) fn occurrences(spec: &Value, from: DateTime<Utc>, to: DateTime<Utc>) -> Result<Vec<(DateTime<Utc>, String)>> {
    let mut found = Vec::new();
    let mut cursor = from - Duration::seconds(1);
    loop {
        let Some((next, offset)) = schedules::next_occurrence(spec, cursor)? else {
            break;
        };
        if next >= to {
            break;
        }
        if found.len() == MAX_OCCURRENCES {
            return Err(RuntimeError::Protocol {
                status: StatusCode::UNPROCESSABLE_ENTITY,
                response: json!({"message":format!(
                    "The backfill range has more than {MAX_OCCURRENCES} occurrences. Split it into smaller ranges."),
                    "reason":"backfill_range_too_large","limit":MAX_OCCURRENCES}),
            });
        }
        found.push((next, fire_time(next, offset)));
        cursor = next;
    }
    Ok(found)
}

/// The validated range and policy override of a backfill body.
pub(crate) struct Range {
    pub(crate) from: DateTime<Utc>,
    pub(crate) to: DateTime<Utc>,
    pub(crate) overlap: Option<String>,
}

pub(crate) fn range(body: &Value) -> Result<Range> {
    let body = normalize(body);
    validate(&body, RULES)?;
    let read = |field: &str| php_date(body[field].as_str().unwrap_or_default()).expect("validated date");
    let (from, to) = (read("start_time"), read("end_time"));
    if to <= from {
        return Err(RuntimeError::Protocol {
            status: StatusCode::UNPROCESSABLE_ENTITY,
            response: json!({"message":"end_time must be after start_time.","reason":"invalid_time_range"}),
        });
    }
    Ok(Range {
        from,
        to,
        overlap: body["overlap_policy"].as_str().map(str::to_owned),
    })
}

impl<DB: Backend> Store<DB>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> Option<&'q str>: Encode<'q, DB>,
    for<'q> Option<DB::EncodedDocument>: Encode<'q, DB>,
    for<'q> Option<i64>: Encode<'q, DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'r> i64: Decode<'r, DB>,
    usize: ColumnIndex<DB::Row>,
{
    /// `ScheduleManager::backfill` on a live schedule: each occurrence is
    /// triggered on its own, as `triggerForBackfill`, until the run quota is
    /// spent.
    pub(crate) async fn backfill_schedule(&self, schedule: &str, range: Range, context: Value) -> Result<Value> {
        let (key, spec, stored) = {
            let mut tx = self.begin().await?;
            let row = self.schedule_row(&mut tx, schedule, false).await?;
            (
                DB::string(&row, "id")?,
                DB::optional_document_row(&row, "spec")?.unwrap_or(Value::Null),
                DB::string(&row, "overlap_policy")?,
            )
        };
        let occurrences = occurrences(&spec, range.from, range.to)?;
        let policy = range
            .overlap
            .or(Some(stored).filter(|policy| schedules::OVERLAP_POLICIES.contains(&policy.as_str())))
            .unwrap_or_else(|| "skip".to_owned());
        // A buffering policy starts every backfilled occurrence.
        let policy = if policy.starts_with("buffer_") { "allow_all".to_owned() } else { policy };
        let mut results = Vec::new();
        for (occurrence, text) in occurrences {
            let mut result = Map::new();
            result.insert("fire_time".into(), json!(text));
            match self
                .trigger_detailed(&key, Some(&policy), Some(&context), Some(occurrence), "backfilled")
                .await
            {
                Ok(super::schedule_execution::Trigger::Triggered { workflow, .. }) => {
                    result.insert("workflow_id".into(), json!(workflow));
                    result.insert("outcome".into(), json!("started"));
                }
                Ok(_) => {
                    result.insert("outcome".into(), json!("skipped"));
                }
                Err(error @ RuntimeError::Database(_)) => return Err(error),
                Err(error) => {
                    let reason = error.to_string();
                    self.record_failure(&key, &reason).await?;
                    result.insert("outcome".into(), json!("failed"));
                    result.insert("reason".into(), json!(reason));
                }
            }
            results.push(Value::Object(result));
            let remaining = Self::query("SELECT remaining_actions FROM workflow_schedules WHERE id=$1")
                .bind(&key)
                .fetch_one(&self.pool)
                .await?;
            if DB::optional_number(&remaining, "remaining_actions")?.is_some_and(|remaining| remaining <= 0) {
                break;
            }
        }
        self.wake.notify_waiters();
        Ok(json!({"schedule_id":schedule,"outcome":"backfill_started","fires_attempted":results.len(),
            "results":results}))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[derive(serde::Deserialize)]
    struct Case {
        value: String,
        iso: bool,
        valid: bool,
        at: Option<String>,
    }

    fn micros(time: DateTime<Utc>) -> String {
        format!("{}.{:06}", time.timestamp(), time.timestamp_subsec_micros())
    }

    /// Native reads every ISO-8601 date-time as published PHP does and
    /// accepts nothing PHP's `date` rule refuses.
    #[test]
    fn backfill_times_read_as_published_php_reads_them() {
        let corpus = include_str!("../../tests/fixtures/cron/php-2.5.14-backfill-dates.jsonl");
        let mut checked = 0;
        for line in corpus.lines() {
            let case: Case = serde_json::from_str(line).unwrap();
            let native = php_date(&case.value);
            match (&native, case.valid) {
                (Some(native), true) => assert_eq!(Some(micros(*native)), case.at, "{}", case.value),
                (Some(_), false) => panic!("{} is not a date PHP accepts", case.value),
                (None, true) if case.iso => {
                    // Offsets of a day or more are PHP spellings native refuses.
                    let tail = case.value.rsplit(['+', '-']).next().unwrap_or_default();
                    assert!(
                        case.value.len() > 19 && tail.get(..2).and_then(|hours| hours.parse::<u32>().ok()) >= Some(24),
                        "{} is an ISO-8601 date PHP accepts",
                        case.value
                    );
                }
                _ => {}
            }
            checked += usize::from(native.is_some());
        }
        assert!(checked > 400, "only {checked} accepted dates");
    }

    #[test]
    fn occurrences_carry_the_zone_offset_php_formats() {
        let spec = json!({"cron_expressions":["0 3 * * *"],"intervals":[{"every":"PT12H"}],"timezone":"Europe/Budapest"});
        let from = php_date("2026-03-28T00:00:00Z").unwrap();
        let to = php_date("2026-03-30T00:00:00Z").unwrap();
        let texts: Vec<String> = occurrences(&spec, from, to).unwrap().into_iter().map(|(_, text)| text).collect();
        assert_eq!(texts, [
            "2026-03-28T00:00:00+00:00",
            "2026-03-28T03:00:00+01:00",
            "2026-03-28T12:00:00+00:00",
            "2026-03-29T00:00:00+00:00",
            "2026-03-29T03:00:00+02:00",
            "2026-03-29T12:00:00+00:00",
        ]);
        let every_second = json!({"intervals":[{"every":"PT1S"}]});
        let wide = occurrences(&every_second, from, from + Duration::seconds(MAX_OCCURRENCES as i64 + 1));
        assert!(matches!(wide, Err(RuntimeError::Protocol { status, .. }) if status == StatusCode::UNPROCESSABLE_ENTITY));
        assert_eq!(occurrences(&every_second, from, from + Duration::seconds(MAX_OCCURRENCES as i64)).unwrap().len(), MAX_OCCURRENCES);
    }
}
