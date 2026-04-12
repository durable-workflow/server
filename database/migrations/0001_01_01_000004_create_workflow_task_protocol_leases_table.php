<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_task_protocol_leases', function (Blueprint $table) {
            $table->string('task_id', 191)->primary();
            $table->string('namespace', 128);
            $table->string('workflow_instance_id', 191)->nullable();
            $table->string('workflow_run_id', 191)->nullable();
            $table->unsignedInteger('workflow_task_attempt')->default(0);
            $table->string('lease_owner', 255)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('last_claimed_at')->nullable();
            $table->timestamps();

            $table->index(['namespace', 'workflow_instance_id']);
            $table->index(['namespace', 'workflow_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_task_protocol_leases');
    }
};
