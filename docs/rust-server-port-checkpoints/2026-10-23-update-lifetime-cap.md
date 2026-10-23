# October 23, no lifetime cap on updates

- **Change.** Native no longer refuses a run's 65th update with 503
  `update_inventory_exceeds_budget`. That cap came from the first update
  slice, before native enforced the Workflow package's structural limits.
- **Why.** Published PHP 2.11.1 bounds only updates still pending, with
  `DW_V2_LIMIT_PENDING_UPDATES` (default 500). It has no limit on how many
  updates a run receives over its life. Native has enforced that pending
  limit like PHP since the structural limits checkpoint
  ([2026-10-23-structural-limits](2026-10-23-structural-limits.md)).
- **Test.** `updates_have_no_lifetime_cap_and_preserve_accepted_work_after_wait_timeout`
  completes 64 updates, checks that a repeated request id still answers
  with its stored result, then admits a 65th update with 202.
