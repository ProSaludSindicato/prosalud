<?php

namespace App\Jobs;

use App\Services\ConvenioPresidentSignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnqueuePresidentSignBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * @param  list<int>|null  $trackingIds
     */
    public function __construct(
        public int $batchId,
        public ?array $trackingIds = null,
    ) {}

    public function handle(ConvenioPresidentSignService $presidentSign): void
    {
        $presidentSign->enqueueBatchTrackings($this->batchId, $this->trackingIds);
    }
}
