//! Database TLS settings, resolved as published PHP Server resolves them
//! (`DatabaseTlsConfiguration` since 2.9.10), so one configuration means the
//! same thing to both servers: the same inputs, aliases, mode names and
//! refusals. The resolved settings then replace any TLS parameters in
//! `DB_URL`, so a URL spelling that only SQLx reads cannot change them.
use serde_json::{Value, json};
use std::sync::OnceLock;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Driver {
    Postgres,
    /// MySQL and MariaDB.
    MySql,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Mode {
    Disable,
    Allow,
    Prefer,
    Require,
    VerifyCa,
    VerifyFull,
}

impl Mode {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Disable => "disable",
            Self::Allow => "allow",
            Self::Prefer => "prefer",
            Self::Require => "require",
            Self::VerifyCa => "verify-ca",
            Self::VerifyFull => "verify-full",
        }
    }

    fn required(self) -> bool {
        matches!(self, Self::Require | Self::VerifyCa | Self::VerifyFull)
    }
}

/// The resolved TLS mode and CA bundle for one connection.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Settings {
    pub driver: Driver,
    pub mode: Option<Mode>,
    pub root_cert: Option<String>,
}

#[derive(Clone, Debug, PartialEq, Eq, thiserror::Error)]
#[error("{reason}: {message}")]
pub struct Refused {
    pub reason: &'static str,
    pub message: &'static str,
}

const INVALID: &str = "database_tls_configuration_invalid";
pub const CONNECTION_FAILED: Refused = Refused {
    reason: "database_tls_connection_failed",
    message: "The required database TLS connection could not be established. Check the endpoint, CA bundle, certificate hostname and credentials.",
};

fn invalid(message: &'static str) -> Refused {
    Refused {
        reason: INVALID,
        message,
    }
}

/// The TLS keys PHP reads from a database URL's query string. SQLx also reads
/// other spellings, which PHP ignores, so they are removed before parsing.
const URL_TLS_KEYS: [&str; 6] = [
    "sslmode",
    "ssl-mode",
    "sslrootcert",
    "ssl-root-cert",
    "sslca",
    "ssl-ca",
];

/// A database URL split into the parts the TLS rules read.
#[derive(Debug, Default, PartialEq, Eq)]
pub struct UrlParts {
    pub without_tls: String,
    pub sslmode: Option<String>,
    pub sslrootcert: Option<String>,
    pub host: Option<String>,
    pub socket: Option<String>,
}

fn decode(text: &str) -> String {
    form_urlencoded::parse(format!("x={text}").as_bytes())
        .next()
        .map(|(_, value)| value.into_owned())
        .unwrap_or_default()
}

/// Splits a database URL into its TLS inputs and the URL without them.
pub fn url_parts(url: &str) -> UrlParts {
    let (base, query) = url.split_once('?').unwrap_or((url, ""));
    let mut parts = UrlParts::default();
    let mut kept = form_urlencoded::Serializer::new(String::new());
    let mut any = false;
    for (key, value) in form_urlencoded::parse(query.as_bytes()) {
        match key.as_ref() {
            "sslmode" => parts.sslmode = Some(value.into_owned()),
            "sslrootcert" => parts.sslrootcert = Some(value.into_owned()),
            key if URL_TLS_KEYS.contains(&key) => {}
            key => {
                if key == "host" {
                    parts.host = Some(value.to_string());
                }
                if key == "socket" || key == "unix_socket" {
                    parts.socket = Some(value.to_string());
                }
                kept.append_pair(key, &value);
                any = true;
            }
        }
    }
    if parts.host.is_none() {
        let authority = base.split_once("://").map_or("", |(_, rest)| rest);
        let authority = authority.split('/').next().unwrap_or_default();
        let host = authority
            .rsplit_once('@')
            .map_or(authority, |(_, host)| host);
        let host = if let Some(bracketed) = host.strip_prefix('[') {
            bracketed.split(']').next().unwrap_or_default()
        } else {
            host.split(':').next().unwrap_or_default()
        };
        // Laravel keeps DB_HOST when the URL names no host.
        parts.host = Some(decode(host)).filter(|host| !host.is_empty());
    }
    parts.without_tls = if any {
        format!("{base}?{}", kept.finish())
    } else {
        base.to_owned()
    };
    parts
}

