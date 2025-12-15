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
        // Check if table exists
        if (!Schema::hasTable('comfenalco_events')) {
            // If table doesn't exist, create it with the full structure
            Schema::create('comfenalco_events', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('banner_image')->nullable();
                $table->string('registration_link')->nullable();
                $table->string('category');
                $table->enum('display_size', ['carousel', 'mosaic'])->default('mosaic');
                $table->text('description')->nullable();
                $table->date('registration_deadline')->nullable();
                $table->date('event_date')->nullable();
                $table->boolean('is_visible')->default(true);
                $table->timestamps();
            });
            
            return;
        }

        // Check if id column exists
        $columns = Schema::getColumnListing('comfenalco_events');
        
        if (!in_array('id', $columns)) {
            // Add id column as primary key at the beginning
            // We need to use raw SQL because Blueprint doesn't support adding AUTO_INCREMENT columns easily
            $existingPrimaryKey = DB::select("SHOW KEYS FROM comfenalco_events WHERE Key_name = 'PRIMARY'");
            
            if (!empty($existingPrimaryKey)) {
                // Drop existing primary key first
                $pkColumn = $existingPrimaryKey[0]->Column_name;
                DB::statement("ALTER TABLE comfenalco_events DROP PRIMARY KEY");
            }
            
            // Add id column as AUTO_INCREMENT PRIMARY KEY at the beginning
            // Using raw SQL because we need AUTO_INCREMENT which is tricky with Blueprint
            DB::statement("ALTER TABLE comfenalco_events ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
        } else {
            // Column exists, verify it's the primary key and has AUTO_INCREMENT
            $primaryKey = DB::select("SHOW KEYS FROM comfenalco_events WHERE Key_name = 'PRIMARY' AND Column_name = 'id'");
            $columnInfo = DB::select("SHOW COLUMNS FROM comfenalco_events WHERE Field = 'id'");
            
            if (empty($primaryKey)) {
                // id exists but is not primary key, make it primary
                $existingPrimaryKey = DB::select("SHOW KEYS FROM comfenalco_events WHERE Key_name = 'PRIMARY'");
                if (!empty($existingPrimaryKey)) {
                    $pkColumn = $existingPrimaryKey[0]->Column_name;
                    DB::statement("ALTER TABLE comfenalco_events DROP PRIMARY KEY");
                }
                
                // Make id the primary key
                DB::statement("ALTER TABLE comfenalco_events ADD PRIMARY KEY (id)");
            }
            
            // Check if id has AUTO_INCREMENT
            if (!empty($columnInfo)) {
                $idColumn = (array) $columnInfo[0];
                if (strpos($idColumn['Extra'], 'auto_increment') === false) {
                    // Add AUTO_INCREMENT to id column
                    DB::statement("ALTER TABLE comfenalco_events MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT");
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is safe to run multiple times, so we don't need to reverse it
        // If you need to reverse, you would need to be careful about data
    }
};
