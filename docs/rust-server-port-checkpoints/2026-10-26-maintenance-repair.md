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
- **Change.** The ticker can now also run the repair pass with PHP's loop
  throttle: at most once per 5 seconds, shared with requested passes that
  respect it.
  - **Opt-in.** It runs only with `DW_RUST_MAINTENANCE_LOOP=1`, which the
    native image will set. A PHP server container never repairs on its own:
    PHP's separate `scheduler` container does. So the bare binary keeps the
    server role, and the Shared Server fixtures, which pair each native node
    with a PHP server container, compare like with like.
  - On PR #797's first run, a default-on loop repaired a task the corrected
    marker cohort expects untouched, and raced `repair_status`'s staged
    candidates.
  - `DW_V2_MATCHING_ROLE_QUEUE_WAKE=false` (or `0`) still turns it off, as it
    does PHP's.
  - `Runtime::set_maintenance_repair` sets it for one runtime.
- **Tests.**
  - `the_maintenance_loop_gives_an_overdue_idle_run_its_deadline_task` waits
    for the loop alone to give an idle run past its deadline its workflow task.
  - The execution suite passes with the loop off by default, and passed with
    it on (558).
  - Native passes, with the loop on, the shared fixtures most exposed to
    background repair: repair-pass, the lease, cancellation and deadline
    fixtures, worker sessions and service calls.
- **Still to do.** History pruning and external payload cleanup also run in
  PHP's loop; native runs them only on request (`POST
  /api/system/retention/pass` and its payload cleanup).
