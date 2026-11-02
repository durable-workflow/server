//! The daylight-saving rule for cron schedules that Core 2.15.13 applies in
//! `WorkflowSchedule::nextCronOccurrence` (Workflow #882, Server #938).
//!
//! The cron search itself stays the faithful port of the library
//! (`schedule_cron`). On top of it:
//! - when the hour field names specific hours, a wall-clock time repeated
//!   because clocks went back fires once, at its first instant;
//! - an hour field that selects every hour (`*`, `0-23`, a step of one, a
//!   full list, `@hourly`) keeps elapsed-time behavior through both passes;
//! - a skipped wall-clock time already fires once, shifted by the gap, in the
//!   library search, so nothing changes for it here.
//!
//! Published PHP Server 2.11.8 (Core 2.15.12) does not apply this rule. This
//! is a recorded difference from the frozen PHP reference.
use super::schedule_zones::PhpZone;
use jiff::{SignedDuration, Timestamp};

/// Core's `cronHourFieldIsWildcard`: whether the hour field selects every
/// hour, however it is spelled. Of the macros only `@hourly` does.
pub(crate) fn hour_field_is_wildcard(expression: &str) -> bool {
    let expression = expression.trim().to_lowercase();
    if expression.starts_with('@') {
        return expression == "@hourly";
    }
    let hour_field = expression.split_whitespace().nth(1).unwrap_or("");
    (0..=23).all(|hour| field_matches(hour_field, hour, 0, 23))
}

/// When `at` falls in the second pass of a wall-clock range repeated because
/// clocks went back in `zone`, the first instant after that range. Core's
/// `repeatedWallClockWindowEnd`: only a transition within the day before
/// `at` (or at `at` itself) counts.
pub(crate) fn repeated_window_end(at: Timestamp, zone: &str) -> Option<Timestamp> {
    let zone = PhpZone::parse(zone)?;
    let after = at.checked_add(SignedDuration::from_secs(1)).ok()?;
    let transition = zone.zone.preceding(after).next()?;
    let changed = transition.timestamp();
    if changed.as_second() < at.as_second() - 86_400 {
        return None;
    }
    let before = changed.checked_sub(SignedDuration::from_secs(1)).ok()?;
    let repeated = i64::from(zone.zone.to_offset(before).seconds()) - i64::from(transition.offset().seconds());
    let end = changed.as_second().checked_add(repeated)?;
    (repeated > 0 && at.as_second() < end)
        .then(|| Timestamp::from_second(end).ok())
        .flatten()
}

/// Core's `cronFieldMatches`, which classifies the hour field.
fn field_matches(field: &str, value: i64, min: i64, max: i64) -> bool {
    if field == "*" {
        return true;
    }
    for part in field.split(',') {
        if let Some((range, step)) = part.split_once('/') {
            let step = php_int(step);
            if step < 1 {
                continue;
            }
            let (start, end) = if range == "*" {
                (min, max)
            } else if let Some((start, end)) = range.split_once('-') {
                (php_int(start), php_int(end))
            } else if !range.is_empty() && range.bytes().all(|byte| byte.is_ascii_digit()) {
                (php_int(range), max)
            } else {
                continue;
            };
            let mut candidate = start;
            while candidate <= end {
                if candidate == value {
                    return true;
                }
                candidate += step;
            }
            continue;
        }
        if let Some((start, end)) = part.split_once('-') {
            if value >= php_int(start) && value <= php_int(end) {
                return true;
            }
            continue;
        }
        if php_int(part) == value {
            return true;
        }
    }
    false
}

/// PHP's `(int)` cast of a string: leading blanks, an optional sign and the
/// leading digits; anything else is zero.
fn php_int(text: &str) -> i64 {
    let text = text.trim_start_matches([' ', '\t', '\n', '\r', '\x0b', '\x0c']);
    let (sign, digits) = match text.as_bytes().first() {
        Some(b'-') => (-1, &text[1..]),
        Some(b'+') => (1, &text[1..]),
        _ => (1, text),
    };
    let digits: String = digits.chars().take_while(char::is_ascii_digit).collect();
    digits.parse::<i64>().map_or(0, |value| sign * value)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn at(text: &str) -> Timestamp {
        text.parse().unwrap()
    }

    #[test]
    fn every_hour_spellings_are_wildcards() {
        for expression in ["* * * * *", "0 * * * *", "*/15 */1 * * *", "0 0/1 * * *", "0 0-23 * * *",
            "0 0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23 * * *", "@hourly", "@HOURLY"] {
            assert!(hour_field_is_wildcard(expression), "{expression}");
        }
        for expression in ["30 1 * * *", "0 0-22 * * *", "0 */2 * * *", "@daily", "@midnight", "0 1,13 * * *"] {
            assert!(!hour_field_is_wildcard(expression), "{expression}");
        }
    }

    #[test]
    fn the_second_pass_of_new_yorks_repeated_hour_is_found() {
        // 2026-11-01: 02:00 EDT becomes 01:00 EST at 06:00 UTC, repeating 01:00-02:00.
        let zone = "America/New_York";
        assert_eq!(repeated_window_end(at("2026-11-01T05:30:00Z"), zone), None); // 01:30 EDT, first pass
        assert_eq!(repeated_window_end(at("2026-11-01T06:00:00Z"), zone), Some(at("2026-11-01T07:00:00Z")));
        assert_eq!(repeated_window_end(at("2026-11-01T06:30:00Z"), zone), Some(at("2026-11-01T07:00:00Z")));
        assert_eq!(repeated_window_end(at("2026-11-01T07:00:00Z"), zone), None);
        // A forward change repeats nothing.
        assert_eq!(repeated_window_end(at("2026-03-08T07:30:00Z"), zone), None);
    }

    #[test]
    fn utc_and_fixed_offsets_never_repeat() {
        assert_eq!(repeated_window_end(at("2026-11-01T06:30:00Z"), "UTC"), None);
        assert_eq!(repeated_window_end(at("2026-11-01T06:30:00Z"), "+02:00"), None);
    }

    #[test]
    fn kyivs_repeated_hour_is_found() {
        // 2026-10-25: 04:00 EEST becomes 03:00 EET at 01:00 UTC.
        assert_eq!(repeated_window_end(at("2026-10-25T01:30:00Z"), "Europe/Kyiv"), Some(at("2026-10-25T02:00:00Z")));
        assert_eq!(repeated_window_end(at("2026-10-25T00:30:00Z"), "Europe/Kyiv"), None);
    }
}
