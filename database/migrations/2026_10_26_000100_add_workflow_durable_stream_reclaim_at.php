<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_durable_streams', function (Blueprint $table) {
            // When the retention pass may reclaim a closed or errored
            // stream's items: closed_at plus its retention (Server #774).
            // Null while the stream is open and once its items are gone; the
            // stream row itself stays until its run is pruned.
            $table->timestamp('reclaim_at', 6)->nullable();
            // The retention pass reads one namespace's due streams in
            // deadline order from this index.
            $table->index(['namespace', 'reclaim_at', 'id'], 'wf_durable_streams_ns_reclaim_idx');
        });

        DB::table('workflow_durable_streams')
            ->whereIn('status', ['closed', 'errored'])
            ->whereNotNull('closed_at')
            ->orderBy('id')
            ->chunkById(500, function ($streams): void {
                foreach ($streams as $stream) {
                    DB::table('workflow_durable_streams')
                        ->where('id', $stream->id)
                        ->update(['reclaim_at' => Carbon::parse($stream->closed_at, 'UTC')
                            ->addSeconds((int) ($stream->retention_seconds ?? 3600))]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('workflow_durable_streams', function (Blueprint $table) {
            $table->dropIndex('wf_durable_streams_ns_reclaim_idx');
            $table->dropColumn('reclaim_at');
        });
    }
};
