<?php

namespace Tests\Unit;

use App\Support\NamespaceExternalPayloadStorage;
use App\Support\WorkflowStartService;
use LogicException;
use Mockery\MockInterface;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\WorkflowControlPlane;

class WorkflowStartServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(NamespaceExternalPayloadStorage::class, function (MockInterface $mock): void {
            $mock->shouldReceive('driverFor')
                ->zeroOrMoreTimes()
                ->andReturn(null);
        });
    }

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

                        $codec = (string) ($options['payload_codec'] ?? '');

                        if ($codec !== CodecRegistry::defaultCodec()) {
                            return false;
                        }

                        return Serializer::unserializeWithCodec($codec, (string) ($options['arguments'] ?? '')) === ['Ada'];
                    }),
                )
                ->andReturn([
                    'started' => true,
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
            'started' => true,
            'workflow_id' => 'wf-service-dotted-1',
            'run_id' => 'run-service-dotted-1',
            'workflow_type' => 'tests.external-greeting-workflow',
            'outcome' => 'started_new',
            'reason' => null,
            'rejection_reason' => null,
            'message' => null,
        ], $start);
    }

    public function test_it_preserves_start_rejection_detail_from_the_control_plane(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $this->mock(NamespaceExternalPayloadStorage::class, function (MockInterface $mock): void {
            $mock->shouldReceive('driverFor')
                ->once()
                ->with(null)
                ->andReturn(null);
        });

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')
                ->once()
                ->with(
                    'tests.external-greeting-workflow',
                    'wf-service-compat-blocked',
                    \Mockery::type('array'),
                )
                ->andReturn([
                    'started' => false,
                    'workflow_instance_id' => 'wf-service-compat-blocked',
                    'workflow_run_id' => null,
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'outcome' => 'rejected_compatibility_blocked',
                    'reason' => 'compatibility_blocked',
                    'message' => 'Workflow instance [wf-service-compat-blocked] cannot start.',
                ]);
        });

        $service = app(WorkflowStartService::class);

        $start = $service->start([
            'workflow_id' => 'wf-service-compat-blocked',
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);

        $this->assertFalse($start['started']);
        $this->assertSame('wf-service-compat-blocked', $start['workflow_id']);
        $this->assertNull($start['run_id']);
        $this->assertSame('tests.external-greeting-workflow', $start['workflow_type']);
        $this->assertSame('rejected_compatibility_blocked', $start['outcome']);
        $this->assertSame('compatibility_blocked', $start['reason']);
        $this->assertSame('compatibility_blocked', $start['rejection_reason']);
        $this->assertSame('Workflow instance [wf-service-compat-blocked] cannot start.', $start['message']);
    }

    public function test_it_preserves_ambient_compatibility_current_for_default_unpinned_starts(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')
                ->once()
                ->with(
                    'tests.external-greeting-workflow',
                    'wf-service-ambient-default',
                    \Mockery::on(static function (array $options): bool {
                        return config('workflows.v2.compatibility.current') === 'build-a'
                            && ! array_key_exists('build_id', $options);
                    }),
                )
                ->andReturn([
                    'started' => true,
                    'workflow_instance_id' => 'wf-service-ambient-default',
                    'workflow_run_id' => 'run-service-ambient-default',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'outcome' => 'started_new',
                    'reason' => null,
                ]);
        });

        $service = app(WorkflowStartService::class);

        $start = $service->start([
            'workflow_id' => 'wf-service-ambient-default',
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);

        $this->assertSame('started_new', $start['outcome']);
        $this->assertSame('build-a', config('workflows.v2.compatibility.current'));
    }

    public function test_it_can_suppress_ambient_compatibility_current_for_unpinned_starts(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $observedCurrent = 'not-called';

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock) use (&$observedCurrent): void {
            $mock->shouldReceive('start')
                ->once()
                ->with(
                    'tests.external-greeting-workflow',
                    'wf-service-ambient-suppressed',
                    \Mockery::on(static function (array $options) use (&$observedCurrent): bool {
                        $observedCurrent = config('workflows.v2.compatibility.current');

                        return $observedCurrent === null
                            && ! array_key_exists('build_id', $options);
                    }),
                )
                ->andReturn([
                    'started' => true,
                    'workflow_instance_id' => 'wf-service-ambient-suppressed',
                    'workflow_run_id' => 'run-service-ambient-suppressed',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'outcome' => 'started_new',
                    'reason' => null,
                ]);
        });

        $service = app(WorkflowStartService::class);

        $start = $service->start(
            [
                'workflow_id' => 'wf-service-ambient-suppressed',
                'workflow_type' => 'tests.external-greeting-workflow',
            ],
            allowAmbientCompatibilityFallback: false,
        );

        $this->assertSame('started_new', $start['outcome']);
        $this->assertNull($observedCurrent);
        $this->assertSame('build-a', config('workflows.v2.compatibility.current'));
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

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')
                ->once()
                ->with(
                    'tests.external-greeting-workflow',
                    'wf-ns-ctx-1',
                    \Mockery::on(static function (array $options): bool {
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
                    'started' => true,
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
                    'started' => true,
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
                    'started' => true,
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

    public function test_it_resolves_the_default_queue_when_the_start_request_omits_task_queue(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.queue', 'critical');
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')
                ->once()
                ->with(
                    'tests.external-greeting-workflow',
                    'wf-default-queue-1',
                    \Mockery::on(static function (array $options): bool {
                        return ($options['queue'] ?? null) === 'critical';
                    }),
                )
                ->andReturn([
                    'started' => true,
                    'workflow_instance_id' => 'wf-default-queue-1',
                    'workflow_run_id' => 'run-default-queue-1',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'outcome' => 'started_new',
                    'reason' => null,
                ]);
        });

        $service = app(WorkflowStartService::class);

        $start = $service->start([
            'workflow_id' => 'wf-default-queue-1',
            'workflow_type' => 'tests.external-greeting-workflow',
        ]);

        $this->assertSame('wf-default-queue-1', $start['workflow_id']);
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
                    'started' => true,
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
                    'started' => true,
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
