# October 26, the repair pass runs on its own

- **Found by.** A review of what native does without a request. The published
  image's `scheduler` service (`docker-compose.yml`) loops every 10 seconds
  through:
  - `schedule:evaluate`;
  - `workflow:v2:repair-pass`, unless `DW_V2_MATCHING_ROLE_QUEUE_WAKE` is false
    or 0;
  - activity timeout enforcement;
  - external payload cleanup;
  - history pruning.

  The parity reference runs the same loop every second. Native's ticker
  already fired due timers and schedules every 250 ms, and enforced activity
  and cooperative deadlines. The repair pass ran only when someone called
  `POST /api/system/repair/pass`. So an idle run past its execution or run
  deadline was never given the task that closes it, and overdue ready tasks
  and due service calls waited for a request.
- **Change.** The ticker now also runs the repair pass with PHP's loop
  throttle: at most once per 5 seconds, shared with requested passes that
  respect it. `DW_V2_MATCHING_ROLE_QUEUE_WAKE=false` (or `0`) turns it off, as
  it does PHP's. `Runtime::set_maintenance_repair` does the same for one
  runtime.
- **Tests.**
  - `the_maintenance_loop_gives_an_overdue_idle_run_its_deadline_task` waits
    for the loop alone to give an idle run past its deadline its workflow task.
  - The two tests that count one requested pass, or keep expired leases until
    they retry, turn the loop off.
  - The execution suite passes with the loop on (558).
  - Native passes, with the loop on, the shared fixtures most exposed to
    background repair: repair-pass, the lease, cancellation and deadline
    fixtures, worker sessions and service calls.
- **Still to do.** History pruning and external payload cleanup also run in
  PHP's loop; native runs them only on request (`POST
  /api/system/retention/pass` and its payload cleanup).
