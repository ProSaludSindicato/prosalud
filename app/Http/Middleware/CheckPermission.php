<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        // Por ahora, mientras no hay autenticación, permitir acceso
        // En el futuro, cuando se implemente auth, descomentar las siguientes líneas:

        // if (!auth()->check()) {
        //     return response()->json(['message' => 'Unauthenticated'], 401);
        // }

        // if (!auth()->user()->can($permission)) {
        //     return response()->json(['message' => 'Forbidden. Insufficient permissions.'], 403);
        // }

        return $next($request);
    }
}
