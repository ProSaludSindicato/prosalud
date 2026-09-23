<?php

namespace App\Services;

use App\Enums\ConvenioPdfStage;
use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ConvenioEmailTracking;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ConvenioAffiliateResignService
{
    public function __construct(
        private ConvenioPdfStorageService $pdfStorage,
    ) {}

    public function request(ConvenioEmailTracking $tracking, ?int $requestedByUserId = null): void
    {
        if (! config('convenio_signing.enabled', true)) {
            throw new InvalidArgumentException('La firma digital de convenios no está habilitada.');
        }

        if (! $tracking->isEligibleForAffiliateResign()) {
            throw new InvalidArgumentException($this->rejectionReason($tracking));
        }

        if (! $this->pdfStorage->hasOriginal($tracking)) {
            throw new InvalidArgumentException('No se encontró el PDF original para reenviar la firma.');
        }

        $this->deleteSignedArtifacts($tracking);

        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
            'pdf_firmado_afiliado_path' => null,
            'pdf_final_path' => null,
            'pdf_firmado_afiliado_sha256' => null,
            'firmado_afiliado_at' => null,
            'signed_ip' => null,
            'signed_user_agent' => null,
            'signing_audit_log' => null,
            'terms_accepted_at' => null,
            'signing_satisfaction_score' => null,
            'signing_satisfaction_rated_at' => null,
            'firmado_presidente_at' => null,
            'president_sign_last_error' => null,
            'president_sign_batch_id' => null,
            'president_sign_requested_by_user_id' => null,
            'completed_by_user_id' => null,
            'completed_at' => null,
            'completed_email_sent_at' => null,
            'completed_email_last_error' => null,
            'signing_token_hash' => null,
            'token_expires_at' => null,
        ]);

        $tracking->incrementarIntentos();

        $rutaParaEnvio = is_string($tracking->ruta_archivo_pdf) && is_file($tracking->ruta_archivo_pdf)
            ? $tracking->ruta_archivo_pdf
            : '';

        SendConvenioManualEmailJob::dispatch(
            $tracking->documento,
            $tracking->nombre_archivo,
            $rutaParaEnvio,
            $tracking->nombre_convenio,
            null,
            null,
            $tracking->sede,
            $tracking->convenio_data,
            $requestedByUserId ?? $tracking->generated_by_user_id,
            $tracking->id,
        );

        Log::info('[CONVENIO] Solicitud de nueva firma del afiliado encolada', [
            'tracking_id' => $tracking->id,
            'documento' => $tracking->documento,
            'requested_by_user_id' => $requestedByUserId,
        ]);
    }

    /**
     * @param  list<int>  $trackingIds
     * @return array{accepted: int, rejected: list<array{tracking_id: int, reason: string}>}
     */
    public function requestMany(array $trackingIds, ?int $requestedByUserId = null): array
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
                $this->request($tracking, $requestedByUserId);
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

    private function deleteSignedArtifacts(ConvenioEmailTracking $tracking): void
    {
        foreach ([ConvenioPdfStage::FirmadoAfiliado, ConvenioPdfStage::Final] as $stage) {
            $this->pdfStorage->deleteStage($tracking, $stage);
        }
    }

    private function rejectionReason(ConvenioEmailTracking $tracking): string
    {
        if ($tracking->isInvalidated()) {
            return 'No se puede solicitar nueva firma en un convenio invalidado.';
        }

        if ($tracking->signing_estado === ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE) {
            return 'Espere a que termine la firma del presidente antes de solicitar una nueva firma del afiliado.';
        }

        if ($tracking->signing_estado === ConvenioEmailTracking::SIGNING_COMPLETADO) {
            return 'No se puede solicitar nueva firma en un convenio completado.';
        }

        if (! $tracking->affiliateHasSigned()) {
            return 'El afiliado aún no ha firmado este convenio.';
        }

        if (! $tracking->hasOriginalPathRecorded()) {
            return 'No hay PDF original disponible para reenviar.';
        }

        return 'Este convenio no admite solicitar una nueva firma del afiliado.';
    }
}
