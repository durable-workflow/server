<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_payload_completion_budgets', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->string('namespace', 128)->index();
            $table->json('context');
            $table->json('slots');
            $table->json('objects');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_payload_completion_budgets');
    }
};
