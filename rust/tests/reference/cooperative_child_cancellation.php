<?php
declare(strict_types=1);

// Run in the exact published Server image, with SQLite :memory: configuration.
// Arguments are its Composer autoloader and JSONL captured by execution tests
// through DW_TEST_CHILD_CANCELLATION_REFERENCE. Models use loaded history only.
require $argv[1];
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\CooperativeCancellationDelivery;
use Workflow\V2\Support\ChildCancellation;

$count = 0;
DB::connection()->enableQueryLog();
foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $capture = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $metadata = $capture['summary'];
    $run = new WorkflowRun([
        'id' => $metadata['run_id'],
        'workflow_instance_id' => $metadata['workflow_id'],
        'status' => 'running',
        'cancellation_request_command_id' => $metadata['request']['request_id'],
        'cancellation_delivery_sequence' => null,
    ]);
    $events = [];
    foreach ($capture['raw_history'] as $index => $stored) {
        $event = $capture['history']['events'][$index];
        if ($event['event_type'] !== $stored['event_type']) {
            throw new RuntimeException('Stored and public history order differ.');
        }
        $payload = json_decode($stored['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $events[] = new WorkflowHistoryEvent([
            'id' => $stored['id'],
            'workflow_run_id' => $metadata['run_id'],
            'event_type' => $event['event_type'],
            'sequence' => $event['sequence'],
            'payload' => $payload,
            'workflow_command_id' => $payload['workflow_command_id'] ?? null,
        ]);
    }
    $run->setRelation('historyEvents', new Illuminate\Database\Eloquent\Collection($events));
    $actual = CooperativeCancellationDelivery::validate($run, $metadata['request']['request_id'],
        $metadata['sequence'], $metadata['call_kind']);
    if ($actual !== $metadata['expected_reason']) {
        throw new RuntimeException('Published delivery mismatch: '.json_encode([$metadata, $actual]));
    }
    if (ChildCancellation::prepare($run, new WorkflowTask(), 1) !== false) {
        throw new RuntimeException('Abandoned child unexpectedly waits for cancellation.');
    }
    ++$count;
}
$queries = count(DB::connection()->getQueryLog());
if ($count === 0 || $queries !== 0) {
    throw new RuntimeException('Expected nonempty history-only references without database queries.');
}
$hashes = [];
foreach ([CooperativeCancellationDelivery::class, ChildCancellation::class, WorkflowRun::class, WorkflowHistoryEvent::class] as $class) {
    $hashes[$class] = hash_file('sha256', (new ReflectionClass($class))->getFileName());
}
echo json_encode(['captures'=>$count, 'queries'=>$queries, 'php'=>PHP_VERSION, 'hashes'=>$hashes], JSON_THROW_ON_ERROR), "\n";
