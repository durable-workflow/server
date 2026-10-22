# October 22, cancellation cascade view

- **Why.** The fourth slice of the child cancellation cascade. Published
  PHP's run debug carries `cancellation_cascade_supported: true` and a
  `cancellation_cascade` view. The Sample App's children scenario reads that
  view through the API, the CLI and Waterline. Native reported `false` and
  `null`.
- **Source.** Workflow 2.9.0 `CancellationCascadeView`,
  `CancellationContext::fromArray` and `ParentCloseCancellation::context`,
  and Server 2.10.0 `WorkflowRunDiagnostics::forRun`.
- **Behavior.**
  - **When there is a view.** Both debug routes report the view for the
    selected run. It is `null` when the run has no recorded request, no
    request id and nothing to report.
  - **Walk.** The view starts at the root run of the selected run's request.
    It follows the root's child calls, then child and continue-as-new links
    to runs the calls do not name. It stops at 20 runs, 100 relations, 128
    history events per run and 512 in all. Every related run is checked
    against the namespace first. An inaccessible run is reported as
    `unavailable`, without its id.
  - **Per run.** Each run reports its request (the context without its
    lineage, with text cut at 8,192 bytes), whether it shares the root's
    budget, its delivery, its terminal event and cleanup outcome, its
    lifecycle, activity stops, cleanup recovery and child propagation.
  - **Findings.** What cannot be verified becomes a finding with PHP's code
    and message. Examples are a run whose saved request or deadline
    disagrees with its history, a missing delivery or terminal event, a
    cleanup outcome that does not match the close time, and propagation
    under another request or budget.
  - **Contexts.** Recorded v1 contexts are checked by PHP's `fromArray`
    rules: requester keys and values, lineage shape and cycles, the
    identities along the lineage, ISO times, and a deadline after the
    request. A parent-close request is checked against the parent's latest
    terminal event and PHP's 600-second budget.
- **Also fixed.** On MySQL, run debug for a run with a recorded failure
  panicked. The supporting-event lookup had a JSON path (`'$.failure_id'`)
  that the placeholder conversion read as a parameter. Debug now uses the
  backend's failure-event query, which run observation already uses.
- **Remaining gap.** Native writes no scoped (`cancellation-context/v2`)
  contexts and no activity stop receipts. After a takeover, a PHP-written
  scoped request reads as `request_context_invalid`, and a PHP-written stop
  receipt as `callback_state: unknown`.
- **Tests.**
  - The wait cascade test reads both runs' debug views. They are the same
    except `selected_run_id`. The view has no findings, two runs and one
    resolved child-call relation. Both runs are `cancelled` within the root
    budget. The parent's cleanup is its `WorkflowCancelled` outcome. Its
    delivery and propagation name the recorded events.
  - Clearing the child's saved deadline gives one
    `request_projection_mismatch` finding.
  - A run with no request has a `null` view.
