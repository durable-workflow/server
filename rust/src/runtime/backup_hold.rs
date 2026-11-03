//! The online-backup hold on external payload reclamation (Server #949), as
//! published Server 2.11's `ExternalPayloadBackupHold` keeps it in
//! `runtime_external_payload_backup_hold` row `id=1`.
//!
//! A backup acquires the hold with a unique UUID, renews it while it copies
//! the database and payload storage, and releases it. While the hold is
//! active no reclamation deletes a provider object. An owner is single-use:
//! once its hold has expired or been released it cannot be renewed or
//! acquired again, so the backup discards that candidate and starts over.
//! Every decision uses the database's clock, so processes on different hosts
//! agree on expiry.
//!
//! A reclamation that deletes an object locks the hold row after the
//! object's lock and keeps it through the provider delete
//! ([`Store::locked_backup_hold`]), as PHP's `deleting()` does. `acquire`
//! takes only the hold lock, so it waits for a deletion already under way
//! and never sees a reference whose object is about to go.
use super::Result;
use super::laravel_validation::{Rule, failure_messages, rule_errors};
use super::transaction::Transaction;
use axum::http::StatusCode;
use chrono::{DateTime, Duration, Timelike, Utc};
use serde_json::{Value, json};

/// `ExternalPayloadBackupHold::MAX_TTL_SECONDS`.
const MAX_TTL_SECONDS: i64 = 3600;
/// `ExternalPayloadBackupHold::MAX_DURATION_SECONDS`: a hold never outlives
/// a day from its acquisition, however often it is renewed.
const MAX_DURATION_SECONDS: i64 = 86_400;
/// The command's default `--ttl`.
const DEFAULT_TTL_SECONDS: i64 = 900;
const SCHEMA: &str = "durable-workflow.external-payload-backup-hold.v1";

/// What a request asks of the hold.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum Action {
    Acquire,
    Renew,
    Release,
}

/// A validated request: the backup's owner UUID and, except for release,
/// the lease lifetime in seconds.
#[derive(Debug, PartialEq, Eq)]
pub(crate) struct Request {
    owner: String,
    ttl: i64,
}

/// `Str::isUuid`: 8-4-4-4-12 hexadecimal digits, in either case.
fn is_uuid(value: &str) -> bool {
    let groups: Vec<&str> = value.split('-').collect();
    groups.len() == 5
        && groups.iter().zip([8, 4, 4, 4, 12]).all(|(group, length)| {
            group.len() == length && group.bytes().all(|byte| byte.is_ascii_hexdigit())
        })
}

fn message(field: &str, rule: Rule) -> Option<&'static str> {
    match (field, rule) {
        ("owner", Rule::Pattern(_)) => Some("The owner field must be a valid UUID."),
        _ => None,
    }
}

/// `owner` is a required UUID; `ttl` is an integer from 1 to 3600 seconds,
/// by default 900, and is ignored on release.
pub(crate) fn request(body: &Value, action: Action) -> Result<Request> {
    let body = &super::start_request::normalized(body.clone());
    let owner: &[Rule] = &[Rule::Required, Rule::String, Rule::Pattern(is_uuid)];
    let ttl: &[Rule] = &[Rule::Nullable, Rule::Integer, Rule::Min(1), Rule::Max(MAX_TTL_SECONDS)];
    let rules: Vec<(&str, &[Rule])> = if action == Action::Release {
        vec![("owner", owner)]
    } else {
        vec![("owner", owner), ("ttl", ttl)]
    };
    if let Some(error) = failure_messages(rule_errors(body, &rules, message)) {
        return Err(error);
    }
    Ok(Request {
        owner: body["owner"].as_str().unwrap_or_default().to_owned(),
        ttl: if action == Action::Release {
            DEFAULT_TTL_SECONDS
        } else {
            super::laravel_validation::integer(&body["ttl"]).unwrap_or(DEFAULT_TTL_SECONDS)
        },
    })
}

/// A scoped status names the owner it expects to hold; an unscoped status
/// reports whoever holds it.
pub(crate) fn status_owner(owner: Option<&str>) -> Result<Option<String>> {
    match owner {
        None => Ok(None),
        Some(owner) => request(&json!({ "owner": owner }), Action::Release).map(|request| Some(request.owner)),
    }
}

