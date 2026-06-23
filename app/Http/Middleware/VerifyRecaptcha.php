<?php

namespace App\Http\Middleware;

use App\Services\RecaptchaService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyRecaptcha
{
    public function __construct(
        private readonly RecaptchaService $recaptchaService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string|null  $action  Acción esperada de reCAPTCHA (opcional)
     */
    public function handle(Request $request, Closure $next, ?string $action = null): Response
    {
        if (! $this->recaptchaService->isEnabled()) {
            Log::warning('reCAPTCHA deshabilitado: middleware omitido', [
                'endpoint' => $request->path(),
                'action' => $action,
            ]);

            $request->merge([
                'recaptcha_verified' => true,
                'recaptcha_score' => null,
                'recaptcha_bypassed' => true,
            ]);

            return $next($request);
        }

        // Obtener el token de reCAPTCHA del request
        $token = $request->input('recaptcha_token')
              ?? $request->header('X-Recaptcha-Token');

        // Si no hay token, rechazar la solicitud
        if (empty($token)) {
            Log::warning('Solicitud rechazada: token de reCAPTCHA faltante', [
                'endpoint' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'La verificación de reCAPTCHA es requerida.',
                'error' => 'missing_recaptcha_token',
            ], 400);
        }

        // Verificar el token con reCAPTCHA Enterprise
        $result = $this->recaptchaService->verify(
            $token,
            $action,
            $request->ip()
        );

        // Si la verificación falla, rechazar la solicitud
        if (! $result['success']) {
            // Log estructurado usando el servicio de alertas
            \App\Services\SecurityAlertService::logRecaptchaFailure(
                $request->path(),
                $request->ip(),
                $result
            );

            return response()->json([
                'success' => false,
                'message' => 'La verificación de reCAPTCHA falló. Por favor, intente nuevamente.',
                'error' => 'recaptcha_verification_failed',
            ], 403);
        }

        // Log de verificación exitosa (solo en modo debug para no saturar logs)
        if (config('app.debug')) {
            Log::debug('reCAPTCHA verificado exitosamente', [
                'endpoint' => $request->path(),
                'score' => $result['score'] ?? null,
                'action' => $result['action'] ?? null,
            ]);
        }

        // Agregar información de reCAPTCHA al request para uso posterior
        $request->merge([
            'recaptcha_verified' => true,
            'recaptcha_score' => $result['score'] ?? null,
        ]);

        return $next($request);
    }
}
