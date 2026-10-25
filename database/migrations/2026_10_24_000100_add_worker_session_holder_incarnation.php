<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_worker_sessions', function (Blueprint $table) {
            // SHA-256 of the holder registration's token. A same-ID
            // re-registration rotates the token, which orphans the session.
            $table->char('lease_owner_incarnation', 64)->nullable()->after('lease_owner');
        });

        // Each active session stays with the registration that holds it now,
        // so upgrading does not orphan sessions whose workers keep running.
        DB::table('workflow_worker_sessions')
            ->where('status', 'active')
            ->whereNotNull('lease_owner')
            ->orderBy('id')
            ->chunkById(500, function ($sessions): void {
                foreach ($sessions as $session) {
                    $token = DB::table('workflow_worker_registrations')
                        ->where('namespace', $session->namespace)
                        ->where('worker_id', $session->lease_owner)
                        ->value('registration_token');

                    if (is_string($token)) {
                        DB::table('workflow_worker_sessions')
                            ->where('id', $session->id)
                            ->update(['lease_owner_incarnation' => hash('sha256', $token)]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('workflow_worker_sessions', function (Blueprint $table) {
            $table->dropColumn('lease_owner_incarnation');
        });
    }
};
