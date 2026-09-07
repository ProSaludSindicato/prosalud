<?php

namespace App\Services;

use App\Contracts\DocxToPdfConverter;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WordToPdfApiService implements DocxToPdfConverter
{
    private string $baseUrl;

    private int $timeout;

    private int $connectTimeout;

    private int $maxFileSize;

    private bool $verifySsl;

    private bool $protectPdf;

    private ?string $apiKey;

    /** Resultado del último health check, null si nunca se ha comprobado. */
    private ?bool $cachedAvailability = null;

    /** Timestamp en microsegundos del último health check. */
    private float $availabilityCachedAt = 0.0;

    /** Tiempo en segundos que se reutiliza el resultado del health check. */
    private const AVAILABILITY_CACHE_TTL = 30;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('wordtopdf.base_url', 'http://localhost:5001'), '/');
        $this->timeout = (int) config('wordtopdf.timeout', 120);
        $this->connectTimeout = (int) config('wordtopdf.connect_timeout', 10);
        $this->maxFileSize = (int) config('wordtopdf.max_file_size', 20 * 1024 * 1024);
        $this->verifySsl = (bool) config('wordtopdf.verify_ssl', true);
        $this->protectPdf = (bool) config('wordtopdf.protect_pdf', true);
        $this->apiKey = config('wordtopdf.api_key') ?: null;

        Log::info('WordToPdf API service inicializado', LogSanitizationService::sanitize([
            'base_url' => $this->baseUrl,
            'api_key_configured' => ! empty($this->apiKey),
            'protect_pdf' => $this->protectPdf,
        ]));
    }

    public function convert(string $docxPath, bool $saveToStorage = true, ?bool $protectPdf = null): array
    {
        $this->validateDocxFile($docxPath);

        $fileSize = filesize($docxPath);
        $startTime = microtime(true);
        $shouldProtectPdf = $protectPdf ?? $this->protectPdf;

        Log::info('Iniciando conversión DOCX a PDF con WordToPdf API', [
            'archivo' => basename($docxPath),
            'tamaño' => $this->formatBytes($fileSize),
            'protect_pdf' => $shouldProtectPdf,
        ]);

        try {
            $pdfContent = $this->requestConversion($docxPath, $shouldProtectPdf);
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($saveToStorage) {
                $pdfPath = $this->savePdfToStorage($pdfContent, $docxPath);

                Log::info('PDF guardado exitosamente via WordToPdf API', [
                    'ruta' => $pdfPath,
                    'tamaño' => strlen($pdfContent),
                    'duracion_ms' => $durationMs,
                ]);

                return [
                    'path' => $pdfPath,
                    'content' => null,
                    'size' => strlen($pdfContent),
                ];
            }

            Log::info('Conversión DOCX a PDF completada via WordToPdf API', [
                'tamaño' => strlen($pdfContent),
                'duracion_ms' => $durationMs,
            ]);

            return [
                'content' => $pdfContent,
                'size' => strlen($pdfContent),
            ];
        } catch (Exception $e) {
            Log::error('Error en conversión DOCX a PDF con WordToPdf API', [
                'archivo' => basename($docxPath),
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Error al convertir DOCX a PDF via WordToPdf API: '.$e->getMessage(), 0, $e);
        }
    }

    public function isAvailable(): bool
    {
        if (! config('wordtopdf.enabled', true)) {
            return false;
        }

        if (empty($this->baseUrl)) {
            return false;
        }

        if ($this->cachedAvailability !== null && (microtime(true) - $this->availabilityCachedAt) < self::AVAILABILITY_CACHE_TTL) {
            return $this->cachedAvailability;
        }

        try {
            $healthCheckStart = microtime(true);
            $response = $this->buildHttpClient(5, 5)
                ->get($this->baseUrl.'/health');

            if (! $response->successful()) {
                Log::warning('WordToPdf API health check falló', [
                    'status' => $response->status(),
                ]);

                return $this->cacheAndReturnAvailability(false);
            }

            $data = $response->json();
            $isHealthy = ($data['status'] ?? '') === 'ok' && ($data['libreoffice'] ?? false) === true;
            $healthCheckMs = (int) round((microtime(true) - $healthCheckStart) * 1000);

            if (! $isHealthy) {
                Log::warning('WordToPdf API reporta estado degradado', [
                    'status' => $data['status'] ?? 'unknown',
                    'libreoffice' => $data['libreoffice'] ?? false,
                ]);
            }

            return $this->cacheAndReturnAvailability($isHealthy);
        } catch (ConnectionException $e) {
            Log::warning('WordToPdf API no disponible (conexión)', [
                'error' => $e->getMessage(),
            ]);

            return $this->cacheAndReturnAvailability(false);
        } catch (Exception $e) {
            Log::warning('WordToPdf API no disponible', [
                'error' => $e->getMessage(),
            ]);

            return $this->cacheAndReturnAvailability(false);
        }
    }

    private function cacheAndReturnAvailability(bool $available): bool
    {
        $this->cachedAvailability = $available;
        $this->availabilityCachedAt = microtime(true);

        return $available;
    }

    private function requestConversion(string $docxPath, bool $protectPdf): string
    {
        $fileContents = file_get_contents($docxPath);

        if ($fileContents === false) {
            throw new Exception('No se pudo leer el archivo DOCX');
        }

        try {
            $response = $this->buildHttpClient($this->connectTimeout, $this->timeout)
                ->attach('file', $fileContents, basename($docxPath))
                ->post($this->baseUrl.'/api/convert', [
                    'protect' => $protectPdf ? 'true' : 'false',
                ]);
        } catch (ConnectionException $e) {
            throw new Exception('No se pudo conectar con el servicio WordToPdf: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $errorBody = $response->json();
            $errorMessage = is_array($errorBody)
                ? ($errorBody['error'] ?? $errorBody['message'] ?? 'Error desconocido')
                : $response->body();

            Log::error('WordToPdf API retornó error HTTP', [
                'status' => $response->status(),
                'error' => $errorMessage,
            ]);

            throw new Exception("HTTP {$response->status()}: {$errorMessage}");
        }

        $pdfContent = $response->body();

        if (substr($pdfContent, 0, 4) !== '%PDF') {
            throw new Exception('El archivo recibido no es un PDF válido');
        }

        return $pdfContent;
    }

    private function buildHttpClient(int $connectTimeout, int $timeout): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::withOptions(['verify' => $this->verifySsl])
            ->connectTimeout($connectTimeout)
            ->timeout($timeout);

        if (! empty($this->apiKey)) {
            $client = $client->withToken($this->apiKey);
        }

        return $client;
    }

    private function validateDocxFile(string $docxPath): void
    {
        if (! file_exists($docxPath)) {
            throw new Exception("El archivo .docx no existe en: {$docxPath}");
        }

        $fileSize = filesize($docxPath);

        if ($fileSize === false) {
            throw new Exception("No se pudo obtener el tamaño del archivo: {$docxPath}");
        }

        if ($fileSize > $this->maxFileSize) {
            throw new Exception(
                'El archivo excede el tamaño máximo permitido. '.
                'Tamaño: '.$this->formatBytes($fileSize).', '.
                'Máximo: '.$this->formatBytes($this->maxFileSize)
            );
        }

        $extension = strtolower(pathinfo($docxPath, PATHINFO_EXTENSION));

        if ($extension !== 'docx') {
            throw new Exception("El archivo debe ser .docx, se recibió: .{$extension}");
        }
    }

    private function savePdfToStorage(string $pdfContent, string $originalDocxPath): string
    {
        $tempDir = config('wordtopdf.temp_storage_path', storage_path('app/tmp'));

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $originalName = pathinfo($originalDocxPath, PATHINFO_FILENAME);
        $pdfFileName = $originalName.'.pdf';
        $pdfPath = $tempDir.'/'.$pdfFileName;

        if (file_put_contents($pdfPath, $pdfContent) === false) {
            throw new Exception('Error al guardar PDF en storage');
        }

        return 'tmp/'.$pdfFileName;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2).' '.$units[$pow];
    }
}
