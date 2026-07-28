<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_namespaces', function (Blueprint $table): void {
            $table->string('retention_mode', 32)
                ->default('bounded')
                ->after('description');
            $table->unsignedInteger('retention_days')->nullable()->default(30)->change();
        });

        DB::table('workflow_namespaces')->update([
            'retention_mode' => 'bounded',
        ]);
    }

    public function down(): void
    {
        DB::table('workflow_namespaces')
            ->whereNull('retention_days')
            ->update(['retention_days' => 30]);

        Schema::table('workflow_namespaces', function (Blueprint $table): void {
            $table->dropColumn('retention_mode');
            $table->unsignedInteger('retention_days')->nullable(false)->default(30)->change();
        });
    }
};
