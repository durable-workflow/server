<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Tombstone: the overlay columns (spec, action, paused, note, next_fire_at,
 * last_fired_at, fires_count, failures_count, recent_actions, buffered_actions)
 * now live in the package's create_workflow_schedules_table migration.
 *
 * This migration is retained so that environments that already ran it do not
 * see it as pending, but it performs no schema changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op — columns are now created by the package migration.
    }

    public function down(): void
    {
        // No-op.
    }
};
