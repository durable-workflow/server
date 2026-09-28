<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\HistoryTimeline;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$runId = getenv('QUALIFICATION_RUN_ID');
if (! is_string($runId) || $runId === '') {
    throw new RuntimeException('QUALIFICATION_RUN_ID is required.');
}
$run = WorkflowRun::query()->with([
    'historyEvents', 'commands', 'tasks', 'activityExecutions', 'timers', 'failures',
])->findOrFail($runId);
$canonical = HistoryTimeline::fromHistory($run);
$terminalEvent = $run->historyEvents->sortByDesc('sequence')->first();
$terminalPayload = is_array($terminalEvent?->payload) ? $terminalEvent->payload : [];
$terminalProjection = WorkflowTimelineEntry::query()
    ->where('workflow_run_id', $runId)->orderByDesc('sequence')->first();
$projected = WorkflowTimelineEntry::query()->where('workflow_run_id', $runId)
    ->orderBy('sequence')->orderBy('history_event_id')->get()
    ->map(fn (WorkflowTimelineEntry $entry): array => $entry->toTimelinePayload())->all();

$normalize = function (mixed $value) use (&$normalize): mixed {
    if ($value instanceof \Carbon\CarbonInterface) {
        return $value->toJSON();
    }
    if (! is_array($value)) {
        return $value;
    }
    if (! array_is_list($value)) {
        ksort($value);
    }
    return array_map($normalize, $value);
};
$differences = [];
foreach ($canonical as $index => $expected) {
    $actual = $projected[$index] ?? [];
    if ($normalize($expected) === $normalize($actual)) {
        continue;
    }
    $fields = [];
    foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
        $a = $normalize($actual[$key] ?? null);
        $b = $normalize($expected[$key] ?? null);
        if ($a !== $b) {
            $fields[$key] = [
                'actual_type' => get_debug_type($a),
                'expected_type' => get_debug_type($b),
                'actual_sha256' => hash('sha256', json_encode($a, JSON_THROW_ON_ERROR)),
                'expected_sha256' => hash('sha256', json_encode($b, JSON_THROW_ON_ERROR)),
                'actual_scalar' => is_scalar($a) ? substr((string) $a, 0, 100) : null,
                'expected_scalar' => is_scalar($b) ? substr((string) $b, 0, 100) : null,
            ];
        }
    }
    $differences[] = [
        'index' => $index,
        'sequence' => $expected['sequence'] ?? null,
        'type' => $expected['type'] ?? null,
        'fields' => $fields,
    ];
    if (count($differences) >= 5) {
        break;
    }
}
echo json_encode([
    'run_id' => $runId,
    'terminal_event' => [
        'type' => $terminalEvent?->event_type?->value,
        'workflow_command_id' => $terminalEvent?->workflow_command_id,
        'payload_keys' => array_keys($terminalPayload),
        'payload_command_type' => $terminalPayload['command']['type'] ?? null,
        'payload_command_type_field' => $terminalPayload['command_type'] ?? null,
        'created_at' => $terminalEvent?->created_at?->toIso8601String(),
        'updated_at' => $terminalEvent?->updated_at?->toIso8601String(),
    ],
    'terminal_projection' => [
        'created_at' => $terminalProjection?->created_at?->toIso8601String(),
        'updated_at' => $terminalProjection?->updated_at?->toIso8601String(),
        'payload_keys' => array_keys(is_array($terminalProjection?->payload) ? $terminalProjection->payload : []),
    ],
    'canonical_count' => count($canonical),
    'projected_count' => count($projected),
    'first_differences' => $differences,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
