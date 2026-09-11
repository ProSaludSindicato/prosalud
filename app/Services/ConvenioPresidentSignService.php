<?php

namespace App\Services;

use App\Jobs\ApplyPresidentSignatureJob;
use App\Jobs\EnqueuePresidentSignBatchJob;
use App\Models\ConvenioEmailTracking;
use App\Models\ConvenioPresidentSignBatch;
use App\Support\ConvenioAutoSign;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class ConvenioPresidentSignService
{
    public function isEnabled(): bool
    {
        return ConvenioAutoSign::enabled();
    }

    public function isBulkEnabled(): bool
    {
        return ConvenioAutoSign::bulkEnabled();
    }

    public function queue(ConvenioEmailTracking $tracking, ?int $requestedByUserId = null, ?int $batchId = null): void
    {
        if (! $this->isEnabled()) {
            throw new InvalidArgumentException('La autofirma del presidente no está habilitada.');
        }

        if (! filled($tracking->pdf_firmado_afiliado_path)) {
            throw new InvalidArgumentException('No hay PDF firmado por el afiliado.');
        }

        if (! $tracking->isEligibleForPresidentSign()) {
            throw new InvalidArgumentException('El convenio no está listo para firma del presidente.');
        }

        if ($tracking->isInvalidated()) {
            throw new InvalidArgumentException('No se puede firmar un convenio invalidado.');
        }

        $update = [
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
            'president_sign_last_error' => null,
        ];

        if ($requestedByUserId !== null) {
            $update['president_sign_requested_by_user_id'] = $requestedByUserId;
        }

        if ($batchId !== null) {
            $update['president_sign_batch_id'] = $batchId;
        }

        $claimed = ConvenioEmailTracking::query()
            ->where('id', $tracking->id)
            ->whereIn('signing_estado', ConvenioEmailTracking::presidentSignEligibleStates())
            ->whereNotNull('pdf_firmado_afiliado_path')
            ->where('pdf_firmado_afiliado_path', '!=', '')
            ->update($update);

        if ($claimed === 0) {
            throw new InvalidArgumentException('El convenio no está listo para firma del presidente.');
        }

        ApplyPresidentSignatureJob::dispatch($tracking->id);
    }

    /**
     * @param  list<int>  $trackingIds
     * @return array{accepted: int, rejected: list<array{tracking_id: int, reason: string}>, batch_id: int|null}
     */
    public function queueMany(array $trackingIds, int $requestedByUserId): array
    {
        if (! $this->isBulkEnabled()) {
            throw new InvalidArgumentException('La firma masiva del presidente no está habilitada.');
        }

        $this->assertNoActiveBatch();

        $batch = ConvenioPresidentSignBatch::query()->create([
            'requested_by_user_id' => $requestedByUserId,
            'scope' => ConvenioPresidentSignBatch::SCOPE_IDS,
            'include_errors' => false,
            'total' => count($trackingIds),
            'status' => ConvenioPresidentSignBatch::STATUS_PROCESSING,
            'require_review' => ConvenioAutoSign::requireReview(),
        ]);

        $rejected = [];
        $eligibleIds = [];

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

            if (! $tracking->isEligibleForPresidentSign()) {
                $rejected[] = [
                    'tracking_id' => $trackingId,
                    'reason' => 'El convenio no está listo para firma del presidente.',
                ];

                continue;
            }

            if ($tracking->isInvalidated()) {
                $rejected[] = [
                    'tracking_id' => $trackingId,
                    'reason' => 'No se puede firmar un convenio invalidado.',
                ];

                continue;
            }

            $eligibleIds[] = $trackingId;
        }

        if ($eligibleIds !== []) {
            EnqueuePresidentSignBatchJob::dispatch($batch->id, $eligibleIds);
        }

        $batch->update(['total' => count($eligibleIds)]);
        $batch->refreshProgressCounts();

        return [
            'accepted' => count($eligibleIds),
            'rejected' => $rejected,
            'batch_id' => $batch->id,
        ];
    }

    /**
     * @return array{count: int}
     */
    public function previewCampaign(string $scope, ?string $dateFrom, ?string $dateTo, bool $includeErrors): array
    {
        $count = $this->eligibleCampaignQuery($scope, $dateFrom, $dateTo, $includeErrors)->count();

        return ['count' => $count];
    }

    /**
     * @return array{batch_id: int, accepted: int}
     */
    public function queueCampaign(
        string $scope,
        ?string $dateFrom,
        ?string $dateTo,
        bool $includeErrors,
        int $requestedByUserId,
    ): array {
        if (! $this->isBulkEnabled()) {
            throw new InvalidArgumentException('La firma masiva del presidente no está habilitada.');
        }

        $this->assertNoActiveBatch();

        $query = $this->eligibleCampaignQuery($scope, $dateFrom, $dateTo, $includeErrors);
        $count = $query->count();

        if ($count === 0) {
            throw new InvalidArgumentException('No hay convenios elegibles para la firma del presidente.');
        }

        if ($count > ConvenioAutoSign::bulkMax()) {
            throw new InvalidArgumentException(
                sprintf(
                    'Hay %d convenios elegibles; el máximo permitido es %d. Acote el rango de fechas.',
                    $count,
                    ConvenioAutoSign::bulkMax(),
                ),
            );
        }

        $batch = ConvenioPresidentSignBatch::query()->create([
            'requested_by_user_id' => $requestedByUserId,
            'scope' => $scope,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'include_errors' => $includeErrors,
            'total' => $count,
            'status' => ConvenioPresidentSignBatch::STATUS_PROCESSING,
            'require_review' => ConvenioAutoSign::requireReview(),
        ]);

        EnqueuePresidentSignBatchJob::dispatch($batch->id);

        return [
            'batch_id' => $batch->id,
            'accepted' => $count,
        ];
    }

    /**
     * @param  list<int>|null  $trackingIds
     * @return array{accepted: int, rejected: list<array{tracking_id: int, reason: string}>}
     */
    public function enqueueBatchTrackings(int $batchId, ?array $trackingIds = null): array
    {
        $batch = ConvenioPresidentSignBatch::query()->findOrFail($batchId);
        $rejected = [];
        $accepted = 0;

        if ($trackingIds !== null) {
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
                    $this->queue($tracking, $batch->requested_by_user_id, $batch->id);
                    $accepted++;
                } catch (InvalidArgumentException $exception) {
                    $rejected[] = [
                        'tracking_id' => $trackingId,
                        'reason' => $exception->getMessage(),
                    ];
                }
            }
        } else {
            $this->eligibleCampaignQuery(
                $batch->scope,
                $batch->date_from?->format('Y-m-d'),
                $batch->date_to?->format('Y-m-d'),
                (bool) $batch->include_errors,
            )
                ->whereNull('president_sign_batch_id')
                ->orderBy('id')
                ->chunkById(100, function ($trackings) use ($batch, &$accepted): void {
                    foreach ($trackings as $tracking) {
                        try {
                            $this->queue($tracking, $batch->requested_by_user_id, $batch->id);
                            $accepted++;
                        } catch (InvalidArgumentException) {
                            // Otro proceso pudo reclamar el registro entre el conteo y el encolado.
                        }
                    }
                });
        }

        $batch->update(['total' => $batch->trackings()->count()]);
        $batch->refreshProgressCounts();

        return [
            'accepted' => $accepted,
            'rejected' => $rejected,
        ];
    }

    public function findProcessingBatch(): ?ConvenioPresidentSignBatch
    {
        return ConvenioPresidentSignBatch::query()
            ->where('status', ConvenioPresidentSignBatch::STATUS_PROCESSING)
            ->latest('id')
            ->first();
    }

    public function findActiveBatch(): ?ConvenioPresidentSignBatch
    {
        $processing = $this->findProcessingBatch();

        if ($processing !== null) {
            return $processing->refreshProgressCounts();
        }

        $pendingReview = ConvenioPresidentSignBatch::query()
            ->where('require_review', true)
            ->where('status', ConvenioPresidentSignBatch::STATUS_FINISHED)
            ->where('ready_for_review', '>', 0)
            ->latest('id')
            ->first();

        if ($pendingReview === null) {
            return null;
        }

        $pendingReview->refreshProgressCounts();

        return $pendingReview->ready_for_review > 0 ? $pendingReview : null;
    }

    /**
     * @return Builder<ConvenioEmailTracking>
     */
    private function eligibleCampaignQuery(
        string $scope,
        ?string $dateFrom,
        ?string $dateTo,
        bool $includeErrors,
    ): Builder {
        $states = [ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO];

        if ($includeErrors) {
            $states[] = ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE;
        }

        $query = ConvenioEmailTracking::query()
            ->whereIn('signing_estado', $states)
            ->whereNotNull('pdf_firmado_afiliado_path')
            ->where('pdf_firmado_afiliado_path', '!=', '');

        if ($scope === ConvenioPresidentSignBatch::SCOPE_DATE_RANGE) {
            if ($dateFrom) {
                $query->where('firmado_afiliado_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }

            if ($dateTo) {
                $query->where('firmado_afiliado_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }
        }

        return $query;
    }

    private function assertNoActiveBatch(): void
    {
        $active = $this->findProcessingBatch();

        if ($active !== null) {
            throw new InvalidArgumentException(
                sprintf('Ya hay un lote de firma presidencial en curso (ID %d).', $active->id),
            );
        }
    }
}
