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
        Schema::table('voting_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('voting_settings', 'active_candidate_election_key')) {
                $table->string('active_candidate_election_key', 64)->nullable()->after('active_mode');
                $table->index('active_candidate_election_key');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voting_settings', function (Blueprint $table) {
            if (Schema::hasColumn('voting_settings', 'active_candidate_election_key')) {
                $table->dropIndex(['active_candidate_election_key']);
                $table->dropColumn('active_candidate_election_key');
            }
        });
    }
};
