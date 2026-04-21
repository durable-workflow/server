<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workflow_worker_registrations', 'workflow_definition_fingerprints')) {
            Schema::table('workflow_worker_registrations', function (Blueprint $table): void {
                $table->json('workflow_definition_fingerprints')->nullable()->after('supported_workflow_types');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('workflow_worker_registrations', 'workflow_definition_fingerprints')) {
            Schema::table('workflow_worker_registrations', function (Blueprint $table): void {
                $table->dropColumn('workflow_definition_fingerprints');
            });
        }
    }
};
