<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$runIds = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($runIds) || count($runIds) !== 2 || count(array_filter($runIds, 'is_string')) !== 2) {
    throw new InvalidArgumentException('Supply the two original caller run IDs.');
}
$rows = Workflow\V2\Models\WorkflowServiceCall::query()
    ->whereIn('caller_workflow_run_id', $runIds)->orderBy('caller_workflow_run_id')->get()
    ->map(static fn ($call): array => [
        'id' => $call->getKey(), 'caller_workflow_run_id' => $call->caller_workflow_run_id,
        'caller_namespace' => $call->caller_namespace, 'target_namespace' => $call->target_namespace,
        'caller_principal_roles' => $call->caller_principal_roles,
        'outcome' => $call->outcome?->value,
        'accepted_at' => $call->accepted_at, 'started_at' => $call->started_at,
        'linked_workflow_instance_id' => $call->linked_workflow_instance_id,
        'linked_workflow_run_id' => $call->linked_workflow_run_id,
    ])->all();
echo json_encode(['scope' => 'read_only_service_call_admission_audit', 'rows' => $rows], JSON_THROW_ON_ERROR).PHP_EOL;
