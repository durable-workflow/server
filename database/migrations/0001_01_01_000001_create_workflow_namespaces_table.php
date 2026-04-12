<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_namespaces', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->string('description', 1000)->nullable();
            $table->unsignedInteger('retention_days')->default(30);
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_namespaces');
    }
};
