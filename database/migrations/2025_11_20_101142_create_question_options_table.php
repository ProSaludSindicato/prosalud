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
        Schema::create('question_options', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('question_id', 255);
            $table->string('option_key', 100);
            $table->string('text', 500);
            $table->text('description')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->foreign('question_id')
                ->references('id')
                ->on('assembly_questions')
                ->onDelete('cascade');

            $table->index('question_id');
            $table->unique(['question_id', 'option_key'], 'question_option_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_options');
    }
};
