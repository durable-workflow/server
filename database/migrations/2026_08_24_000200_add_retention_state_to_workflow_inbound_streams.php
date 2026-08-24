<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_inbound_streams', function (Blueprint $table): void {
            $table->timestamp('cleanup_blocked_at', 6)->nullable();
            $table->string('cleanup_blocked_reason', 64)->nullable();
            $table->string('cleanup_blocked_run_id', 26)->nullable()->index();
        });

        Schema::table('workflow_inbound_stream_items', function (Blueprint $table): void {
            $table->timestamp('payload_released_at', 6)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_inbound_stream_items', function (Blueprint $table): void {
            $table->dropIndex(['payload_released_at']);
            $table->dropColumn('payload_released_at');
        });

        Schema::table('workflow_inbound_streams', function (Blueprint $table): void {
            $table->dropIndex(['cleanup_blocked_run_id']);
            $table->dropColumn([
                'cleanup_blocked_at',
                'cleanup_blocked_reason',
                'cleanup_blocked_run_id',
            ]);
        });
    }
};
