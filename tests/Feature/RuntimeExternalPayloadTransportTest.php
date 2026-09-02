<?php

namespace Tests\Feature;

use App\Models\RuntimeExternalPayload;
use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\RuntimeExternalPayloadException;
use App\Support\RuntimeExternalPayloadQuota;
use App\Support\RuntimeExternalPayloadReference;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\WorkerProtocol;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\MemoPayload;

class RuntimeExternalPayloadTransportTest extends TestCase
{
    use RefreshDatabase;

    private string $storageDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDirectory = storage_path('framework/testing/runtime-external-payloads');
        File::deleteDirectory($this->storageDirectory);
        config([
            'cache.default' => 'file',
            'server.external_payload_transport.max_payload_bytes' => 4096,
            'server.external_payload_transport.abandoned_upload_expiry_seconds' => 60,
            'server.external_payload_transport.max_bytes_per_namespace' => null,
            'server.external_payload_transport.max_objects_per_namespace' => null,
            'server.external_payload_transport.hard_max_bytes_per_namespace' => null,
            'server.external_payload_transport.hard_max_objects_per_namespace' => null,
            'server.external_payload_transport.namespace_overrides' => [],
            'workflows.v2.types.workflows' => [
                'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
            ],
        ]);

        $this->createNamespace('default');
        $this->createNamespace('other');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageDirectory);

        parent::tearDown();
    }

    public function test_authenticated_runtime_upload_and_fetch_round_trip_hides_provider_location(): void
    {
        $payload = Serializer::serializeWithCodec('avro', [str_repeat('A', 256)]);
        $upload = $this->upload($payload);

        $upload->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('transport_version', 1)
            ->assertJsonPath('reference.schema', RuntimeExternalPayloadReference::SCHEMA)
            ->assertJsonPath('reference.codec', 'avro')
            ->assertJsonPath('reference.size_bytes', strlen($payload))
            ->assertJsonPath('reference.sha256', hash('sha256', $payload));

        $json = json_encode($upload->json(), JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('file://', $json);
        $this->assertStringNotContainsString($this->storageDirectory, $json);

        $row = RuntimeExternalPayload::query()->firstOrFail();
        $this->assertStringStartsWith('file://', $row->storage_uri);
        $this->assertNull($row->retained_at);
        $this->assertNotNull($row->expires_at);

        $reference = $upload->json('reference');
        $fetch = $this->fetch($reference);
        $fetch->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('X-Durable-Workflow-Payload-Codec', 'avro')
            ->assertHeader('X-Durable-Workflow-Payload-SHA256', hash('sha256', $payload))
            ->assertHeader('Cache-Control', 'immutable, max-age=60, private');
        $this->assertSame($payload, $fetch->getContent());

        $retry = $this->upload($payload);
        $retry->assertCreated()
            ->assertJsonPath('reference.reference_id', $reference['reference_id']);
        $this->assertDatabaseCount('runtime_external_payloads', 1);
    }

    public function test_transport_audit_events_exclude_provider_and_reusable_reference_details(): void
    {
        Log::spy();
        $reference = $this->upload('audited bytes')->json('reference');
        $this->fetch($reference)->assertOk();

        foreach (['external_payload.uploaded', 'external_payload.fetched'] as $event) {
            Log::shouldHaveReceived('info')
                ->withArgs(function (string $message, array $context) use ($event, $reference): bool {
                    $encoded = json_encode($context, JSON_UNESCAPED_SLASHES);

                    return $message === 'Runtime external payload audit event.'
                        && ($context['event'] ?? null) === $event
                        && ($context['namespace'] ?? null) === 'default'
                        && is_string($encoded)
                        && ! str_contains($encoded, $reference['reference_id'])
                        && ! str_contains($encoded, 'file://')
                        && ! array_key_exists('storage_uri', $context)
                        && ! array_key_exists('authorization', $context);
                })
                ->once();
        }
    }

    public function test_reference_is_namespace_scoped_and_cross_namespace_lookup_does_not_disclose_it(): void
    {
        $reference = $this->upload('namespace scoped bytes')->json('reference');

        $this->fetch($reference, 'other')
            ->assertNotFound()
            ->assertJsonPath('reason', 'external_payload_not_found')
            ->assertJsonPath('retryable', false);
    }

    public function test_transport_uses_the_normal_runtime_role_credential(): void
    {
        Log::spy();
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => 'runtime-role-token',
        ]);

        $this->upload('authenticated bytes')
            ->assertUnauthorized()
            ->assertJsonPath('schema', 'durable-workflow.v2.runtime-external-payload-error.v1')
            ->assertJsonPath('reason', 'external_payload_unauthorized')
            ->assertJsonPath('retryable', false);

        $this->upload('authenticated bytes', authorization: 'runtime-role-token')
            ->assertCreated();

        Log::shouldHaveReceived('info')
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Runtime external payload audit event.'
                && ($context['event'] ?? null) === 'external_payload.rejected'
                && ($context['reason'] ?? null) === 'external_payload_unauthorized'
                && ($context['status'] ?? null) === 401
                && ! array_key_exists('authorization', $context)
            )
            ->once();
    }

    public function test_role_and_namespace_checks_precede_reference_resolution(): void
    {
        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.backward_compatible' => true,
            'server.auth.role_tokens' => [
                'worker' => 'worker-role-token',
                'operator' => 'operator-role-token',
            ],
        ]);
        $malformedEnvelope = [
            'codec' => 'avro',
            'external_payload' => ['schema' => 'malformed'],
        ];

        $this->withHeaders($this->controlHeaders() + [
            'Authorization' => 'Bearer worker-role-token',
        ])->postJson('/api/workflows', [
            'workflow_id' => 'role-before-reference',
            'workflow_type' => 'tests.external-greeting-workflow',
            'input' => $malformedEnvelope,
        ])->assertForbidden()
            ->assertJsonPath('reason', 'forbidden');

        $this->withHeaders($this->controlHeaders('missing') + [
            'Authorization' => 'Bearer operator-role-token',
        ])->postJson('/api/workflows', [
            'workflow_id' => 'namespace-before-reference',
            'workflow_type' => 'tests.external-greeting-workflow',
            'input' => $malformedEnvelope,
        ])->assertNotFound()
            ->assertJsonPath('reason', 'namespace_not_found');
    }

    public function test_upload_rejects_wrong_integrity_and_oversized_bytes_without_registering_state(): void
    {
        $this->upload('payload', 'default', str_repeat('0', 64))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'external_payload_integrity_mismatch')
            ->assertJsonPath('retryable', false);

        config(['server.external_payload_transport.max_payload_bytes' => 4]);
        $this->upload('payload')
            ->assertStatus(413)
            ->assertJsonPath('reason', 'external_payload_oversized')
            ->assertJsonPath('retryable', false);

        config(['server.external_payload_transport.max_payload_bytes' => 4096]);
        $this->call('POST', '/api/external-payloads/v1', [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream-invalid',
            'HTTP_X_NAMESPACE' => 'default',
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_CODEC' => 'avro',
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SIZE' => '0',
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SHA256' => hash('sha256', ''),
        ], '')->assertStatus(415)
            ->assertJsonPath('reason', 'external_payload_unsupported');

        $this->call('POST', '/api/external-payloads/v1', [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_X_NAMESPACE' => 'default',
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_CODEC' => 'json',
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SIZE' => '7',
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SHA256' => hash('sha256', 'payload'),
        ], 'payload')->assertStatus(422)
            ->assertJsonPath('reason', 'external_payload_unsupported');

        $this->assertDatabaseCount('runtime_external_payloads', 0);
    }

    public function test_namespace_byte_quota_contains_uploads_without_affecting_another_namespace(): void
    {
        config(['server.external_payload_transport.max_bytes_per_namespace' => 10]);

        $first = $this->upload('123456');
        $first->assertCreated();
        $this->upload('123456')
            ->assertCreated()
            ->assertJsonPath('reference.reference_id', $first->json('reference.reference_id'));

        $this->upload('12345')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('reason', 'external_payload_namespace_bytes_exhausted')
            ->assertJsonPath('retryable', true)
            ->assertJsonPath('retry_after_seconds', 60);

        $this->upload('12345', 'other')->assertCreated();

        $this->assertSame(1, RuntimeExternalPayload::query()->where('namespace', 'default')->count());
        $this->assertSame(1, RuntimeExternalPayload::query()->where('namespace', 'other')->count());

        $this->withHeaders($this->controlHeaders())
            ->getJson('/api/system/metrics')
            ->assertOk()
            ->assertJsonPath('metrics.'.RuntimeExternalPayloadQuota::METRIC_NAME.'.max_bytes', 10)
            ->assertJsonPath('metrics.'.RuntimeExternalPayloadQuota::METRIC_NAME.'.used_bytes', 6)
            ->assertJsonPath('metrics.'.RuntimeExternalPayloadQuota::METRIC_NAME.'.used_objects', 1)
            ->assertJsonPath('metrics.'.RuntimeExternalPayloadQuota::METRIC_NAME.'.remaining_bytes', 4)
            ->assertJsonPath(
                'metrics.'.RuntimeExternalPayloadQuota::METRIC_NAME.'.rejections_by_reason.external_payload_namespace_bytes_exhausted',
                1,
            );
    }

    public function test_namespace_object_quota_recovers_when_registered_state_is_removed(): void
    {
        config(['server.external_payload_transport.max_objects_per_namespace' => 1]);

        $this->upload('first')->assertCreated();
        $this->upload('second')
            ->assertStatus(429)
            ->assertJsonPath('reason', 'external_payload_namespace_objects_exhausted')
            ->assertJsonPath('retryable', true);

        RuntimeExternalPayload::query()->where('namespace', 'default')->delete();

        $this->upload('second')->assertCreated();
    }

    public function test_namespace_override_cannot_exceed_hard_payload_quota(): void
    {
        config([
            'server.external_payload_transport.max_bytes_per_namespace' => 4,
            'server.external_payload_transport.hard_max_bytes_per_namespace' => 8,
            'server.external_payload_transport.namespace_overrides' => [
                'default' => ['max_bytes' => 100],
            ],
        ]);

        $this->upload('12345678')->assertCreated();
        $this->upload('x')
            ->assertStatus(429)
            ->assertJsonPath('reason', 'external_payload_namespace_bytes_exhausted');

        $this->assertSame(8, app(RuntimeExternalPayloadQuota::class)->limits('default')['max_bytes']);
    }

    public function test_invalid_namespace_payload_quota_fails_closed(): void
    {
        config(['server.external_payload_transport.max_objects_per_namespace' => 'invalid']);

        $this->upload('payload')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('reason', 'external_payload_namespace_quota_unavailable')
            ->assertJsonPath('retryable', true);

        $this->assertDatabaseCount('runtime_external_payloads', 0);
    }

    public function test_invalid_namespace_payload_override_document_fails_closed(): void
    {
        config(['server.external_payload_transport.namespace_overrides' => null]);

        $this->upload('payload')
            ->assertStatus(503)
            ->assertJsonPath('reason', 'external_payload_namespace_quota_unavailable');

        $this->assertDatabaseCount('runtime_external_payloads', 0);
    }

    public function test_invalid_namespace_payload_override_entry_fails_closed(): void
    {
        config([
            'server.external_payload_transport.namespace_overrides' => [
                'default' => 'invalid',
            ],
        ]);

        $this->upload('payload')
            ->assertStatus(503)
            ->assertJsonPath('reason', 'external_payload_namespace_quota_unavailable');

        $this->assertDatabaseCount('runtime_external_payloads', 0);
    }

    public function test_retained_payload_registration_cannot_bypass_namespace_quota(): void
    {
        config(['server.external_payload_transport.max_objects_per_namespace' => 0]);

        try {
            app(RuntimeExternalPayloadRegistry::class)->trackRetained(
                'default',
                'file:///quota-bypass',
                'avro',
                hash('sha256', 'payload'),
                strlen('payload'),
            );
            $this->fail('Expected retained payload registration to be rejected.');
        } catch (RuntimeExternalPayloadException $exception) {
            $this->assertSame('external_payload_namespace_objects_exhausted', $exception->reason);
            $this->assertSame(429, $exception->status);
            $this->assertTrue($exception->retryable);
        }

        $this->assertDatabaseCount('runtime_external_payloads', 0);
    }

    public function test_production_request_bootstrap_caps_chunked_upload_without_content_length(): void
    {
        config(['server.external_payload_transport.max_payload_bytes' => 4096]);
        $payload = str_repeat('U', 16 * 1024);
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        $this->assertSame(strlen($payload), fwrite($stream, $payload));
        rewind($stream);

        $entrypoint = File::get(public_path('index.php'));
        $this->assertStringContainsString('Request::createFromGlobals()', $entrypoint);
        $this->assertStringNotContainsString('Request::capture()', $entrypoint);
        $this->assertStringNotContainsString('Request::createFromBase(', $entrypoint);
        $this->assertStringNotContainsString('getContent(', $entrypoint);

        $originalGet = $_GET;
        $originalPost = $_POST;
        $originalCookie = $_COOKIE;
        $originalFiles = $_FILES;
        $originalServer = $_SERVER;

        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/external-payloads/v1',
            'QUERY_STRING' => '',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => '80',
            'HTTP_HOST' => 'localhost',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '',
            'SCRIPT_FILENAME' => public_path('index.php'),
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_TRANSFER_ENCODING' => 'chunked',
            'HTTP_X_NAMESPACE' => 'default',
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_CODEC' => 'avro',
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SIZE' => '4096',
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SHA256' => hash('sha256', $payload),
        ];

        Request::setFactory(static function (
            array $query,
            array $request,
            array $attributes,
            array $cookies,
            array $files,
            array $server,
        ) use ($stream): Request {
            return new Request($query, $request, $attributes, $cookies, $files, $server, $stream);
        });

        $kernel = $this->app->make(HttpKernel::class);

        try {
            Request::enableHttpMethodParameterOverride();
            $request = Request::createFromGlobals();
            $this->assertFalse($request->headers->has('Content-Length'));

            $response = $kernel->handle($request);
            $observedStreamPosition = ftell($stream);
            $kernel->terminate($request, $response);

            TestResponse::fromBaseResponse($response, $request)
                ->assertStatus(413)
                ->assertJsonPath('reason', 'external_payload_oversized')
                ->assertJsonPath('retryable', false);
            $this->assertSame(4097, $observedStreamPosition);
            $this->assertDatabaseCount('runtime_external_payloads', 0);
        } finally {
            Request::setFactory(null);
            $_GET = $originalGet;
            $_POST = $originalPost;
            $_COOKIE = $originalCookie;
            $_FILES = $originalFiles;
            $_SERVER = $originalServer;
            fclose($stream);
        }
    }

    public function test_fetch_reports_missing_corrupt_and_unavailable_backing_bytes(): void
    {
        $missing = $this->upload('missing bytes')->json('reference');
        $missingRow = RuntimeExternalPayload::query()->whereKey($missing['reference_id'])->firstOrFail();
        $missingPath = parse_url($missingRow->storage_uri, PHP_URL_PATH);
        $this->assertIsString($missingPath);
        File::delete($missingPath);

        $this->fetch($missing)
            ->assertNotFound()
            ->assertJsonPath('reason', 'external_payload_not_found')
            ->assertJsonPath('retryable', false);

        $corrupt = $this->upload('integrity bytes')->json('reference');
        $corruptRow = RuntimeExternalPayload::query()->whereKey($corrupt['reference_id'])->firstOrFail();
        $corruptPath = parse_url($corruptRow->storage_uri, PHP_URL_PATH);
        $this->assertIsString($corruptPath);
        File::put($corruptPath, 'corrupted bytes');

        $this->fetch($corrupt)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'external_payload_integrity_mismatch')
            ->assertJsonPath('retryable', false);

        $unavailable = $this->upload('unavailable bytes')->json('reference');
        WorkflowNamespace::query()->where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 's3',
                'enabled' => true,
                'threshold_bytes' => 32,
                'config' => [
                    'disk' => 'missing-runtime-payload-disk',
                    'bucket' => 'payloads',
                ],
            ],
        ]);

        $this->fetch($unavailable)
            ->assertStatus(503)
            ->assertJsonPath('reason', 'external_payload_unavailable')
            ->assertJsonPath('retryable', true);
    }

    public function test_fetch_rejects_oversized_local_backing_object_without_recording_a_fetch(): void
    {
        $reference = $this->upload('bounded local bytes')->json('reference');
        $row = RuntimeExternalPayload::query()->whereKey($reference['reference_id'])->firstOrFail();
        $path = parse_url($row->storage_uri, PHP_URL_PATH);
        $this->assertIsString($path);
        File::put($path, str_repeat('L', 16 * 1024));

        $this->fetch($reference)
            ->assertStatus(413)
            ->assertJsonPath('reason', 'external_payload_oversized')
            ->assertJsonPath('retryable', false);

        $this->assertNull($row->fresh()?->last_fetched_at);
    }

    public function test_fetch_rejects_oversized_configured_disk_backing_object_without_recording_a_fetch(): void
    {
        config([
            'filesystems.disks.runtime-external-payload-test' => [
                'driver' => 'local',
                'root' => $this->storageDirectory.'/configured-disk',
            ],
        ]);
        Storage::fake('runtime-external-payload-test');
        WorkflowNamespace::query()->where('name', 'default')->update([
            'external_payload_storage' => [
                'driver' => 'custom',
                'enabled' => true,
                'threshold_bytes' => 32,
                'config' => [
                    'disk' => 'runtime-external-payload-test',
                    'name' => 'runtime-payloads',
                    'scheme' => 'object',
                ],
            ],
        ]);

        $reference = $this->upload('bounded object bytes')->json('reference');
        $row = RuntimeExternalPayload::query()->whereKey($reference['reference_id'])->firstOrFail();
        $path = parse_url($row->storage_uri, PHP_URL_PATH);
        $this->assertIsString($path);
        Storage::disk('runtime-external-payload-test')->put(ltrim($path, '/'), str_repeat('O', 16 * 1024));

        $this->fetch($reference)
            ->assertStatus(413)
            ->assertJsonPath('reason', 'external_payload_oversized')
            ->assertJsonPath('retryable', false);

        $this->assertNull($row->fresh()?->last_fetched_at);
    }

    public function test_state_bearing_request_rejects_oversized_backing_object_without_partial_state(): void
    {
        Queue::fake();
        $payload = Serializer::serializeWithCodec('avro', ['bounded workflow input']);
        $reference = $this->upload($payload)->json('reference');
        $row = RuntimeExternalPayload::query()->whereKey($reference['reference_id'])->firstOrFail();
        $path = parse_url($row->storage_uri, PHP_URL_PATH);
        $this->assertIsString($path);
        File::put($path, str_repeat('S', 16 * 1024));

        $this->withHeaders($this->controlHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'oversized-runtime-reference',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => [
                    'codec' => 'avro',
                    'external_payload' => $reference,
                ],
            ])
            ->assertStatus(413)
            ->assertJsonPath('reason', 'external_payload_oversized')
            ->assertJsonPath('retryable', false);

        $this->assertDatabaseMissing('workflow_instances', [
            'workflow_id' => 'oversized-runtime-reference',
        ]);
        $this->assertNull($row->fresh()?->last_fetched_at);
    }

    public function test_expired_unclaimed_reference_has_a_stable_typed_outcome(): void
    {
        $reference = $this->upload('expires')->json('reference');
        RuntimeExternalPayload::query()->update([
            'expires_at' => now()->subSecond(),
        ]);

        $this->fetch($reference)
            ->assertStatus(410)
            ->assertJsonPath('reason', 'external_payload_expired')
            ->assertJsonPath('retryable', false);
    }

    public function test_state_bearing_request_claims_reference_and_obsolete_provider_shape_is_rejected(): void
    {
        Queue::fake();
        $payload = Serializer::serializeWithCodec('avro', ['Runtime Ada']);
        $reference = $this->upload($payload)->json('reference');

        $this->withHeaders($this->controlHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'runtime-reference-input',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => [
                    'codec' => 'avro',
                    'external_payload' => $reference,
                ],
            ])
            ->assertCreated();

        $row = RuntimeExternalPayload::query()->where('id', $reference['reference_id'])->firstOrFail();
        $this->assertNotNull($row->retained_at);
        $this->assertNull($row->expires_at);

        $tampered = $reference;
        $tampered['sha256'] = str_repeat('0', 64);
        $this->withHeaders($this->controlHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'tampered-runtime-reference',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => [
                    'codec' => 'avro',
                    'external_payload' => $tampered,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'external_payload_integrity_mismatch');
        $this->assertDatabaseMissing('workflow_instances', ['workflow_id' => 'tampered-runtime-reference']);

        $this->withHeaders($this->controlHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'provider-reference-rejected',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => [
                    'codec' => 'avro',
                    'external_storage' => [
                        'schema' => 'durable-workflow.v2.external-payload-reference.v1',
                        'uri' => $row->storage_uri,
                        'codec' => 'avro',
                        'size_bytes' => strlen($payload),
                        'sha256' => hash('sha256', $payload),
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'external_payload_unsupported');
        $this->assertDatabaseMissing('workflow_instances', ['workflow_id' => 'provider-reference-rejected']);
    }

    public function test_workflow_open_metadata_preserves_reserved_looking_business_keys(): void
    {
        Queue::fake();
        $businessMetadata = $this->reservedLookingBusinessMetadata();

        $start = $this->withHeaders($this->controlHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'reserved-looking-workflow-metadata',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => ['metadata'],
                'memo' => $businessMetadata,
                'search_attributes' => [
                    'external_payload' => 'business-payload-label',
                    'external_storage' => 'business-storage-label',
                ],
            ])
            ->assertCreated();

        $this->withHeaders($this->controlHeaders())
            ->getJson('/api/workflows/reserved-looking-workflow-metadata/runs/'.$start->json('run_id'))
            ->assertOk()
            ->assertJsonPath('memo.external_payload.business_id', 'payload-42')
            ->assertJsonPath('memo.external_storage.bucket_label', 'archive')
            ->assertJsonPath('memo.envelope_shaped_business_value.codec', 'business-codec')
            ->assertJsonPath('memo.envelope_shaped_business_value.external_storage.region_label', 'north')
            ->assertJsonPath('search_attributes.external_payload', 'business-payload-label')
            ->assertJsonPath('search_attributes.external_storage', 'business-storage-label');

        $this->withHeaders($this->controlHeaders())
            ->getJson('/api/workflows/reserved-looking-workflow-metadata/runs/'.$start->json('run_id').'/history/export')
            ->assertOk()
            ->assertJsonPath('workflow.memo.external_payload.business_id', 'payload-42')
            ->assertJsonPath('workflow.memo.external_storage.bucket_label', 'archive')
            ->assertJsonPath('workflow.memo.envelope_shaped_business_value.codec', 'business-codec')
            ->assertJsonPath('workflow.memo.envelope_shaped_business_value.external_storage.region_label', 'north')
            ->assertJsonPath('workflow.search_attributes.external_payload', 'business-payload-label')
            ->assertJsonPath('workflow.search_attributes.external_storage', 'business-storage-label');
    }

    public function test_schedule_and_service_metadata_preserve_reserved_looking_business_keys(): void
    {
        $businessMetadata = $this->reservedLookingBusinessMetadata();

        $this->withHeaders($this->controlHeaders())
            ->postJson('/api/schedules', [
                'schedule_id' => 'reserved-looking-schedule-metadata',
                'spec' => ['cron_expressions' => ['0 * * * *']],
                'action' => [
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'input' => [$businessMetadata],
                ],
                'memo' => $businessMetadata,
                'search_attributes' => [
                    'external_payload' => 'schedule-payload-label',
                    'external_storage' => 'schedule-storage-label',
                ],
            ])
            ->assertCreated();

        $this->withHeaders($this->controlHeaders())
            ->getJson('/api/schedules/reserved-looking-schedule-metadata')
            ->assertOk()
            ->assertJsonPath('memo.external_payload.business_id', 'payload-42')
            ->assertJsonPath('memo.external_storage.bucket_label', 'archive')
            ->assertJsonPath('memo.envelope_shaped_business_value.codec', 'business-codec')
            ->assertJsonPath('memo.envelope_shaped_business_value.external_storage.region_label', 'north')
            ->assertJsonPath('search_attributes.external_payload', 'schedule-payload-label')
            ->assertJsonPath('search_attributes.external_storage', 'schedule-storage-label')
            ->assertJsonPath('action.input.0.external_payload.business_id', 'payload-42')
            ->assertJsonPath('action.input.0.external_storage.bucket_label', 'archive')
            ->assertJsonPath('action.input.0.envelope_shaped_business_value.external_storage.region_label', 'north');

        $this->withHeaders($this->controlHeaders())
            ->postJson('/api/service-endpoints', [
                'endpoint_name' => 'reserved-looking-service-metadata',
                'metadata' => $businessMetadata,
            ])
            ->assertCreated()
            ->assertJsonPath('metadata.external_payload.business_id', 'payload-42')
            ->assertJsonPath('metadata.external_storage.bucket_label', 'archive')
            ->assertJsonPath('metadata.envelope_shaped_business_value.codec', 'business-codec')
            ->assertJsonPath('metadata.envelope_shaped_business_value.external_storage.region_label', 'north');

        $this->withHeaders($this->controlHeaders())
            ->getJson('/api/service-endpoints/reserved-looking-service-metadata')
            ->assertOk()
            ->assertJsonPath('metadata.external_payload.business_id', 'payload-42')
            ->assertJsonPath('metadata.external_storage.bucket_label', 'archive')
            ->assertJsonPath('metadata.envelope_shaped_business_value.codec', 'business-codec')
            ->assertJsonPath('metadata.envelope_shaped_business_value.external_storage.region_label', 'north');
    }

    public function test_schedule_payload_envelope_round_trips_as_an_opaque_runtime_reference(): void
    {
        $payload = Serializer::serializeWithCodec('avro', ['scheduled external input']);
        $reference = $this->upload($payload)->json('reference');

        $this->withHeaders($this->controlHeaders())
            ->postJson('/api/schedules', [
                'schedule_id' => 'external-payload-schedule',
                'spec' => ['cron_expressions' => ['0 * * * *']],
                'action' => [
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'input' => [
                        'codec' => 'avro',
                        'external_payload' => $reference,
                    ],
                ],
            ])
            ->assertCreated();

        $this->withHeaders($this->controlHeaders())
            ->getJson('/api/schedules/external-payload-schedule')
            ->assertOk()
            ->assertJsonPath('action.input.codec', 'avro')
            ->assertJsonPath('action.input.external_payload.reference_id', $reference['reference_id'])
            ->assertJsonMissingPath('action.input.external_storage');
    }

    public function test_worker_poll_and_fetch_use_only_opaque_runtime_reference(): void
    {
        Queue::fake();
        $largeInput = str_repeat('W', 256);

        $start = $this->withHeaders($this->controlHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'runtime-reference-poll',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'runtime-payloads',
                'input' => [$largeInput],
            ])
            ->assertCreated();

        $this->registerWorker('runtime-payload-worker', 'runtime-payloads');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'runtime-payload-worker',
                'task_queue' => 'runtime-payloads',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.run_id', $start->json('run_id'))
            ->assertJsonPath('task.arguments.codec', 'avro')
            ->assertJsonPath('task.arguments.external_payload.schema', RuntimeExternalPayloadReference::SCHEMA)
            ->assertJsonMissingPath('task.arguments.external_storage');

        $json = json_encode($poll->json('task.arguments'), JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('file://', $json);

        $reference = $poll->json('task.arguments.external_payload');
        $fetched = $this->fetch($reference);
        $this->assertSame([$largeInput], Serializer::unserializeWithCodec('avro', $fetched->getContent()));

        $this->withHeaders($this->controlHeaders())
            ->getJson('/api/workflows/runtime-reference-poll/runs/'.$start->json('run_id').'/history/export')
            ->assertOk()
            ->assertJsonPath('payloads.arguments.data.external_payload.reference_id', $reference['reference_id'])
            ->assertJsonMissingPath('payloads.arguments.data.external_storage');
    }

    public function test_worker_memo_command_resolves_opaque_reference_before_recording_history(): void
    {
        Queue::fake();

        $start = $this->withHeaders($this->controlHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'runtime-reference-memo',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'runtime-payloads',
                'input' => ['memo'],
                'memo' => ['tenant' => 'acme'],
            ])
            ->assertCreated();

        $this->registerWorker('runtime-memo-worker', 'runtime-payloads');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'runtime-memo-worker',
                'task_queue' => 'runtime-payloads',
            ])
            ->assertOk();

        $entries = MemoPayload::mapEnvelope([
            'stage' => 'waiting',
            'tenant' => null,
        ]);
        $reference = $this->upload($entries['blob'])->json('reference');
        $completion = [
            'lease_owner' => 'runtime-memo-worker',
            'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
            'commands' => [[
                'type' => 'upsert_memo',
                'entries' => [
                    'codec' => 'avro',
                    'external_payload' => $reference,
                ],
            ]],
        ];

        $route = '/api/worker/workflow-tasks/'.$poll->json('task.task_id').'/complete';
        $completed = $this->withHeaders($this->workerHeaders())
            ->postJson($route, $completion);
        $completed->assertOk()
            ->assertJsonPath('recorded', true);

        $this->withHeaders($this->controlHeaders())
            ->getJson('/api/workflows/runtime-reference-memo/runs/'.$start->json('run_id'))
            ->assertOk()
            ->assertJsonPath('memo', ['stage' => 'waiting']);

        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $start->json('run_id'))
            ->where('event_type', 'MemoUpserted')
            ->sole();
        $this->assertSame(
            ['stage' => 'waiting', 'tenant' => null],
            MemoPayload::decodeEntries($event->payload['entries'] ?? []),
        );
        $this->assertStringNotContainsString(
            $reference['reference_id'],
            json_encode($event->payload, JSON_THROW_ON_ERROR),
        );
        $this->assertDatabaseHas('runtime_external_payloads', [
            'id' => $reference['reference_id'],
            'expires_at' => null,
        ]);

        $this->withHeaders($this->workerHeaders())
            ->postJson($route, $completion)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'task_not_leased');
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $start->json('run_id'))
            ->where('event_type', 'MemoUpserted')
            ->count());
    }

    public function test_stream_reference_is_verified_before_commit_and_returns_opaque(): void
    {
        Queue::fake();
        $start = $this->withHeaders($this->controlHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'runtime-reference-stream',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => ['stream'],
            ])
            ->assertCreated();

        $payload = Serializer::serializeWithCodec('avro', ['stream item']);
        $reference = $this->upload($payload)->json('reference');
        $route = sprintf(
            '/api/workflows/runtime-reference-stream/runs/%s/streams/items/items',
            $start->json('run_id'),
        );

        $this->withHeaders($this->controlHeaders())->postJson($route, [
            'items' => [[
                'payload_reference' => $reference,
                'payload_codec' => 'avro',
            ]],
        ])->assertOk();

        $this->withHeaders($this->controlHeaders())->getJson($route)
            ->assertOk()
            ->assertJsonPath('items.0.payload_reference.schema', RuntimeExternalPayloadReference::SCHEMA)
            ->assertJsonPath('items.0.payload_reference.reference_id', $reference['reference_id'])
            ->assertJsonMissingPath('items.0.payload_reference.uri');

        $corruptReference = $this->upload('stream integrity bytes')->json('reference');
        $row = RuntimeExternalPayload::query()->whereKey($corruptReference['reference_id'])->firstOrFail();
        $path = parse_url($row->storage_uri, PHP_URL_PATH);
        $this->assertIsString($path);
        File::put($path, 'corrupted stream bytes');

        $this->withHeaders($this->controlHeaders())->postJson($route, [
            'items' => [[
                'payload_reference' => $corruptReference,
                'payload_codec' => 'avro',
            ]],
        ])->assertStatus(422)
            ->assertJsonPath('reason', 'external_payload_integrity_mismatch');
        $this->assertDatabaseCount('workflow_durable_stream_items', 1);
    }

    public function test_namespace_cleanup_removes_runtime_reference_and_backing_bytes(): void
    {
        Queue::fake();
        $payload = Serializer::serializeWithCodec('avro', ['retained in other']);
        $reference = $this->upload($payload, 'other')->json('reference');

        $this->withHeaders($this->controlHeaders('other'))
            ->postJson('/api/workflows', [
                'workflow_id' => 'runtime-reference-cleanup',
                'workflow_type' => 'tests.external-greeting-workflow',
                'input' => [
                    'codec' => 'avro',
                    'external_payload' => $reference,
                ],
            ])
            ->assertCreated();

        $row = RuntimeExternalPayload::query()->whereKey($reference['reference_id'])->firstOrFail();
        $path = parse_url($row->storage_uri, PHP_URL_PATH);
        $this->assertIsString($path);
        $this->assertFileExists($path);

        $this->withHeaders($this->controlHeaders('other'))
            ->deleteJson('/api/namespaces/other')
            ->assertOk();

        $this->assertDatabaseMissing('runtime_external_payloads', ['id' => $reference['reference_id']]);
        $this->assertFileDoesNotExist($path);
    }

    public function test_namespace_cleanup_preserves_bytes_owned_by_another_namespace(): void
    {
        Queue::fake();
        $sharedRoot = $this->storageDirectory.'/shared';
        foreach (['default', 'other'] as $namespace) {
            WorkflowNamespace::query()->where('name', $namespace)->update([
                'external_payload_storage' => [
                    'driver' => 'local',
                    'enabled' => true,
                    'threshold_bytes' => 32,
                    'config' => ['uri' => 'file://'.$sharedRoot],
                ],
            ]);
        }

        $payload = Serializer::serializeWithCodec('avro', ['shared retained bytes']);
        $defaultReference = $this->upload($payload)->json('reference');
        $otherReference = $this->upload($payload, 'other')->json('reference');

        foreach ([
            'default' => $defaultReference,
            'other' => $otherReference,
        ] as $namespace => $reference) {
            $this->withHeaders($this->controlHeaders($namespace))
                ->postJson('/api/workflows', [
                    'workflow_id' => 'shared-runtime-reference-'.$namespace,
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'input' => [
                        'codec' => 'avro',
                        'external_payload' => $reference,
                    ],
                ])
                ->assertCreated();
        }

        $this->withHeaders($this->controlHeaders('other'))
            ->deleteJson('/api/namespaces/other')
            ->assertOk();

        $this->fetch($defaultReference)->assertOk();
        $this->assertDatabaseHas('runtime_external_payloads', ['id' => $defaultReference['reference_id']]);
        $this->assertDatabaseMissing('runtime_external_payloads', ['id' => $otherReference['reference_id']]);
    }

    public function test_cluster_discovery_publishes_transport_without_backing_provider_details(): void
    {
        $response = $this->withHeaders(['X-Namespace' => 'default'])->getJson('/api/cluster/info');

        $response->assertOk()
            ->assertJsonPath('capabilities.runtime_external_payload_transport', true)
            ->assertJsonPath('namespace.external_payload_storage.schema', RuntimeExternalPayloadReference::SCHEMA)
            ->assertJsonPath('namespace.external_payload_storage.transport.version', 1)
            ->assertJsonPath('namespace.external_payload_storage.transport.authorization.provider_credentials_exposed', false)
            ->assertJsonPath('namespace.external_payload_storage.transport.retention.client_delete_supported', false)
            ->assertJsonPath('worker_protocol.server_capabilities.runtime_external_payload_transport.reference_schema', RuntimeExternalPayloadReference::SCHEMA)
            ->assertJsonMissingPath('namespace.external_payload_storage.driver')
            ->assertJsonMissingPath('namespace.external_payload_storage.reference_uri_scheme')
            ->assertJsonMissingPath('namespace.external_payload_storage.config');
    }

    private function createNamespace(string $name): void
    {
        WorkflowNamespace::query()->create([
            'name' => $name,
            'description' => 'Runtime payload test namespace',
            'retention_days' => 30,
            'status' => 'active',
            'external_payload_storage' => [
                'driver' => 'local',
                'enabled' => true,
                'threshold_bytes' => 32,
                'config' => [
                    'uri' => 'file://'.$this->storageDirectory.'/'.$name,
                ],
            ],
        ]);
    }

    private function upload(
        string $payload,
        string $namespace = 'default',
        ?string $sha256 = null,
        ?string $authorization = null,
    ): TestResponse {
        $server = [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_X_NAMESPACE' => $namespace,
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_CODEC' => 'avro',
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SIZE' => (string) strlen($payload),
            'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SHA256' => $sha256 ?? hash('sha256', $payload),
        ];
        if ($authorization !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$authorization;
        }

        return $this->call('POST', '/api/external-payloads/v1', [], [], [], $server, $payload);
    }

    /** @param array<string, mixed> $reference */
    private function fetch(array $reference, string $namespace = 'default'): TestResponse
    {
        return $this->withHeaders([
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Payload-Codec' => $reference['codec'],
            'X-Durable-Workflow-Payload-Size' => (string) $reference['size_bytes'],
            'X-Durable-Workflow-Payload-SHA256' => $reference['sha256'],
        ])->get('/api/external-payloads/v1/'.$reference['reference_id']);
    }

    private function controlHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    private function workerHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }

    private function registerWorker(string $workerId, string $taskQueue): void
    {
        WorkerRegistration::query()->create([
            'worker_id' => $workerId,
            'namespace' => 'default',
            'task_queue' => $taskQueue,
            'runtime' => 'php',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => [],
            'capabilities' => ['memo_upserts'],
            'max_concurrent_workflow_tasks' => 1,
            'max_concurrent_activity_tasks' => 1,
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function reservedLookingBusinessMetadata(): array
    {
        return [
            'external_payload' => ['business_id' => 'payload-42'],
            'external_storage' => ['bucket_label' => 'archive'],
            'envelope_shaped_business_value' => [
                'codec' => 'business-codec',
                'external_storage' => ['region_label' => 'north'],
            ],
        ];
    }
}
