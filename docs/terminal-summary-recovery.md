# Repair a terminal history-size summary

This procedure applies to the PHP 2.x Compose stack, `docker-compose.php.yml`.
The published Compose file runs the native server from 3.0.0.

[Server #479](https://github.com/durable-workflow/server/issues/479) tracks
terminal summaries that undercount history bytes after Server adds the
authenticated worker principal to a completed or failed workflow event. The
durable event and workflow outcome remain recorded; the affected value is the
derived `history_size_bytes` counter and its budget pressure calculation.

The correction in [#480](https://github.com/durable-workflow/server/pull/480)
keeps new terminal summaries synchronized with that event. Installing the
correction does not rewrite existing summaries. Use the existing projection
rebuild command for an affected terminal run.

## Select and repair one run

Obtain the exact run ID from the workflow's selected-run observation or history.
Run these commands in the PHP Server deployment that owns that run. With the
published Compose configuration:

```sh
RUN_ID='replace-with-the-selected-run-id'

docker compose -f docker-compose.php.yml exec server \
  php artisan workflow:v2:rebuild-projections --run-id="$RUN_ID" --dry-run --json

docker compose -f docker-compose.php.yml exec server \
  php artisan workflow:v2:rebuild-projections --run-id="$RUN_ID" --json
```

The dry run should report `runs_matched: 1`,
`run_summaries_would_rebuild: 1`, and an empty `failures` array. The repair should
report `runs_matched: 1`, `run_summaries_rebuilt: 1`, and an empty `failures`
array. A zero match requires checking the deployment and run ID. Inspect any
reported failure before repeating the command.

This command rebuilds the selected run's derived projections from durable
state. It does not rerun workflow or activity code. It can read the selected
run's complete retained history, so run it as a maintenance operation with the
deployment's normal database capacity available. The selected-run command above
does not request pruning or a namespace-wide rebuild.

## Verify the result

Read the same selected run again through the normal SDK or observation API.
Confirm its workflow/run IDs, terminal outcome, history event count, event
identities and payloads remain unchanged. The history-size counter should equal
the sum of each stored event type's UTF-8 bytes and its compact JSON payload
bytes, with Unicode and slashes unescaped. The ordinary history API may wrap
stored payload strings in transport envelopes; use the observation history's
stored payload shape for this comparison. A pruned or paginated history is not
sufficient to independently recompute the complete counter.

The recovery evidence in [#479](https://github.com/durable-workflow/server/issues/479)
exercises completed and failed workflows with the installed SDK. It preserves
the original product failure and checks that repair changes only the affected
counter and projection timestamp. Release delivery and exact published-image
verification are recorded there separately.
