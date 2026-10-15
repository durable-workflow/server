# Activity completion uploads

This #325 slice implements the existing published version-1 activity-result
completion context on the native local byte transport. The unchanged native
control refuses the header. The existing wire contract is in
`resources/platform-protocol-specs/external-payload-transport.openapi.yaml` and
`docs/storage-admission.md`. PHP remains the published reference.

Acceptance requires actual separate PHP/native originals, a namespace-bound
current activity attempt before body/provider work, an allowance shared by that
lease, conflicting slot refusal and rechecks after transfer. Upload does not
complete or renew the task. Matching recorded upload references may reconcile
after closure without rewriting or extending provider state. Final activity
completion still applies its own atomic authority fence.

Qualification covers activity result uploads, actual result
completion and cold parent consumption, changed-owner/attempt/namespace and
expired-lease refusal, duplicate/conflicting bytes, bounded allowances and
expiry during transfer. Published PHP/SDK tuples and earlier failed originals
stay unchanged. All database families require qualification before promotion.

Workflow/query contexts, activity failure details, prepared version-2 contexts,
storage-pressure bypass, S3, restart/cleanup/backup faults and full transport
discovery remain separate gates. The source prototype has focused SQLite
library/HTTP checks; its full matrix and actual consumer acceptance remain
pending. No advertised capability or release follows from those checks.
