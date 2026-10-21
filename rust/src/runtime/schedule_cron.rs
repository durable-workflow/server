//! Cron occurrences exactly as published PHP Server computes them.
//!
//! PHP Server 2.5.14 uses `dragonmantank/cron-expression` 3.6.0 through
//! `WorkflowSchedule::nextCronOccurrence` (`getNextRunDate($after, 0, false,
//! $timezone)`). This is a direct port of that library's search, including its
//! field quirks, its 1,000-step limit and PHP's wall-clock rules around
//! daylight-saving changes. The differential corpus in the tests holds PHP's
//! own answers. Zone rules come from the bundled IANA database, so a rule that
//! changed after PHP's bundled release can give a different instant.
use jiff::{
    Timestamp,
    civil::{Date, DateTime},
    tz::{AmbiguousOffset, TimeZone},
};
use super::schedule_zones::PhpZone;
use std::cmp::Ordering;

/// The PHP exception a cron evaluation ends with, by class name.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum CronError {
    /// An invalid expression or field value (`InvalidArgumentException`).
    InvalidArgument,
    /// A step range outside its field (`OutOfRangeException`).
    OutOfRange,
    /// No occurrence within the search limit, or no nearest weekday
    /// (`RuntimeException`).
    Runtime,
    /// A day-of-month and day-of-week search that found neither (`TypeError`).
    Type,
    /// A negative step, which PHP 8.3's `range()` refuses (`ValueError`).
    Value,
    /// A zone name PHP does not know (`DateInvalidTimeZoneException`).
    UnknownTimezone,
}

impl CronError {
    pub(crate) fn php_class(self) -> &'static str {
        match self {
            Self::InvalidArgument => "InvalidArgumentException",
            Self::OutOfRange => "OutOfRangeException",
            Self::Runtime => "RuntimeException",
            Self::Type => "TypeError",
            Self::Value => "ValueError",
            Self::UnknownTimezone => "DateInvalidTimeZoneException",
        }
    }
}

type Result<T> = std::result::Result<T, CronError>;

const ALIASES: [(&str, &str); 7] = [
    ("@yearly", "0 0 1 1 *"),
    ("@annually", "0 0 1 1 *"),
    ("@monthly", "0 0 1 * *"),
    ("@weekly", "0 0 * * 0"),
    ("@daily", "0 0 * * *"),
    ("@midnight", "0 0 * * *"),
    ("@hourly", "0 * * * *"),
];
const MINUTE: usize = 0;
const HOUR: usize = 1;
const DAY: usize = 2;
const MONTH: usize = 3;
const WEEKDAY: usize = 4;
/// The library's evaluation order (it skips the unused year position).
const ORDER: [usize; 5] = [MONTH, DAY, WEEKDAY, HOUR, MINUTE];
const MAX_ITERATIONS: usize = 1000;

/// A validated cron expression.
#[derive(Clone, Debug)]
pub(crate) struct Cron {
    parts: Vec<String>,
}

impl Cron {
    /// `new CronExpression($expression)`, refusing what PHP refuses.
    pub(crate) fn parse(expression: &str) -> Result<Self> {
        let lower = expression.to_lowercase();
        let expression = ALIASES
            .iter()
            .find(|(alias, _)| *alias == lower)
            .map_or(expression, |(_, value)| value);
        let parts: Vec<String> = expression
            .split(is_pcre_space)
            .filter(|part| !part.is_empty())
            .map(str::to_owned)
            .collect();
        let question = |index: usize| parts.get(index).is_some_and(|part| part == "?");
        if parts.len() < 5
            || question(0)
            || question(1)
            || question(3)
            || (question(2) && question(4))
        {
            return Err(CronError::InvalidArgument);
        }
        for (position, part) in parts.iter().enumerate() {
            let field = Field::at(position).ok_or(CronError::InvalidArgument)?;
            if !field.validate(part) {
                return Err(CronError::InvalidArgument);
            }
        }
        Ok(Self { parts })
    }

    /// `CronExpression::isValidExpression`.
    pub(crate) fn is_valid(expression: &str) -> bool {
        Self::parse(expression).is_ok()
    }

