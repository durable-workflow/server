<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('workflow_task_protocol_leases');
    }

    public function down(): void
    {
        // The workflow_task_protocol_leases mirror table has been retired.
        // All lease tracking now uses the package's workflow_tasks table directly.
    }
};
