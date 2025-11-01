<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE request_forms MODIFY COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE request_forms MODIFY COLUMN processed_at TIMESTAMP NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE request_forms MODIFY COLUMN created_at DATE DEFAULT (CURRENT_DATE)');
        DB::statement('ALTER TABLE request_forms MODIFY COLUMN processed_at DATE NULL');
    }
};
