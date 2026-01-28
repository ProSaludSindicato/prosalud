<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('request_status_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('request_status_logs', 'reason')) {
                $table->string('reason', 1000)->nullable()->after('changed_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('request_status_logs', function (Blueprint $table) {
            if (Schema::hasColumn('request_status_logs', 'reason')) {
                $table->dropColumn('reason');
            }
        });
    }
};
