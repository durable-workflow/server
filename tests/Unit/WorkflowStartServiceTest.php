<?php

namespace Tests\Unit;

use App\Support\WorkflowStartService;
use LogicException;
use Mockery\MockInterface;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
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

                        if (($options['search_attributes'] ?? null) !== ['tenant' => 'acme']) {
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
        config()->set('server.mode', 'embedded');
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

    public function test_it_passes_namespace_and_command_context_to_the_control_plane(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $commandContext = CommandContext::controlPlane()->with([
            'caller' => ['type' => 'server', 'label' => 'Standalone Server'],
            'server' => ['namespace' => 'production', 'workflow_id' => 'wf-ns-ctx-1', 'command' => 'start'],
        ]);

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock) use ($commandContext): void {
            $mock->shouldReceive('start')
                ->once()
                ->with(
                    'tests.external-greeting-workflow',
                    'wf-ns-ctx-1',
                    \Mockery::on(static function (array $options) use ($commandContext): bool {
                        if (($options['namespace'] ?? null) !== 'production') {
                            return false;
                        }

                        if (! ($options['command_context'] ?? null) instanceof CommandContext) {
                            return false;
                        }

                        $contextAttrs = $options['command_context']->attributes();
                        if (($contextAttrs['context']['server']['namespace'] ?? null) !== 'production') {
                            return false;
                        }

                        return ($contextAttrs['context']['server']['command'] ?? null) === 'start';
                    }),
                )
                ->andReturn([
                    'workflow_instance_id' => 'wf-ns-ctx-1',
                    'workflow_run_id' => 'run-ns-ctx-1',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'outcome' => 'started_new',
                    'reason' => null,
                ]);
        });

        $service = app(WorkflowStartService::class);

        $start = $service->start(
            [
                'workflow_id' => 'wf-ns-ctx-1',
                'workflow_type' => 'tests.external-greeting-workflow',
            ],
            'production',
            $commandContext,
        );

        $this->assertSame('wf-ns-ctx-1', $start['workflow_id']);
        $this->assertSame('run-ns-ctx-1', $start['run_id']);
        $this->assertSame('started_new', $start['outcome']);
    }

    public function test_it_omits_namespace_and_command_context_from_options_when_not_provided(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')
                ->once()
                ->with(
                    'tests.external-greeting-workflow',
                    null,
                    \Mockery::on(static function (array $options): bool {
                        // namespace and command_context should be filtered out (null values)
                        return ! array_key_exists('namespace', $options)
                            && ! array_key_exists('command_context', $options);
                    }),
                )
                ->andReturn([
                    'workflow_instance_id' => 'auto-id',
                    'workflow_run_id' => 'auto-run',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'outcome' => 'started_new',
                    'reason' => null,
                ]);
        });

        $service = app(WorkflowStartService::class);

        $service->start([
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);
    }

    public function test_it_passes_execution_and_run_timeout_seconds_to_the_control_plane(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')
                ->once()
                ->with(
                    'tests.external-greeting-workflow',
                    'wf-timeout-1',
                    \Mockery::on(static function (array $options): bool {
                        return ($options['execution_timeout_seconds'] ?? null) === 300
                            && ($options['run_timeout_seconds'] ?? null) === 120;
                    }),
                )
                ->andReturn([
                    'workflow_instance_id' => 'wf-timeout-1',
                    'workflow_run_id' => 'run-timeout-1',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'outcome' => 'started_new',
                    'reason' => null,
                ]);
        });

        $service = app(WorkflowStartService::class);

        $start = $service->start([
            'workflow_id' => 'wf-timeout-1',
            'workflow_type' => 'tests.external-greeting-workflow',
            'execution_timeout_seconds' => 300,
            'run_timeout_seconds' => 120,
        ]);

        $this->assertSame('wf-timeout-1', $start['workflow_id']);
        $this->assertSame('started_new', $start['outcome']);
    }

    public function test_it_omits_timeout_seconds_from_options_when_not_provided(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')
                ->once()
                ->with(
                    'tests.external-greeting-workflow',
                    null,
                    \Mockery::on(static function (array $options): bool {
                        return ! array_key_exists('execution_timeout_seconds', $options)
                            && ! array_key_exists('run_timeout_seconds', $options);
                    }),
                )
                ->andReturn([
                    'workflow_instance_id' => 'auto-id',
                    'workflow_run_id' => 'auto-run',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'outcome' => 'started_new',
                    'reason' => null,
                ]);
        });

        $service = app(WorkflowStartService::class);

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
