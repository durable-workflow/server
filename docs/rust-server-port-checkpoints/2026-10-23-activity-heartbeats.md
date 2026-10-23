# October 23, activity heartbeats on a closed run

- **The gap.** When a run was terminated or cancelled while its activity
  ran, published Server 2.11.1 answers the activity's next heartbeat with
  200:
  - `cancel_requested: true` and `can_continue: false`;
  - the closing reason, such as `run_terminated`, with
    `run_closed_reason` and `run_closed_at`;
  - `heartbeat_recorded: false` and a null `lease_expires_at`.

  The worker then stops the activity as cancelled. Native answered 409
  with its activity-outcome body, which a worker can read as a transport
  error rather than a cancellation.
- **What native does now.** For a closed run, native answers PHP's
  structured 200 from the same observation its status route reads.
  Nothing is recorded and no lease is renewed. An ordinary heartbeat also
  carries PHP's fields:
  - `reason`, `run_closed_reason` and `run_closed_at`, all null;
  - `worker_session`, null;
  - `protocol_version` and `server_capabilities`.
- **How it was checked.** A probe ran on native and the published image.
  It scheduled an activity with all four timeouts, claimed it, sent a
  heartbeat, terminated the run, and sent another heartbeat. The claim's
  `deadlines` map and both heartbeat bodies matched.
- **Not checked yet.** A heartbeat while a cooperative cancellation is
  pending on a run that is still open.
