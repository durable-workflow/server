# Operator dashboard by workflow type (October 23, 2026)

`GET /api/system/operator-dashboard/bounded/workflow-types` now answers like
published Server 2.11.1 instead of 501 `backend_capability_unavailable`.

## Why

Published 2.11.1's default observer implements
`workflowTypeDashboardSummary`, so PHP answers with filtered totals. Native's
earlier 501 matched only an observer without that method.

Waterline turns on its workflow type filter whenever the bounded dashboard
has a `workflow_scope` object (`RemoteBackend::capabilities`). Native's
bounded dashboard has one, so Waterline offered the filter and got the 501.

## Behavior

The answer is the bounded dashboard (`OperatorDashboardSummary::snapshot`)
with `summaryQuery`'s filter on every run figure:
- total and last-hour flows, and flows per minute;
- the week's failed flows;
- the last hour's exceptions, through the failure's run;
- the longest wait, longest duration and most exceptions;
- the fleet overview and its trends;
- the ten busiest types;
- the run-based attention alerts;
- the hourly trend series.

The stuck-worker alert and the operator metrics keep the whole namespace,
and `operator_metrics_scope.workflow_types` stays null.
`workflow_scope.workflow_types` echoes the list as given, duplicates
included, with `all_workflow_types: false`. An empty list matches no runs.

## How

Native SQL stays in static templates with bound values. Each filtered query
is the unfiltered template plus `workflow_type IN (...)` over the list,
bound once as a JSON array:
- SQLite: `json_each`;
- PostgreSQL: `jsonb_array_elements_text`;
- MySQL and MariaDB: `JSON_TABLE` with a `utf8mb4_unicode_ci` column.

The comparison therefore goes through the database's own text comparison, as
PHP's `whereIn` does. MySQL and MariaDB match an upper-cased type; SQLite and
PostgreSQL do not. The unfiltered dashboard's queries are unchanged, except
that the exceptions count joins each failure's run instead of using `EXISTS`.
A failure has one run, so the count is the same.

The MySQL placeholder conversion now leaves a `$` without digits, such as
the JSON path `'$[*]'`, as written. Numbered positions must still be in
order.

## Evidence

- **Fixture first: `operator-dashboard-types`.** It uses a fresh namespace
  with two runs of one type, one of another, and one failed workflow task.
  Six lists are read:
  - one type;
  - a type plus a missing type;
  - an empty list;
  - a repeated type;
  - both types;
  - an upper-cased type.

  Five invalid lists are refused with PHP's 422 messages. Expected values
  come from published 2.11.1's live answers. Contract unit tests: 19,
  including 15 refused corruptions such as the old 501.
- **Live, SQLite.** The fixture's probe ran against published 2.11.1 and
  native, and both pass the contract. A wider raw comparison of the same
  scenario gave byte-identical reduced answers from both servers.
- **Native suite.** The `operator_dashboard` integration tests pass on
  SQLite, PostgreSQL, MySQL 8.0 and MariaDB 10.11. They include the
  upper-cased type, which matches only on MySQL-family collations. The MySQL
  conversion has two new unit tests.
