<?php

namespace App\Jobs;

use App\Enums\ConvenioPdfStage;
use App\Exceptions\AutoSignApiException;
use App\Models\ConvenioEmailTracking;
use App\Services\AutoSignApiService;
use App\Services\ConvenioPdfStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApplyPresidentSignatureJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    public int $uniqueFor = 180;

    /**
     * @var list<int>
     */
    public array $backoff = [30];

    public function __construct(public int $trackingId) {}

    public function uniqueId(): string
    {
        return (string) $this->trackingId;
    }

    public function handle(AutoSignApiService $autoSign, ConvenioPdfStorageService $pdfStorage): void
    {
        $tracking = ConvenioEmailTracking::query()->find($this->trackingId);

        if ($tracking === null || $tracking->signing_estado !== ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE) {
            return;
        }

        $affiliatePdf = $pdfStorage->get($tracking->pdf_firmado_afiliado_path);

        if ($affiliatePdf === null) {
            throw new AutoSignApiException('No se encontró el PDF firmado por el afiliado.', 'missing_affiliate_pdf', 404);
        }

        $result = $autoSign->sign(
            $affiliatePdf,
            $tracking->resolveDownloadFilename(),
            (string) $tracking->id,
        );

        $finalPath = $pdfStorage->storeFromContents($tracking, ConvenioPdfStage::Final, $result->pdfContents);

        $tracking->update([
            'pdf_final_path' => $finalPath,
            'signing_estado' => ConvenioEmailTracking::SIGNING_COMPLETADO,
            'firmado_presidente_at' => now(),
            'president_sign_last_error' => null,
        ]);

        Log::info('[AUTO SIGN] Firma del presidente aplicada', [
            'tracking_id' => $tracking->id,
            'detection_method' => $result->detectionMethod,
            'page' => $result->page,
            'duration_ms' => $result->durationMs,
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

        Log::error('[AUTO SIGN] Falló la firma del presidente', [
            'tracking_id' => $this->trackingId,
            'error' => $exception?->getMessage(),
            'code' => $exception instanceof AutoSignApiException ? $exception->errorCode : null,
        ]);
    }

    private function safeMessage(?Throwable $exception): string
    {
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
                default => 'No se pudo aplicar la firma del presidente.',
            };
        }

        return 'No se pudo aplicar la firma del presidente.';
    }
}
