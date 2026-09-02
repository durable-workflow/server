<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class RuntimeExternalPayloadOpenApiContractTest extends TestCase
{
    public function test_runtime_external_payload_openapi_is_parseable_and_complete(): void
    {
        $path = dirname(__DIR__, 2).'/resources/platform-protocol-specs/external-payload-transport.openapi.yaml';
        $spec = Yaml::parseFile($path);

        $this->assertSame('3.1.0', $spec['openapi'] ?? null);
        $this->assertSame('1', $spec['info']['version'] ?? null);
        $this->assertSame('runtime_external_payload_transport', $spec['x-durable-workflow-catalog-entry'] ?? null);
        $this->assertSame(
            'durable-workflow.v2.platform-protocol-specs.catalog',
            $spec['x-durable-workflow-catalog-schema'] ?? null,
        );
        $this->assertSame(
            'durable-workflow.v2.runtime-external-payload-reference.v1',
            $spec['components']['schemas']['RuntimeExternalPayloadReference']['properties']['schema']['const'] ?? null,
        );
        $this->assertSame(
            ['schema', 'reference_id', 'codec', 'size_bytes', 'sha256'],
            $spec['components']['schemas']['RuntimeExternalPayloadReference']['required'] ?? null,
        );

        foreach (['post', 'get'] as $method) {
            $operation = $method === 'post'
                ? $spec['paths']['/external-payloads/v1'][$method] ?? null
                : $spec['paths']['/external-payloads/v1/{referenceId}'][$method] ?? null;

            $this->assertIsArray($operation);
            $this->assertSame(['worker', 'operator', 'admin'], $operation['x-durable-workflow-required-roles'] ?? null);
        }

        $reasons = $spec['components']['schemas']['RuntimeExternalPayloadError']['properties']['reason']['enum'] ?? [];
        $this->assertSame([
            'external_payload_not_found',
            'external_payload_expired',
            'external_payload_unauthorized',
            'external_payload_unavailable',
            'external_payload_oversized',
            'external_payload_unsupported',
            'external_payload_integrity_mismatch',
            'external_payload_namespace_bytes_exhausted',
            'external_payload_namespace_objects_exhausted',
            'external_payload_namespace_quota_unavailable',
        ], $reasons);

        $this->assertSame(
            '#/components/responses/NamespaceQuotaExceeded',
            $spec['paths']['/external-payloads/v1']['post']['responses']['429']['$ref'] ?? null,
        );
        $this->assertSame(
            1,
            $spec['components']['schemas']['RuntimeExternalPayloadError']['properties']['retry_after_seconds']['minimum'] ?? null,
        );
    }
}
