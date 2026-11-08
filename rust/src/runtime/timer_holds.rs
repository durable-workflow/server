//! A debug-build test seam for the maintenance loop's due timers. A test holds
//! one timer by its ID; while the hold lives, `fire_due_timers` leaves that
//! timer pending and untouched, and reports the first tick that found it due.
//! Regressions use it to keep an already-due timer unfired across worker
//! turns, in a fixed order and without sleeping. Holds are process-wide and
//! keyed by timer ID, so concurrent tests never share one. Release builds
//! compile neither this module nor its call site.
use std::sync::{Mutex, PoisonError};
use tokio::sync::oneshot;

struct Hold {
    timer_id: String,
    found: Option<oneshot::Sender<()>>,
}

static HOLDS: Mutex<Vec<Hold>> = Mutex::new(Vec::new());

/// A live hold. `skipped` resolves once a maintenance tick has found the
/// timer due and left it pending; dropping the hold lets later ticks fire it.
pub struct TimerHold {
    timer_id: String,
    pub skipped: oneshot::Receiver<()>,
}

/// Hold the timer `timer_id` until the returned hold is dropped.
pub fn hold_timer(timer_id: &str) -> TimerHold {
    let (found, skipped) = oneshot::channel();
    HOLDS
        .lock()
        .unwrap_or_else(PoisonError::into_inner)
        .push(Hold { timer_id: timer_id.to_owned(), found: Some(found) });
    TimerHold { timer_id: timer_id.to_owned(), skipped }
}

impl Drop for TimerHold {
    fn drop(&mut self) {
        HOLDS
            .lock()
            .unwrap_or_else(PoisonError::into_inner)
            .retain(|hold| hold.timer_id != self.timer_id);
    }
}

/// A due timer in `fire_due_timers`: whether a test holds it. The first tick
/// that finds it reports to the test.
pub(super) fn held(timer_id: &str) -> bool {
    let mut holds = HOLDS.lock().unwrap_or_else(PoisonError::into_inner);
    let Some(hold) = holds.iter_mut().find(|hold| hold.timer_id == timer_id) else {
        return false;
    };
    if let Some(found) = hold.found.take() {
        let _ = found.send(());
    }
    true
}
