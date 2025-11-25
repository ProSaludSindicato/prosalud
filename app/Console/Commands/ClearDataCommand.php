<?php

namespace App\Console\Commands;

use App\Models\{
    ChatbotConversation,
    ComfenalcoEvent,
    RequestForm,
    RequestResponse,
    WellnessActivityEvidence,
    WellnessActivityRealized,
    WellnessEvent,
    WellnessEventImage,
    WellnessRequest,
    WellnessRequestDetail,
};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log, Storage};

class ClearDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:clear 
                            {--force : Skip confirmation prompt}
                            {--backup : Create backup before clearing}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all records related to: Solicitudes, Solicitudes de Bienestar, Bienestar, Experiencias Comfenalco, and Conversaciones del Chatbot';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🧹 Data Cleanup Command');
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
            $this->line('  • Solicitudes (RequestForm, RequestResponse)');
            $this->line('  • Solicitudes de Bienestar (WellnessRequest, WellnessRequestDetail, WellnessActivityRealized, WellnessActivityEvidence)');
            $this->line('  • Bienestar (WellnessEvent, WellnessEventImage)');
            $this->line('  • Experiencias Comfenalco (ComfenalcoEvent)');
            $this->line('  • Conversaciones del Chatbot (ChatbotConversation)');
            $this->line('');
            $this->warn('⚠️  This will also delete ALL associated files from storage buckets (S3/local):');
            $this->line('  • RequestForm files (prosalud-private/local)');
            $this->line('  • WellnessActivityEvidence images (prosalud-public/public)');
            $this->line('  • WellnessActivityRealized listado_asistencia files (prosalud-private/local)');
            $this->line('  • WellnessEventImage images (prosalud-public/public)');
            $this->line('  • ComfenalcoEvent banner images (prosalud-public/public)');
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
            Log::warning('Data cleared via artisan command', [
                'command' => 'data:clear',
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

            $this->info('  → Deleting Wellness Activity Evidences...');
            WellnessActivityEvidence::truncate();
            $this->info('    ✓ Wellness Activity Evidences deleted');

            $this->info('  → Deleting Wellness Activity Realized...');
            WellnessActivityRealized::truncate();
            $this->info('    ✓ Wellness Activity Realized deleted');

            $this->info('  → Deleting Wellness Request Details...');
            WellnessRequestDetail::truncate();
            $this->info('    ✓ Wellness Request Details deleted');

            $this->info('  → Deleting Wellness Event Images...');
            WellnessEventImage::truncate();
            $this->info('    ✓ Wellness Event Images deleted');

            // 2. Delete main records
            $this->info('  → Deleting Request Forms...');
            RequestForm::truncate();
            $this->info('    ✓ Request Forms deleted');

            $this->info('  → Deleting Wellness Requests...');
            WellnessRequest::truncate();
            $this->info('    ✓ Wellness Requests deleted');

            $this->info('  → Deleting Wellness Events...');
            WellnessEvent::truncate();
            $this->info('    ✓ Wellness Events deleted');

            $this->info('  → Deleting Comfenalco Events...');
            ComfenalcoEvent::truncate();
            $this->info('    ✓ Comfenalco Events deleted');

            $this->info('  → Deleting Chatbot Conversations...');
            ChatbotConversation::truncate();
            $this->info('    ✓ Chatbot Conversations deleted');

            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            $totalDeleted = array_sum($counts);
            $this->info('');
            $this->info('✅ Successfully cleared all data.');
            $this->info("🗑️  Deleted {$totalDeleted} records total.");
            $this->info("📁 Deleted {$filesDeleted} files from storage buckets.");

            return 0;
        } catch (\Exception $e) {
            // Re-enable foreign key checks in case of error
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            $this->error('❌ Error occurred while clearing data:');
            $this->error($e->getMessage());

            Log::error('Error clearing data', [
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
            'wellness_requests' => WellnessRequest::count(),
            'wellness_request_details' => WellnessRequestDetail::count(),
            'wellness_activity_realized' => WellnessActivityRealized::count(),
            'wellness_activity_evidences' => WellnessActivityEvidence::count(),
            'wellness_events' => WellnessEvent::count(),
            'wellness_event_images' => WellnessEventImage::count(),
            'comfenalco_events' => ComfenalcoEvent::count(),
            'chatbot_conversations' => ChatbotConversation::count(),
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

        $this->line('');
        $this->line('🏥 Solicitudes de Bienestar:');
        $this->line("  • Wellness Requests: {$counts['wellness_requests']}");
        $this->line("  • Wellness Request Details: {$counts['wellness_request_details']}");
        $this->line("  • Wellness Activity Realized: {$counts['wellness_activity_realized']}");
        $this->line("  • Wellness Activity Evidences: {$counts['wellness_activity_evidences']}");

        $this->line('');
        $this->line('✨ Bienestar:');
        $this->line("  • Wellness Events: {$counts['wellness_events']}");
        $this->line("  • Wellness Event Images: {$counts['wellness_event_images']}");

        $this->line('');
        $this->line('🎉 Experiencias Comfenalco:');
        $this->line("  • Comfenalco Events: {$counts['comfenalco_events']}");

        $this->line('');
        $this->line('💬 Conversaciones del Chatbot:');
        $this->line("  • Chatbot Conversations: {$counts['chatbot_conversations']}");

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
                $this->line("  • ID: {$form->id} - {$form->request_type} - {$form->status}");
            }
            $this->line('');
        }

        if (WellnessRequest::count() > 0) {
            $this->info('Recent Wellness Requests (last 5):');
            $recent = WellnessRequest::orderBy('created_at', 'desc')->limit(5)->get();
            foreach ($recent as $request) {
                $this->line("  • ID: {$request->id} - {$request->activity_name} - {$request->status}");
            }
            $this->line('');
        }

        if (ChatbotConversation::count() > 0) {
            $this->info('Recent Chatbot Conversations (last 5):');
            $recent = ChatbotConversation::orderBy('created_at', 'desc')->limit(5)->get();
            foreach ($recent as $conv) {
                $question = substr($conv->user_question, 0, 50);
                $this->line("  • ID: {$conv->id} - Q: {$question}...");
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
                'wellness_requests' => WellnessRequest::all()->toArray(),
                'wellness_request_details' => WellnessRequestDetail::all()->toArray(),
                'wellness_activity_realized' => WellnessActivityRealized::all()->toArray(),
                'wellness_activity_evidences' => WellnessActivityEvidence::all()->toArray(),
                'wellness_events' => WellnessEvent::all()->toArray(),
                'wellness_event_images' => WellnessEventImage::all()->toArray(),
                'comfenalco_events' => ComfenalcoEvent::all()->toArray(),
                'chatbot_conversations' => ChatbotConversation::all()->toArray(),
            ];

            $backupFileName = 'data_backup_' . now()->format('Y_m_d_H_i_s') . '.json';
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

            Log::info('Data backup created', [
                'backup_file' => $backupFileName,
                'counts' => $counts,
                'total_records' => $totalRecords,
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Exception $e) {
            $this->error('❌ Error creating backup: ' . $e->getMessage());
            Log::error('Error creating data backup', [
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ]);
        }
    }

    /**
     * Delete all files from storage buckets related to the models.
     */
    private function deleteAllFiles(): int
    {
        $filesDeleted = 0;

        // 1. Delete RequestForm files
        $requestForms = RequestForm::whereNotNull('files')->get();
        foreach ($requestForms as $form) {
            if (is_array($form->files)) {
                foreach ($form->files as $fileData) {
                    if (isset($fileData['path']) && isset($fileData['disk'])) {
                        try {
                            if (Storage::disk($fileData['disk'])->exists($fileData['path'])) {
                                Storage::disk($fileData['disk'])->delete($fileData['path']);
                                $filesDeleted++;
                            }
                        } catch (\Exception $e) {
                            Log::warning('Error deleting RequestForm file', [
                                'path' => $fileData['path'],
                                'disk' => $fileData['disk'],
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        }

        // 2. Delete WellnessActivityEvidence images
        $evidences = WellnessActivityEvidence::all();
        foreach ($evidences as $evidence) {
            if ($evidence->image_url) {
                $deleted = $this->deleteFileFromUrl($evidence->image_url, ['prosalud-public', 'public']);
                if ($deleted) {
                    $filesDeleted++;
                }
            }
        }

        // 3. Delete WellnessActivityRealized listado_asistencia files
        $activities = WellnessActivityRealized::whereNotNull('listado_asistencia_path')->get();
        foreach ($activities as $activity) {
            try {
                $disk = 'prosalud-private';
                $fallbackDisk = 'local';
                $path = $activity->listado_asistencia_path;

                // Try prosalud-private first
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                    $filesDeleted++;
                } elseif (Storage::disk($fallbackDisk)->exists($path)) {
                    Storage::disk($fallbackDisk)->delete($path);
                    $filesDeleted++;
                }
            } catch (\Exception $e) {
                Log::warning('Error deleting WellnessActivityRealized file', [
                    'activity_id' => $activity->id,
                    'path' => $activity->listado_asistencia_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 4. Delete WellnessEventImage files
        $eventImages = WellnessEventImage::all();
        foreach ($eventImages as $image) {
            if ($image->image_url) {
                $deleted = $this->deleteFileFromUrl($image->image_url, ['prosalud-public', 'public']);
                if ($deleted) {
                    $filesDeleted++;
                }
            }
        }

        // 5. Delete ComfenalcoEvent banner images
        $comfenalcoEvents = ComfenalcoEvent::whereNotNull('banner_image')->get();
        foreach ($comfenalcoEvents as $event) {
            try {
                $disk = 'prosalud-public';
                $fallbackDisk = 'public';
                $path = $event->banner_image;

                // Try prosalud-public first
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                    $filesDeleted++;
                } elseif (Storage::disk($fallbackDisk)->exists($path)) {
                    Storage::disk($fallbackDisk)->delete($path);
                    $filesDeleted++;
                }
            } catch (\Exception $e) {
                Log::warning('Error deleting ComfenalcoEvent banner', [
                    'event_id' => $event->id,
                    'path' => $event->banner_image,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $filesDeleted;
    }

    /**
     * Extract path from URL and delete file from storage.
     *
     * @param  string  $url  The full URL of the file
     * @param  array  $disks  Array of disk names to try (in order)
     * @return bool True if file was deleted, false otherwise
     */
    private function deleteFileFromUrl(string $url, array $disks): bool
    {
        try {
            $path = $this->extractPathFromUrl($url);
            if (!$path) {
                return false;
            }

            // Try each disk in order
            foreach ($disks as $disk) {
                try {
                    if (Storage::disk($disk)->exists($path)) {
                        Storage::disk($disk)->delete($path);
                        return true;
                    }
                } catch (\Exception $e) {
                    Log::warning('Error checking/deleting file from disk', [
                        'disk' => $disk,
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::warning('Error deleting file from URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Extract storage path from URL.
     *
     * @param  string  $url  The full URL of the file
     * @return string|null The storage path or null if extraction fails
     */
    private function extractPathFromUrl(string $url): ?string
    {
        // Try to extract path from prosalud-public URL
        $publicBaseUrl = config('filesystems.disks.prosalud-public.url');
        if ($publicBaseUrl && str_starts_with($url, $publicBaseUrl)) {
            $path = str_replace($publicBaseUrl . '/', '', $url);
            $path = ltrim($path, '/');
            return $path;
        }

        // Try public disk URL
        $baseUrl = config('filesystems.disks.public.url');
        if ($baseUrl && str_starts_with($url, $baseUrl)) {
            $path = str_replace($baseUrl . '/', '', $url);
            $path = ltrim($path, '/');
            // Remove 'storage/' prefix if present
            $path = preg_replace('#^storage/#', '', $path);
            return $path;
        }

        // Try parsing URL directly
        $parsed = parse_url($url);
        if ($parsed && isset($parsed['path'])) {
            $path = ltrim($parsed['path'], '/');
            // Remove 'storage/' prefix if present
            $path = preg_replace('#^storage/#', '', $path);
            return $path;
        }

        return null;
    }
}

