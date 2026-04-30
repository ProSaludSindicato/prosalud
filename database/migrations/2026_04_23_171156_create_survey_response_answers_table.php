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
        Schema::create('survey_response_answers', function (Blueprint $table) {
            $table->id();
            $table->string('response_id', 10);
            $table->foreign('response_id')->references('id')->on('survey_responses')->cascadeOnDelete();
            $table->unsignedBigInteger('question_id');
            $table->foreign('question_id')->references('id')->on('survey_questions')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();

            $table->index('response_id');
            $table->index('question_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_response_answers');
    }
};
