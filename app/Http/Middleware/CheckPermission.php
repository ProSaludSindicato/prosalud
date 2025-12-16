<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, Log};
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param \Closure(Request): (Response) $next
     */
    public function handle(Request $request, \Closure $next, string $permission): Response
    {
        if (!auth()->check()) {
            Log::warning('[PERMISSION CHECK] Usuario no autenticado', [
                'permission' => $permission,
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'No autenticado'], 401);
        }

        $user = auth()->user();

        // Cachear permisos del usuario para evitar múltiples consultas a la BD
        $cacheKey = "user:{$user->id}:permissions";
        
        // Verificar si existe en caché
        $cachedPermissions = Cache::get($cacheKey);
        if ($cachedPermissions !== null) {
            $userPermissions = $cachedPermissions;
        } else {
            Log::debug('[CACHE MISS] Consultando permisos desde BD', [
                'cache_key' => $cacheKey,
                'user_id' => $user->id,
            ]);
            
            $userPermissions = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($user) {
                // Forzar recarga de roles y permisos si no están cargados
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
            
            Log::debug('[CACHE STORED] Permisos guardados en caché', [
                'cache_key' => $cacheKey,
                'user_id' => $user->id,
                'permissions_count' => count($userPermissions),
            ]);
        }

        // Verificar si el usuario tiene el permiso requerido
        $hasPermission = in_array($permission, $userPermissions);

        if (!$hasPermission) {
            $userRoles = $user->getRoleNames()->toArray();

            Log::warning('[PERMISSION CHECK] Acceso denegado por permisos insuficientes', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'required_permission' => $permission,
                'user_roles' => $userRoles,
                'user_permissions' => $userPermissions ?? [],
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'message' => 'Acceso denegado. Permisos insuficientes.',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
