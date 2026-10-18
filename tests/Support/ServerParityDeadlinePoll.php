<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;

require getenv('DW_PARITY_SDK_AUTOLOAD');
require __DIR__.'/../../scripts/conformance/server-parity/activity-deadline-poll.php';

[$script, $url, $kind, $budget, $mode, $count] = $argv;
$client = new Client($url, namespace:'default');
$started = microtime(true);
$deadline = $started + (float) $budget;
$retries = [];
$poll = static fn (string $id): array => $kind === 'workflow'
    ? $client->pollWorkflowTaskResponse('original-worker', 'original-queue', 0, $id)
    : $client->pollActivityTaskResponse('original-worker', 'original-queue', 0, $id);
try {
    $tasks = [];
    for ($index = 0; $index < (int) $count; ++$index) {
        $tasks[] = $mode === 'old' ? $poll('old-logical-poll')['task']
            : activityDeadlinePoll($poll, 'original-worker', 'original-queue', $kind, $deadline, $retries);
    }
    echo json_encode(['outcome'=>'pass', 'tasks'=>$tasks, 'retries'=>$retries,
        'started_at'=>$started, 'deadline'=>$deadline], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    echo json_encode(['outcome'=>'refused', 'status'=>$error instanceof ServerException ? $error->status : null,
        'reason'=>$error instanceof ServerException ? $error->reason : $error->getMessage(),
        'retries'=>$retries, 'started_at'=>$started, 'deadline'=>$deadline], JSON_THROW_ON_ERROR)."\n";
}
