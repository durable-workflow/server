<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_schedules', function (Blueprint $table) {
            $table->json('buffered_actions')->nullable()->after('recent_actions');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_schedules', function (Blueprint $table) {
            $table->dropColumn('buffered_actions');
        });
    }
};
