<?php

namespace Tests\Unit;

use App\Http\Middleware\CompressResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class CompressResponseTest extends TestCase
{
    private CompressResponse $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new CompressResponse;
    }

    public function test_it_compresses_large_json_responses_with_gzip(): void
    {
        $request = $this->makeRequest('gzip');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse($this->largePayload());
        });

        $this->assertSame('gzip', $response->headers->get('Content-Encoding'));
        $this->assertSame('Accept-Encoding', $response->headers->get('Vary'));
        $this->assertFalse($response->headers->has('Content-Length'));

        $decompressed = gzdecode($response->getContent());
        $this->assertIsString($decompressed);
        $this->assertJson($decompressed);
    }

    public function test_it_compresses_large_json_responses_with_deflate(): void
    {
        $request = $this->makeRequest('deflate');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse($this->largePayload());
        });

        $this->assertSame('deflate', $response->headers->get('Content-Encoding'));

        $decompressed = gzinflate($response->getContent());
        $this->assertIsString($decompressed);
        $this->assertJson($decompressed);
    }

    public function test_it_does_not_compress_small_responses(): void
    {
        $request = $this->makeRequest('gzip');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse(['ok' => true]);
        });

        $this->assertFalse($response->headers->has('Content-Encoding'));
        $this->assertJson($response->getContent());
    }

    public function test_it_does_not_compress_when_accept_encoding_is_missing(): void
    {
        $request = Request::create('/test', 'GET');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse($this->largePayload());
        });

        $this->assertFalse($response->headers->has('Content-Encoding'));
    }

    public function test_it_does_not_compress_when_disabled_via_config(): void
    {
        config(['server.compression.enabled' => false]);

        $request = $this->makeRequest('gzip');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse($this->largePayload());
        });

        $this->assertFalse($response->headers->has('Content-Encoding'));
    }

    public function test_it_prefers_gzip_over_deflate(): void
    {
        $request = $this->makeRequest('gzip, deflate');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse($this->largePayload());
        });

        $this->assertSame('gzip', $response->headers->get('Content-Encoding'));
    }

    public function test_it_honors_accept_encoding_quality_values(): void
    {
        $request = $this->makeRequest('gzip;q=0, deflate;q=1');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse($this->largePayload());
        });

        $this->assertSame('deflate', $response->headers->get('Content-Encoding'));
    }

    public function test_it_does_not_compress_when_supported_encodings_are_refused(): void
    {
        $request = $this->makeRequest('gzip;q=0, deflate;q=0');

        $response = $this->middleware->handle($request, function () {
            return new JsonResponse($this->largePayload());
        });

        $this->assertFalse($response->headers->has('Content-Encoding'));
        $this->assertJson($response->getContent());
    }

    public function test_it_does_not_double_compress(): void
    {
        $request = $this->makeRequest('gzip');

        $response = $this->middleware->handle($request, function () {
            $response = new JsonResponse($this->largePayload());
            $response->headers->set('Content-Encoding', 'gzip');

            return $response;
        });

        // Should still be the single Content-Encoding set by the inner handler
        $this->assertSame('gzip', $response->headers->get('Content-Encoding'));
    }

    private function makeRequest(string $acceptEncoding): Request
    {
        $request = Request::create('/test', 'GET');
        $request->headers->set('Accept-Encoding', $acceptEncoding);

        return $request;
    }

    /**
     * @return list<array{event: string, sequence: int}>
     */
    private function largePayload(): array
    {
        return array_map(
            static fn (int $i) => ['event' => 'WorkflowTaskScheduled', 'sequence' => $i],
            range(1, 100),
        );
    }
}
