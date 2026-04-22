<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_namespaces', function (Blueprint $table): void {
            $table->json('external_payload_storage')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_namespaces', function (Blueprint $table): void {
            $table->dropColumn('external_payload_storage');
        });
    }
};
