<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('namespace', 128)->index();
            $table->string('name', 128);
            $table->string('type', 32);
            $table->timestamps();

            $table->unique(['namespace', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_attribute_definitions');
    }
};
