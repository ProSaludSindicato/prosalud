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
        DB::statement('DELETE FROM request_forms');
        DB::statement('ALTER TABLE request_forms MODIFY COLUMN id VARCHAR(10) NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DELETE FROM request_forms');
        DB::statement('ALTER TABLE request_forms MODIFY COLUMN id VARCHAR(24) NOT NULL');
    }
};
