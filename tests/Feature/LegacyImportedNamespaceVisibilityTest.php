<?php

namespace Tests\Feature;

use App\Support\NamespaceWorkflowScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\StandaloneActivity\StandaloneActivityHostType;

class LegacyImportedNamespaceVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    #[DataProvider('listOwners')]
    public function test_lists_require_the_canonical_instance_namespace(string $path, ?string $instanceNamespace): void
    {
        $this->createNamespace('default');
        $this->seedImportedRun($instanceNamespace, $path === '/api/activities');
        $before = DB::table('workflow_instances')->where('id', 'legacy-instance')->first();
        $key = $path === '/api/activities' ? 'activities' : 'workflows';
        $expectedCount = $instanceNamespace === 'default' ? 1 : 0;

        $response = $this->getJson($path, $this->apiHeaders())
            ->assertOk()->assertJsonCount($expectedCount, $key);

        if ($expectedCount === 0) {
            $this->assertStringNotContainsString('private-owner-key', $response->getContent());
        } else {
            $response->assertJsonPath($key.'.0.business_key', 'private-owner-key');
        }

        if ($path === '/api/workflows') {
            $this->getJson($path.'?query=private-owner-key', $this->apiHeaders())
                ->assertOk()->assertJsonCount($expectedCount, $key);
        }

        $this->assertEquals($before, DB::table('workflow_instances')->where('id', 'legacy-instance')->first());
    }

    public static function listOwners(): iterable
    {
        foreach (['/api/workflows', '/api/activities'] as $path) {
            foreach (['other', 'DEFAULT', null, 'default'] as $namespace) {
                yield $path.' / '.($namespace ?? 'embedded null') => [$path, $namespace];
            }
        }
    }

    #[DataProvider('instanceOwners')]
    public function test_run_resolution_requires_the_canonical_instance_namespace(?string $instanceNamespace): void
    {
        $this->seedImportedRun($instanceNamespace);
        $expected = $instanceNamespace === 'default' ? 'legacy-run' : null;

        $this->assertSame($expected, NamespaceWorkflowScope::currentRun('default', 'legacy-instance')?->id);
        $this->assertSame($expected, NamespaceWorkflowScope::run('default', 'legacy-instance', 'legacy-run')?->id);
    }

    public static function instanceOwners(): iterable
    {
        foreach (['other', 'DEFAULT', null, 'default'] as $namespace) {
            yield $namespace ?? 'embedded null' => [$namespace];
        }
    }

    #[DataProvider('changedSummaryIdentity')]
    public function test_lists_do_not_trust_a_summary_with_a_different_canonical_run(string $path, string $column): void
    {
        $this->createNamespace('default');
        $this->seedImportedRun('default', $path === '/api/activities');
        if ($column === 'namespace') {
            DB::table('workflow_runs')->where('id', 'legacy-run')->update(['namespace' => 'other']);
        } else {
            DB::table('workflow_run_summaries')->where('id', 'legacy-run')
                ->update(['workflow_instance_id' => 'different-instance']);
        }

        $key = $path === '/api/activities' ? 'activities' : 'workflows';
        $this->getJson($path, $this->apiHeaders())->assertOk()->assertJsonCount(0, $key);
    }

    public static function changedSummaryIdentity(): iterable
    {
        foreach (['/api/workflows', '/api/activities'] as $path) {
            foreach (['namespace', 'workflow_instance_id'] as $column) {
                yield $path.' / '.$column => [$path, $column];
            }
        }
    }

    private function seedImportedRun(?string $instanceNamespace, bool $activity = false): void
    {
        $type = $activity ? StandaloneActivityHostType::WORKFLOW_TYPE : 'tests.external-greeting-workflow';
        $instance = new WorkflowInstance([
            'id' => 'legacy-instance', 'namespace' => $instanceNamespace,
            'workflow_class' => ExternalGreetingWorkflow::class, 'workflow_type' => $type,
            'business_key' => 'private-owner-key', 'run_count' => 1, 'current_run_id' => 'legacy-run',
        ]);
        $instance->saveQuietly();
        WorkflowRun::query()->create([
            'id' => 'legacy-run', 'workflow_instance_id' => $instance->id, 'namespace' => 'default',
            'workflow_class' => ExternalGreetingWorkflow::class, 'workflow_type' => $type,
            'status' => 'running', 'run_number' => 1, 'connection' => 'database',
            'queue' => 'default', 'payload_codec' => 'avro', 'started_at' => now(),
        ]);
        WorkflowRunSummary::query()->create([
            'id' => 'legacy-run', 'workflow_instance_id' => $instance->id, 'namespace' => 'default',
            'class' => ExternalGreetingWorkflow::class, 'workflow_type' => $type,
            'engine_source' => 'embedded_v2_import', 'projection_schema_version' => 2,
            'business_key' => 'private-owner-key', 'visibility_labels' => ['private-owner-label'],
            'status' => 'running', 'status_bucket' => 'running', 'run_number' => 1,
            'connection' => 'database', 'queue' => 'default', 'started_at' => now(), 'sort_timestamp' => now(),
        ]);
    }
}
