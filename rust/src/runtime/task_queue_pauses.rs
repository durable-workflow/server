//! Pausing activity dispatch on one task queue (Server #577).
//!
//! An operator can hold new activity dispatch on one task queue of one
//! namespace and later resume it. While the queue is paused:
//!
//! - no activity task on it is leased, by an activity poll, a carried
//!   activity poll or any other claim path. Ready activity tasks stay ready
//!   and no attempt is used, so resuming dispatches the same backlog;
//! - activity deadlines keep running and are not extended. A
//!   schedule-to-start or schedule-to-close deadline that passes while the
//!   task waits times the activity out as it would without the pause;
//! - activities already leased keep running, and their heartbeats,
//!   completions and failures are accepted;
//! - workflow tasks, signals, queries and updates are delivered as before.
//!
//! The pause is a durable row in `dw_task_queue_pauses`, so it holds across
//! restarts and every server node sharing the database. It is admission
//! state, not history. Pausing and resuming take the transition lock, so a
//! claim either commits before the pause or sees it. The row keeps the last
//! pause and resume, with the authenticated principal and time of each.
//!
//! A pause is distinct from a worker build drain, which refuses one build's
//! workers, and from a worker's own concurrency settings. Native has no
//! server-side concurrency caps; no cap value means "paused".
use super::laravel_rules::{self, Rule};
use super::{Result, backend::Backend, store::{Store, now}};
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

/// The cluster info capability that advertises these routes.
pub(super) const CAPABILITY: &str = "task_queue_activity_dispatch_pause";

const PAUSE_RULES: &laravel_rules::Rules = &[("reason", &[Rule::Nullable, Rule::String, Rule::Max(1000)])];
const QUEUE_RULES: &laravel_rules::Rules = &[("task_queue", &[Rule::Required, Rule::String, Rule::Max(255)])];

/// Whether new activity dispatch on a queue is held.
pub(super) const PAUSED_SQL: &str =
    "SELECT namespace FROM dw_task_queue_pauses WHERE namespace=$1 AND task_queue=$2 AND resumed_at IS NULL";

/// A queue's last pause, and its resume if it was resumed.
pub(super) struct Pause {
    reason: Option<String>,
    paused_by: Option<Value>,
    paused_at: DateTime<Utc>,
    resumed_by: Option<Value>,
    resumed_at: Option<DateTime<Utc>>,
}

impl Pause {
    fn paused(&self) -> bool {
        self.resumed_at.is_none()
    }
}

/// The `activity_dispatch` object of a queue: whether it is paused, and the
/// last pause and resume, each with its principal and time.
pub(super) fn view(pause: Option<&Pause>) -> Value {
    let time = |at: Option<DateTime<Utc>>| super::task_queues::json_time(at);
    match pause {
        Some(pause) => json!({"state":if pause.paused() { "paused" } else { "active" },"paused":pause.paused(),
            "reason":pause.reason,"paused_at":time(Some(pause.paused_at)),"paused_by":pause.paused_by,
            "resumed_at":time(pause.resumed_at),"resumed_by":pause.resumed_by}),
        None => json!({"state":"active","paused":false,"reason":null,"paused_at":null,"paused_by":null,
            "resumed_at":null,"resumed_by":null}),
    }
}

/// The route's queue name, stored as given: a registration's `task_queue`
/// is at most 255 characters.
fn queue_name(queue: &str) -> Result<()> {
    laravel_rules::validate(&laravel_rules::normalize(&json!({"task_queue": queue})), QUEUE_RULES)
}

fn principal(text: Option<String>) -> Result<Option<Value>> {
    Ok(text.map(|text| serde_json::from_str(&text)).transpose()?)
}