    /// The first occurrence strictly after `after`'s minute, in `zone`.
    #[cfg(test)]
    pub(crate) fn next_after(&self, after: Timestamp, zone: &str) -> Result<Timestamp> {
        self.next_after_with_offset(after, zone).map(|(next, _)| next)
    }

    /// `next_after` and the UTC offset, in seconds, PHP's result carries.
    pub(crate) fn next_after_with_offset(&self, after: Timestamp, zone: &str) -> Result<(Timestamp, i64)> {
        let zone = PhpZone::parse(zone).ok_or(CronError::UnknownTimezone)?;
        let found = run_date(&self.parts, after, &zone)?;
        let next = Timestamp::from_second(found.seconds).map_err(|_| CronError::Runtime)?;
        Ok((next, found.offset()))
    }
}

fn is_pcre_space(character: char) -> bool {
    matches!(character, ' ' | '\t' | '\n' | '\r' | '\x0b' | '\x0c')
}

/// A PHP `DateTime` with a named zone, to whole seconds: cron search never
/// carries a fraction past its first minute truncation.
#[derive(Clone)]
struct Moment<'z> {
    seconds: i64,
    zone: &'z PhpZone,
}

impl<'z> Moment<'z> {
    fn timestamp(&self) -> Timestamp {
        Timestamp::from_second(self.seconds).expect("cron search stays within jiff's range")
    }
    fn local(&self) -> DateTime {
        self.zone.zone.to_datetime(self.timestamp())
    }
    fn offset(&self) -> i64 {
        i64::from(self.zone.zone.to_offset(self.timestamp()).seconds())
    }
    fn dst(&self) -> bool {
        self.zone.dst(self.timestamp())
    }
    fn minute(&self) -> i64 {
        i64::from(self.local().minute())
    }
    fn hour(&self) -> i64 {
        i64::from(self.local().hour())
    }
    fn day(&self) -> i64 {
        i64::from(self.local().day())
    }
    fn month(&self) -> i64 {
        i64::from(self.local().month())
    }
    fn year(&self) -> i16 {
        self.local().year()
    }
    fn days_in_month(&self) -> i64 {
        i64::from(self.local().date().days_in_month())
    }
    /// PHP's `N`: Monday 1 to Sunday 7.
    fn iso_weekday(&self) -> i64 {
        i64::from(self.local().weekday().to_monday_one_offset())
    }
    /// PHP's `w`: Sunday 0 to Saturday 6.
    fn weekday(&self) -> i64 {
        i64::from(self.local().weekday().to_sunday_zero_offset())
    }
    /// The library's `timezoneSafeModify`: elapsed seconds, applied in UTC.
    fn shift(&mut self, seconds: i64) -> Result<()> {
        self.seconds = self
            .seconds
            .checked_add(seconds)
            .filter(|value| Timestamp::from_second(*value).is_ok())
            .ok_or(CronError::Runtime)?;
        Ok(())
    }
    /// Moves to a wall-clock time in this zone as PHP does: a time skipped by
    /// a forward change keeps the offset from before it, and a repeated time
    /// keeps this moment's daylight-saving flag.
    fn set_local(&mut self, local: DateTime) -> Result<()> {
        let wall = local
            .to_zoned(TimeZone::UTC)
            .map_err(|_| CronError::Runtime)?
            .timestamp()
            .as_second();
        let current = self.dst();
        self.seconds = match self.zone.zone.to_ambiguous_timestamp(local).offset() {
            AmbiguousOffset::Unambiguous { offset } => wall - i64::from(offset.seconds()),
            AmbiguousOffset::Gap { before, .. } => wall - i64::from(before.seconds()),
            AmbiguousOffset::Fold { before, after } => {
                let earlier = wall - i64::from(before.seconds());
                let later = wall - i64::from(after.seconds());
                let dst = |seconds: i64| {
                    Timestamp::from_second(seconds)
                        .map(|at| self.zone.dst(at))
                        .unwrap_or(false)
                };
                if dst(earlier) == current || dst(later) != current {
                    earlier
                } else {
                    later
                }
            }
        };
        Timestamp::from_second(self.seconds).map_err(|_| CronError::Runtime)?;
        Ok(())
    }
    /// `setTime($hour, $minute)`.
    fn set_time(&mut self, hour: i8, minute: i8) -> Result<()> {
        let date = self.local().date();
        self.set_local(date.at(hour, minute, 0, 0))
    }
    /// `add(new DateInterval('P1D'))`: the same wall-clock time a day later.
    fn add_day(&mut self) -> Result<()> {
        let local = self.local();
        let next = local.date().tomorrow().map_err(|_| CronError::Runtime)?;
        self.set_local(next.to_datetime(local.time()))
    }
    /// `modify('first day of next month')`.
    fn first_day_of_next_month(&mut self) -> Result<()> {
        let local = self.local();
        let first = local.date().first_of_month();
        let next = first
            .checked_add(jiff::Span::new().months(1))
            .map_err(|_| CronError::Runtime)?;
        self.set_local(next.to_datetime(local.time()))
    }
}

