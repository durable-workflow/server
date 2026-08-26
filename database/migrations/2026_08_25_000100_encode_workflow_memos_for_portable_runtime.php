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
        // The expand phase deliberately keeps the predecessor-readable value.
        // Contracting the compatibility columns belongs to a later release
        // after rollback to the predecessor is no longer supported.
    }
};
