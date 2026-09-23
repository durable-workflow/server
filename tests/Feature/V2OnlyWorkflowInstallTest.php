<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class V2OnlyWorkflowInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_server_install_omits_legacy_embedded_tables(): void
    {
        $this->assertFalse(config('workflows.v1.enabled'));
        $this->assertTrue(Schema::hasTable('workflow_runs'));

        foreach ([
            'workflows',
            'workflow_logs',
            'workflow_signals',
            'workflow_timers',
            'workflow_exceptions',
            'workflow_relationships',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected legacy table {$table}");
        }
    }
}
