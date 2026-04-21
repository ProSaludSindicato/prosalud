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
        Schema::table('votes', function (Blueprint $table) {
            if (! Schema::hasColumn('votes', 'candidate_election_key')) {
                $table->string('candidate_election_key', 64)->nullable()->after('candidate_hospital');
                $table->index('candidate_election_key');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            if (Schema::hasColumn('votes', 'candidate_election_key')) {
                $table->dropIndex(['candidate_election_key']);
                $table->dropColumn('candidate_election_key');
            }
        });
    }
};
