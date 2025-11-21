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
        Schema::create('assembly_questions', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->text('help_text')->nullable();
            $table->enum('type', ['SINGLE', 'MULTIPLE'])->default('SINGLE');
            $table->enum('majority_type', ['SIMPLE', 'ABSOLUTE', 'TWO_THIRDS'])->default('SIMPLE');
            $table->enum('status', ['PENDING', 'OPEN', 'CLOSED'])->default('PENDING');
            $table->integer('time_limit')->default(60);
            $table->boolean('quorum_required')->default(false);
            $table->boolean('allow_change_vote')->default(false);
            $table->integer('order')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->integer('votes_count')->default(0);
            $table->boolean('results_visible')->default(false);
            $table->string('assembly_id', 255)->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assembly_questions');
    }
};