impl<DB: Backend> Store<DB>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> Option<&'q str>: Encode<'q, DB>,
    for<'q> Option<DB::EncodedDocument>: Encode<'q, DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'r> i64: Decode<'r, DB>,
    usize: ColumnIndex<DB::Row>,
{
    /// The queue's last pause, if it was ever paused.
    pub(super) async fn task_queue_pause<'e, E>(executor: E, namespace: &str, queue: &str) -> Result<Option<Pause>>
    where
        E: Executor<'e, Database = DB>,
    {
        let Some(row) = Self::query("SELECT reason,paused_by,paused_at,resumed_by,resumed_at FROM dw_task_queue_pauses WHERE namespace=$1 AND task_queue=$2")
            .bind(namespace).bind(queue).fetch_optional(executor).await?
        else {
            return Ok(None);
        };
        Ok(Some(Pause {
            reason: DB::optional_string(&row, "reason")?,
            paused_by: principal(DB::optional_string(&row, "paused_by")?)?,
            paused_at: DB::instant(&row, "paused_at")?,
            resumed_by: principal(DB::optional_string(&row, "resumed_by")?)?,
            resumed_at: DB::optional_instant(&row, "resumed_at")?,
        }))
    }

    /// Whether new activity dispatch on the queue is held.
    pub(super) async fn activity_dispatch_paused<'e, E>(executor: E, namespace: &str, queue: &str) -> Result<bool>
    where
        E: Executor<'e, Database = DB>,
    {
        Ok(Self::query(PAUSED_SQL).bind(namespace).bind(queue).fetch_optional(executor).await?.is_some())
    }

    /// The namespace's paused queues, for the queue list.
    pub(super) async fn paused_task_queues(&self) -> Result<Vec<String>> {
        let rows = Self::query("SELECT task_queue FROM dw_task_queue_pauses WHERE namespace=$1 AND resumed_at IS NULL")
            .bind(self.namespace.as_ref()).fetch_all(&self.pool).await?;
        rows.iter().map(|row| DB::string(row, "task_queue")).collect()
    }

    /// `GET /api/task-queues/{name}/activity-dispatch`.
    pub(crate) async fn activity_dispatch(&self, queue: &str) -> Result<Value> {
        queue_name(queue)?;
        let pause = Self::task_queue_pause(&self.pool, self.namespace.as_ref(), queue).await?;
        Ok(json!({"namespace":self.namespace.as_ref(),"task_queue":queue,"activity_dispatch":view(pause.as_ref())}))
    }

    /// `POST /api/task-queues/{name}/activity-dispatch/pause`. Pausing a
    /// paused queue changes nothing and answers the original pause.
    pub(crate) async fn pause_activity_dispatch(&self, queue: &str, body: &Value, paused_by: Option<Value>) -> Result<Value> {
        queue_name(queue)?;
        let body = laravel_rules::normalize(body);
        laravel_rules::validate(&body, PAUSE_RULES)?;
        let namespace = self.namespace.as_ref();
        let mut tx = self.begin().await?;
        let previous = Self::task_queue_pause(&mut *tx, namespace, queue).await?;
        if let Some(pause) = previous.as_ref().filter(|pause| pause.paused()) {
            tx.commit().await?;
            return Ok(json!({"namespace":namespace,"task_queue":queue,"outcome":"already_paused",
                "activity_dispatch":view(Some(pause))}));
        }
        let pause = Pause { reason: body["reason"].as_str().map(str::to_owned), paused_by, paused_at: now(),
            resumed_by: None, resumed_at: None };
        let recorded = pause.paused_by.as_ref().map(Value::to_string);
        // A new pause replaces the last cycle's record. MySQL binds in order.
        if previous.is_some() {
            Self::query("UPDATE dw_task_queue_pauses SET reason=$1,paused_by=$2,paused_at=$3,resumed_by=NULL,resumed_at=NULL WHERE namespace=$4 AND task_queue=$5")
                .bind(pause.reason.as_deref()).bind(recorded.as_deref()).bind(DB::bind_time(pause.paused_at))
                .bind(namespace).bind(queue).execute(&mut *tx).await?;
        } else {
            Self::query("INSERT INTO dw_task_queue_pauses(namespace,task_queue,reason,paused_by,paused_at) VALUES ($1,$2,$3,$4,$5)")
                .bind(namespace).bind(queue).bind(pause.reason.as_deref()).bind(recorded.as_deref())
                .bind(DB::bind_time(pause.paused_at)).execute(&mut *tx).await?;
        }
        tx.commit().await?;
        Ok(json!({"namespace":namespace,"task_queue":queue,"outcome":"paused","activity_dispatch":view(Some(&pause))}))
    }

    /// `POST /api/task-queues/{name}/activity-dispatch/resume`. Resuming an
    /// active queue changes nothing. Held polls on this node claim at once;
    /// other nodes' held polls see the resume at their next read.
    pub(crate) async fn resume_activity_dispatch(&self, queue: &str, resumed_by: Option<Value>) -> Result<Value> {
        queue_name(queue)?;
        let namespace = self.namespace.as_ref();
        let mut tx = self.begin().await?;
        let mut pause = match Self::task_queue_pause(&mut *tx, namespace, queue).await? {
            Some(pause) if pause.paused() => pause,
            last => {
                tx.commit().await?;
                return Ok(json!({"namespace":namespace,"task_queue":queue,"outcome":"not_paused",
                    "activity_dispatch":view(last.as_ref())}));
            }
        };
        let resumed_at = now();
        let recorded = resumed_by.as_ref().map(Value::to_string);
        Self::query("UPDATE dw_task_queue_pauses SET resumed_by=$1,resumed_at=$2 WHERE namespace=$3 AND task_queue=$4")
            .bind(recorded.as_deref()).bind(DB::bind_time(resumed_at))
            .bind(namespace).bind(queue).execute(&mut *tx).await?;
        (pause.resumed_by, pause.resumed_at) = (resumed_by, Some(resumed_at));
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(json!({"namespace":namespace,"task_queue":queue,"outcome":"resumed","activity_dispatch":view(Some(&pause))}))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn views_report_state_and_audit() {
        assert_eq!(view(None), json!({"state":"active","paused":false,"reason":null,"paused_at":null,"paused_by":null,
            "resumed_at":null,"resumed_by":null}));
        let at = DateTime::parse_from_rfc3339("2026-11-12T10:00:00.123456Z").unwrap().with_timezone(&Utc);
        let mut pause = Pause { reason: Some("vendor outage".into()), paused_by: Some(json!({"type":"auth:token","id":"role:operator"})),
            paused_at: at, resumed_by: None, resumed_at: None };
        assert_eq!(view(Some(&pause)), json!({"state":"paused","paused":true,"reason":"vendor outage",
            "paused_at":"2026-11-12T10:00:00.123456Z","paused_by":{"type":"auth:token","id":"role:operator"},
            "resumed_at":null,"resumed_by":null}));
        pause.resumed_at = Some(at + chrono::Duration::seconds(5));
        pause.resumed_by = Some(json!({"type":"auth:token","id":"role:admin"}));
        let resumed = view(Some(&pause));
        assert_eq!((resumed["state"].clone(), resumed["paused"].clone()), (json!("active"), json!(false)));
        assert_eq!(resumed["resumed_at"], "2026-11-12T10:00:05.123456Z");
        assert_eq!(resumed["reason"], "vendor outage");
    }

    #[test]
    fn queue_names_follow_the_registration_rule() {
        assert!(queue_name("orders").is_ok());
        assert!(queue_name(&"q".repeat(255)).is_ok());
        assert!(queue_name(&"q".repeat(256)).is_err());
        assert!(queue_name("   ").is_err());
    }
}
