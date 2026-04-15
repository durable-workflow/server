<?php

namespace Tests\Fixtures;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;
use function Workflow\V2\activity;

/**
 * PHP workflow fixture for polyglot interop testing.
 *
 * This workflow schedules a Python activity to validate that:
 * 1. PHP workflows can call Python activities
 * 2. JSON payloads round-trip correctly across runtimes
 * 3. Activity results from Python are decoded properly in PHP
 */
#[Type('tests.polyglot.php-workflow')]
class PolyglotPhpWorkflow extends Workflow
{
    public ?string $queue = 'polyglot-queue';

    public function handle(array $data): array
    {
        // Schedule Python activity with structured input
        $pythonResult = activity('tests.polyglot.python-activity', [$data]);

        return [
            'workflow_runtime' => 'php',
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
            'python_activity_result' => $pythonResult,
            'validation' => [
                'called_python_activity' => true,
                'result_is_array' => is_array($pythonResult),
                'result_has_runtime' => isset($pythonResult['runtime']) && $pythonResult['runtime'] === 'python',
            ],
        ];
    }
}
