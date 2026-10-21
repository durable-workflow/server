//! Time zones exactly as PHP's `new DateTimeZone($name)` reads them.
//!
//! This follows timelib's `timelib_parse_zone`: optional leading blanks or
//! `(`, a `GMT+`/`GMT-` prefix, a numeric offset after `+` or `-`, otherwise
//! one word that is first an abbreviation (PHP's table, below) and then an
//! IANA identifier, matched without regard to case, then at most as many `)`
//! as were opened and nothing else. Offsets of 26 hours or more, which PHP
//! accepts up to 100 hours, are refused.
use jiff::{
    Timestamp,
    tz::{Offset, TimeZone},
};

/// A zone and, for an abbreviation, its fixed daylight-saving flag.
#[derive(Clone, Debug)]
pub(crate) struct PhpZone {
    pub(super) zone: TimeZone,
    fixed_dst: Option<bool>,
}

/// Zones whose standard time, in the IANA main data PHP bundles, is their
/// summer time: winter is "daylight saving" with a negative offset. The
/// bundled database here uses the positive-only convention, so PHP's flag is
/// the opposite one for current dates (Ireland since 1971, Morocco and
/// Western Sahara since 2018).
const NEGATIVE_DST_ZONES: [&str; 4] = [
    "europe/dublin",
    "eire",
    "africa/casablanca",
    "africa/el_aaiun",
];

impl PhpZone {
    /// PHP's `format('I')`: whether this instant is daylight-saving time.
    pub(super) fn dst(&self, at: Timestamp) -> bool {
        if let Some(dst) = self.fixed_dst {
            return dst;
        }
        let dst = self.zone.to_offset_info(at).dst().is_dst();
        let negative = self.zone.iana_name().is_some_and(|name| {
            NEGATIVE_DST_ZONES.contains(&name.to_ascii_lowercase().as_str())
        });
        dst != negative
    }

    /// `new DateTimeZone($name)`, or `None` where PHP throws.
    pub(crate) fn parse(name: &str) -> Option<Self> {
        let mut rest = name.trim_start_matches([' ', '\t', '(']);
        // Only as many closing parentheses as were opened may follow.
        let opened = name[..name.len() - rest.len()].matches('(').count();
        if rest.len() > 3
            && rest.starts_with("GMT")
            && matches!(rest.as_bytes()[3], b'+' | b'-')
        {
            rest = &rest[3..];
        }
        let (zone, rest) = match rest.as_bytes().first() {
            Some(sign @ (b'+' | b'-')) => {
                let (seconds, rest) = offset(&rest[1..])?;
                let seconds = if *sign == b'-' { -seconds } else { seconds };
                // timezone_initialize's range; jiff's own is narrower.
                if seconds.abs() >= 100 * 3600 {
                    return None;
                }
                let offset = Offset::from_seconds(i32::try_from(seconds).ok()?).ok()?;
                (
                    Self {
                        zone: TimeZone::fixed(offset),
                        fixed_dst: Some(false),
                    },
                    rest,
                )
            }
            _ => {
                let end = rest
                    .find(|character: char| {
                        !(character.is_ascii_alphanumeric() || "/_-+".contains(character))
                    })
                    .unwrap_or(rest.len());
                let word = &rest[..end];
                let abbreviation = (word.len() < 6)
                    .then(|| abbreviation(word))
                    .flatten();
                let zone = match abbreviation {
                    Some((seconds, dst)) if word != "UTC" => Self {
                        zone: TimeZone::fixed(Offset::from_seconds(seconds).ok()?),
                        fixed_dst: Some(dst),
                    },
                    _ => match TimeZone::get(word) {
                        Ok(zone) => Self {
                            zone,
                            fixed_dst: None,
                        },
                        Err(_) => {
                            let (seconds, dst) = abbreviation?;
                            Self {
                                zone: TimeZone::fixed(Offset::from_seconds(seconds).ok()?),
                                fixed_dst: Some(dst),
                            }
                        }
                    },
                };
                (zone, &rest[end..])
            }
        };
        let closing = rest.len() - rest.trim_start_matches(')').len();
        (closing == rest.len() && closing <= opened).then_some(zone)
    }
}

/// `timelib_parse_tz_cor`: the digits and colons after a sign.
fn offset(text: &str) -> Option<(i64, &str)> {
    let end = text
        .find(|character: char| !(character.is_ascii_digit() || character == ':'))
        .unwrap_or(text.len());
    let (digits, rest) = text.split_at(end);
    let bytes = digits.as_bytes();
    // strtol: the leading digits of a slice, as C reads them.
    let number = |from: usize| -> i64 {
        digits[from.min(digits.len())..]
            .bytes()
            .take_while(u8::is_ascii_digit)
            .fold(0i64, |value, byte| value.saturating_mul(10).saturating_add(i64::from(byte - b'0')))
    };
    let seconds = match digits.len() {
        1 | 2 => number(0) * 3600,
        3 | 4 if bytes[1] == b':' => number(0) * 3600 + number(2) * 60,
        3 | 4 if bytes[2] == b':' => number(0) * 3600 + number(3) * 60,
        3 | 4 => {
            let value = number(0);
            value / 100 * 3600 + value % 100 * 60
        }
        5 if bytes[2] == b':' => number(0) * 3600 + number(3) * 60,
        6 => {
            let value = number(0);
            value / 10000 * 3600 + (value / 100) % 100 * 60 + value % 100
        }
        8 if bytes[2] == b':' && bytes[5] == b':' => {
            number(0) * 3600 + number(3) * 60 + number(6)
        }
        _ => return None,
    };
    Some((seconds, rest))
}

