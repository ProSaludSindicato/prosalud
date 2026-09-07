<?php

namespace App\Jobs;

use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioCompletedEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendConvenioCompletedEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120];

    public function __construct(public int $trackingId) {}

    public function handle(ConvenioCompletedEmailService $completedEmailService): void
    {
        $tracking = ConvenioEmailTracking::query()->find($this->trackingId);

        if ($tracking === null) {
            return;
        }

        $completedEmailService->send($tracking);
    }
}
