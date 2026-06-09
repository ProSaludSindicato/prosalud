<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('socio_demographic_surveys', function (Blueprint $table) {
            $table->string('barrio')->nullable()->after('municipio');
        });
    }

    public function down(): void
    {
        Schema::table('socio_demographic_surveys', function (Blueprint $table) {
            $table->dropColumn('barrio');
        });
    }
};
