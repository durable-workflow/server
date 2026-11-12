//! Per-connection prepared-statement cache of the MySQL and PostgreSQL
//! runtime pools (Server #1174).
//!
//! SQLx keeps a least-recently-used cache of server-side prepared statements
//! on each connection. Its default of 100 entries is smaller than the native
//! hot path: the #1078 workload uses about 160 distinct statements on every
//! pool connection, so the cache evicted and prepared about 12 statements
//! (plus 11 closes) per workflow. 200 entries hold that working set.
//!
//! On MySQL, cached statements count toward the server-wide
//! `max_prepared_stmt_count` (default 16382), shared by every client of the
//! server. A native node holds at most `POOL_CONNECTIONS × capacity` of them,
//! so a cluster stays within the limit while
//! `nodes × POOL_CONNECTIONS × capacity + other clients ≤ max_prepared_stmt_count`.
//! The upper bound below keeps a single node within the default limit.

/// Environment variable that sets the capacity.
pub const ENV: &str = "DW_NATIVE_DB_STATEMENT_CACHE_CAPACITY";
/// SQLx's `DB_URL` parameter for the same capacity.
pub const URL_PARAMETER: &str = "statement-cache-capacity";
/// Capacity when neither `ENV` nor `URL_PARAMETER` is set.
pub const DEFAULT: usize = 200;
/// Connections in each native MySQL and PostgreSQL runtime pool.
pub const POOL_CONNECTIONS: u32 = 4;
/// MySQL's default `max_prepared_stmt_count`.
pub const MYSQL_DEFAULT_MAX_PREPARED_STMT_COUNT: usize = 16_382;
/// Largest accepted capacity: one node's full pool stays within MySQL's
/// default `max_prepared_stmt_count`.
pub const MAX: usize = MYSQL_DEFAULT_MAX_PREPARED_STMT_COUNT / POOL_CONNECTIONS as usize;

pub const INVALID: &str = "invalid DW_NATIVE_DB_STATEMENT_CACHE_CAPACITY";
pub const INVALID_URL: &str = "invalid DB_URL statement-cache-capacity";
pub const CONFLICT: &str = "DB_URL statement-cache-capacity conflicts with DW_NATIVE_DB_STATEMENT_CACHE_CAPACITY";

/// Distinct statements per pool connection on the #1078 workload (Server #1174).
const MEASURED_WORKING_SET: usize = 166;
const _: () = assert!(DEFAULT >= MEASURED_WORKING_SET);
const _: () = assert!(MAX * POOL_CONNECTIONS as usize <= MYSQL_DEFAULT_MAX_PREPARED_STMT_COUNT);
// 20 nodes at the default fit within MySQL's default limit.
const _: () = assert!(20 * DEFAULT * POOL_CONNECTIONS as usize <= MYSQL_DEFAULT_MAX_PREPARED_STMT_COUNT);

fn parse(value: &str) -> Option<usize> {
    if value.is_empty() || !value.bytes().all(|byte| byte.is_ascii_digit()) {
        return None;
    }
    value.parse().ok().filter(|capacity| *capacity <= MAX)
}

/// The capacity for the runtime connection options: `ENV`, else the
/// `DB_URL` parameter, else `DEFAULT`. An empty `ENV` counts as unset.
/// Both values must be whole numbers from 0 (no cache) to `MAX`, and must
/// agree when both are set. Errors never echo the URL.
pub fn capacity(configured: Option<&str>, url: Option<&str>) -> Result<usize, &'static str> {
    let configured = match configured.map(str::trim).filter(|value| !value.is_empty()) {
        Some(value) => Some(parse(value).ok_or(INVALID)?),
        None => None,
    };
    let query = url
        .and_then(|url| url.split_once('?'))
        .map(|(_, query)| query.split('#').next().unwrap_or_default())
        .unwrap_or_default();
    let mut from_url = None;
    for (key, value) in form_urlencoded::parse(query.as_bytes()) {
        if key == URL_PARAMETER {
            from_url = Some(parse(&value).ok_or(INVALID_URL)?);
        }
    }
    match (configured, from_url) {
        (Some(configured), Some(from_url)) if configured != from_url => Err(CONFLICT),
        (configured, from_url) => Ok(configured.or(from_url).unwrap_or(DEFAULT)),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn unset_capacity_uses_the_default() {
        assert_eq!(capacity(None, None), Ok(DEFAULT));
        assert_eq!(capacity(Some(""), Some("mysql://db/app?charset=utf8mb4")), Ok(DEFAULT));
        assert_eq!(MAX, 4095);
        // The environment audit knows the name; it is not an unknown DW_ variable.
        assert!(crate::config_audit::NATIVE_HONORED.iter().any(|setting| setting.name == ENV));
    }

    #[test]
    fn environment_or_url_sets_the_capacity() {
        assert_eq!(capacity(Some("400"), None), Ok(400));
        assert_eq!(capacity(Some(" 0 "), None), Ok(0));
        assert_eq!(capacity(Some("4095"), None), Ok(4095));
        assert_eq!(capacity(None, Some("mysql://u:p@db/app?statement-cache-capacity=150")), Ok(150));
        assert_eq!(
            capacity(Some("150"), Some("postgres://db/app?sslmode=prefer&statement-cache-capacity=150#x")),
            Ok(150)
        );
        assert_eq!(capacity(None, Some("mysql://db/app#statement-cache-capacity=7")), Ok(200));
    }

    #[test]
    fn invalid_or_conflicting_capacities_are_refused_without_the_url() {
        for value in ["-1", "+5", "1e3", "abc", "4096", "18446744073709551616"] {
            assert_eq!(capacity(Some(value), None), Err(INVALID), "{value}");
        }
        let secret = "mysql://user:secret@db/app?statement-cache-capacity=";
        for value in ["", "x", "4096"] {
            let error = capacity(None, Some(&format!("{secret}{value}"))).unwrap_err();
            assert_eq!(error, INVALID_URL);
            assert!(!error.contains("secret"));
        }
        assert_eq!(capacity(Some("300"), Some("mysql://db/app?statement-cache-capacity=200")), Err(CONFLICT));
    }
}
