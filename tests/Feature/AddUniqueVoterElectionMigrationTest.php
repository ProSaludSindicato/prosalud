<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddUniqueVoterElectionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX_NAME = 'votes_voter_election_unique';

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_18_073022_add_unique_voter_election_to_votes_table.php');
    }

    public function test_up_is_skipped_when_index_already_exists(): void
    {
        $this->assertTrue(Schema::hasIndex('votes', self::INDEX_NAME));

        $this->migration()->up();

        $this->assertTrue(Schema::hasIndex('votes', self::INDEX_NAME));
    }

    public function test_up_creates_index_when_missing(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->assertFalse(Schema::hasIndex('votes', self::INDEX_NAME));

        $migration->up();

        $this->assertTrue(Schema::hasIndex('votes', self::INDEX_NAME));
    }
}
