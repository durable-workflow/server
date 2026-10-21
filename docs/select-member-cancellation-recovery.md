# Recover a workflow blocked while cancelling a select member

PHP Server 2.10.0 discards select-member cancellation identity fields
when validating a workflow-task completion. The runtime then refuses the
`cancel_selection_operation` command. A worker can report that refusal as a
workflow replay error, leaving the run waiting with `workflow_replay_blocked`.
The activity the workflow tried to cancel can still be queued or running.

Upgrade to the corrected PHP Server release before repairing affected runs.
The correction preserves the existing command format and does not require an
SDK or Workflow dependency upgrade. It does not automatically retry blocked
runs or undo an activity that has already executed.

## Inspect and repair the original run

Check the selected run's history and activity state. Confirm that the failure
names selection cancellation validation rather than another replay error.
Keep the original workflow and run IDs. Use the existing selected-run repair
endpoint with an operator-authorized token and the original namespace:

```sh
curl --fail-with-body --request POST \
  "$DW_SERVER_URL/api/workflows/$WORKFLOW_ID/runs/$RUN_ID/repair" \
  --header "Authorization: Bearer $DW_API_TOKEN" \
  --header "X-Namespace: $DW_NAMESPACE" \
  --header 'X-Durable-Workflow-Control-Plane-Version: 2' \
  --header 'Content-Type: application/json' \
  --data '{"request_id":"repair-select-member-cancellation"}'
```

Use URL-encoded identifiers in the path. Keep a compatible workflow worker
running on the original queue. Repair makes the original run available for
replay; the workflow can then submit its original cancellation and complete.
Verify the new workflow-task outcome and the member's recorded outcome in
history. A still-queued losing activity records `SelectionOperationCancelled`
and cannot be claimed after cancellation. Already completed operations retain
their recorded results.
