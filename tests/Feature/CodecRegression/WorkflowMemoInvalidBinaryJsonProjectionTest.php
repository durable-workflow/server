<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Support\ServerCodecRegressionBoundaryV4;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;

final class WorkflowMemoInvalidBinaryJsonProjectionTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNamespace('default');
        config([
            'workflows.v2.types.workflows' => [
                'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
            ],
        ]);
    }

    public function test_workflow_describe_projects_invalid_binary_memo_as_json_safe_bytes(): void
    {
        ServerCodecRegressionFixtureExecutor::exercise(function (): void {
            ServerCodecRegressionBoundaryV4::exerciseWorkflowMemoJsonProjection($this);
        });
    }
}
