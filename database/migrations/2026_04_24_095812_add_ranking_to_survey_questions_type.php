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
            $table->enum('type', [
                'text', 'textarea', 'single_choice', 'multiple_choice',
                'date', 'number', 'scale', 'ranking',
            ])->change();
        });
    }

    public function down(): void
    {
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->enum('type', [
                'text', 'textarea', 'single_choice', 'multiple_choice',
                'date', 'number', 'scale',
            ])->change();
        });
    }
};
