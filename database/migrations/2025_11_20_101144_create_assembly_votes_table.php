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
        Schema::create('assembly_votes', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('question_id', 255);
            $table->string('voter_id', 255);
            $table->string('voter_name', 255);
            $table->json('selected_options');
            $table->timestamp('voted_at')->useCurrent();
            $table->timestamps();

            $table->foreign('question_id')
                ->references('id')
                ->on('assembly_questions')
                ->onDelete('cascade');

            $table->unique(['question_id', 'voter_id'], 'unique_vote');
            $table->index('question_id');
            $table->index('voter_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assembly_votes');
    }
};
