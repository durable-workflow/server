<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_worker_registrations', function (Blueprint $table): void {
            $table->json('capability_manifest')
                ->nullable()
                ->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_worker_registrations', function (Blueprint $table): void {
            $table->dropColumn('capability_manifest');
        });
    }
};
