<?php

namespace App\Services;

use App\Enums\ConvenioPdfStage;
use App\Models\ConvenioEmailTracking;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ConvenioInvalidationRestoreService
{
    public function __construct(
        private ConvenioPdfStorageService $pdfStorage,
    ) {}

    public function restore(ConvenioEmailTracking $tracking, ?int $userId = null): ConvenioEmailTracking
    {
        if (! $tracking->isInvalidated()) {
            throw new InvalidArgumentException('El convenio no está invalidado.');
        }

        if (! $tracking->affiliateHasSigned()) {
            throw new InvalidArgumentException(
                'El afiliado no había firmado este convenio. Restaure manualmente a pendiente de firma si aplica.',
            );
        }

        if (! filled($tracking->pdf_firmado_afiliado_path)) {
            throw new InvalidArgumentException('No hay PDF firmado por el afiliado registrado en el historial.');
        }

        if (! $this->pdfStorage->hasStage($tracking, ConvenioPdfStage::FirmadoAfiliado)) {
            throw new InvalidArgumentException('No se encontró el PDF firmado por el afiliado en almacenamiento.');
        }

        $this->deletePresidentArtifacts($tracking);

        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'rechazado_at' => null,
            'motivo_rechazo' => null,
            'pdf_final_path' => null,
            'firmado_presidente_at' => null,
            'president_sign_last_error' => null,
            'president_sign_batch_id' => null,
            'president_sign_requested_by_user_id' => null,
            'completed_by_user_id' => null,
            'completed_at' => null,
            'completed_email_sent_at' => null,
            'completed_email_last_error' => null,
        ]);

        Log::info('[CONVENIO] Convenio invalidado restaurado a firmado por afiliado', [
            'tracking_id' => $tracking->id,
            'documento' => $tracking->documento,
            'sede' => $tracking->sede,
            'user_id' => $userId,
        ]);

        return $tracking->fresh();
    }

    /**
     * @param  list<int>  $trackingIds
     * @return array{accepted: int, rejected: list<array{tracking_id: int, reason: string}>}
     */
    public function restoreMany(array $trackingIds, ?int $userId = null): array
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
                $this->restore($tracking, $userId);
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

    private function deletePresidentArtifacts(ConvenioEmailTracking $tracking): void
    {
        $this->pdfStorage->deleteStage($tracking, ConvenioPdfStage::Final);
    }
}
