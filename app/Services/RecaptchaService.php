<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecaptchaService
{
    private ?string $apiKey;

    private ?string $siteKey;

    private ?string $projectId;

    private ?string $verifyUrl;

    public function __construct()
    {
        $this->apiKey = config('services.recaptcha.secret_key');
        $this->siteKey = config('services.recaptcha.site_key');
        $this->projectId = config('services.recaptcha.project_id');

        if (! empty($this->projectId) && ! empty($this->apiKey)) {
            $this->verifyUrl = "https://recaptchaenterprise.googleapis.com/v1/projects/{$this->projectId}/assessments?key={$this->apiKey}";
        } else {
            $this->verifyUrl = null;
        }
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.recaptcha.enabled', true);
    }

    /**
     * Verifica el token de reCAPTCHA Enterprise con Google
     *
     * @param  string|null  $expectedAction  Acción esperada (opcional)
     */
    public function verify(?string $token, ?string $expectedAction = null, ?string $remoteIp = null): array
    {
        if (! $this->isEnabled()) {
            Log::warning('reCAPTCHA deshabilitado: verificación omitida', [
                'expected_action' => $expectedAction,
            ]);

            return [
                'success' => true,
                'score' => null,
                'disabled' => true,
                'error_codes' => [],
            ];
        }

        // Si no hay token, retornar fallo
        if (empty($token)) {
            return [
                'success' => false,
                'error' => 'Token de reCAPTCHA no proporcionado',
                'error_codes' => ['missing-input-response'],
            ];
        }

        // Validar configuración
        if (empty($this->apiKey) || empty($this->projectId) || empty($this->siteKey)) {
            Log::error('Configuración de reCAPTCHA incompleta', [
                'has_api_key' => ! empty($this->apiKey),
                'has_project_id' => ! empty($this->projectId),
                'has_site_key' => ! empty($this->siteKey),
            ]);

            return [
                'success' => false,
                'error' => 'Error de configuración del servidor. Contacte al administrador.',
                'error_codes' => ['missing-input-secret'],
            ];
        }

        if (empty($this->verifyUrl)) {
            Log::error('URL de verificación de reCAPTCHA no configurada');

            return [
                'success' => false,
                'error' => 'Error de configuración del servidor. Contacte al administrador.',
                'error_codes' => ['missing-input-secret'],
            ];
        }

        try {
            // Construir el payload según la documentación de reCAPTCHA Enterprise
            $payload = [
                'event' => [
                    'token' => $token,
                    'siteKey' => $this->siteKey,
                ],
            ];

            // Agregar expectedAction si se proporciona
            if (! empty($expectedAction)) {
                $payload['event']['expectedAction'] = $expectedAction;
            }

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])
                ->timeout(30)
                ->post($this->verifyUrl, $payload);

            $result = $response->json();

            if (! $response->successful()) {
                Log::warning('Error al verificar reCAPTCHA Enterprise', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'error' => 'Error al comunicarse con el servicio de reCAPTCHA',
                    'error_codes' => ['bad-request'],
                ];
            }

            // La respuesta de reCAPTCHA Enterprise tiene una estructura diferente
            // Verificar el score y el tokenProperties
            $tokenProperties = $result['tokenProperties'] ?? [];
            $riskAnalysis = $result['riskAnalysis'] ?? [];
            $score = $riskAnalysis['score'] ?? null;

            // En reCAPTCHA Enterprise, un score >= 0.5 generalmente indica que es legítimo
            // Pero también verificamos que el token sea válido
            $isValid = isset($tokenProperties['valid']) && $tokenProperties['valid'] === true;
            $actionMatches = true;

            // Si se proporcionó expectedAction, verificar que coincida
            if (! empty($expectedAction) && isset($tokenProperties['action'])) {
                $actionMatches = $tokenProperties['action'] === $expectedAction;
            }

            $success = $isValid && $actionMatches && ($score === null || $score >= 0.5);

            // Log único con el resultado final para verificar que está funcionando
            Log::info('reCAPTCHA Enterprise verificación', [
                'success' => $success,
                'score' => $score,
                'valid' => $isValid,
                'action_matches' => $actionMatches,
            ]);

            return [
                'success' => $success,
                'score' => $score,
                'action' => $tokenProperties['action'] ?? null,
                'hostname' => $tokenProperties['hostname'] ?? null,
                'createTime' => $tokenProperties['createTime'] ?? null,
                'error_codes' => $success ? [] : ['invalid-input-response'],
            ];
        } catch (\Exception $e) {
            Log::error('Excepción al verificar reCAPTCHA Enterprise', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [
                'success' => false,
                'error' => 'Error inesperado al verificar reCAPTCHA',
                'error_codes' => ['bad-request'],
            ];
        }
    }

    /**
     * Verifica si el token es válido (método de conveniencia)
     */
    public function isValid(?string $token, ?string $expectedAction = null, ?string $remoteIp = null): bool
    {
        $result = $this->verify($token, $expectedAction, $remoteIp);

        return $result['success'] === true;
    }
}
