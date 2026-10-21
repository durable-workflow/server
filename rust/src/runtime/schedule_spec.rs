//! A schedule's next fire time exactly as published PHP Server computes it
//! (`WorkflowSchedule::computeNextFireAt`): the earliest occurrence after the
//! given instant across its cron expressions, in its time zone, and its
//! intervals, which PHP counts in UTC from the Unix epoch plus an offset.
use super::schedule_cron::{Cron, CronError};
use jiff::Timestamp;
use serde_json::Value;

/// The members PHP's `foreach` visits: a list's items or a map's values.
fn members(value: &Value) -> Vec<&Value> {
    match value {
        Value::Array(items) => items.iter().collect(),
        Value::Object(fields) => fields.values().collect(),
        _ => Vec::new(),
    }
}

/// The next fire time without jitter, `None` when no rule yields one, or the
/// exception PHP throws evaluating a cron expression.
pub(crate) fn next_fire(spec: &Value, after: Timestamp) -> Result<Option<Timestamp>, CronError> {
    Ok(next_occurrence(spec, after)?.map(|(next, _)| next))
}

/// `next_fire` and the UTC offset of PHP's result: the schedule zone's for
/// a cron occurrence, zero for an interval. The first rule wins a tie.
pub(crate) fn next_occurrence(spec: &Value, after: Timestamp) -> Result<Option<(Timestamp, i64)>, CronError> {
    let zone = spec
        .get("timezone")
        .and_then(Value::as_str)
        .unwrap_or("UTC");
    let mut earliest: Option<(Timestamp, i64)> = None;
    let mut consider = |next: (Timestamp, i64)| {
        if earliest.is_none_or(|current| next.0 < current.0) {
            earliest = Some(next);
        }
    };
    for expression in members(spec.get("cron_expressions").unwrap_or(&Value::Null)) {
        let Some(expression) = expression.as_str().filter(|text| !text.is_empty()) else {
            continue;
        };
        consider(Cron::parse(expression)?.next_after_with_offset(after, zone)?);
    }
    for interval in members(spec.get("intervals").unwrap_or(&Value::Null)) {
        if let Some(next) = interval_occurrence(interval, after) {
            consider((next, 0));
        }
    }
    Ok(earliest)
}

/// `WorkflowSchedule::nextIntervalOccurrence`.
fn interval_occurrence(interval: &Value, after: Timestamp) -> Option<Timestamp> {
    let every = duration_seconds(interval.get("every")?.as_str()?)?;
    if every <= 0 {
        return None;
    }
    // An invalid offset counts as none.
    let offset = interval
        .get("offset")
        .and_then(Value::as_str)
        .and_then(duration_seconds)
        .unwrap_or(0);
    let elapsed = i128::from(after.as_second()) - offset;
    let next = offset + (elapsed.div_euclid(every) + 1) * every;
    Timestamp::from_second(i64::try_from(next).ok()?).ok()
}

/// `dateIntervalToSeconds(new DateInterval($spec))`, or `None` where PHP's
/// `DateInterval` refuses the string (or it is empty).
pub(crate) fn duration_seconds(text: &str) -> Option<i128> {
    let parts = duration(text)?;
    Some(
        parts.years * 365 * 86400
            + parts.months * 30 * 86400
            + parts.days * 86400
            + parts.hours * 3600
            + parts.minutes * 60
            + parts.seconds,
    )
}

#[derive(Default, Debug, PartialEq)]
struct Duration {
    years: i128,
    months: i128,
    days: i128,
    hours: i128,
    minutes: i128,
    seconds: i128,
}

