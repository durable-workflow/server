<?php

declare(strict_types=1);

// Compare actual source transitions with published bridge/HTTP normalization and FailureFactory.
// No native process, installed consumer execution, or reference database.
require $argv[1];

$promote = new ReflectionMethod(App\Http\Controllers\Api\WorkerController::class, 'promoteWorkflowFailureExceptionPayload');
$controller = (new ReflectionClass(App\Http\Controllers\Api\WorkerController::class))->newInstanceWithoutConstructor();
$normalize = new ReflectionMethod(Workflow\V2\Support\DefaultWorkflowTaskBridge::class, 'normalizeFailWorkflowCommand');
$count = 0;
foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    foreach ($case['raw_history'] as $event) {
        if ($event['event_type'] !== 'WorkflowFailed') {
            continue;
        }
        $command = $normalize->invoke(null, $promote->invoke($controller, [$case['summary']['reference_failure_command']])[0]);
        $actual = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $structured = $command['exception'] ?? [];
        $class = trim($structured['class'] ?? '') ?: ($command['exception_class'] ?? RuntimeException::class);
        $message = $command['message'];
        $type = trim($structured['type'] ?? '') ?: ($command['exception_type'] ?? null);
        $expected = [
            'source_kind' => 'workflow_run', 'source_id' => $case['summary']['run_id'],
            'failure_category' => Workflow\V2\Support\FailureFactory::classifyFromStrings('terminal', 'workflow_run', $class, $message)->value,
            'non_retryable' => $command['non_retryable'] ?? Workflow\V2\Support\FailureFactory::isNonRetryableFromStrings($class),
            'exception_class' => $class, 'exception_type' => $type, 'message' => $message,
            'exception' => array_replace([
                'class' => $class, 'type' => $type, 'message' => trim($structured['message'] ?? '') ?: $message,
                'code' => 0, 'file' => '', 'line' => 0, 'trace' => [], 'properties' => [],
            ], $structured),
        ];
        foreach ($expected as $field => $value) {
            if ($actual[$field] !== $value) {
                throw new RuntimeException($case['summary']['run_id'].' differs from published failure field '.$field);
            }
        }
        ++$count;
    }
}
if ($count === 0) {
    throw new RuntimeException('Expected actual terminal workflow failure evidence.');
}
echo json_encode(['outcome' => 'pass', 'cases' => $count, 'php' => PHP_VERSION,
    'workflow' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow'), 'database_queries' => 0], JSON_THROW_ON_ERROR)."\n";
