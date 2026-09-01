<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('convenio_data')->index();
        });

        DB::table('convenio_email_tracking')
            ->where('estado', 'verificacion')
            ->update(['is_test' => true]);
    }

    public function down(): void
    {
        Schema::table('convenio_email_tracking', function (Blueprint $table) {
            $table->dropIndex(['is_test']);
            $table->dropColumn('is_test');
        });
    }
};
