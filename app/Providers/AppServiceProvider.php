<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Registrar el servicio de conversión DOCX a PDF
        $this->app->singleton(\App\Services\DocxToPdfCloudConvertService::class, function ($app) {
            return new \App\Services\DocxToPdfCloudConvertService;
        });

        // Registrar el servicio de firma de documentos según configuración
        $this->app->singleton(\App\Contracts\DocumentSigningServiceInterface::class, function ($app) {
            $provider = config('services.document_signing.provider', 'docusign');

            return match ($provider) {
                'signnow' => new \App\Services\SignNowService,
                'docusign' => new \App\Services\DocuSignService,
                default => new \App\Services\DocuSignService,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configurar rate limiters personalizados para seguridad con fingerprinting mejorado
        RateLimiter::for('critical-endpoints', function (Request $request) {
            // Fingerprinting: IP + User-Agent + documento (si está presente)
            // Esto previene ataques distribuidos usando múltiples IPs
            $documento = $request->input('documento');
            $fingerprint = md5(
                $request->ip().
                $request->userAgent().
                ($documento ?? '')
            );

            return Limit::perMinute(5)
                ->by($fingerprint)
                ->response(function (Request $request, array $headers) {
                    // Log estructurado para alertas
                    \App\Services\SecurityAlertService::logRateLimitExceeded(
                        'critical-endpoints',
                        $request->path(),
                        $request->ip(),
                        $request->userAgent()
                    );

                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiadas solicitudes. Intenta nuevamente más tarde.',
                        'error' => 'rate_limit_exceeded',
                    ], 429, $headers);
                });
        });

        RateLimiter::for('convenio-signing-public', function (Request $request) {
            $fingerprint = md5($request->ip().$request->userAgent());

            return Limit::perMinute(60)
                ->by($fingerprint)
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiadas solicitudes. Intenta nuevamente más tarde.',
                        'error' => 'rate_limit_exceeded',
                    ], 429, $headers);
                });
        });

        RateLimiter::for('public-endpoints', function (Request $request) {
            // Fingerprinting: IP + User-Agent para endpoints públicos
            $fingerprint = md5(
                $request->ip().
                $request->userAgent()
            );

            return Limit::perMinute(20)
                ->by($fingerprint)
                ->response(function (Request $request, array $headers) {
                    \App\Services\SecurityAlertService::logRateLimitExceeded(
                        'public-endpoints',
                        $request->path(),
                        $request->ip(),
                        $request->userAgent()
                    );

                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiadas solicitudes. Intenta nuevamente más tarde.',
                        'error' => 'rate_limit_exceeded',
                    ], 429, $headers);
                });
        });

        // Rate limiters híbridos con doble capa (defensa en profundidad)
        // Estos rate limiters se usan como fallback o para compatibilidad
        // El middleware DualRateLimit implementa la lógica híbrida completa

        RateLimiter::for('otp-requests', function (Request $request) {
            // Rate limiting mejorado para OTP: documento + IP + User-Agent
            // Prioriza documento si está presente, sino usa fingerprint completo
            $documento = $request->input('documento');

            if ($documento) {
                // Si hay documento, usar documento + IP para tracking por usuario
                $identifier = md5($documento.$request->ip());
            } else {
                // Si no hay documento, usar fingerprint completo
                $identifier = md5(
                    $request->ip().
                    $request->userAgent()
                );
            }

            return Limit::perMinute(5)
                ->by($identifier)
                ->response(function (Request $request, array $headers) {
                    \App\Services\SecurityAlertService::logRateLimitExceeded(
                        'otp-requests',
                        $request->path(),
                        $request->ip(),
                        $request->userAgent(),
                        $request->input('documento')
                    );

                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiadas solicitudes de OTP. Por favor, espera un momento.',
                        'error' => 'rate_limit_exceeded',
                    ], 429, $headers);
                });
        });

        // Rate limiters individuales para el middleware DualRateLimit
        // Estos se usan internamente por el middleware para verificar cada capa

        RateLimiter::for('otp-requests-doc', function (Request $request) {
            // Capa 1: Límite por documento (5/min)
            $documento = $request->input('documento');

            if (! $documento) {
                // Si no hay documento, no aplicar límite (se aplicará el límite por IP)
                return Limit::none();
            }

            $identifier = 'otp:doc:'.md5($documento);

            return Limit::perMinute(5)
                ->by($identifier)
                ->response(function (Request $request, array $headers) {
                    \App\Services\SecurityAlertService::logRateLimitExceeded(
                        'otp-requests-doc',
                        $request->path(),
                        $request->ip(),
                        $request->userAgent(),
                        $request->input('documento')
                    );

                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiadas solicitudes de OTP. Por favor, espera un momento.',
                        'error' => 'rate_limit_exceeded',
                    ], 429, $headers);
                });
        });

        RateLimiter::for('otp-requests-ip', function (Request $request) {
            // Capa 2: Límite global por IP (30/min)
            $identifier = 'otp:ip:'.md5($request->ip().$request->userAgent());

            return Limit::perMinute(30)
                ->by($identifier)
                ->response(function (Request $request, array $headers) {
                    \App\Services\SecurityAlertService::logRateLimitExceeded(
                        'otp-requests-ip',
                        $request->path(),
                        $request->ip(),
                        $request->userAgent(),
                        $request->input('documento')
                    );

                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiadas solicitudes desde esta dirección. Intenta nuevamente más tarde.',
                        'error' => 'rate_limit_exceeded',
                    ], 429, $headers);
                });
        });

        RateLimiter::for('critical-endpoints-doc', function (Request $request) {
            // Capa 1: Límite por documento (5/min)
            $documento = $request->input('documento');

            if (! $documento) {
                // Si no hay documento, no aplicar límite (se aplicará el límite por IP)
                return Limit::none();
            }

            $identifier = 'cert:doc:'.md5($documento);

            return Limit::perMinute(5)
                ->by($identifier)
                ->response(function (Request $request, array $headers) {
                    \App\Services\SecurityAlertService::logRateLimitExceeded(
                        'critical-endpoints-doc',
                        $request->path(),
                        $request->ip(),
                        $request->userAgent()
                    );

                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiadas solicitudes. Intenta nuevamente más tarde.',
                        'error' => 'rate_limit_exceeded',
                    ], 429, $headers);
                });
        });

        RateLimiter::for('critical-endpoints-ip', function (Request $request) {
            // Capa 2: Límite global por IP (50/min)
            $identifier = 'cert:ip:'.md5($request->ip().$request->userAgent());

            return Limit::perMinute(50)
                ->by($identifier)
                ->response(function (Request $request, array $headers) {
                    \App\Services\SecurityAlertService::logRateLimitExceeded(
                        'critical-endpoints-ip',
                        $request->path(),
                        $request->ip(),
                        $request->userAgent()
                    );

                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiadas solicitudes desde esta dirección. Intenta nuevamente más tarde.',
                        'error' => 'rate_limit_exceeded',
                    ], 429, $headers);
                });
        });
    }
}
