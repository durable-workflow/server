<?php
declare(strict_types=1);

require '/app/vendor/autoload.php';

// Run only in the pinned published image. No handler, queue or database runs.
$classes = [
    'workflow' => Workflow\V2\Jobs\RunWorkflowTask::class,
    'activity' => Workflow\V2\Jobs\RunActivityTask::class,
    'timer' => Workflow\V2\Jobs\RunTimerTask::class,
];
$cases = [];
foreach ($classes as $kind => $class) {
    $job = new $class('01m4z62k0n2hhv9nv95kmb1rcr');
    if ($kind === 'activity') {
        $job->onConnection('database')->onQueue('upgradeλ');
    }
    if ($kind === 'timer') {
        $job->delay(Illuminate\Support\Carbon::parse('2026-10-15T07:15:26.289171Z'));
    }
    $cases[] = ['kind' => $kind, 'task_id' => $job->taskId, 'command' => serialize($job)];
}
echo json_encode([
    'source_image' => 'ghcr.io/durable-workflow/server@sha256:cba7c4c6732529293ef6c2625a64785dcb21b4f7fe0013fdc74f1af466f534ac',
    'scope' => 'published_classes_serialized_without_dispatch_or_database_admission',
    'cases' => $cases,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
