<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Package migration 000142 adds the `memo` column to workflow_instances and
 * workflow_runs but omits workflow_run_summaries. Package migration 000146
 * then references `after('memo')` on workflow_run_summaries, causing a
 * failure. This server-side migration fills the gap so that 000146 can run.
 *
 * Remove when the package adds memo to workflow_run_summaries natively.
 */
return new class() extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('workflow_run_summaries', 'memo')) {
            return;
        }

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->json('memo')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropColumn('memo');
        });
    }
};
