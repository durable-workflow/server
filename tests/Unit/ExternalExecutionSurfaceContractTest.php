<?php

namespace Tests\Unit;

use App\Support\ExternalExecutionSurfaceContract;
use App\Support\ExternalTaskInputContract;
use App\Support\ExternalTaskResultContract;
use PHPUnit\Framework\TestCase;

class ExternalExecutionSurfaceContractTest extends TestCase
{
    public function test_manifest_defines_activity_grade_external_execution_boundary(): void
    {
        $manifest = ExternalExecutionSurfaceContract::manifest();

        $this->assertSame('durable-workflow.v2.external-execution-surface.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('activity_grade_external_execution', $manifest['product_boundary']['name']);
        $this->assertSame('operator_platform_integration', $manifest['product_boundary']['primary_wedge']);
        $this->assertTrue($manifest['product_boundary']['contract_first']);
        $this->assertTrue($manifest['product_boundary']['carrier_second']);
        $this->assertContains('execute one leased workflow or activity task', $manifest['runtime_boundary']['external_handlers_may']);
        $this->assertContains('interpret workflow replay semantics', $manifest['runtime_boundary']['external_handlers_must_not']);
        $this->assertContains('external_handlers_as_replay_runtimes', $manifest['non_goals']);
    }

    public function test_manifest_links_published_external_task_contract_seams(): void
    {
        $manifest = ExternalExecutionSurfaceContract::manifest();

        $this->assertSame(
            ExternalTaskInputContract::SCHEMA,
            $manifest['contract_seams']['input_envelope']['schema'],
        );
        $this->assertSame(
            ExternalTaskResultContract::SCHEMA,
            $manifest['contract_seams']['result_envelope']['schema'],
        );
        $this->assertSame('published', $manifest['contract_seams']['input_envelope']['status']);
        $this->assertSame('published', $manifest['contract_seams']['result_envelope']['status']);
        $this->assertSame('planned', $manifest['contract_seams']['handler_mappings']['status']);
        $this->assertSame('planned', $manifest['contract_seams']['bridge_adapters']['status']);
    }

    public function test_document_mentions_every_contract_seam(): void
    {
        $manifest = ExternalExecutionSurfaceContract::manifest();
        $document = file_get_contents(dirname(__DIR__, 2).'/docs/contracts/external-execution-surface.md');

        $this->assertNotFalse($document);
        $this->assertStringContainsString($manifest['schema'], $document);
        $this->assertStringContainsString($manifest['product_boundary']['name'], $document);

        foreach (array_keys($manifest['contract_seams']) as $seam) {
            $this->assertStringContainsString($seam, $document);
        }
    }
}
