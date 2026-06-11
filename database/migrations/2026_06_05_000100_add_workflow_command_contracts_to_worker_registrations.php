<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workflow_worker_registrations', 'workflow_command_contracts')) {
            Schema::table('workflow_worker_registrations', function (Blueprint $table): void {
                $table->json('workflow_command_contracts')->nullable()->after('workflow_definition_fingerprints');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('workflow_worker_registrations', 'workflow_command_contracts')) {
            Schema::table('workflow_worker_registrations', function (Blueprint $table): void {
                $table->dropColumn('workflow_command_contracts');
            });
        }
    }
};