/// Laravel's `env()`: an unset variable and the literal `null` are absent;
/// `empty` is an empty string.
fn laravel_env(value: Option<String>) -> Option<String> {
    match value?.as_str() {
        text if text.eq_ignore_ascii_case("null") || text.eq_ignore_ascii_case("(null)") => None,
        text if text.eq_ignore_ascii_case("empty") || text.eq_ignore_ascii_case("(empty)") => {
            Some(String::new())
        }
        text => Some(text.to_owned()),
    }
}

fn mode_name(value: Option<&str>) -> Option<String> {
    let value = value.filter(|value| !value.is_empty())?;
    let value = value.trim().to_ascii_lowercase();
    Some(
        match value.as_str() {
            "disabled" => "disable",
            "preferred" => "prefer",
            "required" => "require",
            "verify_ca" => "verify-ca",
            "verify_identity" | "verify-identity" => "verify-full",
            other => other,
        }
        .to_owned(),
    )
}

fn certificate(
    configured: Option<&str>,
    alias: Option<&str>,
    requested: Option<&str>,
) -> Result<Option<String>, Refused> {
    let mut certificate = configured;
    if let (Some(alias), Some(current)) = (alias.filter(|alias| !alias.is_empty()), certificate)
        && current != alias
    {
        return Err(invalid(
            "DB_SSLROOTCERT and DB_SSL_ROOT_CERT name different CA bundles.",
        ));
    }
    certificate = certificate.or(alias);
    if let (Some(requested), Some(current)) = (
        requested.filter(|requested| !requested.is_empty()),
        certificate,
    ) && current != requested
    {
        return Err(invalid("The database URL conflicts with DB_SSLROOTCERT."));
    }
    let Some(certificate) = certificate.filter(|certificate| !certificate.is_empty()) else {
        return Ok(None);
    };
    let readable = !certificate.contains([';', '\r', '\n', '\0'])
        && std::fs::metadata(certificate).is_ok_and(|metadata| metadata.is_file())
        && std::fs::File::open(certificate).is_ok();
    if !readable {
        return Err(invalid(
            "The configured database CA bundle must be a readable regular file.",
        ));
    }
    Ok(Some(certificate.to_owned()))
}

fn require_tcp(driver: Driver, host: Option<&str>, socket: Option<&str>) -> Result<(), Refused> {
    let refused =
        || invalid("Required database TLS needs an explicit TCP host, not a Unix socket.");
    let host = host.unwrap_or_default();
    if host.trim().is_empty() || socket.is_some_and(|socket| !socket.is_empty()) {
        return Err(refused());
    }
    for endpoint in host.split(',').map(str::trim) {
        if endpoint.starts_with('/')
            || endpoint.starts_with('@')
            || driver == Driver::MySql && endpoint.eq_ignore_ascii_case("localhost")
        {
            return Err(refused());
        }
    }
    Ok(())
}

/// The inputs PHP's `config/database.php` gathers for one connection.
struct Inputs {
    mode: Option<String>,
    mode_alias: Option<String>,
    mode_requested: Option<String>,
    cert: Option<String>,
    cert_alias: Option<String>,
    cert_requested: Option<String>,
    host: Option<String>,
    socket: Option<String>,
    legacy_ca: Option<String>,
}

fn inputs(driver: Driver, env: &impl Fn(&str) -> Option<String>, url: Option<&UrlParts>) -> Inputs {
    let mode_requested = env("DB_SSLMODE");
    let mode_alias = env("DB_SSL_MODE");
    let mut mode = mode_requested.clone().or_else(|| mode_alias.clone());
    if driver == Driver::Postgres && mode.is_none() {
        mode = Some("prefer".into());
    }
    let cert_requested = env("DB_SSLROOTCERT");
    let cert_alias = env("DB_SSL_ROOT_CERT");
    let mysql = driver == Driver::MySql;
    let mut inputs = Inputs {
        mode,
        mode_alias,
        mode_requested,
        cert: cert_requested.clone().or_else(|| cert_alias.clone()),
        cert_alias,
        cert_requested,
        host: env("DB_HOST").or_else(|| Some("127.0.0.1".into())),
        socket: mysql.then(|| env("DB_SOCKET")).flatten(),
        legacy_ca: mysql
            .then(|| env("MYSQL_ATTR_SSL_CA"))
            .flatten()
            .filter(|legacy| !legacy.is_empty()),
    };
    // A URL's values replace the configured ones, as Laravel merges them.
    if let Some(url) = url {
        inputs.mode = url.sslmode.clone().or(inputs.mode);
        inputs.cert = url.sslrootcert.clone().or(inputs.cert);
        inputs.host = url.host.clone().or(inputs.host);
        inputs.socket = url.socket.clone().or(inputs.socket);
    }
    inputs
}