/// Row `id=1` as it was read under the lock, with the database's clock.
#[derive(Clone, Debug, PartialEq, Eq)]
pub(super) struct Hold {
    owner: Option<String>,
    acquired_at: Option<DateTime<Utc>>,
    expires_at: Option<DateTime<Utc>>,
    now: DateTime<Utc>,
}

impl Hold {
    /// An owner whose hold has not expired pauses reclamation.
    pub(super) fn active(&self) -> bool {
        self.owner.is_some() && self.expires_at.is_some_and(|expires| expires > self.now)
    }

    /// PostgreSQL returns the uuid in lower case; MariaDB may keep the case it
    /// was given.
    fn owned_by(&self, owner: &str) -> bool {
        self.owner.as_deref().is_some_and(|held| held.eq_ignore_ascii_case(owner))
    }

    /// `assertOwner`: the backup still holds an unexpired hold.
    fn assert_owner(&self, owner: &str) -> Result<()> {
        if self.owned_by(owner) && self.active() {
            Ok(())
        } else {
            Err(not_owned())
        }
    }

    fn report(&self) -> Value {
        json!({
            "schema": SCHEMA,
            "active": self.active(),
            "owner": self.owner,
            "acquired_at": self.acquired_at.map(sql_time),
            "expires_at": self.expires_at.map(sql_time),
            "database_time": sql_time(self.now),
        })
    }

    /// Carry out `action`, returning the row to save, if it changes.
    fn apply(&self, action: Action, request: &Request) -> Result<Option<Self>> {
        let now = whole_second(self.now);
        let mut next = self.clone();
        match action {
            Action::Acquire => {
                if self.owned_by(&request.owner) {
                    self.assert_owner(&request.owner)?;
                    return Ok(None);
                }
                if self.active() {
                    return Err(super::refuse(StatusCode::CONFLICT, "backup_hold_owned_elsewhere"));
                }
                next.owner = Some(request.owner.clone());
                next.acquired_at = Some(now);
                next.expires_at = Some(now + Duration::seconds(request.ttl));
            }
            Action::Renew => {
                self.assert_owner(&request.owner)?;
                let acquired = self.acquired_at.unwrap_or(now);
                let deadline = acquired + Duration::seconds(MAX_DURATION_SECONDS);
                next.expires_at = Some((now + Duration::seconds(request.ttl)).min(deadline));
            }
            Action::Release => {
                // PHP releases a hold its owner names even after it expired.
                if !self.owned_by(&request.owner) {
                    return Err(not_owned());
                }
                next.expires_at = None;
            }
        }
        Ok(Some(next))
    }
}

/// The backup's hold expired, was released, or belongs to another backup.
fn not_owned() -> super::RuntimeError {
    super::refuse(StatusCode::CONFLICT, "backup_hold_not_owned")
}

/// Laravel stores the hold's times as whole seconds.
fn whole_second(at: DateTime<Utc>) -> DateTime<Utc> {
    at.with_nanosecond(0).unwrap_or(at)
}

/// Carbon's `toDateTimeString()`.
fn sql_time(at: DateTime<Utc>) -> String {
    at.format("%Y-%m-%d %H:%M:%S").to_string()
}

/// One offline `backup-hold` invocation, as PHP's
/// `external-payloads:backup-hold {action} --owner= --ttl=900` takes it
/// (Server #973): a restore releases the restored hold before any Server
/// process starts.
#[derive(Debug, PartialEq, Eq)]
pub struct Command {
    /// `None` is `status`.
    action: Option<Action>,
    owner: Option<String>,
    ttl: i64,
}

