<?php

namespace App\Jobs;

use App\Enums\ConvenioPdfStage;
use App\Exceptions\AutoSignApiException;
use App\Models\ConvenioEmailTracking;
use App\Models\ConvenioPresidentSignBatch;
use App\Services\AutoSignApiService;
use App\Services\ConvenioCompletedEmailService;
use App\Services\ConvenioPdfStorageService;
use App\Support\ConvenioAutoSign;
use App\Support\ConvenioRateLimiter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApplyPresidentSignatureJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 3;

    public int $timeout = 90;

    public int $uniqueFor = 180;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60];

    public function __construct(public int $trackingId) {}

    public function retryUntil(): Carbon
    {
        return now()->addHours(2);
    }

    public function uniqueId(): string
    {
        return (string) $this->trackingId;
    }

    public function handle(
        AutoSignApiService $autoSign,
        ConvenioPdfStorageService $pdfStorage,
        ConvenioCompletedEmailService $completedEmailService,
    ): void {
        if (ConvenioRateLimiter::tooManyAutoSignAttempts()) {
            $this->release(ConvenioRateLimiter::autoSignAvailableIn());

            return;
        }

        $tracking = ConvenioEmailTracking::query()->find($this->trackingId);

        if ($tracking === null || $tracking->signing_estado !== ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE) {
            return;
        }

        $affiliatePdf = $pdfStorage->get($tracking->pdf_firmado_afiliado_path);

        if ($affiliatePdf === null) {
            throw new AutoSignApiException('No se encontró el PDF firmado por el afiliado.', 'missing_affiliate_pdf', 404);
        }

        try {
            ConvenioRateLimiter::hitAutoSign();

            $result = $autoSign->sign(
                $affiliatePdf,
                $tracking->resolveDownloadFilename(),
                (string) $tracking->id,
            );
        } catch (AutoSignApiException $exception) {
            if ($exception->errorCode === 'rate_limited') {
                $this->release(60);

                return;
            }

            throw $exception;
        }

        $finalPath = $pdfStorage->storeFromContents($tracking, ConvenioPdfStage::Final, $result->pdfContents);

        $requireReview = $this->resolveRequireReview($tracking);

        if ($requireReview) {
            $tracking->update([
                'pdf_final_path' => $finalPath,
                'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
                'firmado_presidente_at' => now(),
                'president_sign_last_error' => null,
            ]);
        } else {
            $tracking->update([
                'pdf_final_path' => $finalPath,
                'firmado_presidente_at' => now(),
                'president_sign_last_error' => null,
                'completed_by_user_id' => $tracking->president_sign_requested_by_user_id,
            ]);

            try {
                $completedEmailService->send($tracking->fresh());

                $tracking->update([
                    'signing_estado' => ConvenioEmailTracking::SIGNING_COMPLETADO,
                    'completed_at' => now(),
                    'completed_email_last_error' => null,
                ]);
            } catch (Throwable $exception) {
                $tracking->update([
                    'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
                    'completed_email_last_error' => $exception->getMessage() ?: 'No se pudo enviar el correo del convenio completado.',
                ]);
            }
        }

        $this->refreshBatchProgress($tracking->president_sign_batch_id);

        Log::info('[AUTO SIGN] Firma del presidente aplicada', [
            'tracking_id' => $tracking->id,
            'detection_method' => $result->detectionMethod,
            'page' => $result->page,
            'duration_ms' => $result->durationMs,
            'require_review' => $requireReview,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        ConvenioEmailTracking::query()
            ->where('id', $this->trackingId)
            ->where('signing_estado', ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE)
            ->update([
                'signing_estado' => ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE,
                'president_sign_last_error' => $this->safeMessage($exception),
            ]);

        $batchId = ConvenioEmailTracking::query()
            ->where('id', $this->trackingId)
            ->value('president_sign_batch_id');

        $this->refreshBatchProgress($batchId);

        Log::error('[AUTO SIGN] Falló la firma del presidente', [
            'tracking_id' => $this->trackingId,
            'error' => $this->safeMessage($exception),
            'api_error' => $exception?->getMessage(),
            'code' => $exception instanceof AutoSignApiException ? $exception->errorCode : null,
        ]);
    }

    private function resolveRequireReview(ConvenioEmailTracking $tracking): bool
    {
        if ($tracking->president_sign_batch_id !== null) {
            $batchReview = ConvenioPresidentSignBatch::query()
                ->where('id', $tracking->president_sign_batch_id)
                ->value('require_review');

            if ($batchReview !== null) {
                return (bool) $batchReview;
            }
        }

        return ConvenioAutoSign::requireReview();
    }

    private function refreshBatchProgress(?int $batchId): void
    {
        if ($batchId === null) {
            return;
        }

        $batch = ConvenioPresidentSignBatch::query()->find($batchId);

        if ($batch !== null) {
            $batch->refreshProgressCounts();
        }
    }

    private function safeMessage(?Throwable $exception): string
    {
        if ($exception instanceof MaxAttemptsExceededException) {
            return 'La firma del presidente agotó el tiempo de espera en cola. Reintente el procesamiento.';
        }

        if ($exception instanceof AutoSignApiException) {
            return match ($exception->errorCode) {
                'unauthorized' => 'El servicio de autofirma rechazó la autenticación.',
                'anchor_not_found' => 'No se encontró el texto ancla de la firma del presidente en el PDF.',
                'draw_out_of_page' => 'La firma quedaría fuera de la página del PDF.',
                'invalid_pdf' => 'El PDF enviado no es válido.',
                'invalid_signature' => 'La imagen de firma del presidente no es válida.',
                'file_too_large' => 'El PDF supera el tamaño máximo permitido por el servicio de autofirma.',
                'rate_limited' => 'El servicio de autofirma está limitando solicitudes. Intente de nuevo en un momento.',
                'missing_affiliate_pdf' => 'No se encontró el PDF firmado por el afiliado.',
                'detection_error' => 'El PDF firmado por el afiliado no es compatible con autofirma.',
                default => 'No se pudo aplicar la firma del presidente.',
            };
        }

        return 'No se pudo aplicar la firma del presidente.';
    }
}