/// A local wall-clock rendering used for the library's `Y-m-d H:i:s` sort.
fn wall(moment: &Moment<'_>) -> String {
    moment.local().strftime("%Y-%m-%d %H:%M:%S").to_string()
}

/// `getRunDate($currentTime, 0, false, false, $timeZone)`.
fn run_date<'z>(parts: &[String], after: Timestamp, zone: &'z PhpZone) -> Result<Moment<'z>> {
    let part = |position: usize| {
        parts
            .get(position)
            .map(String::as_str)
            .filter(|part| *part != "*")
    };
    if let (Some(day), Some(weekday)) = (part(DAY), part(WEEKDAY)) {
        // Either field may match: search each half separately.
        let dom = format!("{} {} {} {} *", parts[0], parts[1], parts[2], parts[3]);
        let dow = format!("{} {} * {} {}", parts[0], parts[1], parts[3], parts[4]);
        let mut combined = Vec::new();
        for (expression, field) in [(dom, day), (dow, weekday)] {
            let half = Cron::parse(&expression)?;
            // getMultipleRunDates stops at a RuntimeException only.
            match run_date(&half.parts, after, zone) {
                Ok(found) if field != "?" => combined.push(found),
                Ok(_) | Err(CronError::Runtime) => {}
                Err(error) => return Err(error),
            }
        }
        // A stable sort by local wall-clock text, as usort compares them.
        combined.sort_by_key(wall);
        return combined.into_iter().next().ok_or(CronError::Type);
    }
    // Truncate to the current minute in the target zone.
    let current = Moment {
        seconds: after.as_second().div_euclid(60) * 60,
        zone,
    };
    let mut next = current.clone();
    'search: for _ in 0..MAX_ITERATIONS {
        for position in ORDER {
            let Some(value) = part(position) else {
                continue;
            };
            let field = Field::at(position).expect("validated position");
            let satisfied = if value.contains(',') {
                let mut any = false;
                for item in value.split(',') {
                    if field.satisfied_by(&next, item.trim())? {
                        any = true;
                        break;
                    }
                }
                any
            } else {
                field.satisfied_by(&next, value)?
            };
            if !satisfied {
                field.increment(&mut next, Some(value))?;
                continue 'search;
            }
        }
        if next.seconds == current.seconds {
            Field::Minute.increment(&mut next, part(MINUTE))?;
            continue;
        }
        return Ok(next);
    }
    Err(CronError::Runtime)
}

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
enum Field {
    Minute,
    Hour,
    DayOfMonth,
    Month,
    DayOfWeek,
}

const MONTHS: [&str; 12] = [
    "JAN", "FEB", "MAR", "APR", "MAY", "JUN", "JUL", "AUG", "SEP", "OCT", "NOV", "DEC",
];
const WEEKDAYS: [&str; 7] = ["MON", "TUE", "WED", "THU", "FRI", "SAT", "SUN"];

impl Field {
    fn at(position: usize) -> Option<Self> {
        Some(match position {
            MINUTE => Self::Minute,
            HOUR => Self::Hour,
            DAY => Self::DayOfMonth,
            MONTH => Self::Month,
            WEEKDAY => Self::DayOfWeek,
            _ => return None,
        })
    }

