<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_credentials', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('name')->nullable();
            $table->string('subject');
            $table->json('roles');
            $table->string('tenant', 128)->index();
            $table->json('claims')->nullable();
            $table->string('token_prefix', 16);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_credentials');
    }
};
