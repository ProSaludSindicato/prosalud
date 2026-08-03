<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->json('convenio_data')->nullable()->after('generated_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->dropColumn('convenio_data');
        });
    }
};