    fn range(self) -> (i64, i64) {
        match self {
            Self::Minute => (0, 59),
            Self::Hour => (0, 23),
            Self::DayOfMonth => (1, 31),
            Self::Month => (1, 12),
            Self::DayOfWeek => (0, 7),
        }
    }

    /// `convertLiterals`: a whole-value name lookup, case-insensitive.
    fn convert_literals(self, value: &str) -> String {
        let names: &[&str] = match self {
            Self::Month => &MONTHS,
            Self::DayOfWeek => &WEEKDAYS,
            _ => &[],
        };
        let upper = value.to_ascii_uppercase();
        names
            .iter()
            .position(|name| *name == upper)
            .map_or_else(|| value.to_owned(), |index| (index + 1).to_string())
    }

    /// `validate`, including each subclass's additions.
    fn validate(self, value: &str) -> bool {
        match self {
            Self::DayOfMonth => {
                let basic = self.basic_validate(value);
                if value.contains(',') && (value.contains('W') || value.contains('L')) {
                    return false;
                }
                if basic {
                    return true;
                }
                if value == "?" || value == "L" {
                    return true;
                }
                match value.strip_suffix('W') {
                    Some(prefix) => self.validate(prefix),
                    None => false,
                }
            }
            Self::DayOfWeek => {
                if self.basic_validate(value) {
                    return true;
                }
                if value == "?" {
                    return true;
                }
                if value.contains('#') {
                    let mut chunks = value.split('#');
                    let weekday = self.convert_literals(chunks.next().unwrap_or(""));
                    let nth = chunks.next().unwrap_or("");
                    if self.basic_validate(&weekday)
                        && php_is_numeric(nth)
                        && (1..=5).contains(&php_int(nth))
                    {
                        return true;
                    }
                }
                match value.strip_suffix('L') {
                    Some(prefix) => self.validate(prefix),
                    None => false,
                }
            }
            _ => self.basic_validate(value),
        }
    }

    /// `AbstractField::validate`; recursion uses the subclass rules.
    fn basic_validate(self, value: &str) -> bool {
        let value = self.convert_literals(value);
        if value == "*" {
            return true;
        }
        if value.contains(',') {
            return value.split(',').all(|item| self.validate(item));
        }
        if value.contains('/') {
            let mut chunks = value.split('/');
            let range = chunks.next().unwrap_or("");
            let step = chunks.next().unwrap_or("");
            if php_is_numeric(range) {
                return false;
            }
            return self.validate(range) && php_filter_int(step).is_some_and(|step| step != 0);
        }
        if value.contains('-') {
            if value.matches('-').count() > 1 {
                return false;
            }
            let (start, end) = value.split_once('-').expect("one dash");
            let start = self.convert_literals(start);
            let end = self.convert_literals(end);
            if start == "*" || end == "*" {
                return false;
            }
            return self.validate(&start) && self.validate(&end);
        }
        if !php_is_numeric(&value) || value.contains('.') {
            return false;
        }
        let (low, high) = self.range();
        (low..=high).contains(&php_int(&value))
    }

    /// `isSatisfiedBy($date, $value, false)`.
    fn satisfied_by(self, date: &Moment<'_>, value: &str) -> Result<bool> {
        if value == "?" && self != Self::Hour {
            return Ok(true);
        }
        match self {
            Self::Minute => self.satisfied(date.minute(), value),
            Self::Month => self.satisfied(date.month(), &self.convert_literals(value)),
            Self::Hour => {
                let hour = date.hour();
                if self.satisfied(hour, value)? {
                    return Ok(true);
                }
                // On the edge of an offset change, the skipped or repeated
                // hour also counts.
                if !transition_within_hour(date) {
                    return Ok(false);
                }
                let mut before = date.clone();
                before.shift(-3600)?;
                let mut after = date.clone();
                after.shift(3600)?;
                let change = after.offset() - before.offset();
                if change >= 3600 {
                    return self.satisfied(hour - 1, value);
                }
                if change <= -3600 {
                    return self.satisfied(hour + 1, value);
                }
                Ok(false)
            }
            Self::DayOfMonth => {
                if value == "L" {
                    return Ok(date.day() == date.days_in_month());
                }
                if let Some(position) = value.find('W').filter(|position| *position > 0) {
                    let target = php_int(&value[..position]);
                    return match nearest_weekday(date.year(), date.month(), target) {
                        Some(day) => Ok(date.day() == day),
                        None => Err(CronError::Runtime),
                    };
                }
                self.satisfied(date.day(), value)
            }
            Self::DayOfWeek => self.weekday_satisfied(date, &self.convert_literals(value)),
        }
    }

