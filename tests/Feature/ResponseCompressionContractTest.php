<?php

namespace Tests\Feature;

use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;

class ResponseCompressionContractTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    public function test_worker_protocol_responses_can_be_gzip_compressed_without_losing_worker_envelope(): void
    {
        Queue::fake();

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-gzip-contract',
            'workflow_type' => 'remote.large-worker-contract',
            'task_queue' => 'compressed-workers',
            'input' => ['blob' => str_repeat('worker-compression-', 300)],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorker('gzip-worker', 'compressed-workers');

        $response = $this->withHeaders($this->workerHeaders() + [
            'Accept-Encoding' => 'gzip',
        ])->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'gzip-worker',
            'task_queue' => 'compressed-workers',
        ]);

        $response->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertHeader('Content-Encoding', 'gzip')
            ->assertHeader('Vary', 'Accept-Encoding');

        $body = $this->compressedJson($response, 'gzip');

        $this->assertSame(WorkerProtocol::VERSION, $body['protocol_version'] ?? null);
        $this->assertSame(['gzip', 'deflate'], $body['server_capabilities']['response_compression'] ?? null);
        $this->assertSame('wf-worker-gzip-contract', $body['task']['workflow_id'] ?? null);
        $this->assertSame('gzip-worker', $body['task']['lease_owner'] ?? null);
    }

    public function test_control_plane_responses_can_be_deflate_compressed_without_worker_envelope(): void
    {
        Queue::fake();

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-control-deflate-contract',
            'workflow_type' => 'remote.large-control-contract',
            'task_queue' => 'compressed-control',
            'input' => ['blob' => str_repeat('control-compression-', 300)],
        ], $this->apiHeaders());

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $response = $this->withHeaders($this->apiHeaders() + [
            'Accept-Encoding' => 'deflate',
        ])->getJson("/api/workflows/wf-control-deflate-contract/runs/{$runId}/history");

        $response->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertHeader('Content-Encoding', 'deflate')
            ->assertHeader('Vary', 'Accept-Encoding');

        $body = $this->compressedJson($response, 'deflate');

        $this->assertArrayHasKey('events', $body);
        $this->assertNotEmpty($body['events']);
        $this->assertArrayNotHasKey('protocol_version', $body);
        $this->assertArrayNotHasKey('server_capabilities', $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function compressedJson(TestResponse $response, string $encoding): array
    {
        $content = $response->baseResponse->getContent();
        $this->assertIsString($content);

        $decompressed = match ($encoding) {
            'gzip' => gzdecode($content),
            'deflate' => gzinflate($content),
            default => false,
        };

        $this->assertIsString($decompressed);

        $decoded = json_decode($decompressed, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
