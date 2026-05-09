<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_worker_registrations', function (Blueprint $table) {
            $table->unsignedInteger('available_workflow_slots')
                ->nullable()
                ->after('max_concurrent_worker_sessions');
            $table->unsignedInteger('available_activity_slots')
                ->nullable()
                ->after('available_workflow_slots');
            $table->unsignedInteger('available_session_slots')
                ->nullable()
                ->after('available_activity_slots');
            $table->json('process_metrics')
                ->nullable()
                ->after('available_session_slots');
            $table->unsignedInteger('heartbeat_interval_seconds')
                ->nullable()
                ->after('process_metrics');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_worker_registrations', function (Blueprint $table) {
            $table->dropColumn([
                'available_workflow_slots',
                'available_activity_slots',
                'available_session_slots',
                'process_metrics',
                'heartbeat_interval_seconds',
            ]);
        });
    }
};
