<?php

namespace App\Console\Commands;

use App\Models\SocioDemographicSurvey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log};

class MigrateBulkEntryToNewEntryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'surveys:migrate-bulk-entry-to-new-entry
                            {--force : Skip confirmation prompt}
                            {--dry-run : Show what would be changed without actually changing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate all socio-demographic surveys from legacy type bulk_entry to new_entry';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔄 Socio-Demographic Surveys Type Migration Command');
        $this->line('==================================================');
        $this->line('');

        // Get current counts
        $bulkEntryCount = SocioDemographicSurvey::where('survey_type', 'bulk_entry')->count();
        $newEntryCount = SocioDemographicSurvey::where('survey_type', 'new_entry')->count();
        $totalCount = SocioDemographicSurvey::count();

        // Show current statistics
        $this->showStatistics($bulkEntryCount, $newEntryCount, $totalCount);

        // Check if there are any bulk_entry surveys to migrate
        if ($bulkEntryCount === 0) {
            $this->info('✅ No surveys with type "bulk_entry" found. Nothing to migrate.');
            $this->line('');

            return 0;
        }

        // Dry run mode
        if ($this->option('dry-run')) {
            $this->warn('🔍 DRY RUN MODE - No data will be changed');
            $this->line('');
            $this->info("The following {$bulkEntryCount} survey(s) would be migrated from 'bulk_entry' to 'new_entry':");
            $this->showSampleSurveys($bulkEntryCount);
            $this->line('');

            return 0;
        }

        // Confirmation prompt (unless --force is used)
        if (!$this->option('force')) {
            $this->warn("⚠️  WARNING: This will change {$bulkEntryCount} survey(s) from type 'bulk_entry' to 'new_entry'");
            $this->line('');
            $this->warn('⚠️  This action will update the survey_type field permanently.');
            $this->line('');

            if (!$this->confirm('Are you sure you want to continue?')) {
                $this->info('❌ Operation cancelled by user.');
                $this->line('');

                return 0;
            }

            // Double confirmation for safety
            $this->warn("This will migrate {$bulkEntryCount} survey(s) from 'bulk_entry' to 'new_entry'.");
            if (!$this->confirm('Are you absolutely sure? This action cannot be undone!')) {
                $this->info('❌ Operation cancelled by user.');
                $this->line('');

                return 0;
            }
        }

        // Perform the migration
        $this->info('🔄 Migrating surveys from bulk_entry to new_entry...');
        $this->line('');

        try {
            // Use a transaction for safety
            DB::beginTransaction();

            // Update all bulk_entry surveys to new_entry
            $updated = SocioDemographicSurvey::where('survey_type', 'bulk_entry')
                ->update(['survey_type' => 'new_entry']);

            // Commit the transaction
            DB::commit();

            // Log the operation
            Log::info('Socio-demographic surveys migrated from bulk_entry to new_entry', [
                'command' => 'surveys:migrate-bulk-entry-to-new-entry',
                'surveys_migrated' => $updated,
                'user' => 'artisan_command',
                'timestamp' => now()->toISOString(),
                'options' => [
                    'force' => $this->option('force'),
                    'dry_run' => $this->option('dry-run'),
                ],
            ]);

            // Show results
            $this->info("✅ Successfully migrated {$updated} survey(s) from 'bulk_entry' to 'new_entry'.");
            $this->line('');

            // Show updated statistics
            $newBulkEntryCount = SocioDemographicSurvey::where('survey_type', 'bulk_entry')->count();
            $newNewEntryCount = SocioDemographicSurvey::where('survey_type', 'new_entry')->count();

            $this->info('📊 Updated Statistics:');
            $this->line("  • Surveys with type 'bulk_entry': {$newBulkEntryCount}");
            $this->line("  • Surveys with type 'new_entry': {$newNewEntryCount}");
            $this->line("  • Total surveys: {$totalCount}");
            $this->line('');

            return 0;
        } catch (\Exception $e) {
            // Rollback the transaction in case of error
            DB::rollBack();

            $this->error('❌ Error occurred while migrating surveys:');
            $this->error($e->getMessage());
            $this->line('');

            Log::error('Error migrating socio-demographic surveys from bulk_entry to new_entry', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()->toISOString(),
            ]);

            return 1;
        }
    }

    /**
     * Show current statistics.
     */
    private function showStatistics(int $bulkEntryCount, int $newEntryCount, int $totalCount): void
    {
        $this->info('📊 Current Survey Statistics:');
        $this->line('');
        $this->line("  • Surveys with type 'bulk_entry' (legacy): {$bulkEntryCount}");
        $this->line("  • Surveys with type 'new_entry': {$newEntryCount}");
        $this->line("  • Total surveys: {$totalCount}");
        $this->line('');
    }

    /**
     * Show sample surveys that would be migrated (for dry-run).
     */
    private function showSampleSurveys(int $totalCount): void
    {
        $surveys = SocioDemographicSurvey::where('survey_type', 'bulk_entry')
            ->orderBy('created_at', 'desc')
            ->limit(min(10, $totalCount))
            ->get();

        if ($surveys->isEmpty()) {
            $this->line('  (No surveys found)');
            return;
        }

        $this->line('');
        foreach ($surveys as $survey) {
            $this->line(sprintf(
                '  • ID: %s - %s %s - Doc: %s %s - Hospital: %s - Created: %s',
                $survey->id,
                $survey->nombres ?? '',
                $survey->apellidos ?? '',
                $survey->tipo_documento,
                $survey->numero_documento,
                $survey->hospital ?? '-',
                $survey->created_at?->format('Y-m-d H:i:s')
            ));
        }

        if ($totalCount > 10) {
            $remaining = $totalCount - 10;
            $this->line("");
            $this->line("  ... and {$remaining} more survey(s)");
        }
    }
}

