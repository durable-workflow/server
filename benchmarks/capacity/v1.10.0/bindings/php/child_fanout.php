<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;

require __DIR__.'/vendor/autoload.php';

const PARENT_TYPE = 'capacity.v1_10.child_parent';
const LEAF_TYPE = 'capacity.v1_10.child_leaf';
const PARENT_QUEUE = 'capacity-v1-10-parent';
const LEAF_QUEUE = 'capacity-v1-10-leaf';

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new InvalidArgumentException("Set {$name} for the isolated capacity runtime.");
    }

    return $value;
}

function fanOut(WorkflowContext $context, int $width): int
{
    if ($width !== 10 && $width !== 1000) {
        throw new InvalidArgumentException('Use width 10 for the control or width 1000 for the capacity cell.');
    }

    $children = [];
    $expected = range(1, $width);
    foreach ($expected as $index) {
        $children[] = $context->deferChildWorkflow(LEAF_TYPE, [$index], ['queue' => LEAF_QUEUE]);
    }

    $results = $context->all($children);
    if ($results !== $expected) {
        throw new RuntimeException('The child barrier returned unexpected values or ordering.');
    }

    return array_sum($results);
}

$client = new Client(
    requiredEnvironment('DURABLE_WORKFLOW_RUNTIME_URL'),
    namespace: getenv('DURABLE_WORKFLOW_NAMESPACE') ?: 'default',
    token: requiredEnvironment('DURABLE_WORKFLOW_TOKEN'),
);

$reportRegistration = static function (string $event): void {
    if ($event === 'worker.registered') {
        fwrite(STDERR, "registered\n");
    }
};

switch ($argv[1] ?? '') {
    case 'parent':
        Worker::create($client, PARENT_QUEUE, diagnosticListener: $reportRegistration)
            ->registerWorkflow(PARENT_TYPE, fanOut(...))
            ->run(1);
        break;
    case 'leaf':
        Worker::create($client, LEAF_QUEUE, diagnosticListener: $reportRegistration)
            ->registerWorkflow(LEAF_TYPE, static fn (WorkflowContext $context, int $index): int => $index)
            ->run(1);
        break;
    case 'run':
        $width = match ($argv[2] ?? null) {
            '10' => 10,
            '1000' => 1000,
            default => throw new InvalidArgumentException('Expected width 10 or 1000.'),
        };
        $workflowId = $argv[3] ?? throw new InvalidArgumentException('Provide a unique synthetic workflow ID.');
        $start = hrtime(true);
        $handle = $client->startWorkflow(PARENT_TYPE, $workflowId, PARENT_QUEUE, [$width]);
        $result = $handle->resultOfSelectedRun(240, 0.25);
        if ($result !== intdiv($width * ($width + 1), 2)) {
            throw new RuntimeException('The parent completed with the wrong result.');
        }
        echo json_encode([
            'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
            'workflow_id' => $workflowId,
            'run_id' => $handle->selectedRunId,
            'width' => $width,
            'result' => $result,
            'elapsed_ms' => (hrtime(true) - $start) / 1_000_000,
        ], JSON_THROW_ON_ERROR), PHP_EOL;
        break;
    default:
        throw new InvalidArgumentException('Expected parent, leaf, or run.');
}
