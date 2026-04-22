<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_worker_build_id_rollouts', function (Blueprint $table) {
            $table->id();
            $table->string('namespace', 128);
            $table->string('task_queue', 255);
            // Stores the worker build_id or an empty string for the
            // unversioned cohort (pre-rollout default). Non-null so the
            // unique index below behaves consistently across backends —
            // several databases treat null values as distinct in unique
            // indexes, which would allow duplicate unversioned rows.
            $table->string('build_id', 255)->default('');
            $table->string('drain_intent', 32)->default('active');
            $table->timestamp('drained_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['namespace', 'task_queue', 'build_id'],
                'workflow_build_id_rollouts_scope_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_worker_build_id_rollouts');
    }
};