/// Resolves the TLS settings for `driver` from the environment, as PHP's
/// `config/database.php` and `DatabaseTlsConfiguration::configure` do.
pub fn resolve(
    driver: Driver,
    env: impl Fn(&str) -> Option<String>,
) -> Result<(Settings, Option<UrlParts>), Refused> {
    let env = |key: &str| laravel_env(env(key));
    let url = env("DB_URL")
        .filter(|url| !url.is_empty())
        .map(|url| url_parts(&url));
    let settings = configure(driver, &inputs(driver, &env, url.as_ref()))?;
    Ok((settings, url))
}

/// `DatabaseTlsConfiguration::configure`.
fn configure(driver: Driver, inputs: &Inputs) -> Result<Settings, Refused> {
    let mode = mode_name(inputs.mode.as_deref());
    let alias = mode_name(inputs.mode_alias.as_deref());
    let requested = mode_name(inputs.mode_requested.as_deref());
    if alias.is_some() && mode.is_some() && alias != mode {
        return Err(invalid(
            "DB_SSLMODE and DB_SSL_MODE request different TLS modes.",
        ));
    }
    if requested.is_some() && mode.is_some() && requested != mode {
        return Err(invalid("The database URL conflicts with DB_SSLMODE."));
    }
    let root_cert = certificate(
        inputs.cert.as_deref(),
        inputs.cert_alias.as_deref(),
        inputs.cert_requested.as_deref(),
    )?;
    match mode.or(alias) {
        Some(mode) => apply_mode(driver, &mode, root_cert, inputs),
        None if root_cert.is_some() => {
            Err(invalid("Set DB_SSLMODE=verify-full with DB_SSLROOTCERT."))
        }
        None => Ok(Settings {
            driver,
            mode: None,
            root_cert,
        }),
    }
}

fn parse_mode(driver: Driver, mode: &str) -> Result<Mode, Refused> {
    Ok(match (driver, mode) {
        (_, "disable") => Mode::Disable,
        (Driver::Postgres, "allow") => Mode::Allow,
        (Driver::Postgres, "prefer") => Mode::Prefer,
        (_, "require") => Mode::Require,
        (Driver::Postgres, "verify-ca") => Mode::VerifyCa,
        (_, "verify-full") => Mode::VerifyFull,
        _ => {
            return Err(invalid(
                "The selected database driver does not support the requested TLS mode. MySQL/MariaDB support disable, require and verify-full.",
            ));
        }
    })
}

/// `DatabaseTlsConfiguration::applyMode`.
fn apply_mode(
    driver: Driver,
    mode: &str,
    mut root_cert: Option<String>,
    inputs: &Inputs,
) -> Result<Settings, Refused> {
    let mode = parse_mode(driver, mode)?;
    if mode.required() {
        require_tcp(driver, inputs.host.as_deref(), inputs.socket.as_deref())?;
    }
    if root_cert.is_none() && driver == Driver::MySql && mode == Mode::VerifyFull {
        root_cert = certificate(inputs.legacy_ca.as_deref(), None, None)?;
    }
    if matches!(mode, Mode::VerifyCa | Mode::VerifyFull) && root_cert.is_none() {
        return Err(invalid(
            "Certificate verification requires a readable CA bundle in DB_SSLROOTCERT.",
        ));
    }
    if driver == Driver::MySql {
        mysql_options(mode, root_cert.as_deref(), inputs.legacy_ca.as_deref())?;
    }
    Ok(Settings {
        driver,
        mode: Some(mode),
        root_cert,
    })
}

/// `DatabaseTlsConfiguration::mysqlOptions`: the legacy PDO CA option must
/// agree, and disabled TLS takes no TLS option.
fn mysql_options(mode: Mode, root_cert: Option<&str>, legacy: Option<&str>) -> Result<(), Refused> {
    if root_cert.is_some() && legacy.is_some() && root_cert != legacy {
        return Err(invalid(
            "DB_SSLROOTCERT and MYSQL_ATTR_SSL_CA name different CA bundles.",
        ));
    }
    if mode == Mode::Disable && (root_cert.is_some() || legacy.is_some()) {
        return Err(invalid(
            "Disabled database TLS conflicts with configured TLS connection options.",
        ));
    }
    Ok(())
}

