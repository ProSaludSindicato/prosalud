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
        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            
            // Voter information
            $table->string('voter_document_type', 10);
            $table->string('voter_document_number', 20);
            $table->string('voter_hospital', 100);
            $table->string('voter_position', 100);
            
            // Candidate information
            $table->string('candidate_id', 20);
            $table->string('candidate_name', 200);
            $table->string('candidate_position', 100);
            $table->string('candidate_hospital', 100);
            
            // Vote metadata
            $table->timestamp('vote_timestamp');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            
            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['voter_document_type', 'voter_document_number']);
            $table->index('candidate_id');
            $table->index('vote_timestamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('votes');
    }
};
