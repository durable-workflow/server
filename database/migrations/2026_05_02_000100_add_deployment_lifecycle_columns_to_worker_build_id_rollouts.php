<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflow_worker_build_id_rollouts')) {
            return;
        }

        Schema::table('workflow_worker_build_id_rollouts', function (Blueprint $table) {
            // First-class deployment lifecycle audit timestamps.
            // The legacy `drain_intent` / `drained_at` pair stays the
            // authority on drain state; these columns extend the row
            // with the promote/rollback transitions the deployment
            // lifecycle surface needs without breaking existing
            // build-id callers.
            if (! Schema::hasColumn('workflow_worker_build_id_rollouts', 'promoted_at')) {
                $table->timestamp('promoted_at')->nullable();
            }

            if (! Schema::hasColumn('workflow_worker_build_id_rollouts', 'rolled_back_at')) {
                $table->timestamp('rolled_back_at')->nullable();
            }

            if (! Schema::hasColumn('workflow_worker_build_id_rollouts', 'required_compatibility')) {
                $table->string('required_compatibility', 255)->nullable();
            }

            if (! Schema::hasColumn('workflow_worker_build_id_rollouts', 'recorded_fingerprint')) {
                $table->string('recorded_fingerprint', 255)->nullable();
            }

            if (! Schema::hasColumn('workflow_worker_build_id_rollouts', 'compatibility_policy')) {
                $table->string('compatibility_policy', 32)->nullable();
            }

            if (! Schema::hasColumn('workflow_worker_build_id_rollouts', 'workflow_types')) {
                $table->json('workflow_types')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workflow_worker_build_id_rollouts')) {
            return;
        }

        Schema::table('workflow_worker_build_id_rollouts', function (Blueprint $table) {
            foreach ([
                'promoted_at',
                'rolled_back_at',
                'required_compatibility',
                'recorded_fingerprint',
                'compatibility_policy',
                'workflow_types',
            ] as $column) {
                if (Schema::hasColumn('workflow_worker_build_id_rollouts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
