<?php

namespace App\Console\Commands;

use App\Models\SocioDemographicSurvey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log, Storage};

class ClearSocioDemographicSurveysCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'socio-demographic-surveys:clear
                            {--force : Skip confirmation prompt}
                            {--backup : Create backup before clearing}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all SocioDemographicSurvey records and their associated signature files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🧹 Socio-Demographic Surveys Cleanup Command');
        $this->line('===========================================');

        // Get current counts
        $counts = $this->getRecordCounts();

        if ($this->allTablesEmpty($counts)) {
            $this->info('✅ All socio-demographic survey tables are already empty. Nothing to clear.');

            return 0;
        }

        // Show current statistics
        $this->showStatistics($counts);

        // Dry run mode
        if ($this->option('dry-run')) {
            $this->warn('🔍 DRY RUN MODE - No data will be deleted');
            $this->info('The following records would be deleted:');
            $this->showDetails();

            return 0;
        }

        // Backup option
        if ($this->option('backup')) {
            $this->createBackup($counts);
        }

        // Confirmation prompt (unless --force is used)
        if (!$this->option('force')) {
            $totalRecords = array_sum($counts);
            $this->warn('⚠️  WARNING: This will permanently delete ALL records from the following tables:');
            $this->line('');
            $this->line('  • SocioDemographicSurvey');
            $this->line('');
            $this->warn('⚠️  This will also delete ALL associated signature files from storage buckets (S3/local):');
            $this->line('  • socio-demographic-surveys/signatures/* (prosalud-private/local)');
            $this->line('');
            $this->warn("⚠️  Total records to delete: {$totalRecords}");
            $this->warn('⚠️  This action cannot be undone!');

            if (!$this->confirm('Are you sure you want to continue?')) {
                $this->info('❌ Operation cancelled by user.');

                return 0;
            }

            // Double confirmation for safety
            $this->warn("This will delete {$totalRecords} records.");
            if (!$this->confirm('Are you absolutely sure? This action cannot be undone!')) {
                $this->info('❌ Operation cancelled by user.');

                return 0;
            }
        }

        // Perform the deletion
        $this->info('🗑️  Clearing socio-demographic surveys data...');

        try {
            // Delete files from storage first, before deleting records
            $this->info('  → Deleting signature files from storage buckets...');
            $filesDeleted = $this->deleteAllFiles();
            $this->info("    ✓ Deleted {$filesDeleted} signature files from storage");

            // Log the operation
            Log::warning('Socio-demographic surveys cleared via artisan command', [
                'command' => 'socio-demographic-surveys:clear',
                'counts' => $counts,
                'total_records' => array_sum($counts),
                'files_deleted' => $filesDeleted,
                'user' => 'artisan_command',
                'timestamp' => now()->toISOString(),
                'options' => [
                    'force' => $this->option('force'),
                    'backup' => $this->option('backup'),
                    'dry_run' => $this->option('dry-run'),
                ],
            ]);

            // Disable foreign key checks temporarily
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            // Delete main records
            $this->info('  → Deleting SocioDemographicSurvey records...');
            SocioDemographicSurvey::truncate();
            $this->info('    ✓ SocioDemographicSurvey records deleted');

            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            $totalDeleted = array_sum($counts);
            $this->info('');
            $this->info('✅ Successfully cleared all socio-demographic surveys data.');
            $this->info("🗑️  Deleted {$totalDeleted} records total.");
            $this->info("📁 Deleted {$filesDeleted} signature files from storage buckets.");

            return 0;
        } catch (\Exception $e) {
            // Re-enable foreign key checks in case of error
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            $this->error('❌ Error occurred while clearing socio-demographic surveys data:');
            $this->error($e->getMessage());

            Log::error('Error clearing socio-demographic surveys data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()->toISOString(),
            ]);

            return 1;
        }
    }

    /**
     * Get current record counts.
     */
    private function getRecordCounts(): array
    {
        $total = SocioDemographicSurvey::count();
        $withSignature = SocioDemographicSurvey::whereNotNull('firma_path')->count();

        return [
            'surveys' => $total,
            'surveys_with_signature' => $withSignature,
        ];
    }

    /**
     * Check if all tables are empty.
     */
    private function allTablesEmpty(array $counts): bool
    {
        return array_sum($counts) === 0;
    }

    /**
     * Show statistics.
     */
    private function showStatistics(array $counts): void
    {
        $this->line('');
        $this->info('📊 Current Socio-Demographic Survey Records in Database:');
        $this->line('');

        $this->line("  • Total surveys: {$counts['surveys']}");
        $this->line("  • Surveys with signature: {$counts['surveys_with_signature']}");

        $this->line('');
        $this->info("📈 Total records: " . array_sum($counts));
        $this->line('');
    }

    /**
     * Show detailed information about records (for dry-run).
     */
    private function showDetails(): void
    {
        $this->line('');

        if (SocioDemographicSurvey::count() > 0) {
            $this->info('Recent SocioDemographicSurvey records (last 5):');
            $recent = SocioDemographicSurvey::orderBy('created_at', 'desc')->limit(5)->get();
            foreach ($recent as $survey) {
                $this->line(sprintf(
                    '  • ID: %s - %s %s - Doc: %s %s - Hospital: %s - Created: %s - Has signature: %s',
                    $survey->id,
                    $survey->nombres ?? '',
                    $survey->apellidos ?? '',
                    $survey->tipo_documento,
                    $survey->numero_documento,
                    $survey->hospital ?? '-',
                    $survey->created_at?->format('Y-m-d H:i:s'),
                    $survey->firma_path ? 'yes' : 'no'
                ));
            }
            $this->line('');
        }
    }

    /**
     * Create backup of data before clearing.
     */
    private function createBackup(array $counts): void
    {
        $this->info('💾 Creating socio-demographic surveys backup...');

        try {
            $backupData = [
                'timestamp' => now()->toISOString(),
                'counts' => $counts,
                'surveys' => SocioDemographicSurvey::all()->toArray(),
            ];

            $backupFileName = 'socio_demographic_surveys_backup_' . now()->format('Y_m_d_H_i_s') . '.json';
            $backupPath = storage_path('app/backups/' . $backupFileName);

            // Create backup directory if it doesn't exist
            if (!file_exists(storage_path('app/backups'))) {
                mkdir(storage_path('app/backups'), 0755, true);
            }

            // Save backup
            file_put_contents($backupPath, json_encode($backupData, JSON_PRETTY_PRINT));

            $this->info("✅ Backup created: {$backupPath}");
            $this->info("📁 Backup contains {$counts['surveys']} survey records");

            Log::info('Socio-demographic surveys backup created', [
                'backup_file' => $backupFileName,
                'counts' => $counts,
                'total_records' => $counts['surveys'],
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Exception $e) {
            $this->error('❌ Error creating socio-demographic surveys backup: ' . $e->getMessage());
            Log::error('Error creating socio-demographic surveys backup', [
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ]);
        }
    }

    /**
     * Delete all signature files from storage buckets related to SocioDemographicSurvey.
     */
    private function deleteAllFiles(): int
    {
        $filesDeleted = 0;

        $this->line('    → Processing SocioDemographicSurvey signature files...');
        $surveys = SocioDemographicSurvey::whereNotNull('firma_path')->get();

        foreach ($surveys as $survey) {
            $path = $survey->firma_path;
            if (!$path) {
                continue;
            }

            try {
                $disk = 'prosalud-private';
                $fallbackDisk = 'local';

                // Try prosalud-private first
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                    $filesDeleted++;
                } elseif (Storage::disk($fallbackDisk)->exists($path)) {
                    Storage::disk($fallbackDisk)->delete($path);
                    $filesDeleted++;
                }
            } catch (\Exception $e) {
                Log::warning('Error deleting SocioDemographicSurvey signature file', [
                    'survey_id' => $survey->id,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $filesDeleted;
    }
}


