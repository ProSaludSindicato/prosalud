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
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->foreignId('parent_tracking_id')->nullable()->after('id')
                ->constrained('convenio_email_tracking')
                ->nullOnDelete();
            $table->index('parent_tracking_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->dropForeign(['parent_tracking_id']);
            $table->dropIndex(['parent_tracking_id']);
            $table->dropColumn('parent_tracking_id');
        });
    }
};