    fn weekday_satisfied(self, date: &Moment<'_>, value: &str) -> Result<bool> {
        if let Some(position) = value.find('L').filter(|position| *position > 0) {
            let weekday = php_int(&self.convert_literals(&value[..position])).rem_euclid(7);
            let remaining = date.days_in_month() - date.day();
            return Ok(weekday == date.weekday() && remaining < 7);
        }
        if value.find('#').is_some_and(|position| position > 0) {
            let mut chunks = value.split('#');
            let weekday = chunks.next().unwrap_or("");
            let nth = chunks.next().unwrap_or("");
            if !php_is_numeric(nth) {
                return Err(CronError::InvalidArgument);
            }
            let nth = php_int(nth);
            let weekday = if weekday == "0" { "7" } else { weekday };
            let weekday = php_int(&self.convert_literals(weekday));
            if !(0..=7).contains(&weekday) || !(1..=5).contains(&nth) {
                return Err(CronError::InvalidArgument);
            }
            if date.iso_weekday() != weekday {
                return Ok(false);
            }
            // The library counts from the first of the month.
            let first = Date::new(date.year(), date.month() as i8, 1).map_err(|_| CronError::Runtime)?;
            let last = date.days_in_month();
            let mut count = 0;
            let mut day = 1;
            while day < last + 1 {
                let current = first
                    .checked_add(jiff::Span::new().days(day - 1))
                    .map_err(|_| CronError::Runtime)?;
                if i64::from(current.weekday().to_monday_one_offset()) == weekday {
                    count += 1;
                    if count >= nth {
                        break;
                    }
                }
                day += 1;
            }
            return Ok(date.day() == day);
        }
        let mut value = value.to_owned();
        if value.contains('-') {
            let mut parts: Vec<String> = value.split('-').map(str::to_owned).collect();
            if parts[0] == "7" {
                parts[0] = "0".into();
            } else if parts.get(1).is_some_and(|part| part == "0") {
                parts[1] = "7".into();
            }
            value = parts.join("-");
        }
        // Any character 7 selects ISO numbering (Sunday 7).
        let iso = value.chars().any(|character| character == '7');
        let field = if iso { date.iso_weekday() } else { date.weekday() };
        self.satisfied(field, &value)
    }

    /// `AbstractField::isSatisfied`.
    fn satisfied(self, current: i64, value: &str) -> Result<bool> {
        if value.contains('/') {
            return self.in_increments(current, value);
        }
        if value.contains('-') {
            let (start, end) = value.split_once('-').expect("one dash");
            let start = php_int(&self.convert_literals(start.trim()));
            let end = php_int(&self.convert_literals(end.trim()));
            return Ok(current >= start && current <= end);
        }
        Ok(value == "*" || current == php_int(value))
    }

    /// `isInIncrementsOfRanges`.
    fn in_increments(self, current: i64, value: &str) -> Result<bool> {
        let (range, step) = match value.split_once('/') {
            Some((range, step)) => (range.trim(), step.trim()),
            None => (value.trim(), "0"),
        };
        if step == "0" {
            return Ok(false);
        }
        let step = php_int(step);
        let (low, high) = self.range();
        let full;
        let range = if range == "*" {
            full = format!("{low}-{high}");
            full.as_str()
        } else {
            range
        };
        let (start, end) = match range.split_once('-') {
            Some((start, end)) => (php_int(start), php_int(end)),
            None => (php_int(range), php_int(range)),
        };
        if start < low || start > high || start > end {
            return Err(CronError::OutOfRange);
        }
        if end < low || end > high || end < start {
            return Err(CronError::OutOfRange);
        }
        if step > high {
            let count = high - low + 1;
            return Ok(current == low + step.rem_euclid(count));
        }
        if step > end - start {
            return Ok(current == start);
        }
        if step < 1 {
            // PHP 8.3's range() refuses a negative step for a rising range.
            return Err(CronError::Value);
        }
        Ok(current >= start && current <= end && (current - start) % step == 0)
    }

