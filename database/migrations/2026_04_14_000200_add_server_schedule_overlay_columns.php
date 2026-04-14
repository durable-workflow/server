<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_schedules', function (Blueprint $table) {
            $table->json('spec')->nullable()->after('namespace');
            $table->json('action')->nullable()->after('spec');
            $table->boolean('paused')->default(false)->after('overlap_policy');
            $table->string('note', 1000)->nullable()->after('paused');
            $table->timestamp('next_fire_at')->nullable()->index()->after('note');
            $table->timestamp('last_fired_at')->nullable()->after('next_fire_at');
            $table->unsignedInteger('fires_count')->default(0)->after('last_fired_at');
            $table->unsignedInteger('failures_count')->default(0)->after('fires_count');
            $table->json('recent_actions')->nullable()->after('failures_count');
            $table->json('buffered_actions')->nullable()->after('recent_actions');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'spec', 'action', 'paused', 'note',
                'next_fire_at', 'last_fired_at',
                'fires_count', 'failures_count',
                'recent_actions', 'buffered_actions',
            ]);
        });
    }
};