/// Parse the arguments after `backup-hold`: an action (`acquire`, `renew`,
/// `release` or `status`), then `--owner` and `--ttl`, each as `--name=value`
/// or `--name value` and at most once. The TTL must be an integer whatever
/// the action, as PHP's command checks it first.
pub fn command(args: &[String]) -> Result<Command> {
    let invalid = || super::refuse(StatusCode::BAD_REQUEST, "backup_hold_command_invalid");
    let mut args = args.iter();
    let action = match args.next().map(String::as_str) {
        Some("acquire") => Some(Action::Acquire),
        Some("renew") => Some(Action::Renew),
        Some("release") => Some(Action::Release),
        Some("status") => None,
        _ => return Err(super::refuse(StatusCode::BAD_REQUEST, "backup_hold_action_invalid")),
    };
    let (mut owner, mut ttl) = (None, None);
    while let Some(arg) = args.next() {
        let (name, value) = match arg.split_once('=') {
            Some((name, value)) => (name, value.to_owned()),
            None => (arg.as_str(), args.next().ok_or_else(invalid)?.clone()),
        };
        let slot = match name {
            "--owner" => &mut owner,
            "--ttl" => &mut ttl,
            _ => return Err(invalid()),
        };
        if slot.replace(value).is_some() {
            return Err(invalid());
        }
    }
    let ttl = match ttl {
        None => DEFAULT_TTL_SECONDS,
        Some(text) => super::laravel_validation::integer(&json!(text))
            .ok_or_else(|| super::refuse(StatusCode::BAD_REQUEST, "backup_hold_ttl_invalid"))?,
    };
    Ok(Command { action, owner, ttl })
}

/// `backup-hold` against a native SQLite database that already exists.
pub async fn console_sqlite(database: &str, command: &Command) -> Result<Value> {
    let store = super::store::Store::new(super::sqlite::open_existing(database).await?);
    store.run_backup_hold_command(command).await
}

/// `backup-hold` against an initialized native PostgreSQL database.
pub async fn console_postgres(options: sqlx::postgres::PgConnectOptions, command: &Command) -> Result<Value> {
    let store = super::store::Store::new(super::postgres::PostgresStorage::open_existing(options).await?);
    store.run_backup_hold_command(command).await
}

/// `backup-hold` against an initialized native MySQL or MariaDB database.
pub async fn console_mysql(options: sqlx::mysql::MySqlConnectOptions, command: &Command) -> Result<Value> {
    let store = super::store::Store::new(super::mysql::MySqlStorage::open_existing(options).await?);
    store.run_backup_hold_command(command).await
}

/// The line the command prints on failure: the refusal reason, or the
/// validation messages of an invalid owner or TTL.
pub fn console_failure(error: &super::RuntimeError) -> String {
    match error {
        super::RuntimeError::Refused { reason, .. } => (*reason).to_owned(),
        super::RuntimeError::Protocol { response, .. } => response["message"]
            .as_str()
            .unwrap_or("backup_hold_request_invalid")
            .to_owned(),
        _ => "storage_unavailable".to_owned(),
    }
}

