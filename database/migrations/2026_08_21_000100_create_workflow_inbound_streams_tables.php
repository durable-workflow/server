<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_inbound_streams', function (Blueprint $table) {
            $table->id();
            $table->string('namespace', 128)->index();
            $table->string('workflow_instance_id', 191)->index();
            $table->string('stream_name', 128);
            $table->unsignedBigInteger('last_position')->default(0);
            $table->unsignedBigInteger('cursor_position')->default(0);
            $table->string('cursor_checkpoint_run_id', 26)->nullable()->index();
            $table->string('waiting_run_id', 26)->nullable()->index();
            $table->unsignedBigInteger('waiting_after_position')->nullable();
            $table->timestamp('waiting_since', 6)->nullable();
            $table->unsignedBigInteger('duplicate_count')->default(0);
            $table->unsignedBigInteger('malformed_count')->default(0);
            $table->string('last_input_outcome', 64)->nullable();
            $table->string('last_input_message_id', 191)->nullable();
            $table->timestamp('last_input_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(
                ['namespace', 'workflow_instance_id', 'stream_name'],
                'wf_inbound_streams_ns_instance_name_unique',
            );
        });

        Schema::create('workflow_inbound_stream_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stream_id');
            $table->string('namespace', 128)->index();
            $table->string('workflow_instance_id', 191)->index();
            $table->string('stream_name', 128);
            $table->string('message_id', 191);
            $table->unsignedBigInteger('position');
            $table->string('payload_codec', 32);
            $table->longText('payload_blob');
            $table->string('payload_hash', 64);
            $table->string('delivered_run_id', 26)->nullable()->index();
            $table->string('workflow_command_id', 26)->nullable()->index();
            $table->timestamp('delivered_at', 6)->nullable();
            $table->string('consumed_run_id', 26)->nullable()->index();
            $table->string('consumed_task_id', 26)->nullable()->index();
            $table->timestamp('consumed_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['stream_id', 'message_id'], 'wf_inbound_items_stream_message_unique');
            $table->unique(['stream_id', 'position'], 'wf_inbound_items_stream_position_unique');
            $table->index(
                ['namespace', 'workflow_instance_id', 'stream_name', 'position'],
                'wf_inbound_items_ns_instance_name_position_idx',
            );
            $table->foreign('stream_id')
                ->references('id')
                ->on('workflow_inbound_streams')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_inbound_stream_items');
        Schema::dropIfExists('workflow_inbound_streams');
    }
};