/// The abbreviation's offset and daylight flag, as PHP resolves it: its
/// first entry in timelib's table (`DateTimeZone::listAbbreviations()`).
fn abbreviation(word: &str) -> Option<(i32, bool)> {
    let lower = word.to_ascii_lowercase();
    ABBREVIATIONS
        .iter()
        .find(|(name, _, _)| *name == lower)
        .map(|(_, seconds, dst)| (*seconds, *dst))
}

/// PHP 8.3's timelib abbreviations (time zone database 2026.1).
const ABBREVIATIONS: [(&str, i32, bool); 144] = [
    ("acdt", 37800, true),
    ("acst", 34200, false),
    ("addt", -7200, true),
    ("adt", -10800, true),
    ("aedt", 39600, true),
    ("aest", 36000, false),
    ("ahdt", -32400, true),
    ("ahst", -36000, false),
    ("akdt", -28800, true),
    ("akst", -32400, false),
    ("amt", -13840, false),
    ("apt", -10800, true),
    ("ast", -14400, false),
    ("awdt", 32400, true),
    ("awst", 28800, false),
    ("awt", -10800, true),
    ("bdst", 7200, true),
    ("bdt", -36000, true),
    ("bmt", -14309, false),
    ("bst", 3600, true),
    ("cast", 34200, false),
    ("cat", 7200, false),
    ("cddt", -14400, true),
    ("cdt", -18000, true),
    ("cemt", 10800, true),
    ("cest", 7200, true),
    ("cet", 3600, false),
    ("cmt", -15408, false),
    ("cpt", -18000, true),
    ("cst", -21600, false),
    ("cwt", -18000, true),
    ("chst", 36000, false),
    ("dmt", -1521, false),
    ("eat", 10800, false),
    ("eddt", -10800, true),
    ("edt", -14400, true),
    ("eest", 10800, true),
    ("eet", 7200, false),
    ("emt", -26248, false),
    ("ept", -14400, true),
    ("est", -18000, false),
    ("ewt", -14400, true),
    ("ffmt", -14660, false),
    ("fmt", -4056, false),
    ("gdt", 39600, true),
    ("gmt", 0, false),
    ("gst", 36000, false),
    ("hdt", -34200, true),
    ("hkst", 32400, true),
    ("hkt", 28800, false),
    ("hmt", -19776, false),
    ("hpt", -34200, true),
    ("hst", -36000, false),
    ("hwt", -34200, true),
    ("iddt", 14400, true),
    ("idt", 10800, true),
    ("imt", 25025, false),
    ("ist", 7200, false),
    ("jdt", 36000, true),
    ("jmt", 8440, false),
    ("jst", 32400, false),
    ("kdt", 36000, true),
    ("kmt", 5736, false),
    ("kst", 30600, false),
    ("lst", 9394, true),
    ("mddt", -18000, true),
    ("mdst", 16279, true),
    ("mdt", -21600, true),
    ("mest", 7200, true),
    ("met", 3600, false),
    ("mmt", 9017, false),
    ("mpt", -21600, true),
    ("msd", 14400, true),
    ("msk", 10800, false),
    ("mst", -25200, false),
    ("mwt", -21600, true),
    ("nddt", -5400, true),
    ("ndt", -9052, true),
    ("npt", -9000, true),
    ("nst", -12600, false),
    ("nwt", -9000, true),
    ("nzdt", 46800, true),
    ("nzmt", 41400, false),
    ("nzst", 43200, false),
    ("pddt", -21600, true),
    ("pdt", -25200, true),
    ("pkst", 21600, true),
    ("pkt", 18000, false),
    ("plmt", 25590, false),
    ("pmt", -13236, false),
    ("ppmt", -17340, false),
    ("ppt", -25200, true),
    ("pst", -28800, false),
    ("pwt", -25200, true),
    ("qmt", -18840, false),
    ("rmt", 5794, false),
    ("sast", 7200, false),
    ("sdmt", -16800, false),
    ("sjmt", -20173, false),
    ("smt", -13884, false),
    ("sst", -39600, false),
    ("tbmt", 10751, false),
    ("tmt", 12344, false),
    ("uct", 0, false),
    ("utc", 0, false),
    ("wast", 7200, true),
    ("wat", 3600, false),
    ("wemt", 7200, true),
    ("west", 3600, true),
    ("wet", 0, false),
    ("wib", 25200, false),
    ("wita", 28800, false),
    ("wit", 32400, false),
    ("wmt", 5040, false),
    ("yddt", -25200, true),
    ("ydt", -28800, true),
    ("ypt", -28800, true),
    ("yst", -32400, false),
    ("ywt", -28800, true),
    ("a", 3600, false),
    ("b", 7200, false),
    ("c", 10800, false),
    ("d", 14400, false),
    ("e", 18000, false),
    ("f", 21600, false),
    ("g", 25200, false),
    ("h", 28800, false),
    ("i", 32400, false),
    ("k", 36000, false),
    ("l", 39600, false),
    ("m", 43200, false),
    ("n", -3600, false),
    ("o", -7200, false),
    ("p", -10800, false),
    ("q", -14400, false),
    ("r", -18000, false),
    ("s", -21600, false),
    ("t", -25200, false),
    ("u", -28800, false),
    ("v", -32400, false),
    ("w", -36000, false),
    ("x", -39600, false),
    ("y", -43200, false),
    ("z", 0, false),
];
