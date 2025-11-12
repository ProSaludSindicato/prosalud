<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hospital_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('hospital_uuid')
                ->nullable()
                ->after('hospital_name');

            $table->uuid('target_location_id')
                ->nullable()
                ->after('hospital_uuid');

            $table->foreign('hospital_uuid')
                ->references('id')
                ->on('hospitals')
                ->nullOnDelete();

            $table->foreign('target_location_id')
                ->references('id')
                ->on('inventory_locations')
                ->nullOnDelete();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hospital_requests', function (Blueprint $table) {
            $table->dropForeign(['hospital_uuid']);
            $table->dropForeign(['target_location_id']);
            $table->dropColumn(['hospital_uuid', 'target_location_id']);
        });
    }
};
