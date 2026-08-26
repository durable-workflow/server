<?php

declare(strict_types=1);

use App\Support\WorkflowMemoPayloadMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $legacyEnvelopeCutoff = WorkflowMemoPayloadMigration::legacyEnvelopeCutoff();

        WorkflowMemoPayloadMigration::ensureExpandedSchema();
        WorkflowMemoPayloadMigration::backfillAll($legacyEnvelopeCutoff);
    }

    public function down(): void
    {
        // The Workflow package owns the expanded columns. Retaining the
        // compatibility projection also keeps a subsequent image rollback
        // readable by the predecessor.
    }
};
