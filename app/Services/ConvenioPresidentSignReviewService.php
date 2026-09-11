<?php

namespace App\Services;

use App\Models\ConvenioEmailTracking;
use InvalidArgumentException;
use Throwable;

class ConvenioPresidentSignReviewService
{
    public function __construct(
        private ConvenioCompletedEmailService $completedEmailService,
    ) {}

    public function complete(ConvenioEmailTracking $tracking, int $userId): void
    {
        if (! $tracking->isEligibleForReviewComplete()) {
            throw new InvalidArgumentException('El convenio no está pendiente de revisión o no tiene PDF final.');
        }

        $tracking->update([
            'completed_by_user_id' => $userId,
        ]);

        try {
            $this->completedEmailService->send($tracking->fresh());
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                $exception->getMessage() ?: 'No se pudo enviar el correo al afiliado. El convenio sigue pendiente de revisión.',
            );
        }

        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_COMPLETADO,
            'completed_at' => now(),
            'completed_email_last_error' => null,
        ]);

        $this->refreshBatchProgress($tracking->president_sign_batch_id);
    }

    /**
     * @param  list<int>  $trackingIds
     * @return array{accepted: int, rejected: list<array{tracking_id: int, reason: string}>}
     */
    public function completeMany(array $trackingIds, int $userId): array
    {
        $rejected = [];
        $accepted = 0;

        $trackings = ConvenioEmailTracking::query()
            ->whereIn('id', $trackingIds)
            ->get()
            ->keyBy('id');

        foreach ($trackingIds as $trackingId) {
            $tracking = $trackings->get($trackingId);

            if ($tracking === null) {
                $rejected[] = [
                    'tracking_id' => $trackingId,
                    'reason' => 'Registro no encontrado.',
                ];

                continue;
            }

            try {
                $this->complete($tracking, $userId);
                $accepted++;
            } catch (InvalidArgumentException $exception) {
                $rejected[] = [
                    'tracking_id' => $trackingId,
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        return [
            'accepted' => $accepted,
            'rejected' => $rejected,
        ];
    }

    public function markReviewError(ConvenioEmailTracking $tracking, ?string $reason = null): void
    {
        if (! $tracking->isEligibleForReviewError()) {
            throw new InvalidArgumentException('El convenio no está pendiente de revisión.');
        }

        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE,
            'president_sign_last_error' => $reason ?: 'Rechazado durante la revisión del convenio.',
        ]);

        $this->refreshBatchProgress($tracking->president_sign_batch_id);
    }

    /**
     * @param  list<int>  $trackingIds
     * @return array{accepted: int, rejected: list<array{tracking_id: int, reason: string}>}
     */
    public function markReviewErrorMany(array $trackingIds, ?string $reason = null): array
    {
        $rejected = [];
        $accepted = 0;

        $trackings = ConvenioEmailTracking::query()
            ->whereIn('id', $trackingIds)
            ->get()
            ->keyBy('id');

        foreach ($trackingIds as $trackingId) {
            $tracking = $trackings->get($trackingId);

            if ($tracking === null) {
                $rejected[] = [
                    'tracking_id' => $trackingId,
                    'reason' => 'Registro no encontrado.',
                ];

                continue;
            }

            try {
                $this->markReviewError($tracking, $reason);
                $accepted++;
            } catch (InvalidArgumentException $exception) {
                $rejected[] = [
                    'tracking_id' => $trackingId,
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        return [
            'accepted' => $accepted,
            'rejected' => $rejected,
        ];
    }

    private function refreshBatchProgress(?int $batchId): void
    {
        if ($batchId === null) {
            return;
        }

        $batch = \App\Models\ConvenioPresidentSignBatch::query()->find($batchId);

        if ($batch !== null) {
            $batch->refreshProgressCounts();
        }
    }
}
