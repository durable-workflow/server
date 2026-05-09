<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_durable_streams', function (Blueprint $table) {
            $table->id();

            $table->string('namespace', 128)->index();
            $table->string('workflow_instance_id', 191)->index();
            $table->string('workflow_run_id', 26)->index();
            $table->string('stream_name', 191);

            // Lifecycle: open (still accepting items), closed (producer
            // has signalled end-of-stream), errored (producer failed).
            $table->string('status', 16)->default('open')->index();

            // Last appended offset (sequence). Items use offsets [0..last_offset].
            // -1 means no items appended yet.
            $table->bigInteger('last_offset')->default(-1);
            $table->unsignedBigInteger('total_items')->default(0);

            // Optional retention bound in seconds (after stream close); the
            // namespace retention sweep removes expired closed streams.
            $table->unsignedInteger('retention_seconds')->nullable();

            $table->timestamp('opened_at', 6)->nullable();
            $table->timestamp('last_appended_at', 6)->nullable();
            $table->timestamp('closed_at', 6)->nullable();

            // Optional terminal error reason if status = 'errored'.
            $table->string('error_reason', 191)->nullable();

            // Bookkeeping for backpressure: items emitted but not yet
            // observed by any consumer. Kept advisory; consumers maintain
            // their own offset.
            $table->unsignedBigInteger('pending_items')->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps(6);

            // Unique stream identity per run.
            $table->unique(
                ['workflow_run_id', 'stream_name'],
                'wf_durable_streams_run_name_unique',
            );

            // Listing per-instance and per-namespace.
            $table->index(
                ['namespace', 'workflow_instance_id', 'stream_name'],
                'wf_durable_streams_ns_instance_name_idx',
            );
            $table->index(
                ['namespace', 'status'],
                'wf_durable_streams_ns_status_idx',
            );
        });

        Schema::create('workflow_durable_stream_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('stream_id');
            $table->string('namespace', 128)->index();
            $table->string('workflow_run_id', 26)->index();
            $table->string('stream_name', 191);

            // Per-stream monotonically-increasing offset (0-based).
            $table->bigInteger('offset');

            // Producer-supplied idempotency key for at-least-once producers.
            // When present, an append with the same key on the same stream
            // is a no-op that returns the previously assigned offset.
            $table->string('idempotency_key', 191)->nullable();

            // Origin of the item: 'workflow_command', 'signal_handler',
            // 'update_handler', 'external'. Free-form so SDKs can label
            // their own producers.
            $table->string('origin', 64)->default('workflow_command');

            // Optional reference to the originating record (e.g. signal
            // record id, update id, command id).
            $table->string('origin_reference', 191)->nullable();

            // Inline JSON payload (small items). Larger payloads use the
            // payload_reference field with namespace external storage.
            $table->json('payload')->nullable();
            $table->string('payload_reference', 191)->nullable();
            $table->string('payload_codec', 64)->nullable();

            // Item type discriminator (e.g. 'token', 'progress',
            // 'log_line', 'final'). Free-form; SDKs label their own.
            $table->string('item_type', 64)->nullable();

            // Optional content-type hint for binary payloads / chunked
            // protocols.
            $table->string('content_type', 191)->nullable();

            $table->timestamp('emitted_at', 6);

            $table->timestamps(6);

            // Per-stream offset is the durable order key.
            $table->unique(
                ['stream_id', 'offset'],
                'wf_durable_stream_items_stream_offset_unique',
            );

            // Consumer reads from this index: paginate by (stream, offset).
            $table->index(
                ['workflow_run_id', 'stream_name', 'offset'],
                'wf_durable_stream_items_run_name_offset_idx',
            );

            // Idempotency lookup is per (stream, key).
            $table->unique(
                ['stream_id', 'idempotency_key'],
                'wf_durable_stream_items_idempotency_unique',
            );

            $table->foreign('stream_id')
                ->references('id')
                ->on('workflow_durable_streams')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_durable_stream_items');
        Schema::dropIfExists('workflow_durable_streams');
    }
};
