<?php

namespace App\Services;

use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ConvenioEmailTracking;
use App\Support\ConvenioDelivery;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class ConvenioFailedEmailRetryService
{
    public const MAX_RETRY_BATCH = 1000;

    public function __construct(
        private readonly ConvenioPdfStorageService $pdfStorageService,
    ) {}

    /**
     * @return list<array{fecha: string, total: int}>
     */
    public function failedDays(): array
    {
        return ConvenioEmailTracking::query()
            ->where('estado', 'fallido')
            ->toBase()
            ->selectRaw('DATE(created_at) as fecha, COUNT(*) as total')
            ->groupByRaw('DATE(created_at)')
            ->orderByDesc('fecha')
            ->get()
            ->map(fn (object $row): array => [
                'fecha' => (string) $row->fecha,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{
     *     tracking_ids?: list<int>|null,
     *     fechas?: list<string>|null,
     *     fecha_desde?: string|null,
     *     fecha_hasta?: string|null,
     *     sede?: string|null,
     *     q?: string|null
     * }  $filters
     * @return array{
     *     total: int,
     *     success_count: int,
     *     failed_count: int,
     *     fechas: list<string>|null,
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
            'fechas' => $filters['fechas'] ?? null,
            'fecha_desde' => $filters['fecha_desde'] ?? null,
            'fecha_hasta' => $filters['fecha_hasta'] ?? null,
            'results' => $results,
        ];
    }

    /**
     * @param  array{
     *     tracking_ids?: list<int>|null,
     *     fechas?: list<string>|null,
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

        $fechas = $filters['fechas'] ?? null;
        if (is_array($fechas) && $fechas !== []) {
            $query->where(function (Builder $outer) use ($fechas): void {
                foreach ($fechas as $fecha) {
                    if (! is_string($fecha) || $fecha === '') {
                        continue;
                    }

                    $outer->orWhere(function (Builder $dayQuery) use ($fecha): void {
                        $dayQuery->where('created_at', '>=', Carbon::parse($fecha)->startOfDay())
                            ->where('created_at', '<=', Carbon::parse($fecha)->endOfDay());
                    });
                }
            });
        } elseif (! empty($filters['fecha_desde']) || ! empty($filters['fecha_hasta'])) {
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
        if (! $this->pdfStorageService->hasOriginal($tracking)) {
            $results['failed'][] = [
                'tracking_id' => $tracking->id,
                'error' => 'Archivo PDF no encontrado para este registro.',
            ];

            return;
        }

        $rutaParaEnvio = is_string($tracking->ruta_archivo_pdf) && is_file($tracking->ruta_archivo_pdf)
            ? $tracking->ruta_archivo_pdf
            : '';

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
