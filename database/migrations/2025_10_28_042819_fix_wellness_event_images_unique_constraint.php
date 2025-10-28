<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wellness_event_images', function (Blueprint $table) {
            $table->dropUnique(['image_url']);
        });

        // Clean up any duplicate URLs by deleting duplicates (keep the first one)
        $duplicates = DB::table('wellness_event_images')
            ->select('image_url')
            ->groupBy('image_url')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            // Keep the first record, delete the rest
            $records = DB::table('wellness_event_images')
                ->where('image_url', $duplicate->image_url)
                ->orderBy('id')
                ->get();

            // Delete all but the first record
            for ($i = 1; $i < count($records); $i++) {
                DB::table('wellness_event_images')
                    ->where('id', $records[$i]->id)
                    ->delete();
            }
        }

        // Add the unique constraint back
        Schema::table('wellness_event_images', function (Blueprint $table) {
            $table->unique('image_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the unique constraint
        Schema::table('wellness_event_images', function (Blueprint $table) {
            $table->dropUnique(['image_url']);
        });

        // Add it back (this will fail if there are duplicates, but that's expected)
        Schema::table('wellness_event_images', function (Blueprint $table) {
            $table->unique('image_url');
        });
    }
};
