# Workflow failure commands

The published `fail_workflow` command closes the workflow run as failed. It is
distinct from the worker task failure endpoint, which may retry a task without
failing its run. This records existing Server 2.8.7 / Workflow 2.5.32 behavior;
it adds no API or database field.

A completion batch has at most one terminal command. The bridge applies all
nonterminal commands in authored order, then the terminal command. A workflow
failure does not require its other durable operations to have completed. Their
original records remain intact; closed runs cannot claim or acknowledge new work.
The native implementation retains its existing explicit limits on other command
shapes and does not reinterpret unsupported shapes.

The required message uses PHP string trimming. Optional structured exception
message/class/type take precedence at the HTTP boundary. Preserve the complete
structured exception, filling only missing defaults. Failure category and default
non-retryable behavior follow the portable published FailureFactory rules;
unknown external classes do not declare PHP inheritance. An explicit boolean
non_retryable overrides its default.
Reference comparisons ignore JSON object member order, which PostgreSQL JSONB
does not preserve. Array order, keys and scalar types remain exact.

One transaction writes a new workflow-run failure, WorkflowFailed history, the
run's failed/closed state, and the originating task's failed state with its lease
expiry cleared. Existing completion receipts retain duplicate/conflict and stale
attempt fencing. A failure report must never create an automatic task retry.
Cooperative cancellation takes precedence and preserves its original root
identity/deadline rather than creating WorkflowFailed history.

Uncaught activity attribution requires both a positive authored sequence and the
original activity execution ID. Attribute the terminal event only when the last
actual history event is exactly that ActivityFailed boundary. A false or stale
claim must not manufacture attribution.

A failed sequential child resolves its original child call using the new terminal
failure ID, never an earlier handled activity failure. The original parent receives
one ChildRunFailed event and one resume task. Parent notification failure rolls
back the child's terminal transition. An abandoned child cannot wake a closed or
replacement parent. Pending-update closure and broader child policies retain
their separately stated implementation/qualification limits.
The current native update-task guard still refuses workflow terminal commands
on an update-owned snapshot; this component does not qualify that closure path.

References: published DefaultWorkflowTaskBridge::parseCommands,
normalizeFailWorkflowCommand, applyWorkflowFailure; Server WorkerController's
promoteWorkflowFailureExceptionPayload; FailureFactory; V2ChildTerminalOutcomeTest.
