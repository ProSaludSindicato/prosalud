<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Services\LogSanitizationService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Auth, Cache, Log};

class AuthenticateWithApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param \Closure(Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, \Closure $next)
    {
        // Obtener el token de la cookie HttpOnly
        $token = $request->cookie('prosalud_auth_token');

        // Logging para diagnóstico
        if (!$token) {
            $cookieNames = array_keys($request->cookies->all());
            $hasCookieHeader = $request->headers->has('Cookie');
            $cookieCount = count($request->cookies->all());
            
            Log::warning('[API TOKEN AUTH] Cookie de autenticación no encontrada', LogSanitizationService::sanitize([
                'reason' => 'Cookie prosalud_auth_token ausente en la petición',
                'ip_address' => $request->ip(),
                'ip_forwarded' => $request->header('X-Forwarded-For'),
                'path' => $request->path(),
                'full_url' => $request->fullUrl(),
                'method' => $request->method(),
                'user_agent' => $request->userAgent(),
                'referer' => $request->header('Referer'),
                'origin' => $request->header('Origin'),
                'cookie_count' => $cookieCount,
                'cookie_names_present' => $cookieNames,
                'has_cookie_header' => $hasCookieHeader,
                'accept_header' => $request->header('Accept'),
                'content_type' => $request->header('Content-Type'),
                'timestamp' => now()->toISOString(),
            ]));
            
            return $this->unauthorizedResponse('Cookie de autenticación no encontrada', $request, [
                'cookie_count' => $cookieCount,
                'has_cookie_header' => $hasCookieHeader,
                'cookie_names_present' => $cookieNames,
            ]);
        }

        $hashedToken = hash('sha256', $token);
        $tokenLength = strlen($token);
        $tokenHash = substr($hashedToken, 0, 12);

        $apiToken = ApiToken::with('user.roles', 'user.permissions')
            ->where('token', $hashedToken)
            ->first();

        if (!$apiToken) {
            return $this->unauthorizedResponse('Token inválido o no encontrado en la base de datos', $request, [
                'token_length' => $tokenLength,
                'token_hash_preview' => $tokenHash,
                'token_created_at' => null,
                'token_expires_at' => null,
            ]);
        }

        if (!$apiToken->user) {
            return $this->unauthorizedResponse('Token sin usuario asociado', $request, [
                'token_id' => $apiToken->id,
                'token_hash_preview' => $tokenHash,
                'token_created_at' => $apiToken->created_at?->toISOString(),
                'token_expires_at' => $apiToken->expires_at?->toISOString(),
            ]);
        }

        if ($apiToken->isExpired()) {
            $expiredAt = $apiToken->expires_at?->toISOString();
            $expiredMinutesAgo = $apiToken->expires_at ? abs(now()->diffInMinutes($apiToken->expires_at, false)) : null;
            $apiToken->delete();

            return $this->unauthorizedResponse('Token expirado', $request, [
                'token_id' => $apiToken->id,
                'user_id' => $apiToken->user_id,
                'user_email' => $apiToken->user->email,
                'token_expired_at' => $expiredAt,
                'expired_minutes_ago' => $expiredMinutesAgo,
            ]);
        }

        if (false === $apiToken->user->is_active) {
            $apiToken->delete();

            return $this->unauthorizedResponse('Usuario inactivo', $request, [
                'token_id' => $apiToken->id,
                'user_id' => $apiToken->user_id,
                'user_email' => $apiToken->user->email,
                'user_is_active' => $apiToken->user->is_active,
            ]);
        }

        $apiToken->forceFill(['last_used_at' => now()])->save();

        $user = $apiToken->user;

        // Cachear permisos del usuario para evitar múltiples consultas a la BD
        $cacheKey = "user:{$user->id}:permissions";
        
        // Verificar si existe en caché
        $cachedPermissions = Cache::get($cacheKey);
        if ($cachedPermissions !== null) {
            $userPermissions = $cachedPermissions;
        } else {
            Log::debug('[CACHE MISS] Consultando permisos desde BD (API Token)', [
                'cache_key' => $cacheKey,
                'user_id' => $user->id,
            ]);
            
            $userPermissions = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($user) {
                // Asegurar que los roles y permisos estén cargados
                if (!$user->relationLoaded('roles')) {
                    $user->load('roles');
                }
                if (!$user->relationLoaded('permissions')) {
                    $user->load('permissions');
                }
                // Cargar permisos de los roles también
                $user->loadMissing('roles.permissions');

                // Obtener todos los permisos del usuario (directos y a través de roles)
                return $user->getAllPermissions()->pluck('name')->toArray();
            });
            
            Log::debug('[CACHE STORED] Permisos guardados en caché (API Token)', [
                'cache_key' => $cacheKey,
                'user_id' => $user->id,
                'permissions_count' => count($userPermissions),
            ]);
        }

        // Cargar relaciones básicas para compatibilidad con el resto del código
        if (!$user->relationLoaded('roles')) {
            $user->load('roles');
        }
        if (!$user->relationLoaded('permissions')) {
            $user->load('permissions');
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function unauthorizedResponse(string $message, Request $request, array $additionalContext = []): JsonResponse
    {
        $context = LogSanitizationService::sanitize(array_merge([
            'reason' => $message,
            'ip_address' => $request->ip(),
            'ip_forwarded' => $request->header('X-Forwarded-For'),
            'path' => $request->path(),
            'full_url' => $request->fullUrl(),
            'method' => $request->method(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->header('Referer'),
            'origin' => $request->header('Origin'),
            'accept_header' => $request->header('Accept'),
            'content_type' => $request->header('Content-Type'),
            'timestamp' => now()->toISOString(),
        ], $additionalContext));

        Log::warning('[API TOKEN AUTH] Acceso no autorizado', $context);

        return response()->json([
            'message' => 'No autorizado',
            'reason' => $message,
        ], 401);
    }
}
