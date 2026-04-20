<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAutoSignEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('convenio_auto_sign.enabled')) {
            return response()->json([
                'success' => false,
                'message' => 'La firma automática del presidente no está habilitada.',
                'error_code' => 'AUTO_SIGN_DISABLED',
            ], 404);
        }

        return $next($request);
    }
}
