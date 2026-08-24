<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_external_payloads', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('namespace', 128)->index();
            $table->text('storage_uri');
            $table->char('storage_uri_sha256', 64);
            $table->string('codec', 64);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamp('retained_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['namespace', 'storage_uri_sha256'], 'runtime_external_payloads_namespace_uri_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_external_payloads');
    }
};
