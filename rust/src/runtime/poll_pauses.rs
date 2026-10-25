//! A debug-build test seam for workflow polls that carry a request ID. A test
//! registers a stop for one request ID at one point; the next poll with that
//! ID to reach the point reports its arrival and waits there until the test
//! lets it go. Regressions use it to commit a duplicate's work inside a window
//! between a poll's steps, in a fixed order and without sleeping. Release
//! builds compile neither this module nor its call sites.
use std::sync::{Mutex, PoisonError};
use tokio::sync::oneshot;

/// Where a workflow poll with a request ID can be stopped.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum PollPoint {
    /// A held single-kind poll whose probe found nothing and whose queue
    /// had not changed, before it waits for work.
    Waiting,
    /// After the poll's last probe and its decision to answer without a
    /// task, before it records that answer under the request ID.
    Terminal,
}

/// A registered stop. `reached` resolves when the poll arrives; sending on
/// `release`, or dropping it, lets the poll go on.
pub struct PollPause {
    pub reached: oneshot::Receiver<()>,
    pub release: oneshot::Sender<()>,
}

struct Stop {
    request_id: String,
    point: PollPoint,
    arrived: oneshot::Sender<()>,
    resume: oneshot::Receiver<()>,
}

static STOPS: Mutex<Vec<Stop>> = Mutex::new(Vec::new());

/// Stop the next workflow poll with `request_id` at `point`, once. The stops
/// are process-wide, so each test uses request IDs of its own.
pub fn pause_workflow_poll(request_id: &str, point: PollPoint) -> PollPause {
    let (arrived, reached) = oneshot::channel();
    let (release, resume) = oneshot::channel();
    STOPS
        .lock()
        .unwrap_or_else(PoisonError::into_inner)
        .push(Stop { request_id: request_id.to_owned(), point, arrived, resume });
    PollPause { reached, release }
}

/// A poll at `point`: wait there if a test registered a stop for its ID.
pub(super) async fn stop_at(request_id: &str, point: PollPoint) {
    let stop = {
        let mut stops = STOPS.lock().unwrap_or_else(PoisonError::into_inner);
        stops
            .iter()
            .position(|stop| stop.request_id == request_id && stop.point == point)
            .map(|index| stops.swap_remove(index))
    };
    if let Some(stop) = stop {
        let _ = stop.arrived.send(());
        let _ = stop.resume.await;
    }
}
