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
        Schema::table('socio_demographic_surveys', function (Blueprint $table) {
            $table->string('nombres')->nullable()->after('numero_documento');
            $table->string('apellidos')->nullable()->after('nombres');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('socio_demographic_surveys', function (Blueprint $table) {
            $table->dropColumn(['nombres', 'apellidos']);
        });
    }
};
