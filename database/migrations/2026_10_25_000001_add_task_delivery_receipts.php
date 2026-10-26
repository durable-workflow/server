<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_tasks', function (Blueprint $table): void {
            $table->string('delivery_poll_key', 64)->nullable()->index();
        });
        Schema::table('workflow_update_validation_tasks', function (Blueprint $table): void {
            $table->string('delivery_poll_key', 64)->nullable()->index();
            $table->json('delivery_receipt')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('workflow_tasks')) {
            Schema::table('workflow_tasks', function (Blueprint $table): void {
                $table->dropIndex(['delivery_poll_key']);
                $table->dropColumn('delivery_poll_key');
            });
        }
        if (Schema::hasTable('workflow_update_validation_tasks')) {
            Schema::table('workflow_update_validation_tasks', function (Blueprint $table): void {
                $table->dropIndex(['delivery_poll_key']);
                $table->dropColumn(['delivery_poll_key', 'delivery_receipt']);
            });
        }
    }
};
