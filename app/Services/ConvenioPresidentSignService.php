<?php

namespace App\Services;

use App\Jobs\ApplyPresidentSignatureJob;
use App\Models\ConvenioEmailTracking;
use App\Support\ConvenioAutoSign;
use InvalidArgumentException;

class ConvenioPresidentSignService
{
    public function isEnabled(): bool
    {
        return ConvenioAutoSign::enabled();
    }

    public function queue(ConvenioEmailTracking $tracking): void
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

        $claimed = ConvenioEmailTracking::query()
            ->where('id', $tracking->id)
            ->whereIn('signing_estado', ConvenioEmailTracking::presidentSignEligibleStates())
            ->whereNotNull('pdf_firmado_afiliado_path')
            ->where('pdf_firmado_afiliado_path', '!=', '')
            ->update([
                'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
                'president_sign_last_error' => null,
            ]);

        if ($claimed === 0) {
            throw new InvalidArgumentException('El convenio no está listo para firma del presidente.');
        }

        ApplyPresidentSignatureJob::dispatch($tracking->id);
    }

    /**
     * @param  list<int>  $trackingIds
     * @return array{accepted: int, rejected: list<array{tracking_id: int, reason: string}>}
     */
    public function queueMany(array $trackingIds): array
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
                $this->queue($tracking);
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
}
