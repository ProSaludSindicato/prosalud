<?php

namespace App\Console\Commands;

use App\Models\{
    CertificadoConvenioRecord,
    RequestForm,
    RequestResponse,
};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log, Storage};

class ClearRequestFormsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'request-forms:clear 
                            {--force : Skip confirmation prompt}
                            {--backup : Create backup before clearing}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all RequestForm records with their associated files from S3, responses, and certificado convenio records';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🧹 Request Forms Cleanup Command');
        $this->line('================================');

        // Get current counts
        $counts = $this->getRecordCounts();

        if ($this->allTablesEmpty($counts)) {
            $this->info('✅ All tables are already empty. Nothing to clear.');

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
            $this->line('  • Solicitudes (RequestForm)');
            $this->line('  • Respuestas de Solicitudes (RequestResponse)');
            $this->line('  • Certificados de Convenio (CertificadoConvenioRecord)');
            $this->line('');
            $this->warn('⚠️  This will also delete ALL associated files from storage buckets (S3/local):');
            $this->line('  • RequestForm files (prosalud-private/local)');
            $this->line('  • Certificado Convenio PDF files (prosalud-private)');
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
        $this->info('🗑️  Clearing data...');

        try {
            // Delete files from storage first, before deleting records
            $this->info('  → Deleting files from storage buckets...');
            $filesDeleted = $this->deleteAllFiles();
            $this->info("    ✓ Deleted {$filesDeleted} files from storage");

            // Log the operation
            Log::warning('Request forms cleared via artisan command', [
                'command' => 'request-forms:clear',
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

            // Delete in order to respect foreign key constraints
            // 1. Delete related records first (child tables)
            $this->info('  → Deleting Request Responses...');
            RequestResponse::truncate();
            $this->info('    ✓ Request Responses deleted');

            // 2. Delete certificado convenio records
            $this->info('  → Deleting Certificado Convenio Records...');
            CertificadoConvenioRecord::truncate();
            $this->info('    ✓ Certificado Convenio Records deleted');

            // 3. Delete main records
            $this->info('  → Deleting Request Forms...');
            RequestForm::truncate();
            $this->info('    ✓ Request Forms deleted');

            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            $totalDeleted = array_sum($counts);
            $this->info('');
            $this->info('✅ Successfully cleared all request forms data.');
            $this->info("🗑️  Deleted {$totalDeleted} records total.");
            $this->info("📁 Deleted {$filesDeleted} files from storage buckets.");

            return 0;
        } catch (\Exception $e) {
            // Re-enable foreign key checks in case of error
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            $this->error('❌ Error occurred while clearing data:');
            $this->error($e->getMessage());

            Log::error('Error clearing request forms data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()->toISOString(),
            ]);

            return 1;
        }
    }

    /**
     * Get current record counts for all tables.
     */
    private function getRecordCounts(): array
    {
        return [
            'request_forms' => RequestForm::count(),
            'request_responses' => RequestResponse::count(),
            'certificado_convenio_records' => CertificadoConvenioRecord::count(),
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
     * Show statistics for all tables.
     */
    private function showStatistics(array $counts): void
    {
        $this->line('');
        $this->info('📊 Current Records in Database:');
        $this->line('');

        $this->line('📋 Solicitudes:');
        $this->line("  • Request Forms: {$counts['request_forms']}");
        $this->line("  • Request Responses: {$counts['request_responses']}");
        $this->line("  • Certificado Convenio Records: {$counts['certificado_convenio_records']}");

        $total = array_sum($counts);
        $this->line('');
        $this->info("📈 Total records: {$total}");
        $this->line('');
    }

    /**
     * Show detailed information about records.
     */
    private function showDetails(): void
    {
        $this->line('');

        // Show recent records from each table
        if (RequestForm::count() > 0) {
            $this->info('Recent Request Forms (last 5):');
            $recent = RequestForm::orderBy('created_at', 'desc')->limit(5)->get();
            foreach ($recent as $form) {
                $this->line("  • ID: {$form->id} - {$form->request_type} - {$form->status} - {$form->document_number}");
            }
            $this->line('');
        }

        if (CertificadoConvenioRecord::count() > 0) {
            $this->info('Recent Certificado Convenio Records (last 5):');
            $recent = CertificadoConvenioRecord::orderBy('generated_at', 'desc')->limit(5)->get();
            foreach ($recent as $record) {
                $this->line("  • Document: {$record->document_number} - Consecutivo: {$record->consecutivo} - Path: {$record->storage_path}");
            }
            $this->line('');
        }
    }

    /**
     * Create backup of data before clearing.
     */
    private function createBackup(array $counts): void
    {
        $this->info('💾 Creating backup...');

        try {
            $backupData = [
                'timestamp' => now()->toISOString(),
                'request_forms' => RequestForm::all()->toArray(),
                'request_responses' => RequestResponse::all()->toArray(),
                'certificado_convenio_records' => CertificadoConvenioRecord::all()->toArray(),
            ];

            $backupFileName = 'request_forms_backup_' . now()->format('Y_m_d_H_i_s') . '.json';
            $backupPath = storage_path('app/backups/' . $backupFileName);

            // Create backup directory if it doesn't exist
            if (!file_exists(storage_path('app/backups'))) {
                mkdir(storage_path('app/backups'), 0755, true);
            }

            // Save backup
            file_put_contents($backupPath, json_encode($backupData, JSON_PRETTY_PRINT));

            $totalRecords = array_sum($counts);
            $this->info("✅ Backup created: {$backupPath}");
            $this->info("📁 Backup contains {$totalRecords} records total");

            Log::info('Request forms backup created', [
                'backup_file' => $backupFileName,
                'counts' => $counts,
                'total_records' => $totalRecords,
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Exception $e) {
            $this->error('❌ Error creating backup: ' . $e->getMessage());
            Log::error('Error creating request forms backup', [
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ]);
        }
    }

    /**
     * Delete all files from storage buckets related to RequestForm and CertificadoConvenioRecord.
     */
    private function deleteAllFiles(): int
    {
        $filesDeleted = 0;

        // 1. Delete RequestForm files
        $this->line('    → Processing RequestForm files...');
        $requestForms = RequestForm::whereNotNull('files')->get();
        foreach ($requestForms as $form) {
            if (is_array($form->files)) {
                foreach ($form->files as $fileKey => $fileData) {
                    if (isset($fileData['path']) && isset($fileData['disk'])) {
                        try {
                            $disk = $fileData['disk'];
                            $path = $fileData['path'];

                            if (Storage::disk($disk)->exists($path)) {
                                Storage::disk($disk)->delete($path);
                                $filesDeleted++;
                            }
                        } catch (\Exception $e) {
                            Log::warning('Error deleting RequestForm file', [
                                'request_form_id' => $form->id,
                                'file_key' => $fileKey,
                                'path' => $fileData['path'] ?? null,
                                'disk' => $fileData['disk'] ?? null,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        }

        // 2. Delete CertificadoConvenioRecord files
        $this->line('    → Processing Certificado Convenio files...');
        $certificados = CertificadoConvenioRecord::whereNotNull('storage_path')->get();
        foreach ($certificados as $certificado) {
            try {
                $disk = 'prosalud-private';
                $fallbackDisk = 'local';
                $path = $certificado->storage_path;

                // Try prosalud-private first (where they should be stored)
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                    $filesDeleted++;
                } elseif (Storage::disk($fallbackDisk)->exists($path)) {
                    // Fallback to local disk
                    Storage::disk($fallbackDisk)->delete($path);
                    $filesDeleted++;
                } else {
                    // File doesn't exist, log for information
                    Log::info('Certificado file not found in storage', [
                        'certificado_id' => $certificado->id,
                        'document_number' => $certificado->document_number,
                        'consecutivo' => $certificado->consecutivo,
                        'storage_path' => $path,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Error deleting Certificado Convenio file', [
                    'certificado_id' => $certificado->id,
                    'document_number' => $certificado->document_number,
                    'consecutivo' => $certificado->consecutivo,
                    'storage_path' => $certificado->storage_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $filesDeleted;
    }
}


