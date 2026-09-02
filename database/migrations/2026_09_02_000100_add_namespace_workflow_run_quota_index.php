<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'workflow_runs_namespace_status_quota_idx';

    public function up(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->index(['namespace', 'status'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
