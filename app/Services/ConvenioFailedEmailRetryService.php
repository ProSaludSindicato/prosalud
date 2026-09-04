<?php

namespace App\Services;

use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ConvenioEmailTracking;
use App\Support\ConvenioDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class ConvenioFailedEmailRetryService
{
    public const MAX_RETRY_BATCH = 1000;

    public function __construct(
        private readonly ConvenioPdfStorageService $pdfStorageService,
    ) {}

    /**
     * @param  array{
     *     tracking_ids?: list<int>|null,
     *     fecha_desde?: string|null,
     *     fecha_hasta?: string|null,
     *     sede?: string|null,
     *     q?: string|null
     * }  $filters
     * @return array{
     *     total: int,
     *     success_count: int,
     *     failed_count: int,
     *     fecha_desde: string|null,
     *     fecha_hasta: string|null,
     *     results: array{
     *         success: list<array{tracking_id: int, documento: string}>,
     *         failed: list<array{tracking_id: int, error: string}>
     *     }
     * }
     */
    public function retry(array $filters, ?int $requestedByUserId, ?string $requestedByEmail): array
    {
        $trackings = $this->failedTrackingsQuery($filters)
            ->orderBy('id')
            ->limit(self::MAX_RETRY_BATCH)
            ->get();

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($trackings as $tracking) {
            $this->dispatchRetry($tracking, $requestedByUserId, $requestedByEmail, $results);
        }

        return [
            'total' => $trackings->count(),
            'success_count' => count($results['success']),
            'failed_count' => count($results['failed']),
            'fecha_desde' => $filters['fecha_desde'] ?? null,
            'fecha_hasta' => $filters['fecha_hasta'] ?? null,
            'results' => $results,
        ];
    }

    /**
     * @param  array{
     *     tracking_ids?: list<int>|null,
     *     fecha_desde?: string|null,
     *     fecha_hasta?: string|null,
     *     sede?: string|null,
     *     q?: string|null
     * }  $filters
     * @return Builder<ConvenioEmailTracking>
     */
    private function failedTrackingsQuery(array $filters): Builder
    {
        $query = ConvenioEmailTracking::query()->where('estado', 'fallido');

        $trackingIds = $filters['tracking_ids'] ?? null;
        if (is_array($trackingIds) && $trackingIds !== []) {
            return $query->whereIn('id', $trackingIds);
        }

        if (! empty($filters['fecha_desde']) || ! empty($filters['fecha_hasta'])) {
            $query->byFechaRango(
                $filters['fecha_desde'] ?? '1970-01-01',
                $filters['fecha_hasta'] ?? now()->toDateString(),
            );
        }

        if (! empty($filters['sede'])) {
            $query->bySede((string) $filters['sede']);
        }

        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $pattern = '%'.$term.'%';
            $query->where(function (Builder $inner) use ($pattern): void {
                $inner->where('documento', 'like', $pattern)
                    ->orWhere('nombre_convenio', 'like', $pattern);
            });
        }

        return $query;
    }

    /**
     * @param  array{
     *     success: list<array{tracking_id: int, documento: string}>,
     *     failed: list<array{tracking_id: int, error: string}>
     * }  $results
     */
    private function dispatchRetry(
        ConvenioEmailTracking $tracking,
        ?int $requestedByUserId,
        ?string $requestedByEmail,
        array &$results,
    ): void {
        $rutaParaEnvio = $tracking->ruta_archivo_pdf;
        if (! is_file((string) $rutaParaEnvio)) {
            $rutaParaEnvio = $this->pdfStorageService->materializeOriginalToTemp($tracking);
        }

        if ($rutaParaEnvio === null || ! is_file($rutaParaEnvio)) {
            $results['failed'][] = [
                'tracking_id' => $tracking->id,
                'error' => 'Archivo PDF no encontrado para este registro.',
            ];

            return;
        }

        $claimed = ConvenioEmailTracking::query()
            ->where('id', $tracking->id)
            ->where('estado', 'fallido')
            ->update([
                'estado' => 'pendiente',
                'error_message' => null,
            ]);

        if ($claimed === 0) {
            $results['failed'][] = [
                'tracking_id' => $tracking->id,
                'error' => 'El registro ya no está en estado fallido.',
            ];

            return;
        }

        $tracking->refresh();
        $tracking->incrementarIntentos();

        $optionalEmail = $this->resolveOptionalEmail($tracking, $requestedByEmail);

        SendConvenioManualEmailJob::dispatch(
            $tracking->documento,
            $tracking->nombre_archivo,
            $rutaParaEnvio,
            $tracking->nombre_convenio,
            null,
            $optionalEmail,
            $tracking->sede,
            $tracking->convenio_data,
            $requestedByUserId ?? $tracking->generated_by_user_id,
            $tracking->id,
        );

        $results['success'][] = [
            'tracking_id' => $tracking->id,
            'documento' => $tracking->documento,
        ];

        Log::info('Retry de convenio fallido encolado', [
            'tracking_id' => $tracking->id,
            'documento' => $tracking->documento,
            'intentos' => $tracking->fresh()->intentos,
        ]);
    }

    private function resolveOptionalEmail(ConvenioEmailTracking $tracking, ?string $requestedByEmail): ?string
    {
        if (ConvenioDelivery::isTestMode()) {
            return is_string($requestedByEmail) && $requestedByEmail !== ''
                ? $requestedByEmail
                : null;
        }

        $storedEmail = $tracking->email_afiliado;
        if (is_string($storedEmail) && filter_var($storedEmail, FILTER_VALIDATE_EMAIL)) {
            return $storedEmail;
        }

        return null;
    }
}
