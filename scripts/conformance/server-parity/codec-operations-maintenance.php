<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$prefix = getenv('DW_PARITY_CODEC_PREFIX');
if (!is_string($prefix) || $prefix==='') throw new RuntimeException('Original CPU fixture prefix is required');
while (true) {
    $activities = \Illuminate\Support\Facades\DB::table('activity_executions as a')
        ->join('workflow_runs as r', 'r.id', '=', 'a.workflow_run_id')
        ->where('r.namespace', 'default')->whereIn('r.workflow_instance_id', [$prefix.'-activity', $prefix.'-renewal'])
        ->whereIn('a.status', ['pending','running'])->pluck('a.id');
    foreach ($activities as $id) \Workflow\V2\Support\ActivityTimeoutEnforcer::enforce($id);
    // Invoke the installed watchdog only once its original cleanup deadline
    // is due. Earlier passes must not repair an observer's retained claim.
    $due = \Illuminate\Support\Facades\DB::table('workflow_runs')
        ->where('namespace', 'default')->where('workflow_instance_id', $prefix.'-cancellation')
        ->whereIn('status', ['pending','running','waiting'])->where('cancellation_deadline_at', '<=', now())->pluck('id')->all();
    if ($due!==[]) \Workflow\V2\TaskWatchdog::runPass(respectThrottle:false, runIds:$due);
    usleep(250000);
}
