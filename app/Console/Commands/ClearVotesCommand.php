<?php

namespace App\Console\Commands;

use App\Models\Vote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClearVotesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'votes:clear 
                            {--force : Skip confirmation prompt}
                            {--backup : Create backup before clearing}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all votes from the votes table (useful for removing test data before production)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🗳️  Vote Table Cleanup Command');
        $this->line('================================');

        // Get current vote count
        $voteCount = Vote::count();
        
        if ($voteCount === 0) {
            $this->info('✅ The votes table is already empty. Nothing to clear.');
            return 0;
        }

        $this->info("📊 Current votes in database: {$voteCount}");

        // Show vote statistics
        $this->showVoteStatistics();

        // Dry run mode
        if ($this->option('dry-run')) {
            $this->warn('🔍 DRY RUN MODE - No data will be deleted');
            $this->info('The following votes would be deleted:');
            $this->showVoteDetails();
            return 0;
        }

        // Backup option
        if ($this->option('backup')) {
            $this->createBackup();
        }

        // Confirmation prompt (unless --force is used)
        if (!$this->option('force')) {
            $this->warn('⚠️  WARNING: This will permanently delete ALL votes from the database!');
            $this->warn('⚠️  This action cannot be undone!');
            
            if (!$this->confirm('Are you sure you want to continue?')) {
                $this->info('❌ Operation cancelled by user.');
                return 0;
            }

            // Double confirmation for safety
            if (!$this->confirm('This will delete ALL ' . $voteCount . ' votes. Type "DELETE" to confirm')) {
                $this->info('❌ Operation cancelled by user.');
                return 0;
            }
        }

        // Perform the deletion
        $this->info('🗑️  Clearing votes table...');
        
        try {
            // Log the operation
            Log::warning('Votes table cleared via artisan command', [
                'command' => 'votes:clear',
                'votes_deleted' => $voteCount,
                'user' => 'artisan_command',
                'timestamp' => now()->toISOString(),
                'options' => [
                    'force' => $this->option('force'),
                    'backup' => $this->option('backup'),
                    'dry_run' => $this->option('dry-run')
                ]
            ]);

            // Clear the table (truncate doesn't work in transactions)
            Vote::truncate();

            $this->info('✅ Successfully cleared all votes from the database.');
            $this->info("🗑️  Deleted {$voteCount} votes.");
            
            // Reset auto-increment (already done by truncate, but just to be sure)
            DB::statement('ALTER TABLE votes AUTO_INCREMENT = 1');
            $this->info('🔄 Reset auto-increment counter to 1.');

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error occurred while clearing votes:');
            $this->error($e->getMessage());
            
            Log::error('Error clearing votes table', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()->toISOString()
            ]);

            return 1;
        }
    }

    /**
     * Show vote statistics
     */
    private function showVoteStatistics()
    {
        $this->line('');
        $this->info('📈 Vote Statistics:');

        // Votes by candidate
        $votesByCandidate = Vote::selectRaw('candidate_id, candidate_name, COUNT(*) as vote_count')
            ->groupBy('candidate_id', 'candidate_name')
            ->orderBy('vote_count', 'desc')
            ->get();

        if ($votesByCandidate->count() > 0) {
            $this->line('Votes by Candidate:');
            foreach ($votesByCandidate as $candidate) {
                $this->line("  • {$candidate->candidate_name} (ID: {$candidate->candidate_id}): {$candidate->vote_count} votes");
            }
        }

        // Votes by hospital
        $votesByHospital = Vote::selectRaw('voter_hospital, COUNT(*) as vote_count')
            ->groupBy('voter_hospital')
            ->orderBy('vote_count', 'desc')
            ->get();

        if ($votesByHospital->count() > 0) {
            $this->line('');
            $this->line('Votes by Hospital:');
            foreach ($votesByHospital as $hospital) {
                $this->line("  • {$hospital->voter_hospital}: {$hospital->vote_count} votes");
            }
        }

        // Date range
        $dateRange = Vote::selectRaw('MIN(vote_timestamp) as earliest, MAX(vote_timestamp) as latest')
            ->first();

        if ($dateRange) {
            $this->line('');
            $this->line('Date Range:');
            $this->line("  • Earliest vote: {$dateRange->earliest}");
            $this->line("  • Latest vote: {$dateRange->latest}");
        }

        $this->line('');
    }

    /**
     * Show detailed vote information
     */
    private function showVoteDetails()
    {
        $votes = Vote::orderBy('vote_timestamp', 'desc')->limit(10)->get();
        
        $this->line('');
        $this->info('Recent votes (last 10):');
        
        foreach ($votes as $vote) {
            $this->line("  • Vote #{$vote->id}: {$vote->voter_document_type} {$vote->voter_document_number} voted for {$vote->candidate_name} on {$vote->vote_timestamp}");
        }
        
        if (Vote::count() > 10) {
            $this->line("  ... and " . (Vote::count() - 10) . " more votes");
        }
    }

    /**
     * Create backup of votes before clearing
     */
    private function createBackup()
    {
        $this->info('💾 Creating backup...');
        
        try {
            $votes = Vote::all();
            $backupData = $votes->toArray();
            
            $backupFileName = 'votes_backup_' . now()->format('Y_m_d_H_i_s') . '.json';
            $backupPath = storage_path('app/backups/' . $backupFileName);
            
            // Create backup directory if it doesn't exist
            if (!file_exists(storage_path('app/backups'))) {
                mkdir(storage_path('app/backups'), 0755, true);
            }
            
            // Save backup
            file_put_contents($backupPath, json_encode($backupData, JSON_PRETTY_PRINT));
            
            $this->info("✅ Backup created: {$backupPath}");
            $this->info("📁 Backup contains " . count($backupData) . " votes");
            
            Log::info('Votes backup created', [
                'backup_file' => $backupFileName,
                'votes_count' => count($backupData),
                'timestamp' => now()->toISOString()
            ]);
            
        } catch (\Exception $e) {
            $this->error('❌ Error creating backup: ' . $e->getMessage());
            Log::error('Error creating votes backup', [
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString()
            ]);
        }
    }
}
