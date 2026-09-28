<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Workflow\V2\Support\RunSummaryProjectionDrift;
use Workflow\V2\Support\SelectedRunProjectionDrift;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$runId = getenv('QUALIFICATION_RUN_ID');
if (! is_string($runId) || $runId === '') {
    throw new RuntimeException('QUALIFICATION_RUN_ID is required.');
}
$ids = [$runId];
echo json_encode([
    'run_id' => $runId,
    'stale_summary' => RunSummaryProjectionDrift::staleSummaryQuery($ids)->exists(),
    'schema_outdated' => RunSummaryProjectionDrift::schemaOutdatedQuery($ids)->exists(),
    'waits' => SelectedRunProjectionDrift::waitRunIdsNeedingRebuild($ids),
    'timeline' => SelectedRunProjectionDrift::timelineRunIdsNeedingRebuild($ids),
    'timers' => SelectedRunProjectionDrift::timerRunIdsNeedingRebuild($ids),
    'lineage' => SelectedRunProjectionDrift::lineageRunIdsNeedingRebuild($ids),
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
