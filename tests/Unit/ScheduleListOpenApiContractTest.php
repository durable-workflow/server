<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ScheduleListOpenApiContractTest extends TestCase
{
    public function test_schedule_list_publishes_namespace_carriers_precedence_and_token_scope(): void
    {
        $specPath = dirname(__DIR__, 2).'/resources/platform-protocol-specs/control-plane-api.openapi.yaml';
        $this->assertFileExists($specPath);

        $spec = Yaml::parseFile($specPath);
        $operation = $spec['paths']['/schedules']['get'];
        $parameterRefs = array_column($operation['parameters'], '$ref');

        $this->assertContains(
            '#/components/parameters/NamespaceHeaderOptional',
            $parameterRefs,
        );
        $this->assertContains(
            '#/components/parameters/NamespaceQueryOptional',
            $parameterRefs,
        );

        $header = $spec['components']['parameters']['NamespaceHeaderOptional'];
        $this->assertSame('X-Namespace', $header['name']);
        $this->assertSame('header', $header['in']);
        $this->assertFalse($header['required']);
        $this->assertSame(['type' => 'string', 'minLength' => 1], $header['schema']);

        $query = $spec['components']['parameters']['NamespaceQueryOptional'];
        $this->assertSame('namespace', $query['name']);
        $this->assertSame('query', $query['in']);
        $this->assertFalse($query['required']);
        $this->assertSame(['type' => 'string', 'minLength' => 1], $query['schema']);

        $this->assertSame([
            'carriers' => [
                ['in' => 'header', 'name' => 'X-Namespace'],
                ['in' => 'query', 'name' => 'namespace'],
            ],
            'precedence' => ['header', 'query', 'server_default'],
            'continuation_token_scope' => 'resolved_namespace',
        ], $operation['x-durable-workflow-namespace-routing']);

        $tokenDescription = $spec['components']['parameters']['ScheduleNextPageTokenQuery']['description'];
        $this->assertStringContainsString('bound to namespace', $tokenDescription);
    }
}
