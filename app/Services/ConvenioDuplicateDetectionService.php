<?php

namespace App\Services;

use App\Models\ConvenioEmailTracking;
use App\Support\ConvenioDelivery;
use InvalidArgumentException;

class ConvenioDuplicateDetectionService
{
    public const ACTION_INVALIDATE_AND_PROCEED = 'invalidate_and_proceed';

    public const ACTION_SKIP = 'skip';

    public const ACTION_PROCEED_ANYWAY = 'proceed_anyway';

    public function __construct(
        private readonly ConvenioInvalidationService $invalidationService,
    ) {}

    public static function normalizeDocumento(string $documento): string
    {
        return preg_replace('/\D+/', '', $documento) ?? '';
    }

    public static function normalizeSede(?string $sede): string
    {
        return mb_strtoupper(trim((string) $sede), 'UTF-8');
    }

    public static function conflictKey(string $documento, ?string $sede): string
    {
        return self::normalizeDocumento($documento).'|'.self::normalizeSede($sede);
    }

    /**
     * @param  list<array{documento: string, sede?: string|null, incoming_label?: string|null, incoming_nombre?: string|null}>  $incoming
     * @return list<array{
     *     key: string,
     *     documento: string,
     *     sede: string,
     *     incoming_label: string|null,
     *     incoming_nombre: string|null,
     *     can_invalidate: bool,
     *     has_signed: bool,
     *     recommended_action: string,
     *     existing: list<array{
     *         tracking_id: int,
     *         nombre_afiliado: string,
     *         signing_estado: string|null,
     *         estado: string,
     *         created_at: string|null,
     *         affiliate_has_signed: bool,
     *         can_invalidate: bool
     *     }>
     * }>
     */
    public function findConflicts(array $incoming): array
    {
        $normalizedIncoming = [];

        foreach ($incoming as $entry) {
            $documento = self::normalizeDocumento((string) ($entry['documento'] ?? ''));
            $sede = self::normalizeSede($entry['sede'] ?? null);

            if ($documento === '' || $sede === '') {
                continue;
            }

            $key = $documento.'|'.$sede;
            if (isset($normalizedIncoming[$key])) {
                continue;
            }

            $normalizedIncoming[$key] = [
                'key' => $key,
                'documento' => $documento,
                'sede' => $sede,
                'incoming_label' => isset($entry['incoming_label']) ? (string) $entry['incoming_label'] : null,
                'incoming_nombre' => isset($entry['incoming_nombre']) ? (string) $entry['incoming_nombre'] : null,
            ];
        }

        if ($normalizedIncoming === []) {
            return [];
        }

        $lookupDocumentos = [];
        foreach ($normalizedIncoming as $meta) {
            $lookupDocumentos[] = $meta['documento'];
        }

        foreach ($incoming as $entry) {
            $raw = trim((string) ($entry['documento'] ?? ''));
            if ($raw !== '') {
                $lookupDocumentos[] = $raw;
            }
        }

        $existing = ConvenioEmailTracking::query()
            ->whereIn('documento', array_values(array_unique($lookupDocumentos)))
            ->where('is_test', ConvenioDelivery::isTestMode())
            ->where(function ($query): void {
                $query->whereNull('signing_estado')
                    ->orWhere('signing_estado', '!=', ConvenioEmailTracking::SIGNING_RECHAZADO);
            })
            ->orderByDesc('id')
            ->get();

        $grouped = [];
        foreach ($existing as $tracking) {
            $key = self::conflictKey(
                (string) $tracking->documento,
                filled($tracking->sede) ? (string) $tracking->sede : (string) $tracking->nombre_convenio,
            );

            if (! isset($normalizedIncoming[$key])) {
                continue;
            }

            $grouped[$key][] = $tracking;
        }

        $conflicts = [];
        foreach ($normalizedIncoming as $key => $meta) {
            $matches = $grouped[$key] ?? [];
            if ($matches === []) {
                continue;
            }

            $existingPayload = [];
            $hasInvalidatable = false;
            $hasSigned = false;

            foreach ($matches as $tracking) {
                $canInvalidate = $tracking->isEligibleForInvalidation();
                $signed = $tracking->affiliateHasSigned();
                $hasInvalidatable = $hasInvalidatable || $canInvalidate;
                $hasSigned = $hasSigned || $signed;

                $existingPayload[] = [
                    'tracking_id' => $tracking->id,
                    'nombre_afiliado' => $tracking->nombre_afiliado,
                    'signing_estado' => $tracking->signing_estado,
                    'estado' => $tracking->estado,
                    'created_at' => $tracking->created_at?->timezone('America/Bogota')->format('d/m/Y H:i'),
                    'affiliate_has_signed' => $signed,
                    'can_invalidate' => $canInvalidate,
                ];
            }

            $conflicts[] = [
                'key' => $key,
                'documento' => $meta['documento'],
                'sede' => $meta['sede'],
                'incoming_label' => $meta['incoming_label'],
                'incoming_nombre' => $meta['incoming_nombre'],
                'can_invalidate' => $hasInvalidatable,
                'has_signed' => $hasSigned,
                'recommended_action' => $hasInvalidatable
                    ? self::ACTION_INVALIDATE_AND_PROCEED
                    : self::ACTION_SKIP,
                'existing' => $existingPayload,
            ];
        }

        return $conflicts;
    }

