<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\RunTimelineProjector;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$runId = getenv('QUALIFICATION_RUN_ID');
if (! is_string($runId) || $runId === '') {
    throw new RuntimeException('QUALIFICATION_RUN_ID is required.');
}

/** @var WorkflowRun $run */
$run = WorkflowRun::query()->findOrFail($runId);
$entryLimit = 100;
$entries = [];
foreach (HistoryTimeline::iterateFromHistory($run) as $entry) {
    $entries[] = $entry;
    if (count($entries) === $entryLimit) {
        break;
    }
}

function canonical(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    if (! array_is_list($value)) {
        ksort($value);
    }
    foreach ($value as $key => $nested) {
        $value[$key] = canonical($nested);
    }
    return $value;
}

$updatedRows = 0;
$dirtyColumns = [];
$payloadSemanticEqual = 0;
$payloadSemanticDifferent = 0;
WorkflowTimelineEntry::updating(static function (WorkflowTimelineEntry $row) use (
    &$updatedRows,
    &$dirtyColumns,
    &$payloadSemanticEqual,
    &$payloadSemanticDifferent,
): void {
    $updatedRows++;
    foreach (array_keys($row->getDirty()) as $column) {
        $dirtyColumns[$column] = ($dirtyColumns[$column] ?? 0) + 1;
    }
    if (array_key_exists('payload', $row->getDirty())) {
        $before = json_decode((string) $row->getRawOriginal('payload'), true);
        $after = json_decode((string) $row->getAttributes()['payload'], true);
        if (canonical($before) === canonical($after)) {
            $payloadSemanticEqual++;
        } else {
            $payloadSemanticDifferent++;
        }
    }
});

$connection = $run->getConnection();
$connection->beginTransaction();
try {
    // A limited diagnostic pass must not prune all other rows on a terminal run.
    $run->forceFill(['status' => RunStatus::Waiting]);
    $started = microtime(true);
    RunTimelineProjector::project($run, $entries, collectRows: false);
    $elapsed = microtime(true) - $started;
} finally {
    $connection->rollBack();
}

echo json_encode([
    'run_id' => $runId,
    'entry_limit' => $entryLimit,
    'updated_rows' => $updatedRows,
    'dirty_columns' => $dirtyColumns,
    'payload_semantically_equal' => $payloadSemanticEqual,
    'payload_semantically_different' => $payloadSemanticDifferent,
    'elapsed_seconds' => $elapsed,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