impl Settings {
    pub fn required(&self) -> bool {
        self.mode.is_some_and(Mode::required)
    }

    pub fn postgres_mode(&self) -> sqlx::postgres::PgSslMode {
        use sqlx::postgres::PgSslMode;
        match self.mode {
            Some(Mode::Disable) => PgSslMode::Disable,
            Some(Mode::Allow) => PgSslMode::Allow,
            // libpq's default when no mode is configured.
            Some(Mode::Prefer) | None => PgSslMode::Prefer,
            Some(Mode::Require) => PgSslMode::Require,
            Some(Mode::VerifyCa) => PgSslMode::VerifyCa,
            Some(Mode::VerifyFull) => PgSslMode::VerifyFull,
        }
    }

    pub fn mysql_mode(&self) -> sqlx::mysql::MySqlSslMode {
        use sqlx::mysql::MySqlSslMode;
        match self.mode {
            Some(Mode::Disable) => MySqlSslMode::Disabled,
            Some(Mode::Require) => MySqlSslMode::Required,
            Some(Mode::VerifyFull) => MySqlSslMode::VerifyIdentity,
            // Without a configured mode, use TLS when the server offers it.
            _ => MySqlSslMode::Preferred,
        }
    }

    /// The readiness snapshot of what the configuration guarantees, in PHP's
    /// shape, for native's own connection.
    pub fn snapshot(&self) -> Value {
        snapshot(self.mode, true)
    }
}

fn snapshot(mode: Option<Mode>, network: bool) -> Value {
    let required = mode.is_some_and(Mode::required);
    let encrypted = if required {
        Some(true)
    } else if mode == Some(Mode::Disable) {
        Some(false)
    } else {
        None
    };
    json!({
        "mode": if network { mode.map(Mode::as_str) } else { None },
        "encrypted": encrypted,
        "ca_verified": required.then(|| matches!(mode, Some(Mode::VerifyCa | Mode::VerifyFull))),
        "hostname_verified": required.then_some(mode == Some(Mode::VerifyFull)),
        "evidence": if required { "successful_driver_enforced_connection" } else { "not_verified" },
        "scope": "rust_to_configured_database_endpoint",
    })
}

static CURRENT: OnceLock<Value> = OnceLock::new();

/// Records the serving connection's TLS snapshot for readiness.
pub fn record(settings: &Settings) {
    let _ = CURRENT.set(settings.snapshot());
}