store_methods! {
    /// The hold row and the database's clock, without a lock: the preflight
    /// PHP's `assertReclamationAllowed` makes before a prune stages its
    /// deletions. A missing row is no hold.
    pub(super) async fn read_backup_hold(tx: &mut Transaction<'_, DB>) -> Result<Option<Hold>> {
        let Some(row) = Self::query(DB::BACKUP_HOLD_ROW).fetch_optional(&mut **tx).await? else {
            return Ok(None);
        };
        let now = Self::query(DB::BACKUP_HOLD_CLOCK).fetch_one(&mut **tx).await?;
        Ok(Some(Hold {
            owner: DB::optional_string(&row, "owner")?,
            acquired_at: DB::optional_instant(&row, "acquired_at")?,
            expires_at: DB::optional_instant(&row, "expires_at")?,
            now: DB::instant(&now, "database_time")?,
        }))
    }

    /// The hold row under its lock, held until the transaction ends. Native
    /// schemas don't seed the row, so it is created first if missing: without
    /// a row there would be nothing for a deletion and an acquisition to
    /// serialize on.
    pub(super) async fn locked_backup_hold(tx: &mut Transaction<'_, DB>) -> Result<Hold> {
        if Self::scalar("SELECT COUNT(*) FROM runtime_external_payload_backup_hold WHERE id=1")
            .fetch_one(&mut **tx)
            .await?
            == 0
        {
            Self::query("INSERT INTO runtime_external_payload_backup_hold(id) VALUES (1)")
                .execute(&mut **tx)
                .await?;
        }
        // A write takes the row lock on every backend, and SQLite's writer
        // lock before the read, as PHP does for SQLite.
        Self::query("UPDATE runtime_external_payload_backup_hold SET id=id WHERE id=1")
            .execute(&mut **tx)
            .await?;
        Self::read_backup_hold(tx)
            .await?
            .ok_or_else(|| super::refuse(StatusCode::SERVICE_UNAVAILABLE, "storage_unavailable"))
    }

    /// `acquire`, `renew` or `release`, answering PHP's report.
    pub(crate) async fn change_backup_hold(&self, action: Action, request: &Request) -> Result<Value> {
        let mut tx = self.begin().await?;
        let hold = Self::locked_backup_hold(&mut tx).await?;
        let Some(next) = hold.apply(action, request)? else {
            tx.commit().await?;
            return Ok(hold.report());
        };
        Self::query(DB::BACKUP_HOLD_SAVE)
            .bind(next.owner.as_deref())
            .bind(DB::optional_time(next.acquired_at))
            .bind(DB::optional_time(next.expires_at))
            .execute(&mut *tx)
            .await?;
        tx.commit().await?;
        Ok(next.report())
    }

    /// The offline command's report, from the same methods #953's endpoints
    /// call. The pool closes whatever the outcome.
    async fn run_backup_hold_command(&self, command: &Command) -> Result<Value> {
        let result = match command.action {
            None => match status_owner(command.owner.as_deref()) {
                Ok(owner) => self.backup_hold_status(owner.as_deref()).await,
                Err(error) => Err(error),
            },
            Some(action) => match request(&json!({ "owner": command.owner, "ttl": command.ttl }), action) {
                Ok(request) => self.change_backup_hold(action, &request).await,
                Err(error) => Err(error),
            },
        };
        self.close().await;
        result
    }

    /// `status`: the hold as it stands, refused when a scoped owner no longer
    /// holds it.
    pub(crate) async fn backup_hold_status(&self, owner: Option<&str>) -> Result<Value> {
        let mut tx = self.begin().await?;
        let hold = Self::locked_backup_hold(&mut tx).await?;
        tx.commit().await?;
        if let Some(owner) = owner {
            hold.assert_owner(owner)?;
        }
        Ok(hold.report())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const OWNER: &str = "7f1c3a1e-0000-4000-8000-000000000001";
    const OTHER: &str = "7f1c3a1e-0000-4000-8000-000000000002";

    fn at(seconds: i64) -> DateTime<Utc> {
        DateTime::from_timestamp(1_800_000_000 + seconds, 0).unwrap()
    }

    fn hold(owner: Option<&str>, acquired: Option<i64>, expires: Option<i64>, now: i64) -> Hold {
        Hold { owner: owner.map(str::to_owned), acquired_at: acquired.map(at), expires_at: expires.map(at), now: at(now) }
    }

    fn ask(owner: &str, ttl: i64) -> Request {
        Request { owner: owner.to_owned(), ttl }
    }

    fn reason(result: Result<Option<Hold>>) -> &'static str {
        match result {
            Err(super::super::RuntimeError::Refused { reason, .. }) => reason,
            other => panic!("expected a refusal, got {other:?}"),
        }
    }

    #[test]
    fn acquire_takes_a_free_or_lapsed_hold_and_refuses_an_active_one() {
        let free = hold(None, None, None, 0);
        let taken = free.apply(Action::Acquire, &ask(OWNER, 900)).unwrap().unwrap();
        assert_eq!(taken, hold(Some(OWNER), Some(0), Some(900), 0));
        assert!(taken.active());

        // The owner acquiring again keeps its hold unchanged.
        assert_eq!(taken.apply(Action::Acquire, &ask(OWNER, 60)).unwrap(), None);
        assert_eq!(reason(taken.apply(Action::Acquire, &ask(OTHER, 60))), "backup_hold_owned_elsewhere");

        // A lapsed or released hold goes to the next backup.
        let lapsed = hold(Some(OWNER), Some(0), Some(900), 900);
        assert_eq!(lapsed.apply(Action::Acquire, &ask(OTHER, 60)).unwrap().unwrap(), hold(Some(OTHER), Some(900), Some(960), 900));
        let released = hold(Some(OWNER), Some(0), None, 10);
        assert!(released.apply(Action::Acquire, &ask(OTHER, 60)).unwrap().is_some());
    }

    #[test]
    fn an_owner_is_single_use() {
        let lapsed = hold(Some(OWNER), Some(0), Some(900), 901);
        assert_eq!(reason(lapsed.apply(Action::Acquire, &ask(OWNER, 60))), "backup_hold_not_owned");
        assert_eq!(reason(lapsed.apply(Action::Renew, &ask(OWNER, 60))), "backup_hold_not_owned");
        let released = hold(Some(OWNER), Some(0), None, 10);
        assert_eq!(reason(released.apply(Action::Acquire, &ask(OWNER, 60))), "backup_hold_not_owned");
        assert_eq!(reason(released.apply(Action::Renew, &ask(OWNER, 60))), "backup_hold_not_owned");
    }

    #[test]
    fn renew_extends_only_its_own_hold_and_never_past_a_day() {
        let held = hold(Some(OWNER), Some(0), Some(900), 600);
        assert_eq!(held.apply(Action::Renew, &ask(OWNER, 3600)).unwrap().unwrap().expires_at, Some(at(4200)));
        assert_eq!(reason(held.apply(Action::Renew, &ask(OTHER, 60))), "backup_hold_not_owned");
        let late = hold(Some(OWNER), Some(0), Some(86_000), 85_000);
        assert_eq!(late.apply(Action::Renew, &ask(OWNER, 3600)).unwrap().unwrap().expires_at, Some(at(86_400)));
    }

    #[test]
    fn release_clears_the_expiry_and_keeps_the_owner() {
        let held = hold(Some(OWNER), Some(0), Some(900), 10);
        let released = held.apply(Action::Release, &ask(OWNER, 0)).unwrap().unwrap();
        assert_eq!(released, hold(Some(OWNER), Some(0), None, 10));
        assert!(!released.active());
        assert_eq!(reason(held.apply(Action::Release, &ask(OTHER, 0))), "backup_hold_not_owned");
        // As in PHP, the named owner may release a hold that has lapsed.
        assert!(hold(Some(OWNER), Some(0), Some(5), 10).apply(Action::Release, &ask(OWNER, 0)).unwrap().is_some());
    }

    #[test]
    fn owners_match_regardless_of_case() {
        let held = hold(Some(OWNER), Some(0), Some(900), 10);
        assert_eq!(held.apply(Action::Acquire, &ask(&OWNER.to_uppercase(), 60)).unwrap(), None);
    }

    #[test]
    fn the_report_has_php_shape() {
        assert_eq!(
            hold(Some(OWNER), Some(0), Some(900), 10).report(),
            json!({"schema": SCHEMA, "active": true, "owner": OWNER, "acquired_at": "2027-01-15 08:00:00",
                "expires_at": "2027-01-15 08:15:00", "database_time": "2027-01-15 08:00:10"})
        );
    }

    #[test]
    fn requests_need_a_uuid_owner_and_a_bounded_ttl() {
        assert_eq!(request(&json!({"owner": OWNER}), Action::Acquire).unwrap(), ask(OWNER, 900));
        assert_eq!(request(&json!({"owner": OWNER, "ttl": "60"}), Action::Renew).unwrap(), ask(OWNER, 60));
        assert_eq!(request(&json!({"owner": OWNER, "ttl": 0}), Action::Release).unwrap(), ask(OWNER, 900));
        for (body, action) in [
            (json!({}), Action::Acquire),
            (json!({"owner": "not-a-uuid"}), Action::Acquire),
            (json!({"owner": OWNER, "ttl": 0}), Action::Acquire),
            (json!({"owner": OWNER, "ttl": 3601}), Action::Renew),
            (json!({"owner": OWNER, "ttl": 1.5}), Action::Renew),
        ] {
            assert!(request(&body, action).is_err(), "{body}");
        }
        assert!(is_uuid("7F1C3A1E-0000-4000-8000-000000000001"));
        assert!(!is_uuid("7f1c3a1e00004000800000000000000001"));
    }
}
