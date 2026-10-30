<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'votes_voter_election_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasIndex('votes', self::INDEX_NAME)) {
            return;
        }

        $duplicates = DB::table('votes')
            ->select('voter_document_type', 'voter_document_number', 'candidate_election_key')
            ->whereNotNull('candidate_election_key')
            ->groupBy('voter_document_type', 'voter_document_number', 'candidate_election_key')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicates > 0) {
            throw new RuntimeException(
                "Existen {$duplicates} votantes con más de un voto en la misma elección. Depure los duplicados antes de aplicar la restricción única."
            );
        }

        Schema::table('votes', function (Blueprint $table) {
            $table->unique(
                ['voter_document_type', 'voter_document_number', 'candidate_election_key'],
                self::INDEX_NAME
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            $table->dropUnique(self::INDEX_NAME);
        });
    }
};