    /// `getRangeForExpression($expression, $max)` for one list item.
    fn expand(self, expression: &str, max: i64) -> Result<Vec<i64>> {
        let expression = self.convert_literals(expression);
        if !expression.contains('-') && !expression.contains('/') {
            return Ok(vec![php_int(&expression)]);
        }
        let (low, high) = self.range();
        let (offset, to, step) = if !expression.contains('/') {
            let (offset, to) = expression.split_once('-').expect("a range");
            (
                php_int(&self.convert_literals(offset)),
                php_int(&self.convert_literals(to)),
                1,
            )
        } else {
            let (range, step) = expression.split_once('/').expect("a step");
            let step = php_int(step.trim());
            let range = range.trim();
            let (offset, to) = match range.split_once('-') {
                Some((offset, to)) => (offset, Some(to)),
                None => (range, None),
            };
            let offset = if offset == "*" { low } else { php_int(offset) };
            (offset, to.map_or(max, php_int), step)
        };
        if step >= high {
            let count = high - low + 1;
            return Ok(vec![low + step.rem_euclid(count)]);
        }
        if step < 1 {
            // PHP loops forever here; native refuses instead.
            return Err(CronError::Value);
        }
        let mut values = Vec::new();
        let mut value = offset;
        while value <= to {
            values.push(value);
            value += step;
        }
        values.sort_unstable();
        Ok(values)
    }

    /// `increment($date, false, $parts)`.
    fn increment(self, date: &mut Moment<'_>, parts: Option<&str>) -> Result<()> {
        match self {
            Self::Minute => {
                let Some(parts) = parts else {
                    return date.shift(60);
                };
                let mut items: Vec<&str> = parts.split(',').collect();
                items.sort_by(|left, right| php_compare(left, right));
                let mut minutes = Vec::new();
                for item in items {
                    minutes.extend(self.expand(item, 59)?);
                }
                // An empty expansion reads PHP's missing key as zero.
                let target = minutes
                    .get(position(&minutes, date.minute()))
                    .copied()
                    .unwrap_or(0);
                let mut minute = date.minute();
                if minute >= target {
                    date.shift((60 - minute) * 60)?;
                    minute = date.minute();
                }
                date.shift((target - minute) * 60)
            }
            Self::Hour => {
                let original = date.seconds;
                let mut hours = Vec::new();
                for item in parts.unwrap_or("*").split(',') {
                    hours.extend(self.expand(item, 23)?);
                }
                let target = hours
                    .get(position(&hours, date.hour()))
                    .copied()
                    .unwrap_or(0);
                let mut hour = date.hour();
                let dst = date.dst();
                let day = date.day();
                let offset = date.offset();
                if hour >= target {
                    date.shift((24 - hour) * 3600)?;
                    if date.day() != day + 1 && date.hour() != 0 {
                        let change = offset - date.offset();
                        date.shift(change)?;
                    }
                    hour = date.hour();
                }
                date.shift((target - hour) * 3600)?;
                if !dst && date.dst() {
                    date.shift(-3600)?;
                }
                // setTimeHour: the top of the reached hour, never backwards.
                let reached = date.hour() as i8;
                date.set_time(reached, 0)?;
                if date.seconds <= original {
                    date.shift(3600)?;
                }
                Ok(())
            }
            Self::DayOfMonth | Self::DayOfWeek => {
                date.add_day()?;
                date.set_time(0, 0)
            }
            Self::Month => {
                date.first_day_of_next_month()?;
                date.set_time(0, 0)
            }
        }
    }
}

/// The library's next-target position in an expanded list, which assumes the
/// list is ascending even when it is not.
fn position(values: &[i64], current: i64) -> usize {
    if values.len() > 1 {
        for index in 0..values.len() - 1 {
            if current >= values[index] && current < values[index + 1] {
                return index + 1;
            }
        }
    }
    0
}

