<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\NamespaceExternalPayloadStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Support\ExternalPayloads;

class NamespaceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders([
            'X-Durable-Workflow-Control-Plane-Version' => ControlPlaneProtocol::VERSION,
        ]);
    }

    public function test_it_lists_namespaces(): void
    {
        WorkflowNamespace::create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        WorkflowNamespace::create([
            'name' => 'staging',
            'description' => 'Staging namespace',
            'retention_days' => 7,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/namespaces');

        $response->assertOk()
            ->assertJsonCount(2, 'namespaces')
            ->assertJsonPath('namespaces.0.name', 'default')
            ->assertJsonPath('namespaces.0.retention_days', 30)
            ->assertJsonPath('namespaces.1.name', 'staging')
            ->assertJsonPath('namespaces.1.retention_days', 7);
    }

    public function test_it_returns_empty_list_when_no_namespaces_exist(): void
    {
        $response = $this->getJson('/api/namespaces');

        $response->assertOk()
            ->assertJsonCount(0, 'namespaces');
    }

    public function test_it_creates_a_namespace(): void
    {
        $response = $this->postJson('/api/namespaces', [
            'name' => 'production',
            'description' => 'Production environment',
            'retention_days' => 90,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'production')
            ->assertJsonPath('description', 'Production environment')
            ->assertJsonPath('retention_mode', 'bounded')
            ->assertJsonPath('retention_days', 90)
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('workflow_namespaces', [
            'name' => 'production',
            'description' => 'Production environment',
            'retention_mode' => 'bounded',
            'retention_days' => 90,
            'status' => 'active',
        ]);
    }

    public function test_it_creates_a_namespace_with_default_retention(): void
    {
        $response = $this->postJson('/api/namespaces', [
            'name' => 'minimal',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'minimal')
            ->assertJsonPath('retention_mode', 'bounded')
            ->assertJsonPath('retention_days', config('server.history.retention_days'));
    }

    public function test_it_exposes_forever_retention_consistently_across_namespace_surfaces(): void
    {
        $created = $this->postJson('/api/namespaces', [
            'name' => 'protected',
            'description' => 'Protected history',
            'retention_mode' => 'forever',
        ]);

        $created->assertCreated()
            ->assertJsonPath('retention_mode', 'forever')
            ->assertJsonPath('retention_days', null);

        $this->getJson('/api/namespaces/protected')
            ->assertOk()
            ->assertJsonPath('retention_mode', 'forever')
            ->assertJsonPath('retention_days', null);

        $this->getJson('/api/namespaces')
            ->assertOk()
            ->assertJsonPath('namespaces.0.retention_mode', 'forever')
            ->assertJsonPath('namespaces.0.retention_days', null);

        $this->putJson('/api/namespaces/protected', [
            'description' => 'Protected history updated',
        ])
            ->assertOk()
            ->assertJsonPath('retention_mode', 'forever')
            ->assertJsonPath('retention_days', null);

        $this->assertDatabaseHas('workflow_namespaces', [
            'name' => 'protected',
            'retention_mode' => 'forever',
            'retention_days' => null,
        ]);
    }

    public function test_it_validates_retention_mode_and_day_combinations(): void
    {
        $this->postJson('/api/namespaces', [
            'name' => 'invalid-mode',
            'retention_mode' => 'indefinite',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('retention_mode');

        $this->postJson('/api/namespaces', [
            'name' => 'contradictory-forever',
            'retention_mode' => 'forever',
            'retention_days' => 365,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('retention_days');

        $this->postJson('/api/namespaces', [
            'name' => 'contradictory-bounded',
            'retention_mode' => 'bounded',
            'retention_days' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('retention_days');
    }

    public function test_it_normalizes_mixed_case_namespace_names_to_lowercase(): void
    {
        $response = $this->postJson('/api/namespaces', [
            'name' => 'Production',
            'description' => 'Mixed case input',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'production');

        $this->assertDatabaseHas('workflow_namespaces', [
            'name' => 'production',
        ]);

        $stored = WorkflowNamespace::first();
        $this->assertSame('production', $stored->name);
    }

    public function test_mixed_case_duplicate_is_rejected(): void
    {
        $this->postJson('/api/namespaces', ['name' => 'production']);

        $response = $this->postJson('/api/namespaces', ['name' => 'Production']);

        $response->assertStatus(409);
    }

    public function test_show_resolves_mixed_case_namespace_parameter(): void
    {
        WorkflowNamespace::create([
            'name' => 'production',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/namespaces/Production');

        $response->assertOk()
            ->assertJsonPath('name', 'production');
    }

    public function test_it_rejects_namespace_creation_without_a_name(): void
    {
        $response = $this->postJson('/api/namespaces', [
            'description' => 'Missing name',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_it_rejects_namespace_names_with_invalid_characters(): void
    {
        $response = $this->postJson('/api/namespaces', [
            'name' => 'invalid namespace!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_it_rejects_namespace_names_exceeding_max_length(): void
    {
        $response = $this->postJson('/api/namespaces', [
            'name' => str_repeat('a', 129),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_it_rejects_duplicate_namespace_names(): void
    {
        WorkflowNamespace::create([
            'name' => 'existing',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/namespaces', [
            'name' => 'existing',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Namespace already exists.')
            ->assertJsonPath('namespace', 'existing');
    }

    public function test_it_rejects_retention_days_out_of_range(): void
    {
        $response = $this->postJson('/api/namespaces', [
            'name' => 'bad-retention',
            'retention_days' => 0,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('retention_days');

        $response = $this->postJson('/api/namespaces', [
            'name' => 'bad-retention',
            'retention_days' => 366,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('retention_days');
    }

    public function test_it_shows_a_namespace(): void
    {
        WorkflowNamespace::create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/namespaces/default');

        $response->assertOk()
            ->assertJsonPath('name', 'default')
            ->assertJsonPath('description', 'Default namespace')
            ->assertJsonPath('retention_days', 30)
            ->assertJsonPath('status', 'active')
            ->assertJsonStructure(['created_at', 'updated_at']);
    }

    public function test_it_returns_404_for_unknown_namespace(): void
    {
        $response = $this->getJson('/api/namespaces/nonexistent');

        $response->assertNotFound();
    }

    public function test_it_updates_a_namespace_description(): void
    {
        WorkflowNamespace::create([
            'name' => 'default',
            'description' => 'Original',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->putJson('/api/namespaces/default', [
            'description' => 'Updated description',
        ]);

        $response->assertOk()
            ->assertJsonPath('name', 'default')
            ->assertJsonPath('description', 'Updated description')
            ->assertJsonPath('retention_days', 30);

        $this->assertDatabaseHas('workflow_namespaces', [
            'name' => 'default',
            'description' => 'Updated description',
        ]);
    }

    public function test_it_updates_a_namespace_retention_days(): void
    {
        WorkflowNamespace::create([
            'name' => 'default',
            'description' => 'Default',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->putJson('/api/namespaces/default', [
            'retention_days' => 90,
        ]);

        $response->assertOk()
            ->assertJsonPath('retention_days', 90);

        $this->assertDatabaseHas('workflow_namespaces', [
            'name' => 'default',
            'retention_days' => 90,
        ]);
    }

    public function test_it_requires_days_when_changing_forever_retention_to_bounded(): void
    {
        WorkflowNamespace::create([
            'name' => 'protected',
            'retention_mode' => 'forever',
            'retention_days' => null,
            'status' => 'active',
        ]);

        $this->putJson('/api/namespaces/protected', [
            'retention_mode' => 'bounded',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('retention_days');

        $this->putJson('/api/namespaces/protected', [
            'retention_mode' => 'bounded',
            'retention_days' => 45,
        ])
            ->assertOk()
            ->assertJsonPath('retention_mode', 'bounded')
            ->assertJsonPath('retention_days', 45);

        $this->putJson('/api/namespaces/protected', [
            'retention_mode' => 'forever',
            'retention_days' => 45,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('retention_days');
    }

    public function test_namespace_persistence_rejects_contradictory_forever_retention(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WorkflowNamespace::create([
            'name' => 'invalid-persistence',
            'retention_mode' => 'forever',
            'retention_days' => 30,
            'status' => 'active',
        ]);
    }

    public function test_it_returns_404_when_updating_unknown_namespace(): void
    {
        $response = $this->putJson('/api/namespaces/nonexistent', [
            'description' => 'Update',
        ]);

        $response->assertNotFound();
    }

    public function test_it_rejects_update_with_invalid_retention(): void
    {
        WorkflowNamespace::create([
            'name' => 'default',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->putJson('/api/namespaces/default', [
            'retention_days' => 0,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('retention_days');
    }

    public function test_it_deletes_namespace_and_owned_runtime_state(): void
    {
        WorkflowNamespace::create([
            'name' => 'tenant-a',
            'retention_mode' => 'forever',
            'retention_days' => null,
            'status' => 'active',
        ]);
        WorkflowNamespace::create([
            'name' => 'tenant-b',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $tenantARun = $this->runtimeState('tenant-a', 'wf-tenant-a');
        $tenantBRun = $this->runtimeState('tenant-b', 'wf-tenant-b');

        $response = $this->deleteJson('/api/namespaces/tenant-a');

        $response->assertOk()
            ->assertJsonPath('name', 'tenant-a')
            ->assertJsonPath('status', 'deleted')
            ->assertJsonPath('deleted.workflow_runs', 1)
            ->assertJsonPath('deleted.workflow_schedules', 1)
            ->assertJsonPath('deleted.search_attribute_definitions', 1)
            ->assertJsonPath('deleted.workflow_worker_registrations', 1);

        $this->assertDatabaseMissing('workflow_namespaces', ['name' => 'tenant-a']);
        $this->assertDatabaseMissing('workflow_instances', ['namespace' => 'tenant-a']);
        $this->assertDatabaseMissing('workflow_runs', ['namespace' => 'tenant-a']);
        $this->assertDatabaseMissing('workflow_run_summaries', ['namespace' => 'tenant-a']);
        $this->assertDatabaseMissing('workflow_tasks', ['namespace' => 'tenant-a']);
        $this->assertDatabaseMissing('workflow_schedules', ['namespace' => 'tenant-a']);
        $this->assertDatabaseMissing('search_attribute_definitions', ['namespace' => 'tenant-a']);
        $this->assertDatabaseMissing('workflow_search_attributes', ['workflow_run_id' => $tenantARun]);
        $this->assertDatabaseMissing('workflow_worker_registrations', ['namespace' => 'tenant-a']);
        $this->assertDatabaseMissing('workflow_worker_sessions', ['namespace' => 'tenant-a']);
        $this->assertDatabaseMissing('workflow_worker_build_id_rollouts', ['namespace' => 'tenant-a']);
        $this->assertDatabaseMissing('workflow_durable_streams', ['namespace' => 'tenant-a']);

        $this->assertDatabaseHas('workflow_namespaces', ['name' => 'tenant-b']);
        $this->assertDatabaseHas('workflow_runs', ['id' => $tenantBRun, 'namespace' => 'tenant-b']);
        $this->assertDatabaseHas('workflow_schedules', ['namespace' => 'tenant-b']);
        $this->assertDatabaseHas('search_attribute_definitions', ['namespace' => 'tenant-b']);

        $this->postJson('/api/namespaces', [
            'name' => 'tenant-a',
        ])->assertCreated();

        $this->assertDatabaseHas('workflow_namespaces', ['name' => 'tenant-a']);
        $this->assertDatabaseMissing('workflow_runs', ['workflow_instance_id' => 'wf-tenant-a']);
        $this->assertDatabaseMissing('workflow_schedules', ['namespace' => 'tenant-a']);
    }

    public function test_it_deletes_namespace_external_payload_references_before_runtime_rows(): void
    {
        $storageDirectory = storage_path('framework/testing/namespace-external-payloads');
        File::deleteDirectory($storageDirectory);

        WorkflowNamespace::create([
            'name' => 'tenant-a',
            'retention_days' => 30,
            'status' => 'active',
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'config' => [
                    'uri' => 'file://'.$storageDirectory,
                ],
            ],
        ]);

        $runId = $this->runtimeState('tenant-a', 'wf-tenant-a-external');
        $historyPath = $storageDirectory.'/payloads/result.bin';
        $historyPayload = 'large encoded payload bytes';
        File::ensureDirectoryExists(dirname($historyPath));
        file_put_contents($historyPath, $historyPayload);

        $streamPath = $storageDirectory.'/payloads/stream.bin';
        $streamPayload = 'large stream item payload bytes';
        File::ensureDirectoryExists(dirname($streamPath));
        file_put_contents($streamPath, $streamPayload);

        $this->addExternalPayloadHistoryEvent($runId, 'file://'.$historyPath, $historyPayload);
        $this->addExternalPayloadStreamItem($runId, 'tenant-a', 'file://'.$streamPath);

        $this->assertFileExists($historyPath);
        $this->assertFileExists($streamPath);

        $this->deleteJson('/api/namespaces/tenant-a')
            ->assertOk()
            ->assertJsonPath('deleted.external_payloads_deleted', 2)
            ->assertJsonPath('deleted.workflow_runs', 1);

        $this->assertFileDoesNotExist($historyPath);
        $this->assertFileDoesNotExist($streamPath);
        $this->assertDatabaseMissing('workflow_namespaces', ['name' => 'tenant-a']);
        $this->assertDatabaseMissing('workflow_runs', ['id' => $runId]);

        File::deleteDirectory($storageDirectory);
    }

    public function test_it_deletes_namespace_service_call_external_payload_references_before_service_rows(): void
    {
        $storageDirectory = storage_path('framework/testing/namespace-service-call-payloads');
        $storageRoots = [
            'tenant-a' => $storageDirectory.'/tenant-a',
            'tenant-b' => $storageDirectory.'/tenant-b',
            'tenant-c' => $storageDirectory.'/tenant-c',
        ];
        File::deleteDirectory($storageDirectory);

        foreach ($storageRoots as $namespace => $root) {
            WorkflowNamespace::create([
                'name' => $namespace,
                'retention_days' => 30,
                'status' => 'active',
                'external_payload_storage' => [
                    'driver' => 'local',
                    'enabled' => true,
                    'config' => [
                        'uri' => 'file://'.$root,
                    ],
                ],
            ]);
        }

        $inputPath = $storageRoots['tenant-a'].'/payloads/service-input.bin';
        $outputPath = $storageRoots['tenant-b'].'/payloads/service-output.bin';
        $failurePath = $storageRoots['tenant-c'].'/payloads/service-failure.bin';

        foreach ([$inputPath, $outputPath, $failurePath] as $path) {
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, 'payload:'.$path);
        }

        $inputCall = $this->addServiceCall([
            'namespace' => 'tenant-a',
            'target_namespace' => 'tenant-a',
            'input_payload_reference' => 'file://'.$inputPath,
        ]);
        $outputCall = $this->addServiceCall([
            'namespace' => 'tenant-b',
            'caller_namespace' => 'tenant-a',
            'target_namespace' => 'tenant-b',
            'output_payload_reference' => 'file://'.$outputPath,
        ]);
        $failureCall = $this->addServiceCall([
            'namespace' => 'tenant-c',
            'caller_namespace' => 'tenant-c',
            'target_namespace' => 'tenant-a',
            'failure_payload_reference' => 'file://'.$failurePath,
        ]);

        $this->deleteJson('/api/namespaces/tenant-a')
            ->assertOk()
            ->assertJsonPath('deleted.external_payloads_deleted', 2)
            ->assertJsonPath('deleted.workflow_service_calls', 2);

        foreach ([$inputPath, $failurePath] as $path) {
            $this->assertFileDoesNotExist($path);
        }
        $this->assertFileExists($outputPath);

        foreach ([$inputCall, $failureCall] as $callId) {
            $this->assertDatabaseMissing('workflow_service_calls', ['id' => $callId]);
        }
        $this->assertDatabaseHas('workflow_service_calls', ['id' => $outputCall]);

        File::deleteDirectory($storageDirectory);
    }

    public function test_it_scrubs_caller_side_service_call_state_when_namespace_is_deleted(): void
    {
        $storageDirectory = storage_path('framework/testing/namespace-caller-service-call-payloads');
        $storageRoots = [
            'tenant-a' => $storageDirectory.'/tenant-a',
            'tenant-b' => $storageDirectory.'/tenant-b',
        ];
        File::deleteDirectory($storageDirectory);

        foreach ($storageRoots as $namespace => $root) {
            WorkflowNamespace::create([
                'name' => $namespace,
                'retention_days' => 30,
                'status' => 'active',
                'external_payload_storage' => [
                    'driver' => 'local',
                    'enabled' => true,
                    'config' => [
                        'uri' => 'file://'.$root,
                    ],
                ],
            ]);
        }

        $callerWorkflow = 'wf-tenant-a-caller-cleanup';
        $callerRun = $this->runtimeState('tenant-a', $callerWorkflow);
        $inputPath = $storageRoots['tenant-b'].'/payloads/service-input.bin';
        $outputPath = $storageRoots['tenant-b'].'/payloads/service-output.bin';

        foreach ([$inputPath, $outputPath] as $path) {
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, 'payload:'.$path);
        }

        $serviceCallId = $this->addServiceCall([
            'namespace' => 'tenant-b',
            'caller_namespace' => 'tenant-a',
            'caller_workflow_instance_id' => $callerWorkflow,
            'caller_workflow_run_id' => $callerRun,
            'target_namespace' => 'tenant-b',
            'input_payload_reference' => 'file://'.$inputPath,
            'output_payload_reference' => 'file://'.$outputPath,
            'caller_principal_subject' => 'svc:tenant-a',
            'caller_principal_method' => 'token',
            'caller_principal_roles' => json_encode(['worker']),
            'caller_principal_tenant' => 'tenant-a',
            'caller_principal_claims' => json_encode(['scope' => 'nexus.invoke']),
        ]);

        $this->deleteJson('/api/namespaces/tenant-a')
            ->assertOk()
            ->assertJsonPath('deleted.workflow_service_call_caller_contexts', 1);

        $this->assertFileExists($inputPath);
        $this->assertFileExists($outputPath);
        $this->assertDatabaseHas('workflow_service_calls', [
            'id' => $serviceCallId,
            'namespace' => 'tenant-b',
            'target_namespace' => 'tenant-b',
            'caller_namespace' => null,
            'caller_workflow_instance_id' => null,
            'caller_workflow_run_id' => null,
            'input_payload_reference' => 'file://'.$inputPath,
            'output_payload_reference' => 'file://'.$outputPath,
            'caller_principal_subject' => null,
            'caller_principal_method' => null,
            'caller_principal_roles' => null,
            'caller_principal_tenant' => null,
            'caller_principal_claims' => null,
        ]);

        $this->postJson('/api/namespaces', ['name' => 'tenant-a'])
            ->assertCreated();

        $this->withHeaders(['X-Namespace' => 'tenant-a'])
            ->getJson('/api/workflows/'.$callerWorkflow.'/nexus-operations')
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonCount(0, 'nexus_operations');

        File::deleteDirectory($storageDirectory);
    }

    public function test_it_keeps_namespace_service_call_external_payload_referenced_by_retained_call(): void
    {
        $storageDirectory = storage_path('framework/testing/namespace-shared-service-call-payloads');
        File::deleteDirectory($storageDirectory);

        foreach (['tenant-a', 'tenant-b'] as $namespace) {
            WorkflowNamespace::create([
                'name' => $namespace,
                'retention_days' => 30,
                'status' => 'active',
                'external_payload_storage' => [
                    'driver' => 'local',
                    'enabled' => true,
                    'config' => [
                        'uri' => 'file://'.$storageDirectory,
                    ],
                ],
            ]);
        }

        $path = $storageDirectory.'/payloads/shared-service-call.bin';
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, 'shared service call payload');

        $tenantACall = $this->addServiceCall([
            'namespace' => 'tenant-a',
            'target_namespace' => 'tenant-a',
            'input_payload_reference' => 'file://'.$path,
        ]);
        $tenantBCall = $this->addServiceCall([
            'namespace' => 'tenant-b',
            'caller_namespace' => 'tenant-b',
            'target_namespace' => 'tenant-b',
            'input_payload_reference' => 'file://'.$path,
        ]);

        $this->deleteJson('/api/namespaces/tenant-a')
            ->assertOk()
            ->assertJsonPath('deleted.workflow_service_calls', 1);

        $this->assertFileExists($path);
        $this->assertDatabaseMissing('workflow_service_calls', ['id' => $tenantACall]);
        $this->assertDatabaseHas('workflow_service_calls', ['id' => $tenantBCall]);

        $this->deleteJson('/api/namespaces/tenant-b')
            ->assertOk()
            ->assertJsonPath('deleted.external_payloads_deleted', 1)
            ->assertJsonPath('deleted.workflow_service_calls', 1);

        $this->assertFileDoesNotExist($path);

        File::deleteDirectory($storageDirectory);
    }

    public function test_it_keeps_namespace_payload_referenced_by_retained_run_when_deleted_namespace_has_no_runs(): void
    {
        $storageDirectory = storage_path('framework/testing/namespace-no-run-retained-payloads');
        File::deleteDirectory($storageDirectory);

        foreach (['tenant-a', 'tenant-b'] as $namespace) {
            WorkflowNamespace::create([
                'name' => $namespace,
                'retention_days' => 30,
                'status' => 'active',
                'external_payload_storage' => [
                    'driver' => 'local',
                    'enabled' => true,
                    'config' => [
                        'uri' => 'file://'.$storageDirectory,
                    ],
                ],
            ]);
        }

        $tenantBRun = $this->runtimeState('tenant-b', 'wf-tenant-b-retained-no-run-payload');
        $path = $storageDirectory.'/payloads/shared-no-deleted-runs.bin';
        $payload = 'retained run payload bytes';
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $payload);

        $serviceCallId = $this->addServiceCall([
            'namespace' => 'tenant-a',
            'caller_namespace' => 'tenant-a',
            'target_namespace' => 'tenant-a',
            'input_payload_reference' => 'file://'.$path,
        ]);
        $this->addExternalPayloadHistoryEvent($tenantBRun, 'file://'.$path, $payload);

        $this->deleteJson('/api/namespaces/tenant-a')
            ->assertOk()
            ->assertJsonPath('deleted.workflow_service_calls', 1);

        $this->assertFileExists($path);
        $this->assertDatabaseMissing('workflow_service_calls', ['id' => $serviceCallId]);
        $this->assertDatabaseHas('workflow_runs', ['id' => $tenantBRun, 'namespace' => 'tenant-b']);

        File::deleteDirectory($storageDirectory);
    }

    public function test_it_keeps_namespace_stream_external_payload_referenced_by_retained_run(): void
    {
        $storageDirectory = storage_path('framework/testing/namespace-shared-stream-payloads');
        File::deleteDirectory($storageDirectory);

        foreach (['tenant-a', 'tenant-b'] as $namespace) {
            WorkflowNamespace::create([
                'name' => $namespace,
                'retention_days' => 30,
                'status' => 'active',
                'external_payload_storage' => [
                    'driver' => 'local',
                    'enabled' => true,
                    'config' => [
                        'uri' => 'file://'.$storageDirectory,
                    ],
                ],
            ]);
        }

        $tenantARun = $this->runtimeState('tenant-a', 'wf-tenant-a-shared-stream');
        $tenantBRun = $this->runtimeState('tenant-b', 'wf-tenant-b-shared-stream');
        $path = $storageDirectory.'/payloads/shared-stream.bin';
        $payload = 'shared stream item payload bytes';
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $payload);

        $this->addExternalPayloadStreamItem($tenantARun, 'tenant-a', 'file://'.$path);
        $this->addExternalPayloadStreamItem($tenantBRun, 'tenant-b', 'file://'.$path);

        $this->assertFileExists($path);

        $this->deleteJson('/api/namespaces/tenant-a')
            ->assertOk()
            ->assertJsonPath('deleted.workflow_runs', 1);

        $this->assertFileExists($path);
        $this->assertDatabaseMissing('workflow_namespaces', ['name' => 'tenant-a']);
        $this->assertDatabaseHas('workflow_namespaces', ['name' => 'tenant-b']);
        $this->assertDatabaseHas('workflow_runs', ['id' => $tenantBRun, 'namespace' => 'tenant-b']);

        $this->deleteJson('/api/namespaces/tenant-b')
            ->assertOk()
            ->assertJsonPath('deleted.external_payloads_deleted', 1)
            ->assertJsonPath('deleted.workflow_runs', 1);

        $this->assertFileDoesNotExist($path);

        File::deleteDirectory($storageDirectory);
    }

    public function test_it_preserves_cross_namespace_payloads_owned_by_retained_namespace(): void
    {
        $tenantARoot = storage_path('framework/testing/namespace-cross-owned-a');
        $tenantBRoot = storage_path('framework/testing/namespace-cross-owned-b');
        File::deleteDirectory($tenantARoot);
        File::deleteDirectory($tenantBRoot);

        WorkflowNamespace::create([
            'name' => 'tenant-a',
            'retention_days' => 30,
            'status' => 'active',
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'config' => ['uri' => 'file://'.$tenantARoot],
            ],
        ]);
        WorkflowNamespace::create([
            'name' => 'tenant-b',
            'retention_days' => 30,
            'status' => 'active',
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'config' => ['uri' => 'file://'.$tenantBRoot],
            ],
        ]);

        $tenantARun = $this->runtimeState('tenant-a', 'wf-tenant-a-cross-owner');
        $tenantBWorkflow = 'wf-tenant-b-cross-owner';
        $tenantBRun = $this->runtimeState('tenant-b', $tenantBWorkflow);

        $servicePayload = 'tenant-b service call payload bytes';
        $servicePath = $tenantBRoot.'/payloads/service-call.bin';
        File::ensureDirectoryExists(dirname($servicePath));
        file_put_contents($servicePath, $servicePayload);

        $commandPayload = 'tenant-b command payload bytes';
        $commandPath = $tenantBRoot.'/payloads/command.bin';
        File::ensureDirectoryExists(dirname($commandPath));
        file_put_contents($commandPath, $commandPayload);

        $serviceCallId = (string) Str::ulid();
        DB::table('workflow_service_calls')->insert([
            'id' => $serviceCallId,
            'namespace' => 'tenant-b',
            'workflow_service_endpoint_id' => (string) Str::ulid(),
            'workflow_service_id' => (string) Str::ulid(),
            'workflow_service_operation_id' => (string) Str::ulid(),
            'endpoint_name' => 'billing',
            'service_name' => 'invoicing',
            'operation_name' => 'create',
            'caller_namespace' => 'tenant-a',
            'caller_workflow_instance_id' => 'wf-tenant-a-cross-owner',
            'caller_workflow_run_id' => $tenantARun,
            'target_namespace' => 'tenant-b',
            'linked_workflow_instance_id' => $tenantBWorkflow,
            'linked_workflow_run_id' => $tenantBRun,
            'status' => 'accepted',
            'operation_mode' => 'async',
            'resolved_binding_kind' => 'workflow_run',
            'input_payload_reference' => 'file://'.$servicePath,
            'metadata' => json_encode([
                'input' => [
                    'external_storage' => $this->externalStorageReference('file://'.$servicePath, $servicePayload),
                ],
            ]),
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $commandId = $this->addExternalPayloadCommand(
            $tenantBWorkflow,
            $tenantBRun,
            $tenantARun,
            'file://'.$commandPath,
            $commandPayload,
        );

        $this->deleteJson('/api/namespaces/tenant-a')
            ->assertOk()
            ->assertJsonPath('name', 'tenant-a')
            ->assertJsonPath('status', 'deleted');

        $this->assertFileExists($servicePath);
        $this->assertFileExists($commandPath);
        $this->assertDatabaseHas('workflow_service_calls', ['id' => $serviceCallId, 'namespace' => 'tenant-b']);
        $this->assertDatabaseHas('workflow_commands', ['id' => $commandId, 'workflow_run_id' => $tenantBRun]);

        $tenantBStorage = app(NamespaceExternalPayloadStorage::class)->untrackedDriverFor('tenant-b');
        $this->assertNotNull($tenantBStorage);
        $this->assertSame($servicePayload, $tenantBStorage->get('file://'.$servicePath));
        $this->assertSame($commandPayload, $tenantBStorage->get('file://'.$commandPath));

        $this->deleteJson('/api/namespaces/tenant-b')
            ->assertOk()
            ->assertJsonPath('name', 'tenant-b')
            ->assertJsonPath('status', 'deleted')
            ->assertJsonPath('deleted.external_payloads_deleted', 2);

        $this->assertFileDoesNotExist($servicePath);
        $this->assertFileDoesNotExist($commandPath);

        File::deleteDirectory($tenantARoot);
        File::deleteDirectory($tenantBRoot);
    }

    public function test_it_fails_closed_when_namespace_external_payload_driver_is_unavailable(): void
    {
        WorkflowNamespace::create([
            'name' => 'tenant-a',
            'retention_days' => 30,
            'status' => 'active',
            'external_payload_storage' => [
                'driver' => 's3',
                'enabled' => true,
                'config' => [
                    'bucket' => 'dw-payloads',
                    'prefix' => 'tenant-a/',
                ],
            ],
        ]);

        $runId = $this->runtimeState('tenant-a', 'wf-tenant-a-unavailable');
        $this->addExternalPayloadHistoryEvent($runId, 's3://dw-payloads/tenant-a/result.bin', 'large payload');

        $this->deleteJson('/api/namespaces/tenant-a')
            ->assertStatus(503)
            ->assertJsonPath('reason', 'external_payload_storage_driver_unavailable')
            ->assertJsonPath('namespace', 'tenant-a');

        $this->assertDatabaseHas('workflow_namespaces', ['name' => 'tenant-a']);
        $this->assertDatabaseHas('workflow_runs', ['id' => $runId, 'namespace' => 'tenant-a']);
        $this->assertDatabaseHas('workflow_history_events', ['workflow_run_id' => $runId]);
    }

    public function test_it_returns_404_when_deleting_unknown_namespace(): void
    {
        $response = $this->deleteJson('/api/namespaces/missing');

        $response->assertNotFound()
            ->assertJsonPath('reason', 'namespace_not_found')
            ->assertJsonPath('namespace', 'missing');
    }

    public function test_list_response_includes_timestamps(): void
    {
        WorkflowNamespace::create([
            'name' => 'default',
            'description' => 'Default',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/namespaces');

        $response->assertOk()
            ->assertJsonStructure([
                'namespaces' => [
                    ['name', 'description', 'retention_mode', 'retention_days', 'status', 'created_at', 'updated_at'],
                ],
            ]);
    }

    public function test_it_allows_namespace_names_with_dots_underscores_and_hyphens(): void
    {
        $names = ['my.namespace', 'my_namespace', 'my-namespace', 'ns.v2_test-1'];

        foreach ($names as $name) {
            $response = $this->postJson('/api/namespaces', [
                'name' => $name,
            ]);

            $response->assertCreated();
        }

        $response = $this->getJson('/api/namespaces');
        $response->assertJsonCount(count($names), 'namespaces');
    }

    private function runtimeState(string $namespace, string $workflowId): string
    {
        $now = now();
        $runId = (string) Str::ulid();
        $taskId = (string) Str::ulid();

        DB::table('workflow_instances')->insert([
            'id' => $workflowId,
            'workflow_class' => 'Tests\\Fixtures\\NamespaceLifecycleWorkflow',
            'workflow_type' => 'NamespaceLifecycleWorkflow',
            'namespace' => $namespace,
            'current_run_id' => $runId,
            'run_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('workflow_runs')->insert([
            'id' => $runId,
            'workflow_instance_id' => $workflowId,
            'run_number' => 1,
            'workflow_class' => 'Tests\\Fixtures\\NamespaceLifecycleWorkflow',
            'workflow_type' => 'NamespaceLifecycleWorkflow',
            'namespace' => $namespace,
            'status' => 'running',
            'queue' => 'iso',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('workflow_run_summaries')->insert([
            'id' => $runId,
            'workflow_instance_id' => $workflowId,
            'run_number' => 1,
            'class' => 'Tests\\Fixtures\\NamespaceLifecycleWorkflow',
            'workflow_type' => 'NamespaceLifecycleWorkflow',
            'namespace' => $namespace,
            'status' => 'running',
            'status_bucket' => 'running',
            'queue' => 'iso',
            'sort_timestamp' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('workflow_tasks')->insert([
            'id' => $taskId,
            'workflow_run_id' => $runId,
            'namespace' => $namespace,
            'task_type' => 'workflow',
            'status' => 'ready',
            'queue' => 'iso',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('workflow_schedules')->insert([
            'id' => (string) Str::ulid(),
            'schedule_id' => 'cleanup-'.$namespace,
            'namespace' => $namespace,
            'spec' => json_encode(['intervals' => [['every' => 'PT1M']]]),
            'action' => json_encode(['workflow_type' => 'NamespaceLifecycleWorkflow', 'task_queue' => 'iso']),
            'status' => 'active',
            'overlap_policy' => 'skip',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('search_attribute_definitions')->insert([
            'namespace' => $namespace,
            'name' => 'CustomerId',
            'type' => 'keyword',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('workflow_search_attributes')->insert([
            'workflow_run_id' => $runId,
            'workflow_instance_id' => $workflowId,
            'key' => 'CustomerId',
            'type' => 'keyword',
            'value_keyword' => 'customer-'.$namespace,
            'upserted_at_sequence' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('workflow_worker_registrations')->insert([
            'worker_id' => 'worker-'.$namespace,
            'namespace' => $namespace,
            'task_queue' => 'iso',
            'runtime' => 'php',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('workflow_worker_sessions')->insert([
            'namespace' => $namespace,
            'session_id' => 'session-'.$namespace,
            'queue' => 'iso',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('workflow_worker_build_id_rollouts')->insert([
            'namespace' => $namespace,
            'task_queue' => 'iso',
            'build_id' => 'build-'.$namespace,
            'drain_intent' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $streamId = DB::table('workflow_durable_streams')->insertGetId([
            'namespace' => $namespace,
            'workflow_instance_id' => $workflowId,
            'workflow_run_id' => $runId,
            'stream_name' => 'events',
            'status' => 'open',
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('workflow_durable_stream_items')->insert([
            'stream_id' => $streamId,
            'namespace' => $namespace,
            'workflow_run_id' => $runId,
            'stream_name' => 'events',
            'offset' => 0,
            'origin' => 'workflow_command',
            'payload' => json_encode(['ok' => true]),
            'emitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $runId;
    }

    private function addExternalPayloadHistoryEvent(string $runId, string $uri, string $payload): void
    {
        DB::table('workflow_history_events')->insert([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $runId,
            'sequence' => 999,
            'event_type' => HistoryEventType::ActivityCompleted->value,
            'payload' => json_encode([
                'result' => [
                    'external_storage' => $this->externalStorageReference($uri, $payload),
                ],
                'stored_result' => ExternalPayloads::encodeStoredEnvelope([
                    'codec' => 'avro',
                    'external_storage' => $this->externalStorageReference($uri, $payload),
                ]),
            ]),
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addExternalPayloadCommand(
        string $workflowId,
        string $runId,
        string $foreignRunId,
        string $uri,
        string $payload,
    ): string {
        $commandId = (string) Str::ulid();

        DB::table('workflow_commands')->insert([
            'id' => $commandId,
            'workflow_instance_id' => $workflowId,
            'workflow_run_id' => $runId,
            'requested_workflow_run_id' => $foreignRunId,
            'resolved_workflow_run_id' => $foreignRunId,
            'command_type' => 'signal',
            'target_scope' => 'run',
            'source' => 'php',
            'status' => 'accepted',
            'payload_codec' => 'avro',
            'payload' => ExternalPayloads::encodeStoredEnvelope([
                'codec' => 'avro',
                'external_storage' => $this->externalStorageReference($uri, $payload),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $commandId;
    }

    private function addExternalPayloadStreamItem(string $runId, string $namespace, string $uri): void
    {
        $now = now();
        $stream = DB::table('workflow_durable_streams')
            ->where('workflow_run_id', $runId)
            ->where('namespace', $namespace)
            ->first(['id', 'stream_name']);

        $offset = ((int) DB::table('workflow_durable_stream_items')
            ->where('stream_id', $stream->id)
            ->max('offset')) + 1;

        DB::table('workflow_durable_stream_items')->insert([
            'stream_id' => $stream->id,
            'namespace' => $namespace,
            'workflow_run_id' => $runId,
            'stream_name' => $stream->stream_name,
            'offset' => $offset,
            'origin' => 'workflow_command',
            'payload_reference' => $uri,
            'payload_codec' => 'avro',
            'item_type' => 'chunk',
            'content_type' => 'application/octet-stream',
            'emitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function addServiceCall(array $overrides): string
    {
        $now = now();
        $id = (string) Str::ulid();

        DB::table('workflow_service_calls')->insert(array_merge([
            'id' => $id,
            'namespace' => 'tenant-a',
            'endpoint_name' => 'billing',
            'service_name' => 'ledger',
            'operation_name' => 'capture',
            'caller_namespace' => 'tenant-a',
            'target_namespace' => 'tenant-a',
            'status' => 'pending',
            'operation_mode' => 'async',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        return $id;
    }

    /**
     * @return array{schema: string, uri: string, sha256: string, size_bytes: int, codec: string}
     */
    private function externalStorageReference(string $uri, string $payload): array
    {
        return [
            'schema' => 'durable-workflow.v2.external-payload-reference.v1',
            'uri' => $uri,
            'sha256' => hash('sha256', $payload),
            'size_bytes' => strlen($payload),
            'codec' => 'avro',
        ];
    }
}
