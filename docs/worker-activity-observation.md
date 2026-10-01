# Candidate remote activity observation

Worker protocol1.20 adds `POST /api/worker/activity-tasks/{taskId}/status`.
This remains a source candidate. The default released protocol is1.19.

Send the namespace and worker credential used to claim the task, the1.20
protocol header, and the exact `activity_attempt_id` and `lease_owner` in JSON.
The response reports whether the observed attempt can continue, its stop reason,
lease expiry, user heartbeat time, execution deadlines and any required worker
session. Namespace, task, attempt and owner mismatches refuse the observation.

Observation does not renew the activity lease, worker registration or required
session. It does not reset the application's heartbeat deadline or write
history. The activity retains the existing five-minute lease. Authored user
heartbeats retain their existing progress and renewal behavior. Required
sessions also retain their own lease and TTL. A worker must keep the separate
registration heartbeat current while it executes work.

An expired lease, heartbeat or execution deadline refuses continuation before
the timeout scanner runs. A required session must be active, owned by the same
worker and within its lease and TTL. The read remains available under draining
or fenced storage admission. Backend lock pressure returns retryable503 with
the attempted fence and does not grant permission to continue.

An accepted cooperative request remains pending until the workflow records its
canonical delivery. Pending acceptance alone does not cancel a remote activity.
Once delivery cancels the activity, observation returns `cancel_requested=true`
and `can_continue=false`. Terminal cancel and terminate retain their existing
behavior. Workers must stop unsafe callback execution and check the attempt
again before encoding or publication. A prior successful observation is not an
ownership reservation, and completion/failure still validate the actual fence.

Stopping an activity process cannot undo external effects. Safe retries still
require idempotency or reconciliation. This endpoint supplies the observation
contract for connected SDK qualification and does not by itself prove callback
supervision, graceful shutdown or cold replacement in any SDK.
