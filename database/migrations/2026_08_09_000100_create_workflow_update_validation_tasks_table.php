<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_update_validation_tasks', static function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('idempotency_key', 64)->unique();
            $table->string('namespace')->index();
            $table->string('workflow_instance_id', 191)->index();
            $table->string('workflow_run_id', 26)->index();
            $table->string('workflow_type')->index();
            $table->string('task_queue')->index();
            $table->string('compatibility')->nullable()->index();
            $table->string('workflow_definition_fingerprint')->nullable();
            $table->string('update_name')->index();
            $table->string('request_id', 255);
            $table->string('input_hash', 64);
            $table->string('payload_codec');
            $table->longText('arguments');
            $table->json('command_context')->nullable();
            $table->string('status')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('lease_owner')->nullable()->index();
            $table->timestamp('lease_expires_at', 6)->nullable()->index();
            $table->string('rejection_reason')->nullable();
            $table->text('rejection_message')->nullable();
            $table->string('failure_type')->nullable();
            $table->json('validation_errors')->nullable();
            $table->timestamp('approved_at', 6)->nullable();
            $table->timestamp('rejected_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();
            $table->timestamp('timed_out_at', 6)->nullable();
            $table->timestamps(6);

            $table->index(
                ['namespace', 'task_queue', 'status', 'created_at'],
                'workflow_update_validation_poll_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_update_validation_tasks');
    }
};
