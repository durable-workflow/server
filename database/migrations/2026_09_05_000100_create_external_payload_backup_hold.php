<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_external_payload_backup_hold', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('owner')->nullable();
            $table->timestamp('acquired_at')->nullable();
            $table->timestamp('expires_at')->nullable();
        });
        DB::table('runtime_external_payload_backup_hold')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_external_payload_backup_hold');
    }
};