/// Whether the zone changed offset (or rule) within the past hour.
fn transition_within_hour(date: &Moment<'_>) -> bool {
    date.zone
        .zone
        .preceding(date.timestamp().checked_add(jiff::SignedDuration::from_secs(1)).unwrap_or(date.timestamp()))
        .next()
        .is_some_and(|transition| transition.timestamp().as_second() > date.seconds - 3600)
}

/// `DayOfMonthField::getNearestWeekday`, with PHP's date overflow.
fn nearest_weekday(year: i16, month: i64, target: i64) -> Option<i64> {
    let first = Date::new(year, month as i8, 1).ok()?;
    let overflow = |day: i64| first.checked_add(jiff::Span::new().days(day - 1)).ok();
    let date = overflow(target)?;
    let iso = |date: Date| i64::from(date.weekday().to_monday_one_offset());
    if iso(date) < 6 {
        return Some(i64::from(date.day()));
    }
    let last = i64::from(date.days_in_month());
    for adjust in [-1, 1, -2, 2] {
        let adjusted = target + adjust;
        if adjusted > 0 && adjusted <= last {
            let candidate = overflow(adjusted)?;
            if iso(candidate) < 6 && i64::from(candidate.month()) == month {
                return Some(i64::from(candidate.day()));
            }
        }
    }
    None
}

/// PHP 8's `is_numeric` for the short ASCII strings cron fields contain.
pub(crate) fn php_is_numeric(value: &str) -> bool {
    let trimmed = value.trim_start_matches(is_pcre_space).trim_end_matches(is_pcre_space);
    let digits = trimmed.strip_prefix(['+', '-']).unwrap_or(trimmed);
    let (mantissa, exponent) = match digits.find(['e', 'E']) {
        Some(index) => (&digits[..index], Some(&digits[index + 1..])),
        None => (digits, None),
    };
    let (whole, fraction) = mantissa.split_once('.').unwrap_or((mantissa, ""));
    let mantissa_ok = (!whole.is_empty() || !fraction.is_empty())
        && whole.bytes().all(|byte| byte.is_ascii_digit())
        && fraction.bytes().all(|byte| byte.is_ascii_digit());
    let exponent_ok = exponent.is_none_or(|exponent| {
        let exponent = exponent.strip_prefix(['+', '-']).unwrap_or(exponent);
        !exponent.is_empty() && exponent.bytes().all(|byte| byte.is_ascii_digit())
    });
    mantissa_ok && exponent_ok
}

/// PHP 8's `(int)` cast of a string: its leading number, or zero.
pub(crate) fn php_int(value: &str) -> i64 {
    let trimmed = value.trim_start_matches(is_pcre_space);
    let end = trimmed
        .char_indices()
        .take_while(|(index, character)| {
            character.is_ascii_digit() || (*index == 0 && matches!(character, '+' | '-'))
        })
        .last()
        .map_or(0, |(index, character)| index + character.len_utf8());
    let integer = &trimmed[..end];
    // A numeric string with a fraction or exponent casts through a float.
    let rest = &trimmed[end..];
    if (rest.starts_with('.') || rest.starts_with(['e', 'E'])) && php_is_numeric(trimmed) {
        return trimmed.parse::<f64>().map_or(0, |number| number as i64);
    }
    integer.parse().unwrap_or(0)
}

/// `filter_var($value, FILTER_VALIDATE_INT)`.
fn php_filter_int(value: &str) -> Option<i64> {
    let value = value.trim_matches(is_pcre_space);
    let digits = value.strip_prefix(['+', '-']).unwrap_or(value);
    if digits.is_empty()
        || !digits.bytes().all(|byte| byte.is_ascii_digit())
        || (digits.len() > 1 && digits.starts_with('0'))
    {
        return None;
    }
    value.parse().ok()
}

