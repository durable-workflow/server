<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_task_protocol_leases', function (Blueprint $table): void {
            $table->string('last_poll_request_id', 255)
                ->nullable()
                ->after('last_claimed_at');

            $table->index(
                ['namespace', 'lease_owner', 'last_poll_request_id'],
                'workflow_task_protocol_leases_poll_request_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::table('workflow_task_protocol_leases', function (Blueprint $table): void {
            $table->dropIndex('workflow_task_protocol_leases_poll_request_lookup');
            $table->dropColumn('last_poll_request_id');
        });
    }
};