/// PHP's ISO 8601 duration grammar: `P[nY][nM][nW][nD][T[nH][nM][nS]]` in that
/// order with at least one part, or `PYYYY-MM-DDTHH:MM:SS`; surrounding
/// blanks allowed; whole non-negative numbers that fit a signed 64-bit integer.
fn duration(text: &str) -> Option<Duration> {
    let text = text.trim_matches([' ', '\t']);
    let body = text.strip_prefix('P')?;
    if let Some(parts) = combined(body) {
        return Some(parts);
    }
    let (date, time) = match body.split_once('T') {
        Some((date, time)) => (date, Some(time)),
        None => (body, None),
    };
    let mut parts = Duration::default();
    let mut any = false;
    let mut read = |section: &str, units: &[u8]| -> Option<Vec<(u8, i128)>> {
        let mut found = Vec::new();
        let mut rest = section;
        let mut position = 0;
        while !rest.is_empty() {
            let digits = rest.bytes().take_while(u8::is_ascii_digit).count();
            if digits == 0 {
                return None;
            }
            let value: i64 = rest[..digits].parse().ok()?;
            let unit = *rest.as_bytes().get(digits)?;
            let index = units[position..].iter().position(|candidate| *candidate == unit)?;
            position += index + 1;
            found.push((unit, i128::from(value)));
            rest = &rest[digits + 1..];
            any = true;
        }
        Some(found)
    };
    for (unit, value) in read(date, b"YMWD")? {
        match unit {
            b'Y' => parts.years = value,
            b'M' => parts.months = value,
            b'W' => parts.days += value * 7,
            _ => parts.days += value,
        }
    }
    if let Some(time) = time {
        let found = read(time, b"HMS")?;
        if found.is_empty() {
            return None;
        }
        for (unit, value) in found {
            match unit {
                b'H' => parts.hours = value,
                b'M' => parts.minutes = value,
                _ => parts.seconds = value,
            }
        }
    }
    any.then_some(parts)
}

/// The `YYYY-MM-DDTHH:MM:SS` alternative form.
fn combined(body: &str) -> Option<Duration> {
    let bytes = body.as_bytes();
    let shape = b"0000-00-00T00:00:00";
    if bytes.len() != shape.len()
        || !bytes
            .iter()
            .zip(shape)
            .all(|(byte, expected)| if *expected == b'0' { byte.is_ascii_digit() } else { byte == expected })
    {
        return None;
    }
    let number = |range: std::ops::Range<usize>| body[range].parse::<i128>().ok();
    Some(Duration {
        years: number(0..4)?,
        months: number(5..7)?,
        days: number(8..10)?,
        hours: number(11..13)?,
        minutes: number(14..16)?,
        seconds: number(17..19)?,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Published PHP 2.5.14's `computeNextFireAt` (scripts/conformance/cron-oracle) for seeded specs mixing
    /// cron expressions, intervals with offsets, zones and malformed rules.
    const CORPUS: &str = include_str!("../../tests/fixtures/cron/php-2.5.14-next-fire.jsonl");

    #[test]
    fn next_fire_times_match_published_php() {
        let mut mismatches = Vec::new();
        let lines: Vec<&str> = CORPUS.lines().filter(|line| !line.trim().is_empty()).collect();
        for line in &lines {
            let case: Value = serde_json::from_str(line).unwrap();
            let after: Timestamp = case["after"].as_str().unwrap().parse().unwrap();
            let actual = next_occurrence(&case["spec"], after)
                .map(|next| {
                    next.map(|(next, offset)| {
                        let sign = if offset < 0 { '-' } else { '+' };
                        format!("{} {sign}{:02}:{:02}", next.strftime("%Y-%m-%dT%H:%M:%S.%6fZ"),
                            offset.abs() / 3600, offset.abs() % 3600 / 60)
                    })
                })
                .map_err(CronError::php_class);
            let expected = match case.get("error") {
                Some(error) => Err(error.as_str().unwrap()),
                None => Ok(case["next"]
                    .as_str()
                    .map(|next| format!("{next} {}", case["offset"].as_str().unwrap()))),
            };
            if actual != expected {
                mismatches.push(format!("{line} -> {actual:?}"));
            }
        }
        assert!(lines.len() > 100);
        assert!(
            mismatches.is_empty(),
            "{} of {} differ:\n{}",
            mismatches.len(),
            lines.len(),
            mismatches.join("\n")
        );
    }

    #[test]
    fn durations_follow_php_date_interval() {
        for (text, seconds) in [
            ("P1D", Some(86400)),
            ("PT2S", Some(2)),
            ("P1W2D", Some(9 * 86400)),
            ("P1Y2M3DT4H5M6S", Some(365 * 86400 + 60 * 86400 + 3 * 86400 + 4 * 3600 + 5 * 60 + 6)),
            ("P0001-00-02T00:00:00", Some(367 * 86400)),
            (" P1D", Some(86400)),
            ("P1D ", Some(86400)),
            ("P01D", Some(86400)),
            ("P0D", Some(0)),
            ("P1DT0S", Some(86400)),
            ("p1d", None),
            ("P", None),
            ("PT", None),
            ("P1DT", None),
            ("PT1H1D", None),
            ("P1D1Y", None),
            ("P2D1W", None),
            ("PT1.5S", None),
            ("P-1D", None),
            ("P1H", None),
            ("P1M1M", None),
            ("P10000000000000000000D", None),
        ] {
            assert_eq!(duration_seconds(text), seconds, "{text}");
        }
    }
}
