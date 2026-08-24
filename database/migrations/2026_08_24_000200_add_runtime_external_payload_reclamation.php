<?php

use App\Support\RuntimeExternalPayloadObjectLock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runtime_external_payloads', function (Blueprint $table): void {
            $table->string('upload_status', 16)->default('ready')->after('size_bytes');
            $table->index(
                ['retained_at', 'expires_at', 'upload_status'],
                'runtime_external_payloads_reclamation_idx',
            );
        });

        Schema::create('runtime_external_payload_object_locks', function (Blueprint $table): void {
            $table->unsignedSmallInteger('bucket')->primary();
        });

        DB::table('runtime_external_payload_object_locks')->insert(array_map(
            static fn (int $bucket): array => ['bucket' => $bucket],
            range(0, RuntimeExternalPayloadObjectLock::BUCKETS - 1),
        ));

        Schema::create('runtime_external_payload_cleanup_stats', function (Blueprint $table): void {
            $table->string('namespace', 128)->primary();
            $table->unsignedBigInteger('passes_total')->default(0);
            $table->unsignedBigInteger('deleted_references_total')->default(0);
            $table->unsignedBigInteger('deleted_backing_objects_total')->default(0);
            $table->unsignedBigInteger('shared_objects_preserved_total')->default(0);
            $table->unsignedBigInteger('blocked_outcomes_total')->default(0);
            $table->unsignedBigInteger('storage_driver_failures_total')->default(0);
            $table->unsignedInteger('last_processed')->default(0);
            $table->unsignedInteger('last_deleted_references')->default(0);
            $table->unsignedInteger('last_deleted_backing_objects')->default(0);
            $table->unsignedInteger('last_shared_objects_preserved')->default(0);
            $table->unsignedInteger('last_blocked_outcomes')->default(0);
            $table->unsignedInteger('last_storage_driver_failures')->default(0);
            $table->string('last_pass_status', 16)->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->timestamp('last_storage_failure_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_external_payload_cleanup_stats');
        Schema::dropIfExists('runtime_external_payload_object_locks');

        Schema::table('runtime_external_payloads', function (Blueprint $table): void {
            $table->dropIndex('runtime_external_payloads_reclamation_idx');
            $table->dropColumn('upload_status');
        });
    }
};
