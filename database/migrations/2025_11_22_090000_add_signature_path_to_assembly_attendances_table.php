<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assembly_attendances', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('issue_date_normalized');
        });
    }

    public function down(): void
    {
        Schema::table('assembly_attendances', function (Blueprint $table) {
            $table->dropColumn('signature_path');
        });
    }
};

