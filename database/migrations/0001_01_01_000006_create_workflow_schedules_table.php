<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('schedule_id', 128)->index();
            $table->string('namespace', 128)->index();

            // Spec — cron expressions, intervals, timezone
            $table->json('spec');

            // Action — workflow type, task queue, input, timeouts
            $table->json('action');

            // Policy
            $table->string('overlap_policy', 32)->default('skip');

            // State
            $table->boolean('paused')->default(false);
            $table->string('note', 1000)->nullable();

            // Memo and search attributes carried to started workflows
            $table->json('memo')->nullable();
            $table->json('search_attributes')->nullable();

            // Execution tracking
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamp('next_fire_at')->nullable()->index();
            $table->unsignedInteger('fires_count')->default(0);
            $table->unsignedInteger('failures_count')->default(0);
            $table->json('recent_actions')->nullable();

            $table->timestamps();

            $table->unique(['namespace', 'schedule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_schedules');
    }
};
