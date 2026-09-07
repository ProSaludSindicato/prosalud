<?php

namespace App\Services;

use App\Models\ConvenioEmailTracking;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ConvenioInvalidationService
{
    public function invalidate(
        ConvenioEmailTracking $tracking,
        ?int $userId,
        string $motivo,
    ): ConvenioEmailTracking {
        if (! $tracking->isEligibleForInvalidation()) {
            throw new InvalidArgumentException($this->rejectionReason($tracking));
        }

        $normalizedMotivo = trim($motivo);
        if ($normalizedMotivo === '') {
            throw new InvalidArgumentException('Indique el motivo de la invalidación.');
        }

        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_RECHAZADO,
            'rechazado_at' => now(),
            'motivo_rechazo' => $normalizedMotivo,
            'token_expires_at' => now(),
        ]);

        Log::info('[CONVENIO] Convenio invalidado', [
            'tracking_id' => $tracking->id,
            'documento' => $tracking->documento,
            'sede' => $tracking->sede,
            'user_id' => $userId,
        ]);

        return $tracking;
    }

    /**
     * @param  list<int>  $trackingIds
     * @return array{accepted: int, rejected: list<array{tracking_id: int, reason: string}>}
     */
    public function invalidateMany(array $trackingIds, ?int $userId, string $motivo): array
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
                $this->invalidate($tracking, $userId, $motivo);
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

    public static function defaultReplacementMotivo(): string
    {
        return 'Este convenio fue reemplazado por uno nuevo. Use el enlace de firma más reciente.';
    }

    private function rejectionReason(ConvenioEmailTracking $tracking): string
    {
        if ($tracking->isInvalidated()) {
            return 'Este convenio ya está invalidado.';
        }

        if ($tracking->affiliateHasSigned()) {
            return 'No se puede invalidar un convenio ya firmado por el afiliado.';
        }

        return 'Este convenio no se puede invalidar.';
    }
}
