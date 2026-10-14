<?php

declare(strict_types=1);

// The isolated published reference needs its normal package timeout enforcer.
// Scope it to these originals and stop touching each activity after closure.
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$prefix = getenv('DW_PARITY_CORRECTED_PREFIX');
if (! is_string($prefix) || $prefix === '') throw new RuntimeException('Original fixture prefix is required');
$instances = array_map(static fn (string $id): string => $prefix.'-'.$id, [
    'activity-deadline-active-receipts', 'activity-deadline-receipts',
    'activity-global-deadline-retry', 'activity-heartbeat-renewal',
]);
while (true) {
    $activities = \Illuminate\Support\Facades\DB::table('activity_executions as a')
        ->join('workflow_runs as r', 'r.id', '=', 'a.workflow_run_id')
        ->where('r.namespace', 'default')->whereIn('r.workflow_instance_id', $instances)
        ->whereIn('a.status', ['pending', 'running'])->pluck('a.id');
    foreach ($activities as $id) \Workflow\V2\Support\ActivityTimeoutEnforcer::enforce($id);
    usleep(250000);
}
