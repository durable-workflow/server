//! Physical expiry is best effort. Readers enforce expiry under the same
//! transition lock even when another namespace already used this interval.
use std::{sync::Mutex, time::{Duration, Instant}};

#[derive(Clone, Copy)]
pub(super) struct Config {
    pub interval: Duration,
    pub batch: i64,
}

impl Default for Config {
    fn default() -> Self {
        Self { interval: Duration::from_millis(1000), batch: 256 }
    }
}

impl Config {
    pub(super) fn from_env() -> super::Result<Self> {
        fn read(name: &str, default: u64) -> super::Result<u64> {
            match std::env::var(name) {
                Ok(value) => positive(Some(&value), default),
                Err(std::env::VarError::NotPresent) => positive(None, default),
                Err(_) => Err(super::refuse(axum::http::StatusCode::BAD_REQUEST, "invalid_poll_sweep_configuration")),
            }
        }
        Ok(Self {
            interval: Duration::from_millis(read("DW_NATIVE_POLL_SWEEP_INTERVAL_MS", 1000)?),
            batch: read("DW_NATIVE_POLL_SWEEP_BATCH_SIZE", 256)? as i64,
        })
    }
}

fn positive(value: Option<&str>, default: u64) -> super::Result<u64> {
    match value {
        None => Ok(default),
        Some(value) => value.parse::<u64>().ok().filter(|value| *value > 0 && *value <= i64::MAX as u64)
            .ok_or_else(|| super::refuse(axum::http::StatusCode::BAD_REQUEST, "invalid_poll_sweep_configuration")),
    }
}

#[derive(Default)]
pub(super) struct Sweeps {
    workflow: Mutex<Option<Instant>>,
    query: Mutex<Option<Instant>>,
    receipts: Mutex<Option<Instant>>,
}

pub(super) enum Kind { Workflow, Query, Receipts }

impl Sweeps {
    // Called only after acquiring the database transition lock. Reserve the
    // interval before SQL so a rolled-back poll cannot cause a sweep storm.
    pub(super) fn due(&self, kind: Kind, interval: Duration) -> bool {
        self.due_at(kind, interval, Instant::now())
    }

    /// Whether `due` would run the sweep now, without reserving it. A
    /// lock-free reader uses this to leave a due sweep to the locked path.
    pub(super) fn is_due(&self, kind: Kind, interval: Duration) -> bool {
        self.is_due_at(kind, interval, Instant::now())
    }

    fn is_due_at(&self, kind: Kind, interval: Duration, at: Instant) -> bool {
        let last = self.slot(kind).lock().unwrap_or_else(std::sync::PoisonError::into_inner);
        last.is_none_or(|last| at.saturating_duration_since(last) >= interval)
    }

    fn slot(&self, kind: Kind) -> &Mutex<Option<Instant>> {
        match kind { Kind::Workflow => &self.workflow, Kind::Query => &self.query, Kind::Receipts => &self.receipts }
    }

    fn due_at(&self, kind: Kind, interval: Duration, at: Instant) -> bool {
        let mut last = self.slot(kind).lock().unwrap_or_else(std::sync::PoisonError::into_inner);
        if last.is_some_and(|last| at.saturating_duration_since(last) < interval) { return false; }
        *last = Some(at);
        true
    }
}

store_methods! {
    pub(super) async fn sweep_poll_receipts(&self, tx: &mut super::transaction::Transaction<'_, DB>) -> super::Result<()> {
        if self.poll_sweeps.due(Kind::Receipts, self.poll_sweep_config.interval) {
            let at = super::store::now();
            Self::query(DB::POLL_RECEIPT_CLEANUP_SQL)
                .bind(DB::bind_time(at))
                .bind(DB::bind_time(at))
                .bind(self.poll_sweep_config.batch)
                .execute(&mut **tx)
                .await?;
        }
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn defaults_and_strict_positive_configuration_are_bounded() {
        let config = Config::default();
        assert_eq!(config.interval, Duration::from_millis(1000));
        assert_eq!(config.batch, 256);
        assert_eq!(positive(None, 256).unwrap(), 256);
        assert_eq!(positive(Some("17"), 256).unwrap(), 17);
        for value in ["0", "-1", "false", "", "1.5", " 7", "9223372036854775808"] {
            assert!(positive(Some(value), 256).is_err(), "{value}");
        }
    }

    #[test]
    fn each_sweep_has_an_independent_monotonic_interval() {
        let sweeps = Sweeps::default();
        let interval = Duration::from_millis(73);
        let start = Instant::now();
        for kind in [Kind::Workflow, Kind::Query, Kind::Receipts] {
            assert!(sweeps.due_at(kind, interval, start));
        }
        assert!(!sweeps.due_at(Kind::Workflow, interval, start + Duration::from_millis(72)));
        assert!(!sweeps.due_at(Kind::Workflow, interval, start));
        assert!(sweeps.due_at(Kind::Workflow, interval, start + interval));
        assert!(!sweeps.due_at(Kind::Query, interval, start + Duration::from_millis(72)));
        assert!(sweeps.due_at(Kind::Query, interval, start + interval));
    }

    #[test]
    fn a_due_peek_never_reserves_or_postpones_the_sweep() {
        let sweeps = Sweeps::default();
        let interval = Duration::from_millis(73);
        let start = Instant::now();
        // Never run: due, and peeking any number of times leaves it due.
        for _ in 0..3 {
            assert!(sweeps.is_due_at(Kind::Query, interval, start));
        }
        assert!(sweeps.due_at(Kind::Query, interval, start), "the locked caller still takes it");
        assert!(!sweeps.is_due_at(Kind::Query, interval, start + Duration::from_millis(72)));
        assert!(sweeps.is_due_at(Kind::Query, interval, start + interval));
        assert!(sweeps.is_due_at(Kind::Query, interval, start + interval));
        assert!(sweeps.due_at(Kind::Query, interval, start + interval), "a peek did not move the reservation");
        assert!(sweeps.is_due_at(Kind::Workflow, interval, start), "kinds stay independent");
    }
}
