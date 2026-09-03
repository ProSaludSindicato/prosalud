<?php

namespace App\Services;

use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Support\ConvenioDelivery;
use App\Support\ConvenioPreGeneratedPdfFilename;
use Illuminate\Support\Facades\Log;

class ConvenioPreGeneratedPdfDispatchService
{
    public function __construct(
        private readonly ConvenioPdfStorageService $pdfStorageService,
    ) {}

    /**
     * @return array{success: bool, tracking_id: int|null, dispatched: bool, error: string|null}
     */
    public function persistAndOptionallySend(
        string $absolutePdfPath,
        string $filename,
        string $source,
        ?string $batchId,
        ?int $generatedByUserId,
        bool $sendEmail,
    ): array {
        if (! is_file($absolutePdfPath)) {
            return [
                'success' => false,
                'tracking_id' => null,
                'dispatched' => false,
                'error' => 'Archivo PDF no encontrado.',
            ];
        }

        $parsed = ConvenioPreGeneratedPdfFilename::parse($filename);
        if ($parsed === null) {
            return [
                'success' => false,
                'tracking_id' => null,
                'dispatched' => false,
                'error' => 'Nombre de archivo inválido. Use: SEDE - NOMBRE COMPLETO - DOCUMENTO.pdf',
            ];
        }

        $contents = file_get_contents($absolutePdfPath);
        if ($contents === false || ! ConvenioPreGeneratedPdfFilename::isValidPdfMagic($contents)) {
            return [
                'success' => false,
                'tracking_id' => null,
                'dispatched' => false,
                'error' => 'El archivo no es un PDF válido.',
            ];
        }

        $convenioData = [
            'source' => $source,
            'batch_id' => $batchId,
            'nombres' => $parsed['nombre_afiliado'],
        ];

        $tracking = ConvenioEmailTracking::create([
            'documento' => $parsed['documento'],
            'nombre_afiliado' => $parsed['nombre_afiliado'] !== '' ? $parsed['nombre_afiliado'] : 'No disponible',
            'email_afiliado' => 'No disponible',
            'nombre_convenio' => $parsed['nombre_convenio'],
            'nombre_archivo' => $parsed['filename'],
            'ruta_archivo_pdf' => $absolutePdfPath,
            'estado' => 'pendiente',
            'intentos' => 0,
            'sede' => $parsed['nombre_convenio'],
            'convenio_data' => $convenioData,
            'generated_by_user_id' => $generatedByUserId,
            'is_test' => ConvenioDelivery::isTestMode(),
        ]);

        try {
            $relativeStored = $this->pdfStorageService->storeOriginalFromAbsolutePath($tracking, $absolutePdfPath);
            $tracking->update([
                'pdf_original_path' => $relativeStored,
                'pdf_original_sha256' => hash('sha256', $contents),
            ]);
        } catch (\Throwable $e) {
            $tracking->marcarComoFallido('Error al almacenar PDF: '.$e->getMessage());

            return [
                'success' => false,
                'tracking_id' => $tracking->id,
                'dispatched' => false,
                'error' => $e->getMessage(),
            ];
        }

        if (! $sendEmail) {
            Log::info('[CONVENIO PDF] PDF almacenado sin envío de correo', [
                'tracking_id' => $tracking->id,
                'documento' => $parsed['documento'],
                'source' => $source,
            ]);

            return [
                'success' => true,
                'tracking_id' => $tracking->id,
                'dispatched' => false,
                'error' => null,
            ];
        }

        $optionalEmail = $this->resolveTestRecipientEmail($generatedByUserId);

        SendConvenioManualEmailJob::dispatch(
            $parsed['documento'],
            $parsed['filename'],
            $absolutePdfPath,
            $parsed['nombre_convenio'],
            null,
            $optionalEmail,
            $parsed['nombre_convenio'],
            $convenioData,
            $generatedByUserId,
            $tracking->id,
        );

        Log::info('[CONVENIO PDF] PDF almacenado y envío encolado', [
            'tracking_id' => $tracking->id,
            'documento' => $parsed['documento'],
            'source' => $source,
        ]);

        return [
            'success' => true,
            'tracking_id' => $tracking->id,
            'dispatched' => true,
            'error' => null,
        ];
    }

    private function resolveTestRecipientEmail(?int $generatedByUserId): ?string
    {
        if (! ConvenioDelivery::isTestMode()) {
            return null;
        }

        if ($generatedByUserId === null) {
            return null;
        }

        return User::query()->where('id', $generatedByUserId)->value('email');
    }
}
