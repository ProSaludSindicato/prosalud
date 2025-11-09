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
        Schema::table('sst_delivery_records', function (Blueprint $table) {
            $table->string('delivery_type')
                ->default('first_time')
                ->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sst_delivery_records', function (Blueprint $table) {
            $table->dropColumn('delivery_type');
        });
    }
};

