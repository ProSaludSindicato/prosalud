<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_voting_periods', function (Blueprint $table) {
            $table->id();
            $table->string('election_key', 64)->unique();
            $table->string('name', 160);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        if (Schema::hasTable('votes') && Schema::hasColumn('votes', 'candidate_election_key')) {
            $legacyVotesExist = DB::table('votes')->whereNull('candidate_election_key')->exists();
            if ($legacyVotesExist) {
                $legacyKey = '2025-2';
                DB::table('candidate_voting_periods')->insert([
                    'election_key' => $legacyKey,
                    'name' => '2025-2',
                    'is_active' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('votes')
                    ->whereNull('candidate_election_key')
                    ->update(['candidate_election_key' => $legacyKey]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_voting_periods');
    }
};
