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
            $table->string('survey_type', 20)->default('active_affiliate')->after('id')
                ->comment('Tipo de encuesta: active_affiliate (afiliado activo) o bulk_entry (ingreso masivo)');
            $table->index('survey_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('socio_demographic_surveys', function (Blueprint $table) {
            $table->dropIndex(['survey_type']);
            $table->dropColumn('survey_type');
        });
    }
};
