<?php

namespace App\Services;

use App\Contracts\DocxToPdfConverter;
use Exception;
use Illuminate\Support\Facades\Log;

class DocxToPdfService implements DocxToPdfConverter
{
    public function __construct(
        private WordToPdfApiService $primaryConverter,
        private DocxToPdfCloudConvertService $fallbackConverter,
    ) {}

    public function convert(string $docxPath, bool $saveToStorage = true): array
    {
        $primaryEnabled = config('wordtopdf.enabled', true);
        $primaryAvailable = $primaryEnabled && $this->primaryConverter->isAvailable();
        $usePrimary = $primaryAvailable;

        if ($usePrimary) {
            try {
                Log::info('Intentando conversión DOCX a PDF con servicio primario (WordToPdf API)', [
                    'archivo' => basename($docxPath),
                ]);

                return $this->primaryConverter->convert($docxPath, $saveToStorage);
            } catch (Exception $e) {
                Log::warning('Conversión primaria WordToPdf API falló, intentando fallback CloudConvert', [
                    'archivo' => basename($docxPath),
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            Log::info('Servicio primario WordToPdf API no disponible o deshabilitado, usando CloudConvert', [
                'archivo' => basename($docxPath),
                'enabled' => $primaryEnabled,
                'available' => $primaryAvailable,
            ]);
        }

        if (! $this->fallbackConverter->isAvailable()) {
            Log::critical('Ningún servicio de conversión DOCX a PDF disponible', [
                'archivo' => basename($docxPath),
            ]);

            throw new Exception('No hay servicios de conversión DOCX a PDF disponibles');
        }

        try {
            Log::info('Intentando conversión DOCX a PDF con fallback CloudConvert', [
                'archivo' => basename($docxPath),
            ]);

            return $this->fallbackConverter->convert($docxPath, $saveToStorage);
        } catch (Exception $e) {
            Log::critical('Conversión DOCX a PDF falló en ambos servicios', [
                'archivo' => basename($docxPath),
                'primary_enabled' => $primaryEnabled,
                'primary_available' => $primaryAvailable,
                'fallback_error' => $e->getMessage(),
            ]);

            throw new Exception('Error al convertir DOCX a PDF: todos los servicios fallaron. '.$e->getMessage(), 0, $e);
        }
    }

    public function isAvailable(): bool
    {
        return (config('wordtopdf.enabled', true) && $this->primaryConverter->isAvailable())
            || $this->fallbackConverter->isAvailable();
    }
}
