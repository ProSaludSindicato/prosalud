<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_entries', function (Blueprint $table) {
            $table->uuid('location_id')
                ->nullable()
                ->after('total_quantity');

            $table->foreign('location_id')
                ->references('id')
                ->on('inventory_locations')
                ->nullOnDelete();
        });

        $primaryLocation = DB::table('inventory_locations')->where('is_primary', true)->first();

        if ($primaryLocation) {
            DB::table('inventory_entries')
                ->whereNull('location_id')
                ->update(['location_id' => $primaryLocation->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_entries', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
