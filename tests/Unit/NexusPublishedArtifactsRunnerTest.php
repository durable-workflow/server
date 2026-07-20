<?php

namespace Tests\Unit;

use Tests\TestCase;

class NexusPublishedArtifactsRunnerTest extends TestCase
{
    public function test_php_sdk_service_shard_routes_to_the_target_namespace_without_losing_caller_attribution(): void
    {
        $script = dirname(__DIR__, 2).'/scripts/conformance/nexus-published-artifacts.sh';
        $contents = (string) file_get_contents($script);

        $matched = preg_match(
            '/function executePublishedPhpSdkServiceOperation\((?P<signature>.*?)\n\) \{(?P<body>.*?)\n\}\n\nasync function setupCrossLanguageService/s',
            $contents,
            $executeFunction,
        );

        $this->assertSame(1, $matched);
        $this->assertStringContainsString('namespace: operation.targetNamespace,', $executeFunction['body']);
        $this->assertStringContainsString('caller_namespace: callerNamespace,', $executeFunction['body']);
        $this->assertStringContainsString('caller_workflow_id: callerWorkflowId,', $executeFunction['body']);
        $this->assertStringContainsString('caller_run_id: callerRunId,', $executeFunction['body']);
        $this->assertStringContainsString("namespace: (string) \$input['namespace'],", $executeFunction['body']);
        $this->assertStringContainsString("callerNamespace: (string) \$input['caller_namespace'],", $executeFunction['body']);
        $this->assertStringContainsString("callerWorkflowId: (string) \$input['caller_workflow_id'],", $executeFunction['body']);
        $this->assertStringContainsString("callerRunId: (string) \$input['caller_run_id'],", $executeFunction['body']);
        $this->assertDoesNotMatchRegularExpression('/\n    namespace: callerNamespace,/', $executeFunction['body']);

        $matched = preg_match(
            '/async function setupCrossLanguageService\((?P<signature>.*?)\) \{(?P<body>.*?)\n\}/s',
            $contents,
            $setupFunction,
        );

        $this->assertSame(1, $matched);
        $this->assertStringContainsString("const targetNamespace = 'shared';", $setupFunction['body']);
        $this->assertStringContainsString('return {targetNamespace, endpointName, serviceName, operationName, endpoint, service, operation};', $setupFunction['body']);
    }
}
