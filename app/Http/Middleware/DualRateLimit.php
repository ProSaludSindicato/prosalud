<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de rate limiting híbrido con doble capa:
 * - Capa 1: Límite por identidad (documento) - 5/min
 * - Capa 2: Límite global por IP - 30-50/min
 * 
 * Implementa defensa en profundidad según OWASP y NIST.
 */
class DualRateLimit
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $type  Tipo de rate limiting: 'otp' o 'critical'
     */
    public function handle(Request $request, Closure $next, string $type = 'otp'): Response
    {
        // Determinar límites según el tipo
        $docLimiter = $type === 'otp' ? 'otp-requests-doc' : 'critical-endpoints-doc';
        $ipLimiter = $type === 'otp' ? 'otp-requests-ip' : 'critical-endpoints-ip';
        
        // CAPA 1: Verificar límite por documento (si está presente)
        $documento = $request->input('documento');
        
        if ($documento) {
            $docKey = $type === 'otp' 
                ? 'otp:doc:' . md5($documento)
                : 'cert:doc:' . md5($documento);
            
            // Intentar incrementar contador por documento (5 intentos por minuto)
            $executed = RateLimiter::attempt(
                $docKey,
                5, // máximo 5 intentos
                function () {
                    // Callback ejecutado si el límite no se excede
                    // No necesitamos hacer nada aquí, solo continuar
                },
                60 // decay en segundos (1 minuto)
            );
            
            if (!$executed) {
                // El límite fue excedido
                \App\Services\SecurityAlertService::logRateLimitExceeded(
                    $docLimiter,
                    $request->path(),
                    $request->ip(),
                    $request->userAgent(),
                    $documento
                );
                
                $seconds = RateLimiter::availableIn($docKey);
                
                return response()->json([
                    'success' => false,
                    'message' => $type === 'otp' 
                        ? 'Demasiadas solicitudes de OTP. Por favor, espera un momento.'
                        : 'Demasiadas solicitudes. Intenta nuevamente más tarde.',
                    'error' => 'rate_limit_exceeded',
                ], 429, [
                    'X-RateLimit-Limit' => '5',
                    'X-RateLimit-Remaining' => '0',
                ]);
            }
        }
        
        // CAPA 2: Verificar límite global por IP
        $ipKey = $type === 'otp'
            ? 'otp:ip:' . md5($request->ip() . $request->userAgent())
            : 'cert:ip:' . md5($request->ip() . $request->userAgent());
        
        $ipLimit = $type === 'otp' ? 30 : 50; // 30/min para OTP, 50/min para critical
        
        // Intentar incrementar contador por IP
        $executed = RateLimiter::attempt(
            $ipKey,
            $ipLimit, // máximo de intentos
            function () {
                // Callback ejecutado si el límite no se excede
                // No necesitamos hacer nada aquí, solo continuar
            },
            60 // decay en segundos (1 minuto)
        );
        
        if (!$executed) {
            // El límite fue excedido
            \App\Services\SecurityAlertService::logRateLimitExceeded(
                $ipLimiter,
                $request->path(),
                $request->ip(),
                $request->userAgent(),
                $documento
            );
            
            $seconds = RateLimiter::availableIn($ipKey);
            
            return response()->json([
                'success' => false,
                'message' => 'Demasiadas solicitudes desde esta dirección. Intenta nuevamente más tarde.',
                'error' => 'rate_limit_exceeded',
            ], 429, [
                'X-RateLimit-Limit' => (string) $ipLimit,
                'X-RateLimit-Remaining' => '0',
            ]);
        }
        
        // Si ambas capas pasan, continuar con la solicitud
        return $next($request);
    }
}

