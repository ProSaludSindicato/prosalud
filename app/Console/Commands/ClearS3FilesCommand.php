<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Log, Storage};

class ClearS3FilesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 's3:clear 
                            {bucket : The bucket type to clear (public|private|both)}
                            {--force : Skip confirmation prompt}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--path= : Only delete files in a specific path (e.g., "dotacion-signatures/")}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all files from S3 buckets (public or private) to remove test files before production';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $bucket = strtolower($this->argument('bucket'));

        if (!in_array($bucket, ['public', 'private', 'both'], true)) {
            $this->error("❌ Invalid bucket type. Must be 'public', 'private', or 'both'");

            return 1;
        }

        $this->info('🗂️  S3 Bucket Cleanup Command');
        $this->line('=============================');

        $disksToClear = [];

        if ('public' === $bucket || 'both' === $bucket) {
            $disksToClear[] = 'prosalud-public';
        }

        if ('private' === $bucket || 'both' === $bucket) {
            $disksToClear[] = 'prosalud-private';
        }

        // Get file counts
        $fileCounts = [];
        $totalFiles = 0;

        foreach ($disksToClear as $disk) {
            try {
                $path = $this->option('path') ?: '';
                $files = $this->getFiles($disk, $path);
                $count = count($files);
                $fileCounts[$disk] = $count;
                $totalFiles += $count;
            } catch (\Exception $e) {
                $this->error("❌ Error accessing disk '{$disk}': {$e->getMessage()}");

                return 1;
            }
        }

        if (0 === $totalFiles) {
            $this->info('✅ No files found in the specified buckets/paths. Nothing to clear.');

            return 0;
        }

        $this->info("📊 Files found:");
        foreach ($fileCounts as $disk => $count) {
            $this->line("  • {$disk}: {$count} files");
        }
        $this->line("  • Total: {$totalFiles} files");

        // Show file details
        if ($this->option('dry-run') || !$this->option('force')) {
            $this->showFileDetails($disksToClear);
        }

        // Dry run mode
        if ($this->option('dry-run')) {
            $this->warn('🔍 DRY RUN MODE - No files will be deleted');
            $this->info('The files listed above would be deleted.');

            return 0;
        }

        // Confirmation prompt (unless --force is used)
        if (!$this->option('force')) {
            $this->warn('⚠️  WARNING: This will permanently delete ALL files from the specified buckets!');
            $this->warn('⚠️  This action cannot be undone!');

            if ($this->option('path')) {
                $this->info("⚠️  Only files in path '{$this->option('path')}' will be deleted.");
            }

            if (!$this->confirm('Are you sure you want to continue?')) {
                $this->info('❌ Operation cancelled by user.');

                return 0;
            }

            // Double confirmation for safety
            if (!$this->confirm("This will delete {$totalFiles} files. Type 'DELETE' to confirm")) {
                $this->info('❌ Operation cancelled by user.');

                return 0;
            }
        }

        // Perform the deletion
        $this->info('');
        $this->info('🗑️  Deleting files from S3...');

        $totalDeleted = 0;
        $totalFailed = 0;

        foreach ($disksToClear as $disk) {
            $this->info("");
            $this->info("Processing disk: {$disk}");

            try {
                $result = $this->deleteFiles($disk, $this->option('path'));
                $totalDeleted += $result['deleted'];
                $totalFailed += $result['failed'];

                $this->info("  ✅ Deleted: {$result['deleted']} files");
                if ($result['failed'] > 0) {
                    $this->warn("  ⚠️  Failed: {$result['failed']} files");
                }
            } catch (\Exception $e) {
                $this->error("  ❌ Error: {$e->getMessage()}");
                Log::error('Error deleting files from S3', [
                    'disk' => $disk,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Log the operation
        Log::warning('S3 files cleared via artisan command', [
            'command' => 's3:clear',
            'bucket' => $bucket,
            'disks' => $disksToClear,
            'files_deleted' => $totalDeleted,
            'files_failed' => $totalFailed,
            'path_filter' => $this->option('path'),
            'timestamp' => now()->toISOString(),
            'options' => [
                'force' => $this->option('force'),
                'dry_run' => $this->option('dry-run'),
            ],
        ]);

        $this->info('');
        $this->info("✅ Successfully deleted {$totalDeleted} files from S3.");
        if ($totalFailed > 0) {
            $this->warn("⚠️  {$totalFailed} files could not be deleted. Check logs for details.");
        }

        return 0;
    }

    /**
     * Get all files from a disk, optionally filtered by path.
     */
    private function getFiles(string $disk, ?string $path = null): array
    {
        $storage = Storage::disk($disk);

        if ($path) {
            // Get files in specific path
            if (!$storage->exists($path)) {
                return [];
            }

            // Check if it's a directory or file
            if ($storage->exists($path) && !$storage->getMetadata($path)['type'] === 'file') {
                // It's a directory, get all files recursively
                return $storage->allFiles($path);
            }

            // It's a file
            return [$path];
        }

        // Get all files
        return $storage->allFiles();
    }

    /**
     * Show file details for preview.
     */
    private function showFileDetails(array $disks): void
    {
        $this->line('');
        $this->info('📁 File Details:');

        foreach ($disks as $disk) {
            $this->line("");
            $this->line("Disk: {$disk}");

            try {
                $path = $this->option('path') ?: '';
                $files = $this->getFiles($disk, $path);
                $count = count($files);

                if ($count === 0) {
                    $this->line("  • No files found");
                    continue;
                }

                // Show first 10 files
                $filesToShow = array_slice($files, 0, 10);
                foreach ($filesToShow as $file) {
                    try {
                        $size = Storage::disk($disk)->size($file);
                        $sizeFormatted = $this->formatBytes($size);
                        $this->line("  • {$file} ({$sizeFormatted})");
                    } catch (\Exception $e) {
                        $this->line("  • {$file} (size unknown)");
                    }
                }

                if ($count > 10) {
                    $this->line("  ... and " . ($count - 10) . " more files");
                }

                // Calculate total size
                $totalSize = 0;
                foreach ($files as $file) {
                    try {
                        $totalSize += Storage::disk($disk)->size($file);
                    } catch (\Exception $e) {
                        // Skip files we can't get size for
                    }
                }

                $this->line("  Total size: " . $this->formatBytes($totalSize));
            } catch (\Exception $e) {
                $this->warn("  ⚠️  Could not list files: {$e->getMessage()}");
            }
        }
    }

    /**
     * Delete files from a disk.
     */
    private function deleteFiles(string $disk, ?string $path = null): array
    {
        $files = $this->getFiles($disk, $path);
        $deleted = 0;
        $failed = 0;

        $storage = Storage::disk($disk);

        foreach ($files as $file) {
            try {
                $storage->delete($file);
                $deleted++;
            } catch (\Exception $e) {
                $failed++;
                Log::warning('Failed to delete file from S3', [
                    'disk' => $disk,
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'deleted' => $deleted,
            'failed' => $failed,
        ];
    }

    /**
     * Format bytes to human-readable format.
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

