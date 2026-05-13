<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_run_summaries', function (Blueprint $table): void {
            $table->index(
                ['namespace', 'queue', 'workflow_type'],
                'wfrs_prom_metrics_series_idx',
            );
        });

        Schema::table('activity_executions', function (Blueprint $table): void {
            $table->index(
                ['workflow_run_id', 'queue', 'activity_type'],
                'activity_exec_prom_metrics_series_idx',
            );
        });

        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->index(
                ['namespace', 'queue'],
                'workflow_runs_prom_metrics_queue_idx',
            );
        });

        Schema::table('workflow_tasks', function (Blueprint $table): void {
            $table->index(
                ['namespace', 'queue'],
                'workflow_tasks_prom_metrics_queue_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('workflow_tasks', function (Blueprint $table): void {
            $table->dropIndex('workflow_tasks_prom_metrics_queue_idx');
        });

        Schema::table('activity_executions', function (Blueprint $table): void {
            $table->dropIndex('activity_exec_prom_metrics_series_idx');
        });

        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->dropIndex('workflow_runs_prom_metrics_queue_idx');
        });

        Schema::table('workflow_run_summaries', function (Blueprint $table): void {
            $table->dropIndex('wfrs_prom_metrics_series_idx');
        });
    }
};