/// The serving connection's TLS snapshot. SQLite has no network connection.
pub fn current() -> Value {
    CURRENT
        .get()
        .cloned()
        .unwrap_or_else(|| snapshot(None, false))
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::HashMap;

    fn resolved(driver: Driver, pairs: &[(&str, &str)]) -> Result<Settings, Refused> {
        let env: HashMap<String, String> = pairs
            .iter()
            .map(|(key, value)| ((*key).to_owned(), (*value).to_owned()))
            .collect();
        resolve(driver, |key| env.get(key).cloned()).map(|(settings, _)| settings)
    }

    fn message(driver: Driver, pairs: &[(&str, &str)]) -> &'static str {
        let refused = resolved(driver, pairs).unwrap_err();
        assert_eq!(refused.reason, INVALID);
        refused.message
    }

    #[test]
    fn inputs_reach_the_selected_connection_as_php_resolves_them() {
        let ca = tempfile::NamedTempFile::new().unwrap();
        let ca = ca.path().to_str().unwrap();
        let host = ("DB_HOST", "database.test");
        let postgres = resolved(
            Driver::Postgres,
            &[host, ("DB_SSLMODE", "verify-full"), ("DB_SSLROOTCERT", ca)],
        )
        .unwrap();
        assert_eq!(
            (postgres.mode, postgres.root_cert.as_deref()),
            (Some(Mode::VerifyFull), Some(ca))
        );
        // The Helm chart's existing input and MySQL's mode names.
        assert_eq!(
            resolved(Driver::Postgres, &[host, ("DB_SSL_MODE", "require")])
                .unwrap()
                .mode,
            Some(Mode::Require)
        );
        assert_eq!(
            resolved(Driver::MySql, &[host, ("DB_SSLMODE", "REQUIRED")])
                .unwrap()
                .mode,
            Some(Mode::Require)
        );
        assert_eq!(
            resolved(
                Driver::MySql,
                &[
                    host,
                    ("DB_SSLMODE", "verify_identity"),
                    ("DB_SSL_ROOT_CERT", ca)
                ]
            )
            .unwrap()
            .mode,
            Some(Mode::VerifyFull)
        );
        // MySQL verification may name its CA through the legacy PDO option.
        assert_eq!(
            resolved(
                Driver::MySql,
                &[
                    host,
                    ("DB_SSLMODE", "verify-full"),
                    ("MYSQL_ATTR_SSL_CA", ca)
                ]
            )
            .unwrap()
            .root_cert
            .as_deref(),
            Some(ca)
        );
        // PostgreSQL defaults to prefer; MySQL has no configured mode.
        assert_eq!(
            resolved(Driver::Postgres, &[]).unwrap().mode,
            Some(Mode::Prefer)
        );
        assert_eq!(resolved(Driver::MySql, &[]).unwrap().mode, None);
        // An explicitly empty mode is no mode, as Laravel reads it.
        assert_eq!(
            resolved(
                Driver::Postgres,
                &[("DB_SSLMODE", ""), ("DB_SSLROOTCERT", "")]
            )
            .unwrap()
            .mode,
            None
        );
        // A URL's mode applies when DB_SSLMODE agrees or is absent.
        let url = resolved(
            Driver::Postgres,
            &[(
                "DB_URL",
                "postgres://u:p@db.test:5432/app?sslmode=require&application_name=dw",
            )],
        )
        .unwrap();
        assert_eq!(url.mode, Some(Mode::Require));
    }

    #[test]
    fn invalid_configuration_is_refused_before_a_connection() {
        let host = ("DB_HOST", "database.test");
        let pg = Driver::Postgres;
        assert_eq!(
            message(pg, &[host, ("DB_SSLMODE", "silently-insecure")]),
            "The selected database driver does not support the requested TLS mode. MySQL/MariaDB support disable, require and verify-full."
        );
        assert_eq!(
            message(
                pg,
                &[
                    host,
                    ("DB_SSLMODE", "verify-full"),
                    ("DB_SSL_MODE", "disable")
                ]
            ),
            "DB_SSLMODE and DB_SSL_MODE request different TLS modes."
        );
        assert_eq!(
            message(
                pg,
                &[
                    ("DB_SSLMODE", "verify-full"),
                    ("DB_URL", "postgres://db.test/app?sslmode=disable")
                ]
            ),
            "The database URL conflicts with DB_SSLMODE."
        );
        assert_eq!(
            message(
                pg,
                &[
                    ("DB_SSLMODE", "verify-full"),
                    ("DB_SSLROOTCERT", "/original.pem"),
                    ("DB_URL", "postgres://db.test/app?sslrootcert=/wrong.pem")
                ]
            ),
            "The database URL conflicts with DB_SSLROOTCERT."
        );
        assert_eq!(
            message(pg, &[host, ("DB_SSLMODE", "verify-full")]),
            "Certificate verification requires a readable CA bundle in DB_SSLROOTCERT."
        );
        assert_eq!(
            message(
                pg,
                &[
                    host,
                    ("DB_SSLMODE", "verify-full"),
                    ("DB_SSLROOTCERT", "/not-a-real-database-ca.pem")
                ]
            ),
            "The configured database CA bundle must be a readable regular file."
        );
        let socket = "Required database TLS needs an explicit TCP host, not a Unix socket.";
        assert_eq!(
            message(
                pg,
                &[
                    ("DB_HOST", "/var/run/postgresql"),
                    ("DB_SSLMODE", "require")
                ]
            ),
            socket
        );
        assert_eq!(
            message(pg, &[("DB_HOST", "@postgres"), ("DB_SSLMODE", "require")]),
            socket
        );
        assert_eq!(
            message(
                Driver::MySql,
                &[("DB_HOST", "localhost"), ("DB_SSLMODE", "require")]
            ),
            socket
        );
        assert_eq!(
            message(
                Driver::MySql,
                &[
                    host,
                    ("DB_SOCKET", "/tmp/mysql.sock"),
                    ("DB_SSLMODE", "require")
                ]
            ),
            socket
        );
        assert_eq!(
            message(Driver::MySql, &[host, ("DB_SSLMODE", "verify-ca")]),
            "The selected database driver does not support the requested TLS mode. MySQL/MariaDB support disable, require and verify-full."
        );
        assert_eq!(
            message(Driver::MySql, &[host, ("DB_SSLMODE", "preferred")]),
            "The selected database driver does not support the requested TLS mode. MySQL/MariaDB support disable, require and verify-full."
        );
        let ca = tempfile::NamedTempFile::new().unwrap();
        let ca = ca.path().to_str().unwrap();
        // A CA bundle needs a mode. PostgreSQL's default prefer is one, unless
        // the mode is set empty.
        assert_eq!(
            message(Driver::MySql, &[host, ("DB_SSLROOTCERT", ca)]),
            "Set DB_SSLMODE=verify-full with DB_SSLROOTCERT."
        );
        assert_eq!(
            message(pg, &[host, ("DB_SSLMODE", ""), ("DB_SSLROOTCERT", ca)]),
            "Set DB_SSLMODE=verify-full with DB_SSLROOTCERT."
        );
        assert_eq!(
            resolved(pg, &[host, ("DB_SSLROOTCERT", ca)]).unwrap().mode,
            Some(Mode::Prefer)
        );
        assert_eq!(
            message(
                pg,
                &[
                    host,
                    ("DB_SSLMODE", "verify-full"),
                    ("DB_SSLROOTCERT", ca),
                    ("DB_SSL_ROOT_CERT", "/other.pem")
                ]
            ),
            "DB_SSLROOTCERT and DB_SSL_ROOT_CERT name different CA bundles."
        );
        assert_eq!(
            message(
                Driver::MySql,
                &[host, ("DB_SSLMODE", "disable"), ("MYSQL_ATTR_SSL_CA", ca)]
            ),
            "Disabled database TLS conflicts with configured TLS connection options."
        );
        let other = tempfile::NamedTempFile::new().unwrap();
        assert_eq!(
            message(
                Driver::MySql,
                &[
                    host,
                    ("DB_SSLMODE", "verify-full"),
                    ("DB_SSLROOTCERT", ca),
                    ("MYSQL_ATTR_SSL_CA", other.path().to_str().unwrap())
                ]
            ),
            "DB_SSLROOTCERT and MYSQL_ATTR_SSL_CA name different CA bundles."
        );
    }

    #[test]
    fn url_tls_parameters_are_replaced_by_the_resolved_settings() {
        let parts = url_parts(
            "mysql://user:p%40ss@[::1]:3306/app?ssl-mode=DISABLED&sslmode=require&charset=utf8mb4&sslca=/x.pem",
        );
        assert_eq!(parts.sslmode.as_deref(), Some("require"));
        assert_eq!(parts.sslrootcert, None);
        assert_eq!(parts.host.as_deref(), Some("::1"));
        assert_eq!(
            parts.without_tls,
            "mysql://user:p%40ss@[::1]:3306/app?charset=utf8mb4"
        );
        let socket = url_parts("postgres://user@localhost/app?host=%2Fvar%2Frun%2Fpostgresql");
        assert_eq!(socket.host.as_deref(), Some("/var/run/postgresql"));
        assert_eq!(
            url_parts("postgres://db.test/app").without_tls,
            "postgres://db.test/app"
        );
    }

    #[test]
    fn snapshots_report_what_the_mode_guarantees() {
        let settings = |mode| Settings {
            driver: Driver::Postgres,
            mode,
            root_cert: None,
        };
        assert_eq!(
            settings(Some(Mode::VerifyFull)).snapshot(),
            json!({"mode":"verify-full","encrypted":true,"ca_verified":true,
            "hostname_verified":true,"evidence":"successful_driver_enforced_connection","scope":"rust_to_configured_database_endpoint"})
        );
        assert_eq!(
            settings(Some(Mode::Require)).snapshot()["ca_verified"],
            false
        );
        assert_eq!(
            settings(Some(Mode::Disable)).snapshot(),
            json!({"mode":"disable","encrypted":false,"ca_verified":null,
            "hostname_verified":null,"evidence":"not_verified","scope":"rust_to_configured_database_endpoint"})
        );
        assert_eq!(
            settings(Some(Mode::Prefer)).snapshot()["encrypted"],
            Value::Null
        );
        assert_eq!(current()["mode"], Value::Null);
    }
}