    /**
     * @param  list<array<string, mixed>>  $conflicts
     * @param  list<array{documento?: mixed, sede?: mixed, action?: mixed}>  $actions
     * @return array{
     *     needs_confirmation: bool,
     *     duplicates: list<array<string, mixed>>,
     *     proceed_keys: list<string>,
     *     skip_keys: list<string>,
     *     invalidate_ids: list<int>,
     *     invalidate_entries: list<array{tracking_id: int, reason: string}>
     * }
     */
    public function resolveImportDecision(array $conflicts, bool $confirmed, array $actions, ?string $fallbackInvalidationReason = null): array
    {
        if ($conflicts === []) {
            return [
                'needs_confirmation' => false,
                'duplicates' => [],
                'proceed_keys' => [],
                'skip_keys' => [],
                'invalidate_ids' => [],
                'invalidate_entries' => [],
            ];
        }

        if (! $confirmed) {
            return [
                'needs_confirmation' => true,
                'duplicates' => $conflicts,
                'proceed_keys' => [],
                'skip_keys' => [],
                'invalidate_ids' => [],
                'invalidate_entries' => [],
            ];
        }

        $actionsByKey = [];
        foreach ($actions as $actionRow) {
            $documento = is_string($actionRow['documento'] ?? null) ? $actionRow['documento'] : '';
            $sede = is_string($actionRow['sede'] ?? null) ? $actionRow['sede'] : '';
            $action = is_string($actionRow['action'] ?? null) ? $actionRow['action'] : '';
            $key = self::conflictKey($documento, $sede);

            if ($key === '|' || $action === '') {
                continue;
            }

            $actionsByKey[$key] = $actionRow;
        }

        $proceedKeys = [];
        $skipKeys = [];
        $invalidateIds = [];
        $invalidateEntries = [];
        $fallbackReason = is_string($fallbackInvalidationReason) ? trim($fallbackInvalidationReason) : '';

        foreach ($conflicts as $conflict) {
            $key = (string) $conflict['key'];
            $actionRow = $actionsByKey[$key] ?? null;

            if ($actionRow === null) {
                throw new InvalidArgumentException(
                    'Confirme una acción para cada convenio duplicado antes de continuar.',
                );
            }

            $action = is_string($actionRow['action'] ?? null) ? $actionRow['action'] : '';

            $invalidatableIds = [];
            foreach ($conflict['existing'] as $row) {
                if (! empty($row['can_invalidate'])) {
                    $invalidatableIds[] = (int) $row['tracking_id'];
                }
            }

            if ($action === self::ACTION_SKIP) {
                $skipKeys[] = $key;

                continue;
            }

            if ($action === self::ACTION_PROCEED_ANYWAY) {
                $proceedKeys[] = $key;

                continue;
            }

            if ($action === self::ACTION_INVALIDATE_AND_PROCEED) {
                if ($invalidatableIds === []) {
                    throw new InvalidArgumentException(
                        'No se puede invalidar el convenio de '.$conflict['documento'].' en '.$conflict['sede'].' porque ya está firmado. Omítalo o impórtelo sin invalidar el anterior.',
                    );
                }

                $reason = is_string($actionRow['invalidation_reason'] ?? null)
                    ? trim($actionRow['invalidation_reason'])
                    : '';

                if ($reason === '' && $fallbackReason !== '') {
                    $reason = $fallbackReason;
                }

                if ($reason === '' || mb_strlen($reason) < 8) {
                    throw new InvalidArgumentException(
                        'Indique un motivo de invalidación (mín. 8 caracteres) para '.$conflict['documento'].' en '.$conflict['sede'].'.',
                    );
                }

                $proceedKeys[] = $key;
                foreach ($invalidatableIds as $trackingId) {
                    $invalidateIds[] = $trackingId;
                    $invalidateEntries[] = [
                        'tracking_id' => $trackingId,
                        'reason' => $reason,
                    ];
                }

                continue;
            }

            throw new InvalidArgumentException('Acción de duplicado no válida.');
        }

        return [
            'needs_confirmation' => false,
            'duplicates' => $conflicts,
            'proceed_keys' => $proceedKeys,
            'skip_keys' => $skipKeys,
            'invalidate_ids' => array_values(array_unique($invalidateIds)),
            'invalidate_entries' => $invalidateEntries,
        ];
    }

    /**
     * @param  list<array{tracking_id: int, reason: string}>  $entries
     */
    public function applyInvalidations(array $entries, ?int $userId, ?string $fallbackMotivo = null): void
    {
        if ($entries === []) {
            return;
        }

        $fallback = is_string($fallbackMotivo) && trim($fallbackMotivo) !== ''
            ? trim($fallbackMotivo)
            : ConvenioInvalidationService::defaultReplacementMotivo();

        $rejected = [];

        foreach ($entries as $entry) {
            $trackingId = (int) ($entry['tracking_id'] ?? 0);
            $reason = is_string($entry['reason'] ?? null) ? trim($entry['reason']) : '';

            if ($trackingId <= 0) {
                continue;
            }

            $tracking = ConvenioEmailTracking::query()->find($trackingId);
            if ($tracking === null) {
                $rejected[] = [
                    'tracking_id' => $trackingId,
                    'reason' => 'Registro no encontrado.',
                ];

                continue;
            }

            try {
                $this->invalidationService->invalidate(
                    $tracking,
                    $userId,
                    $reason !== '' ? $reason : $fallback,
                );
            } catch (InvalidArgumentException $exception) {
                $rejected[] = [
                    'tracking_id' => $trackingId,
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        if ($rejected !== []) {
            throw new InvalidArgumentException(
                'No se pudieron invalidar convenios anteriores: '.$rejected[0]['reason'],
            );
        }
    }
}
