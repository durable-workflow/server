<?php

declare(strict_types=1);

use App\Support\WorkflowMemoPayloadMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        WorkflowMemoPayloadMigration::ensureExpandedSchema();

        $recoverLegacyEnvelopeStorage = Schema::hasTable('migrations')
            && DB::table('migrations')
                ->where(
                    'migration',
                    '2026_08_25_000100_encode_workflow_memos_for_portable_runtime',
                )
                ->exists();

        WorkflowMemoPayloadMigration::backfillAll($recoverLegacyEnvelopeStorage);
    }

    public function down(): void
    {
        // The Workflow package owns the expanded columns. Retaining the
        // compatibility projection also keeps a subsequent image rollback
        // readable by the predecessor.
    }
};
