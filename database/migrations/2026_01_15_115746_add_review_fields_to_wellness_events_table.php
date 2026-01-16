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
        Schema::table('wellness_events', function (Blueprint $table) {
            $table->string('review_status')->default('pending')->after('is_visible'); // pending, in_review, approved, rejected
            $table->unsignedBigInteger('wellness_request_id')->nullable()->after('review_status');
            $table->timestamp('reviewed_at')->nullable()->after('wellness_request_id');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('reviewed_at');
            
            // Foreign keys
            $table->foreign('wellness_request_id')->references('id')->on('wellness_requests')->onDelete('set null');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes
            $table->index('review_status');
            $table->index('wellness_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wellness_events', function (Blueprint $table) {
            $table->dropForeign(['wellness_request_id']);
            $table->dropForeign(['reviewed_by']);
            $table->dropIndex(['review_status']);
            $table->dropIndex(['wellness_request_id']);
            $table->dropColumn(['review_status', 'wellness_request_id', 'reviewed_at', 'reviewed_by']);
        });
    }
};
