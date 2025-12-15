<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration will:
     * 1. Drop the existing table (data will be lost)
     * 2. Recreate it with the correct structure (including id column as primary key)
     */
    public function up(): void
    {
        $tableName = 'comfenalco_events';
        
        // Drop the table if it exists (data will be lost)
        Schema::dropIfExists($tableName);
        Log::info('ComfenalcoEvents migration: Table dropped');
        
        // Recreate the table with correct structure
        Schema::create($tableName, function (Blueprint $table) {
            $table->id(); // This creates BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
            $table->string('title');
            $table->string('banner_image')->nullable();
            $table->string('registration_link')->nullable();
            $table->string('category');
            $table->enum('display_size', ['carousel', 'mosaic'])->default('mosaic');
            $table->text('description')->nullable();
            $table->date('registration_deadline')->nullable();
            $table->date('event_date')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestamp('created_at')->nullable();
            // Note: updated_at is not included because the model has UPDATED_AT = null
        });
        
        Log::info('ComfenalcoEvents migration: Table recreated with correct structure (id column as primary key)');
    }

    /**
     * Reverse the migrations.
     * 
     * Note: This will drop the table. If you need to preserve data,
     * you should backup before rolling back.
     */
    public function down(): void
    {
        Schema::dropIfExists('comfenalco_events');
        Log::info('ComfenalcoEvents migration: Table dropped (rollback)');
    }
};
