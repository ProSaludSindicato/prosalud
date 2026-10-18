<?php

namespace App\Providers;

use App\Logging\SanitizeLogContext;
use App\Services\RateLimitIdentity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
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
        $this->app->singleton(\App\Services\WordToPdfApiService::class);

        $this->app->singleton(\App\Services\DocxToPdfCloudConvertService::class, function ($app) {
            return new \App\Services\DocxToPdfCloudConvertService;
        });

        $this->app->singleton(\App\Services\DocxToPdfService::class, function ($app) {
            return new \App\Services\DocxToPdfService(
                $app->make(\App\Services\WordToPdfApiService::class),
                $app->make(\App\Services\DocxToPdfCloudConvertService::class),
            );
        });

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (array_keys((array) config('logging.channels')) as $channel) {
            $taps = (array) config("logging.channels.{$channel}.tap", []);

            if (! in_array(SanitizeLogContext::class, $taps, true)) {
                config(["logging.channels.{$channel}.tap" => [...$taps, SanitizeLogContext::class]]);
            }
        }

        $trustedProxies = config('app.trusted_proxies');

        if (filled($trustedProxies)) {
            TrustProxies::at($trustedProxies === '*' ? '*' : array_map('trim', explode(',', (string) $trustedProxies)));
        }

        $tooManyRequests = fn (Request $request, array $headers) => response()->json([
            'success' => false,
            'message' => 'Demasiadas solicitudes. Intenta nuevamente más tarde.',
            'error' => 'rate_limit_exceeded',
        ], 429, $headers);

        RateLimiter::for('public-reads', fn (Request $request) => Limit::perMinute(600)
            ->by('public-reads:'.$request->ip())
            ->response($tooManyRequests));

        RateLimiter::for('identity', function (Request $request) use ($tooManyRequests) {
            $limits = [Limit::perMinute(300)->by('identity:ip:'.$request->ip())->response($tooManyRequests)];
            $document = RateLimitIdentity::document($request);

            if ($document !== null) {
                $key = RateLimitIdentity::documentKey('identity', $document);
                $limits[] = Limit::perMinute(10)->by($key.':m')->response($tooManyRequests);
                $limits[] = Limit::perHour(40)->by($key.':h')->response($tooManyRequests);
            }

            return $limits;
        });

        // Configurar rate limiters personalizados para seguridad con fingerprinting mejorado
        RateLimiter::for('critical-endpoints', function (Request $request) {
            // Fingerprinting: IP + User-Agent + documento (si está presente)
            // Esto previene ataques distribuidos usando múltiples IPs
            $fingerprint = md5($request->ip().(RateLimitIdentity::document($request) ?? ''));

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
            $fingerprint = md5($request->ip());

            return Limit::perMinute(120)
                ->by($fingerprint)
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiadas solicitudes. Intenta nuevamente más tarde.',
                        'error' => 'rate_limit_exceeded',
                    ], 429, $headers);
                });
        });

        RateLimiter::for('voting', function (Request $request) {
            return Limit::perMinute(300)
                ->by('voting:'.md5($request->ip()))
                ->response(function (Request $request, array $headers) {
                    \App\Services\SecurityAlertService::logRateLimitExceeded(
                        'voting',
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

        RateLimiter::for('public-endpoints', function (Request $request) {
            // Fingerprinting: IP + User-Agent para endpoints públicos
            $fingerprint = md5($request->ip());

            return Limit::perMinute(60)
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
