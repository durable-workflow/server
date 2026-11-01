<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_external_payload_deletions', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('namespace', 191);
            $table->text('storage_uri');
            $table->string('storage_uri_sha256', 64);
            $table->json('storage_policy');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_code')->nullable();
            $table->timestamps();
            $table->unique(['namespace', 'storage_uri_sha256'], 'runtime_payload_deletions_namespace_uri_unique');
            $table->index(['namespace', 'created_at'], 'runtime_payload_deletions_namespace_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_external_payload_deletions');
    }
};
