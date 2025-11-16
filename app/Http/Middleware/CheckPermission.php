<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
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

        // Forzar recarga de roles y permisos si no están cargados
        if (!$user->relationLoaded('roles')) {
            $user->load('roles');
        }
        if (!$user->relationLoaded('permissions')) {
            $user->load('permissions');
        }

        // Cargar permisos de los roles también
        $user->loadMissing('roles.permissions');

        // Verificar permiso usando el método can() de Spatie Permission
        // El método can() verifica tanto permisos directos como permisos a través de roles
        $hasPermission = $user->can($permission);

        if (!$hasPermission) {
            $userRoles = $user->getRoleNames()->toArray();
            $userPermissions = $user->getAllPermissions()->pluck('name')->toArray();

            Log::warning('[PERMISSION CHECK] Acceso denegado por permisos insuficientes', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'required_permission' => $permission,
                'user_roles' => $userRoles,
                'user_permissions' => $userPermissions,
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

        Log::debug('[PERMISSION CHECK] Permiso verificado exitosamente', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'permission' => $permission,
            'path' => $request->path(),
        ]);

        return $next($request);
    }
}