/// PHP 8's standard comparison of two strings, as `sort()` uses it.
fn php_compare(left: &str, right: &str) -> Ordering {
    if php_is_numeric(left) && php_is_numeric(right) {
        let left: f64 = left.trim().parse().unwrap_or(0.0);
        let right: f64 = right.trim().parse().unwrap_or(0.0);
        return left.partial_cmp(&right).unwrap_or(Ordering::Equal);
    }
    left.as_bytes().cmp(right.as_bytes())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::Value;

    /// Published PHP 2.5.14's answers (scripts/conformance/cron-oracle): each case
    /// is an expression, an instant, a zone and PHP's next occurrence or
    /// exception class.
    const CORPUS: &str = include_str!("../../tests/fixtures/cron/php-2.5.14-next-run.jsonl");

    #[test]
    fn next_occurrences_match_published_php() {
        let mut mismatches = Vec::new();
        let mut cases = 0;
        for line in CORPUS.lines().filter(|line| !line.trim().is_empty()) {
            cases += 1;
            let case: Value = serde_json::from_str(line).unwrap();
            let after: Timestamp = case["after"].as_str().unwrap().parse().unwrap();
            let tz = case["tz"].as_str().unwrap();
            let actual = Cron::parse(case["cron"].as_str().unwrap())
                .and_then(|cron| cron.next_after(after, tz))
                .map(|next| next.strftime("%Y-%m-%dT%H:%M:%S.%6fZ").to_string())
                .map_err(CronError::php_class);
            let expected = match case["next"].as_str() {
                Some(next) => Ok(next.to_owned()),
                None => Err(case["error"].as_str().unwrap()),
            };
            if actual != expected {
                mismatches.push(format!("{line} -> {actual:?}"));
            }
        }
        assert!(cases > 0);
        assert!(
            mismatches.is_empty(),
            "{} of {cases} differ:\n{}",
            mismatches.len(),
            mismatches.join("\n")
        );
    }

    #[test]
    fn php_scalar_helpers_follow_php_8() {
        assert!(php_is_numeric("007") && php_is_numeric("+5") && php_is_numeric("1e1"));
        assert!(!php_is_numeric("5-10") && !php_is_numeric("*") && !php_is_numeric(""));
        assert_eq!(php_int("1e1"), 10);
        assert_eq!(php_int("12abc"), 12);
        assert_eq!(php_int("MON"), 0);
        assert_eq!(php_filter_int("+2"), Some(2));
        assert_eq!(php_filter_int("02"), None);
        assert_eq!(php_compare("10", "5"), Ordering::Greater);
        assert_eq!(php_compare("30", "5-10"), Ordering::Less);
    }
}


#[cfg(test)]
mod zone_tests {
    use super::*;

    /// Published PHP 2.5.14's answers for every zone spelling it was given:
    /// each identifier it lists (with links), case variants, every
    /// abbreviation, numeric offsets and refused strings. Each line is the
    /// name and PHP's result for the three fixed cases below.
    const ZONES: &str = include_str!("../../tests/fixtures/cron/php-2.5.14-zone-names.jsonl");
    const CASES: [(&str, &str); 3] = [
        ("0 12 * * *", "2026-07-01T10:17:00Z"),
        ("30 2 * * *", "2026-03-08T05:00:00Z"),
        ("15 1 * * *", "2026-10-25T00:20:00Z"),
    ];

    #[test]
    fn zone_names_resolve_as_published_php_resolves_them() {
        let mut mismatches = Vec::new();
        let lines: Vec<&str> = ZONES.lines().filter(|line| !line.trim().is_empty()).collect();
        for line in &lines {
            let row: Vec<String> = serde_json::from_str(line).unwrap();
            let actual: Vec<String> = CASES
                .iter()
                .map(|(cron, after)| {
                    Cron::parse(cron)
                        .and_then(|cron| cron.next_after(after.parse().unwrap(), &row[0]))
                        .map_or_else(
                            |error| error.php_class().to_owned(),
                            |next| next.strftime("%Y-%m-%dT%H:%MZ").to_string(),
                        )
                })
                .collect();
            if actual[..] != row[1..] {
                mismatches.push(format!("{line} -> {actual:?}"));
            }
        }
        assert!(lines.len() > 1000);
        assert!(
            mismatches.is_empty(),
            "{} of {} differ:\n{}",
            mismatches.len(),
            lines.len(),
            mismatches.join("\n")
        );
    }
}
