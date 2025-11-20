<?php

namespace App\Console\Commands;

use App\Models\{SstDeliveryItem, SstDeliveryRecord, SstReturnItem, SstReturnRecord};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log, Storage};

class ClearDotacionEppCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dotacion-epp:clear 
                            {--force : Skip confirmation prompt}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--delete-signatures : Also delete signature files from S3}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all delivery and return records for dotación and EPP (useful for removing test data before production)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('📦 Dotación/EPP Cleanup Command');
        $this->line('================================');

        // Get current counts
        $deliveryCount = SstDeliveryRecord::count();
        $returnCount = SstReturnRecord::count();
        $deliveryItemCount = SstDeliveryItem::count();
        $returnItemCount = SstReturnItem::count();

        if (0 === $deliveryCount && 0 === $returnCount) {
            $this->info('✅ No delivery or return records found. Nothing to clear.');

            return 0;
        }

        $this->info("📊 Current records in database:");
        $this->line("  • Deliveries: {$deliveryCount}");
        $this->line("  • Returns: {$returnCount}");
        $this->line("  • Delivery Items: {$deliveryItemCount}");
        $this->line("  • Return Items: {$returnItemCount}");

        // Show statistics
        $this->showStatistics();

        // Dry run mode
        if ($this->option('dry-run')) {
            $this->warn('🔍 DRY RUN MODE - No data will be deleted');
            $this->info('The following records would be deleted:');
            $this->showRecordDetails();

            if ($this->option('delete-signatures')) {
                $this->info('');
                $this->info('📁 Signature files that would be deleted from S3:');
                $this->showSignatureFiles();
            }

            return 0;
        }

        // Confirmation prompt (unless --force is used)
        if (!$this->option('force')) {
            $this->warn('⚠️  WARNING: This will permanently delete ALL delivery and return records!');
            $this->warn('⚠️  This action cannot be undone!');

            if ($this->option('delete-signatures')) {
                $this->warn('⚠️  Signature files will also be deleted from S3!');
            }

            if (!$this->confirm('Are you sure you want to continue?')) {
                $this->info('❌ Operation cancelled by user.');

                return 0;
            }

            // Double confirmation for safety
            $totalRecords = $deliveryCount + $returnCount;
            if (!$this->confirm("This will delete {$totalRecords} records. Type 'DELETE' to confirm")) {
                $this->info('❌ Operation cancelled by user.');

                return 0;
            }
        }

        // Perform the deletion
        $this->info('🗑️  Clearing dotación/EPP records...');

        try {
            // Delete signature files from S3 if requested
            if ($this->option('delete-signatures')) {
                $this->deleteSignatureFiles();
            }

            // Disable foreign key checks temporarily for truncate
            // Note: TRUNCATE doesn't work inside transactions in MySQL
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            // Delete items first (foreign key constraints)
            $deletedDeliveryItems = SstDeliveryItem::count();
            $deletedReturnItems = SstReturnItem::count();

            SstDeliveryItem::truncate();
            SstReturnItem::truncate();

            $this->info("✅ Deleted {$deletedDeliveryItems} delivery items");
            $this->info("✅ Deleted {$deletedReturnItems} return items");

            // Delete records
            $deletedDeliveries = SstDeliveryRecord::count();
            $deletedReturns = SstReturnRecord::count();

            SstDeliveryRecord::truncate();
            SstReturnRecord::truncate();

            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $this->info("✅ Deleted {$deletedDeliveries} delivery records");
            $this->info("✅ Deleted {$deletedReturns} return records");

            // Log the operation
            Log::warning('Dotación/EPP records cleared via artisan command', [
                'command' => 'dotacion-epp:clear',
                'deliveries_deleted' => $deletedDeliveries,
                'returns_deleted' => $deletedReturns,
                'delivery_items_deleted' => $deletedDeliveryItems,
                'return_items_deleted' => $deletedReturnItems,
                'signatures_deleted' => $this->option('delete-signatures'),
                'timestamp' => now()->toISOString(),
                'options' => [
                    'force' => $this->option('force'),
                    'dry_run' => $this->option('dry-run'),
                    'delete_signatures' => $this->option('delete-signatures'),
                ],
            ]);

            $this->info('');
            $this->info('✅ Successfully cleared all dotación/EPP records from the database.');

            return 0;
        } catch (\Exception $e) {
            // Re-enable foreign key checks in case of error
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            } catch (\Exception $fkException) {
                // Ignore if we can't re-enable (might already be enabled)
            }

            $this->error('❌ Error occurred while clearing records:');
            $this->error($e->getMessage());

            Log::error('Error clearing dotación/EPP records', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()->toISOString(),
            ]);

            return 1;
        }
    }

    /**
     * Show statistics about the records.
     */
    private function showStatistics()
    {
        $this->line('');

        // Deliveries by hospital
        $deliveriesByHospital = SstDeliveryRecord::selectRaw('affiliate_hospital, COUNT(*) as count')
            ->groupBy('affiliate_hospital')
            ->orderBy('count', 'desc')
            ->get();

        if ($deliveriesByHospital->count() > 0) {
            $this->info('📈 Deliveries by Hospital:');
            foreach ($deliveriesByHospital as $hospital) {
                $this->line("  • {$hospital->affiliate_hospital}: {$hospital->count} deliveries");
            }
        }

        // Returns by hospital
        $returnsByHospital = SstReturnRecord::selectRaw('affiliate_hospital, COUNT(*) as count')
            ->groupBy('affiliate_hospital')
            ->orderBy('count', 'desc')
            ->get();

        if ($returnsByHospital->count() > 0) {
            $this->line('');
            $this->info('📈 Returns by Hospital:');
            foreach ($returnsByHospital as $hospital) {
                $this->line("  • {$hospital->affiliate_hospital}: {$hospital->count} returns");
            }
        }

        // Date ranges
        $deliveryDateRange = SstDeliveryRecord::selectRaw('MIN(delivered_at) as earliest, MAX(delivered_at) as latest')
            ->first();

        if ($deliveryDateRange && $deliveryDateRange->earliest) {
            $this->line('');
            $this->info('📅 Delivery Date Range:');
            $this->line("  • Earliest: {$deliveryDateRange->earliest}");
            $this->line("  • Latest: {$deliveryDateRange->latest}");
        }

        $returnDateRange = SstReturnRecord::selectRaw('MIN(returned_at) as earliest, MAX(returned_at) as latest')
            ->first();

        if ($returnDateRange && $returnDateRange->earliest) {
            $this->line('');
            $this->info('📅 Return Date Range:');
            $this->line("  • Earliest: {$returnDateRange->earliest}");
            $this->line("  • Latest: {$returnDateRange->latest}");
        }

        $this->line('');
    }

    /**
     * Show detailed record information.
     */
    private function showRecordDetails()
    {
        $deliveries = SstDeliveryRecord::orderBy('delivered_at', 'desc')->limit(5)->get();
        $returns = SstReturnRecord::orderBy('returned_at', 'desc')->limit(5)->get();

        if ($deliveries->count() > 0) {
            $this->line('');
            $this->info('Recent deliveries (last 5):');
            foreach ($deliveries as $delivery) {
                $this->line("  • Delivery #{$delivery->id}: {$delivery->affiliate_first_name} {$delivery->affiliate_last_name} ({$delivery->affiliate_document_type} {$delivery->affiliate_document_number}) on {$delivery->delivered_at}");
            }
        }

        if ($returns->count() > 0) {
            $this->line('');
            $this->info('Recent returns (last 5):');
            foreach ($returns as $return) {
                $this->line("  • Return #{$return->id}: {$return->affiliate_first_name} {$return->affiliate_last_name} ({$return->affiliate_document_type} {$return->affiliate_document_number}) on {$return->returned_at}");
            }
        }
    }

    /**
     * Show signature files that would be deleted.
     */
    private function showSignatureFiles()
    {
        $disk = 'prosalud-private';
        $signaturePath = 'dotacion-signatures/';

        try {
            if (!Storage::disk($disk)->exists($signaturePath)) {
                $this->line('  • No signature directory found');
                return;
            }

            $files = Storage::disk($disk)->files($signaturePath);
            $count = count($files);

            $this->line("  • Found {$count} signature files in {$signaturePath}");

            if ($count > 0 && $count <= 10) {
                foreach ($files as $file) {
                    $size = Storage::disk($disk)->size($file);
                    $this->line("    - {$file} ({$size} bytes)");
                }
            } elseif ($count > 10) {
                $this->line("    (showing first 10 of {$count} files)");
                foreach (array_slice($files, 0, 10) as $file) {
                    $size = Storage::disk($disk)->size($file);
                    $this->line("    - {$file} ({$size} bytes)");
                }
            }
        } catch (\Exception $e) {
            $this->warn("  ⚠️  Could not list signature files: {$e->getMessage()}");
        }
    }

    /**
     * Delete signature files from S3.
     */
    private function deleteSignatureFiles()
    {
        $disk = 'prosalud-private';
        $signaturePath = 'dotacion-signatures/';

        $this->info('');
        $this->info('🗑️  Deleting signature files from S3...');

        try {
            if (!Storage::disk($disk)->exists($signaturePath)) {
                $this->line('  • No signature directory found');
                return;
            }

            $files = Storage::disk($disk)->files($signaturePath);
            $count = count($files);

            if ($count === 0) {
                $this->line('  • No signature files to delete');
                return;
            }

            $deleted = 0;
            $failed = 0;

            foreach ($files as $file) {
                try {
                    Storage::disk($disk)->delete($file);
                    $deleted++;
                } catch (\Exception $e) {
                    $failed++;
                    Log::warning('Failed to delete signature file', [
                        'file' => $file,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->info("  ✅ Deleted {$deleted} signature files");
            if ($failed > 0) {
                $this->warn("  ⚠️  Failed to delete {$failed} signature files");
            }
        } catch (\Exception $e) {
            $this->error("  ❌ Error deleting signature files: {$e->getMessage()}");
            Log::error('Error deleting signature files', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}

