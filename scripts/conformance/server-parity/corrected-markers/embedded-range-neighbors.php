<?php

declare(strict_types=1);

[$script, $root, $phase, $workflowId, $fixturePath, $previousJson] = $argv;
require $root.'/vendor/autoload.php';
require dirname(__DIR__).'/observation-values.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('server.mode') !== 'embedded' || ! in_array($phase, ['before', 'after'], true)) {
    throw new RuntimeException('A real embedded neighboring admission/read is required');
}
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$previous = json_decode($previousJson, true, flags: JSON_THROW_ON_ERROR);

#[\Workflow\V2\Attributes\Type('parity.v1.range_neighbor')]
final class CorrectedRangeNeighbor extends \Workflow\V2\Workflow
{
    public function handle(array $value): array
    {
        return $value;
    }
}
config(['queue.default' => 'database', 'workflows.v2.task_dispatch_mode' => 'queue',
    'workflows.v2.types.workflows' => ['parity.v1.range_neighbor' => CorrectedRangeNeighbor::class]]);
foreach ([\Workflow\V2\Models\WorkflowInstance::class, \Workflow\V2\Models\WorkflowRun::class,
    \Workflow\V2\Models\WorkflowTask::class] as $model) {
    $model::creating(static function ($row): void { $row->namespace = 'default'; });
}
$snapshots = [];
foreach (['left', 'right'] as $side) {
    $id = $workflowId.'-neighbor-'.$side;
    if ($phase === 'before') {
        $stub = \Workflow\V2\WorkflowStub::make(CorrectedRangeNeighbor::class, $id, 'default');
        $stub->start($fixture['input'], new \Workflow\WorkflowOptions(connection: 'database', queue: $id.'-queue'),
            new \Workflow\V2\StartOptions(executionTimeoutSeconds: 3600, runTimeoutSeconds: 600));
        $runId = $stub->runId();
    } else {
        $runId = $previous[$side]['run_id'];
    }
    $run = \Workflow\V2\Models\WorkflowRun::query()->findOrFail($runId);
    $events = $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
        'sequence' => $event->sequence, 'event_type' => $event->event_type->value,
        'timestamp' => $event->recorded_at->toISOString(), 'payload' => $event->payload])->all();
    $snapshots[$side] = ['workflow_id' => $run->workflow_instance_id, 'run_id' => $run->id,
        'namespace' => $run->namespace, 'task_queue' => $run->queue, 'status' => $run->status->value,
        'execution' => $run->toArray(), 'typed_input' => typedValue($run->workflowArguments()),
        'events' => $events, 'tasks' => $run->tasks()->orderBy('id')->get()->map->toArray()->all()];
}
echo json_encode(['pid' => getmypid(), 'neighbors' => $snapshots], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
