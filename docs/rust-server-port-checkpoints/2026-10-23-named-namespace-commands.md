# October 23, workflow commands in named namespaces

A live probe ran the same workflow in a named namespace (`ns-live`) against
published Server 2.11.1 and native. PHP served every request. Native refused
these:

| Request | Published PHP | Native before |
| --- | --- | --- |
| a completion with a version marker and a side effect | 200 | 422 `development_namespace_command_unqualified` |
| `continue_as_new` | 200, the run continues | the same 422 |
| a signal wait batched with a timer | 200 | the same 422 |
| an `upsert_search_attributes` command | PHP's own refusal, 409 `workflow_metadata_capability_not_advertised` | the same 422 |
| `POST /update/{name}`, then `complete_update` | 202, then 200 | 422 `development_namespace_operation_unqualified` |
| `POST /repair` | 200 `repair_not_needed` | the same 422 |
| `POST /request-cancellation` | 202 | the same 422 |

The completion refusal was a guard in the batch checks, and the route
refusals were missing entries in the named-namespace route table.

Native's update admission and cooperative cancellation request looked the
instance up in the `default` namespace. Repair refused any other namespace
(`development_workflow_task_repair_namespace_unqualified`), although its
queries already used the request's namespace, as redrive's do.

## What native now does

- Every workflow task command is accepted in any namespace, under the same
  checks as in the default namespace.
- Named namespaces route the following, instance- and run-scoped:
  - `update/{name}`;
  - `repair`;
  - `request-cancellation`;
  - the run's `redrive`.
- Update admission and cooperative cancellation requests read the instance
  and run in the request's namespace.

## Differences that remain

- A route native does not implement at all, such as message streams or Nexus
  operations, answers `501` in the default namespace but
  `422 development_namespace_operation_unqualified` in a named one.
- A task that opens a wait while another wait is open is refused with
  `409 pending_durable_operations` in every namespace (see the port log).

## Tests

`named_namespace_commands_like_php` runs, in a named namespace:

- a version marker and a side effect;
- an update and its completion;
- repair;
- a cooperative cancellation request;
- continue-as-new.

Three older tests expected the named-namespace refusal:

- `named_namespaces_preserve_original_runs_and_global_workflow_id_reservation`
  now checks that a refused completion in the namespace changes nothing,
  using two terminal commands.
- `run_observation_signals` opens two signal waits at once, and now expects
  native's `pending_durable_operations`.
- `timed_signal_waits` batches a wait whose timeout is 0 with a second wait.
  The default namespace already admits that batch, and the named namespace
  now does too (renamed
  `named_batched_timeout_wait_is_admitted_like_the_default_namespace`).
