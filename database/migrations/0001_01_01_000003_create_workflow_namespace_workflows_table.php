<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('workflow_namespace_workflows');
    }

    public function down(): void
    {
        // The bridge table has been retired — all namespace data lives on
        // the package's native namespace column (workflow_instances,
        // workflow_runs, workflow_tasks, workflow_run_summaries).
    }
};
