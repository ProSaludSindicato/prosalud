<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTokenIsValid
{
    /**
     * Handle an incoming request.
     *
     * @param \Closure(Request): (Response) $next
     */
    public function handle(Request $request, \Closure $next): Response
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Token de acceso inválido o expirado',
                'error' => 'Unauthenticated',
            ], 401);
        }

        // Verificar si el usuario está activo
        if (!$request->user()->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Tu cuenta está desactivada. Contacta al administrador.',
                'error' => 'Account deactivated',
            ], 403);
        }

        return $next($request);
    }
}
