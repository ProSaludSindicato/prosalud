<?php

namespace App\Console\Commands;

use App\Models\WellnessEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillApprovedWellnessEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wellness-events:backfill-approved
                            {--dry : Show what would be updated without saving}
                            {--force : Skip confirmation prompt}
                            {--reviewer-id= : Optional user ID to set as reviewer for backfilled events}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark pending wellness events created from published activities as approved, to fix legacy design';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🧾 Backfill Approved Wellness Events');
        $this->line('====================================');

        $reviewerId = $this->option('reviewer-id');

        if ($reviewerId !== null && ! is_numeric($reviewerId)) {
            $this->error('The --reviewer-id option must be a numeric user ID.');

            return 1;
        }

        $query = WellnessEvent::query()
            ->where('review_status', 'pending')
            ->whereHas('activityRealized', function ($q) {
                $q->where('published_to_gallery', true)
                    ->whereNotNull('gallery_event_id');
            })
            ->whereHas('wellnessRequest', function ($q) {
                $q->where('status', 'resolved');
            });

        $totalCandidates = $query->count();

        if ($totalCandidates === 0) {
            $this->info('✅ No wellness events found that require backfilling.');

            return 0;
        }

        $this->line('');
        $this->info("Found {$totalCandidates} wellness events in state 'pending' that were published from resolved requests.");
        $this->line('');

        $sample = (clone $query)
            ->with(['wellnessRequest'])
            ->orderBy('id')
            ->limit(10)
            ->get();

        $this->info('Sample of affected events (up to 10):');
        foreach ($sample as $event) {
            $request = $event->wellnessRequest;
            $this->line(sprintf(
                '  • Event ID: %d | Title: %s | Date: %s | Request ID: %s | Request status: %s',
                $event->id,
                $event->title,
                optional($event->date)->format('Y-m-d') ?? 'N/A',
                $request?->id ?? 'N/A',
                $request?->status ?? 'N/A'
            ));
        }

        $this->line('');

        if ($this->option('dry')) {
            $this->warn('🔍 DRY RUN MODE - No records will be updated.');
            $this->info("Total candidates: {$totalCandidates}");

            return 0;
        }

        if (! $this->option('force')) {
            $this->warn('⚠️  This will update the review_status of the events above from \"pending\" to \"approved\".');
            $this->warn('⚠️  reviewed_at will be set to now(), and reviewed_by will be set to the provided --reviewer-id (or left null).');
            $this->line('');

            if (! $this->confirm("Do you want to approve these {$totalCandidates} wellness events?")) {
                $this->info('❌ Operation cancelled by user.');

                return 0;
            }
        }

        $updatedCount = 0;

        $query->chunkById(100, function ($events) use (&$updatedCount, $reviewerId) {
            foreach ($events as $event) {
                /** @var \App\Models\WellnessEvent $event */
                $event->review_status = 'approved';
                $event->reviewed_at = now();

                if ($reviewerId !== null) {
                    $event->reviewed_by = (int) $reviewerId;
                }

                $event->save();
                $updatedCount++;
            }
        });

        $this->info('');
        $this->info("✅ Backfill completed. Updated {$updatedCount} wellness events to 'approved'.");

        Log::info('Backfilled approved wellness events', [
            'command' => 'wellness-events:backfill-approved',
            'updated' => $updatedCount,
            'reviewer_id' => $reviewerId !== null ? (int) $reviewerId : null,
            'timestamp' => now()->toIso8601String(),
        ]);

        return 0;
    }
}
