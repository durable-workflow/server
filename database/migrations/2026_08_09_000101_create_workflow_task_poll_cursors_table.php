<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_task_poll_cursors', static function (Blueprint $table): void {
            $table->string('namespace', 128);
            $table->string('task_queue', 255);
            $table->string('next_task_kind', 32);
            $table->timestamps(6);

            $table->primary(['namespace', 'task_queue'], 'workflow_task_poll_cursors_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_task_poll_cursors');
    }
};
