<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL can commit before its migration receipt. Resume this
        // additive ALTER safely, independently of create-table adoption.
        if (! Schema::hasColumn('workflow_worker_registrations', 'registration_token')) {
            Schema::table('workflow_worker_registrations', function (Blueprint $table): void {
                $table->string('registration_token', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('workflow_worker_registrations', function (Blueprint $table): void {
            $table->dropColumn('registration_token');
        });
    }
};
