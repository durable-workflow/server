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
$run = WorkflowRun::query()->findOrFail($runId);
$entryLimitRaw = getenv('QUALIFICATION_ENTRY_LIMIT');
$entryLimit = $entryLimitRaw === false ? 100 : (int) $entryLimitRaw;
$entries = [];
foreach (HistoryTimeline::iterateFromHistory($run) as $entry) {
    $entries[] = $entry;
    if ($entryLimit > 0 && count($entries) === $entryLimit) {
        break;
    }
}
$updates = 0;
$examples = [];
WorkflowTimelineEntry::updating(static function (WorkflowTimelineEntry $row) use (&$updates, &$examples): void {
    if (! array_key_exists('recorded_at', $row->getDirty())) {
        return;
    }
    $updates++;
    if (count($examples) < 12) {
        $examples[] = [
            'history_event_id' => $row->history_event_id,
            'before_raw' => $row->getRawOriginal('recorded_at'),
            'after_attribute' => $row->getAttributes()['recorded_at'],
            'before_original_cast' => (string) $row->getOriginal('recorded_at'),
            'after_cast' => (string) $row->recorded_at,
        ];
    }
});

$connection = $run->getConnection();
$connection->beginTransaction();
try {
    $run->forceFill(['status' => RunStatus::Waiting]);
    RunTimelineProjector::project($run, $entries, collectRows: false);
} finally {
    $connection->rollBack();
}
echo json_encode(['entries' => count($entries), 'updated_rows' => $updates, 'examples' => $examples], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
