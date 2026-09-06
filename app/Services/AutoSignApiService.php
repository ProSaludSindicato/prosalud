<?php

namespace App\Services;

use App\Exceptions\AutoSignApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AutoSignApiService
{
    private string $baseUrl;

    private string $apiKey;

    private int $timeout;

    private int $connectTimeout;

    private bool $verifySsl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.auto_sign.url'), '/');
        $this->apiKey = (string) config('services.auto_sign.api_key');
        $this->timeout = (int) config('services.auto_sign.timeout', 60);
        $this->connectTimeout = (int) config('services.auto_sign.connect_timeout', 10);
        $this->verifySsl = (bool) config('services.auto_sign.verify_ssl', true);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== ''
            && $this->apiKey !== ''
            && is_file($this->signatureAbsolutePath());
    }

    public function isAvailable(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = $this->httpClient(5, 5)->get($this->baseUrl.'/api/health');

            if (! $response->successful()) {
                return false;
            }

            return ($response->json('status') ?? '') === 'ok';
        } catch (ConnectionException) {
            return false;
        }
    }

    public function sign(string $pdfContents, string $pdfName, string $referenceId): AutoSignResult
    {
        if (! $this->isConfigured()) {
            throw new AutoSignApiException('El servicio de autofirma no está configurado.', 'misconfigured', 500);
        }

        if ($pdfContents === '' || ! str_starts_with($pdfContents, '%PDF')) {
            throw new AutoSignApiException('El PDF a firmar no es válido.', 'invalid_pdf', 400);
        }

        $signaturePath = $this->signatureAbsolutePath();
        $signatureContents = file_get_contents($signaturePath);

        if ($signatureContents === false || $signatureContents === '') {
            throw new AutoSignApiException('No se pudo leer la imagen de firma del presidente.', 'invalid_signature', 400);
        }

        $safeName = basename($pdfName) !== '' ? basename($pdfName) : 'convenio.pdf';
        if (! str_ends_with(strtolower($safeName), '.pdf')) {
            $safeName .= '.pdf';
        }

        try {
            $response = $this->httpClient($this->connectTimeout, $this->timeout)
                ->withHeaders([
                    'X-Api-Key' => $this->apiKey,
                    'X-Reference-Id' => $referenceId,
                ])
                ->attach('pdf', $pdfContents, $safeName)
                ->attach('signature', $signatureContents, basename($signaturePath))
                ->post($this->baseUrl.'/api/auto-sign', $this->stampFields());
        } catch (ConnectionException $e) {
            throw new AutoSignApiException(
                'No se pudo conectar con el servicio de autofirma.',
                'connection_error',
                null,
                $e,
            );
        }

        if ($response->failed()) {
            $payload = $response->json();
            $code = is_array($payload) ? (string) ($payload['code'] ?? 'signing_error') : 'signing_error';
            $error = is_array($payload)
                ? (string) ($payload['error'] ?? 'El servicio de autofirma rechazó la solicitud.')
                : 'El servicio de autofirma rechazó la solicitud.';

            Log::warning('[AUTO SIGN] El servicio de autofirma retornó error', [
                'reference_id' => $referenceId,
                'status' => $response->status(),
                'code' => $code,
            ]);

            throw new AutoSignApiException($error, $code, $response->status());
        }

        $signedPdf = $response->body();

        if ($signedPdf === '' || ! str_starts_with($signedPdf, '%PDF')) {
            throw new AutoSignApiException('El archivo recibido no es un PDF válido.', 'invalid_pdf', 500);
        }

        $pageHeader = $response->header('X-Signature-Page');

        return new AutoSignResult(
            pdfContents: $signedPdf,
            detectionMethod: $response->header('X-Signature-Detection-Method') ?: null,
            page: is_numeric($pageHeader) ? (int) $pageHeader : null,
            durationMs: is_numeric($response->header('X-Duration-Ms'))
                ? (int) $response->header('X-Duration-Ms')
                : null,
        );
    }

    public function signatureAbsolutePath(): string
    {
        $configured = (string) config('convenio_signing.president.signature_path', 'resources/signatures/presidente.png');

        if ($configured === '') {
            $configured = 'resources/signatures/presidente.png';
        }

        if (str_starts_with($configured, '/')) {
            return $configured;
        }

        return base_path($configured);
    }

    /**
     * @return array<string, int|string>
     */
    private function stampFields(): array
    {
        $fields = [
            'search_text' => (string) config('convenio_signing.president.search_text'),
            'width' => (int) config('convenio_signing.president.width', 48),
            'height' => (int) config('convenio_signing.president.height', 63),
            'offset_x' => (int) config('convenio_signing.president.offset_x', 0),
            'offset_y' => (int) config('convenio_signing.president.offset_y', -14),
        ];

        $secondaryAnchor = trim((string) config('convenio_signing.president.secondary_anchor'));
        if ($secondaryAnchor !== '') {
            $fields['secondary_anchor'] = $secondaryAnchor;
        }

        $searchPage = (int) config('convenio_signing.president.search_page', 0);
        if ($searchPage >= 1) {
            $fields['search_page'] = $searchPage;
        }

        return $fields;
    }

    private function httpClient(int $connectTimeout, int $timeout): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withOptions(['verify' => $this->verifySsl])
            ->connectTimeout($connectTimeout)
            ->timeout($timeout);
    }
}
