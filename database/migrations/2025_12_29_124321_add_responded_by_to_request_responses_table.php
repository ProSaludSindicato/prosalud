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
        Schema::table('request_responses', function (Blueprint $table) {
            $table->foreignId('responded_by')->nullable()->after('request_form_id')->constrained('users')->onDelete('set null');
            $table->index('responded_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('request_responses', function (Blueprint $table) {
            $table->dropForeign(['responded_by']);
            $table->dropIndex(['responded_by']);
            $table->dropColumn('responded_by');
        });
    }
};
