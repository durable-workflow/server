<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_namespace_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('namespace', 128);
            $table->string('workflow_instance_id', 191);
            $table->string('workflow_type')->nullable();
            $table->timestamps();

            $table->unique('workflow_instance_id');
            $table->unique(['namespace', 'workflow_instance_id']);
            $table->index(['namespace', 'workflow_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_namespace_workflows');
    }
};
