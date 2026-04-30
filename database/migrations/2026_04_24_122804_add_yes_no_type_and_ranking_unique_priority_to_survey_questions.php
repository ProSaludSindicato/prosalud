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
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->boolean('ranking_unique_priority')->default(true);
        });

        Schema::table('survey_questions', function (Blueprint $table) {
            $table->enum('type', [
                'text', 'textarea', 'single_choice', 'multiple_choice',
                'date', 'number', 'scale', 'ranking', 'yes_no',
            ])->change();
        });
    }

    public function down(): void
    {
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->enum('type', [
                'text', 'textarea', 'single_choice', 'multiple_choice',
                'date', 'number', 'scale', 'ranking',
            ])->change();
        });

        Schema::table('survey_questions', function (Blueprint $table) {
            $table->dropColumn('ranking_unique_priority');
        });
    }
};
