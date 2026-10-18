<?php

declare(strict_types=1);

// Only this original's expiry is needed. Explicit workflow repair would alter
// its deliberately retained parent claim and cannot count as normal expiry.
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$prefix = getenv('DW_PARITY_CODEC_PREFIX');
if (!is_string($prefix) || $prefix==='') throw new RuntimeException('Original codec fixture prefix is required');
while (true) {
    $activities = \Illuminate\Support\Facades\DB::table('activity_executions as a')
        ->join('workflow_runs as r', 'r.id', '=', 'a.workflow_run_id')
        ->where('r.namespace', 'default')->where('r.workflow_instance_id', $prefix.'-activity')
        ->whereIn('a.status', ['pending','running'])->pluck('a.id');
    foreach ($activities as $id) \Workflow\V2\Support\ActivityTimeoutEnforcer::enforce($id);
    usleep(250000);
}
