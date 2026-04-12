<?php

namespace Tests\Unit;

use App\Support\WorkflowStartService;
use LogicException;
use Mockery\MockInterface;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowControlPlane;

class WorkflowStartServiceTest extends TestCase
{
    public function test_it_routes_configured_dotted_workflow_types_through_the_package_control_plane(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')
                ->once()
                ->with(
                    'tests.external-greeting-workflow',
                    'wf-service-dotted-1',
                    \Mockery::on(static function (array $options): bool {
                        if (($options['queue'] ?? null) !== 'external-workflows') {
                            return false;
                        }

                        if (($options['labels'] ?? null) !== ['tenant' => 'acme']) {
                            return false;
                        }

                        if (($options['memo'] ?? null) !== ['source' => 'api']) {
                            return false;
                        }

                        if (($options['business_key'] ?? null) !== 'order-123') {
                            return false;
                        }

                        if (($options['duplicate_start_policy'] ?? null) !== 'return_existing_active') {
                            return false;
                        }

                        return Serializer::unserialize((string) ($options['arguments'] ?? '')) === ['Ada'];
                    }),
                )
                ->andReturn([
                    'workflow_instance_id' => 'wf-service-dotted-1',
                    'workflow_run_id' => 'run-service-dotted-1',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'outcome' => 'started_new',
                    'reason' => null,
                ]);
        });

        $service = app(WorkflowStartService::class);

        $start = $service->start([
            'workflow_id' => 'wf-service-dotted-1',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'external-workflows',
            'input' => ['Ada'],
            'business_key' => 'order-123',
            'memo' => ['source' => 'api'],
            'search_attributes' => ['tenant' => 'acme'],
            'duplicate_policy' => 'use-existing',
        ]);

        $this->assertSame([
            'workflow_id' => 'wf-service-dotted-1',
            'run_id' => 'run-service-dotted-1',
            'workflow_type' => 'tests.external-greeting-workflow',
            'outcome' => 'started_new',
            'reason' => null,
        ], $start);
    }

    public function test_it_rejects_invalid_configured_dotted_workflow_type_mappings_before_control_plane_start(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => 'App\\Missing\\Workflow',
        ]);

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('start');
        });

        $service = app(WorkflowStartService::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Configured durable workflow type [tests.external-greeting-workflow] points to [App\\Missing\\Workflow], which is not a loadable workflow class.'
        );

        $service->start([
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);
    }

    public function test_it_no_longer_translates_the_legacy_underscore_duplicate_policy_alias(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')
                ->once()
                ->with(
                    'tests.external-greeting-workflow',
                    'wf-service-dotted-legacy-alias',
                    \Mockery::on(static function (array $options): bool {
                        return ($options['duplicate_start_policy'] ?? null) === 'reject_duplicate';
                    }),
                )
                ->andReturn([
                    'workflow_instance_id' => 'wf-service-dotted-legacy-alias',
                    'workflow_run_id' => 'run-service-dotted-legacy-alias',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'outcome' => 'started_new',
                    'reason' => null,
                ]);
        });

        $service = app(WorkflowStartService::class);

        $start = $service->start([
            'workflow_id' => 'wf-service-dotted-legacy-alias',
            'workflow_type' => 'tests.external-greeting-workflow',
            'duplicate_policy' => 'use_existing',
        ]);

        $this->assertSame('wf-service-dotted-legacy-alias', $start['workflow_id']);
        $this->assertSame('run-service-dotted-legacy-alias', $start['run_id']);
        $this->assertSame('started_new', $start['outcome']);
    }
}
