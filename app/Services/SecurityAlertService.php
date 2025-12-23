<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SecurityAlertService
{
    /**
     * Umbral para alertas de rate limiting (número de activaciones en ventana de tiempo)
     */
    private const RATE_LIMIT_ALERT_THRESHOLD = 10; // 10 activaciones
    private const RATE_LIMIT_ALERT_WINDOW = 60; // En 60 segundos

    /**
     * Log estructurado cuando se excede el rate limit
     *
     * @param string $limiterName Nombre del rate limiter
     * @param string $endpoint Endpoint que fue bloqueado
     * @param string $ip Dirección IP
     * @param string|null $userAgent User Agent
     * @param string|null $documento Documento (si aplica)
     * @return void
     */
    public static function logRateLimitExceeded(
        string $limiterName,
        string $endpoint,
        string $ip,
        ?string $userAgent = null,
        ?string $documento = null
    ): void {
        $logData = [
            'event_type' => 'rate_limit_exceeded',
            'limiter' => $limiterName,
            'endpoint' => $endpoint,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'documento' => $documento ? self::hashDocumento($documento) : null,
            'timestamp' => now()->toIso8601String(),
        ];

        // Log estructurado para análisis
        Log::warning('Rate limit excedido', $logData);

        // Incrementar contador para alertas
        $alertKey = "security_alert:rate_limit:{$limiterName}:{$ip}";
        $count = Cache::increment($alertKey, 1);
        
        // Establecer expiración si es la primera vez
        if ($count === 1) {
            Cache::put($alertKey, 1, now()->addSeconds(self::RATE_LIMIT_ALERT_WINDOW));
        }

        // Verificar si se debe enviar alerta
        if ($count >= self::RATE_LIMIT_ALERT_THRESHOLD) {
            self::sendRateLimitAlert($limiterName, $endpoint, $ip, $count);
            
            // Resetear contador después de alerta
            Cache::forget($alertKey);
        }
    }

    /**
     * Log estructurado cuando falla la verificación de reCAPTCHA
     *
     * @param string $endpoint Endpoint donde falló
     * @param string $ip Dirección IP
     * @param array $recaptchaResult Resultado de la verificación
     * @return void
     */
    public static function logRecaptchaFailure(
        string $endpoint,
        string $ip,
        array $recaptchaResult
    ): void {
        $logData = [
            'event_type' => 'recaptcha_failure',
            'endpoint' => $endpoint,
            'ip' => $ip,
            'recaptcha_score' => $recaptchaResult['score'] ?? null,
            'recaptcha_error' => $recaptchaResult['error'] ?? null,
            'recaptcha_error_codes' => $recaptchaResult['error_codes'] ?? [],
            'timestamp' => now()->toIso8601String(),
        ];

        Log::warning('reCAPTCHA verification failed', $logData);

        // Incrementar contador para alertas
        $alertKey = "security_alert:recaptcha_failure:{$ip}";
        $count = Cache::increment($alertKey, 1);
        
        if ($count === 1) {
            Cache::put($alertKey, 1, now()->addSeconds(self::RATE_LIMIT_ALERT_WINDOW));
        }

        // Alertar si hay muchos fallos
        if ($count >= self::RATE_LIMIT_ALERT_THRESHOLD) {
            self::sendRecaptchaFailureAlert($endpoint, $ip, $count);
            Cache::forget($alertKey);
        }
    }

    /**
     * Obtener estadísticas de rate limiting para un endpoint
     *
     * @param string $endpoint
     * @param int $minutes
     * @return array
     */
    public static function getRateLimitStats(string $endpoint, int $minutes = 60): array
    {
        // Esta función puede ser expandida para consultar logs estructurados
        // Por ahora retorna estructura básica
        return [
            'endpoint' => $endpoint,
            'window_minutes' => $minutes,
            'total_exceeded' => 0, // Se puede implementar consultando logs
            'top_ips' => [], // Se puede implementar consultando logs
        ];
    }

    /**
     * Enviar alerta cuando se excede el umbral de rate limiting
     *
     * @param string $limiterName
     * @param string $endpoint
     * @param string $ip
     * @param int $count
     * @return void
     */
    private static function sendRateLimitAlert(
        string $limiterName,
        string $endpoint,
        string $ip,
        int $count
    ): void {
        $alertData = [
            'type' => 'rate_limit_threshold_exceeded',
            'limiter' => $limiterName,
            'endpoint' => $endpoint,
            'ip' => $ip,
            'count' => $count,
            'threshold' => self::RATE_LIMIT_ALERT_THRESHOLD,
            'window_seconds' => self::RATE_LIMIT_ALERT_WINDOW,
            'timestamp' => now()->toIso8601String(),
        ];

        // Log de alerta crítica
        Log::critical('ALERTA DE SEGURIDAD: Rate limit threshold excedido', $alertData);

        // Aquí se puede agregar envío de email/Slack/etc
        // Ejemplo:
        // if (config('security.alerts.enabled')) {
        //     Mail::to(config('security.alerts.email'))->send(new SecurityAlertMail($alertData));
        // }
    }

    /**
     * Enviar alerta cuando hay muchos fallos de reCAPTCHA
     *
     * @param string $endpoint
     * @param string $ip
     * @param int $count
     * @return void
     */
    private static function sendRecaptchaFailureAlert(
        string $endpoint,
        string $ip,
        int $count
    ): void {
        $alertData = [
            'type' => 'recaptcha_failure_threshold_exceeded',
            'endpoint' => $endpoint,
            'ip' => $ip,
            'count' => $count,
            'threshold' => self::RATE_LIMIT_ALERT_THRESHOLD,
            'window_seconds' => self::RATE_LIMIT_ALERT_WINDOW,
            'timestamp' => now()->toIso8601String(),
        ];

        Log::critical('ALERTA DE SEGURIDAD: Múltiples fallos de reCAPTCHA', $alertData);
    }

    /**
     * Hashear documento para logging (protección de PII)
     *
     * @param string $documento
     * @return string
     */
    private static function hashDocumento(string $documento): string
    {
        // Usar hash para proteger PII en logs
        // Mantener solo últimos 4 dígitos para debugging
        $last4 = substr($documento, -4);
        $hash = substr(hash('sha256', $documento), 0, 8);
        
        return "****{$last4} ({$hash})";
    }

    /**
     * Log de intento de acceso sospechoso
     *
     * @param string $endpoint
     * @param string $ip
     * @param string $reason
     * @param array $context
     * @return void
     */
    public static function logSuspiciousActivity(
        string $endpoint,
        string $ip,
        string $reason,
        array $context = []
    ): void {
        $logData = [
            'event_type' => 'suspicious_activity',
            'endpoint' => $endpoint,
            'ip' => $ip,
            'reason' => $reason,
            'context' => $context,
            'timestamp' => now()->toIso8601String(),
        ];

        Log::warning('Actividad sospechosa detectada', $logData);
    }
}
