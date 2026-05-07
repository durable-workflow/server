<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_worker_registrations', function (Blueprint $table) {
            $table->json('capabilities')
                ->nullable()
                ->after('supported_activity_types');
            $table->unsignedInteger('max_concurrent_worker_sessions')
                ->default(10)
                ->after('max_concurrent_activity_tasks');
        });

        Schema::create('workflow_worker_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('namespace', 128);
            $table->string('session_id', 255);
            $table->string('connection', 255)->nullable();
            $table->string('queue', 255)->nullable();
            $table->json('requirements')->nullable();
            $table->string('status', 32)->default('active');
            $table->string('lease_owner', 255)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('ttl_expires_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('failure_reason', 128)->nullable();
            $table->unsignedInteger('lease_seconds')->default(120);
            $table->unsignedInteger('ttl_seconds')->default(1800);
            $table->unsignedInteger('max_concurrent_activities')->default(1);
            $table->boolean('create_if_missing')->default(true);
            $table->boolean('allow_reacquire_after_failure')->default(true);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();

            $table->unique(['namespace', 'session_id']);
            $table->index(['namespace', 'status']);
            $table->index(['namespace', 'lease_owner', 'status']);
            $table->index(['namespace', 'queue', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_worker_sessions');

        Schema::table('workflow_worker_registrations', function (Blueprint $table) {
            $table->dropColumn(['capabilities', 'max_concurrent_worker_sessions']);
        });
    }
};
