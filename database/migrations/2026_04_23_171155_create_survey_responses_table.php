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
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->string('id', 10)->primary();
            $table->string('survey_id', 10);
            $table->foreign('survey_id')->references('id')->on('surveys')->cascadeOnDelete();
            $table->string('respondent_document_type', 5)->nullable();
            $table->string('respondent_document_number', 50)->nullable();
            $table->string('respondent_name')->nullable();
            $table->string('hospital')->nullable();
            $table->string('signature_path')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();

            $table->index('survey_id');
            $table->index(['survey_id', 'respondent_document_number']);
            $table->index('submitted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
    }
};
