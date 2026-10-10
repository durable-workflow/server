<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_worker_registration_incarnations', function (Blueprint $table): void {
            $table->string('token', 32)->primary();
            $table->string('namespace');
            $table->string('worker_id');
            $table->string('status', 32);
            $table->unsignedInteger('recovered_workflow_task_count')->default(0);
            $table->timestamp('finished_at', 6)->nullable()->index();
            $table->timestamps(6);
            $table->index(['namespace', 'worker_id'], 'dw_worker_incarnation_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_worker_registration_incarnations');
    }
};
