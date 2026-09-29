<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$runId = getenv('QUALIFICATION_RUN_ID');
if (! is_string($runId) || $runId === '') {
    throw new RuntimeException('QUALIFICATION_RUN_ID is required');
}

$run = Workflow\V2\Models\WorkflowRun::query()->findOrFail($runId);
$component = getenv('QUALIFICATION_COMPONENT') ?: 'summary';
$started = microtime(true);
$project = static function () use ($component, $run): void {
    match ($component) {
        'budget' => Workflow\V2\Support\HistoryBudget::forRun($run),
        'timeline_map' => Workflow\V2\Support\HistoryTimeline::fromHistory($run),
        'timeline_project' => Workflow\V2\Support\RunTimelineProjector::project($run),
        'summary' => Workflow\V2\Support\RunSummaryProjector::project($run),
        default => throw new InvalidArgumentException('Unknown component'),
    };
};
if (getenv('QUALIFICATION_TRANSACTION') === '1') {
    Illuminate\Support\Facades\DB::transaction($project);
} else {
    $project();
}
echo json_encode([
    'schema' => 'server-237-projector-peak-v1',
    'run_id' => $runId,
    'component' => $component,
    'transaction' => getenv('QUALIFICATION_TRANSACTION') === '1',
    'elapsed_seconds' => microtime(true) - $started,
    'peak_php_allocated_bytes' => memory_get_peak_usage(true),
    'php_memory_limit' => ini_get('memory_limit'),
], JSON_THROW_ON_ERROR), PHP_EOL;
