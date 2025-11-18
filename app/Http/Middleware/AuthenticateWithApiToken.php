<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Auth, Log};

class AuthenticateWithApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param \Closure(Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, \Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return $this->unauthorizedResponse('Token no proporcionado', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'headers' => $request->headers->all(),
            ]);
        }

        $hashedToken = hash('sha256', $token);

        $apiToken = ApiToken::with('user.roles', 'user.permissions')
            ->where('token', $hashedToken)
            ->first();

        if (!$apiToken) {
            return $this->unauthorizedResponse('Token no encontrado', [
                'hashed_token' => $hashedToken,
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);
        }

        if (!$apiToken->user) {
            return $this->unauthorizedResponse('Token sin usuario asociado', [
                'token_id' => $apiToken->id,
                'hashed_token' => $hashedToken,
            ]);
        }

        if ($apiToken->isExpired()) {
            $apiToken->delete();

            return $this->unauthorizedResponse('Token expirado', [
                'token_id' => $apiToken->id,
                'user_id' => $apiToken->user_id,
            ]);
        }

        if (false === $apiToken->user->is_active) {
            $apiToken->delete();

            return $this->unauthorizedResponse('Usuario inactivo', [
                'token_id' => $apiToken->id,
                'user_id' => $apiToken->user_id,
            ]);
        }

        $apiToken->forceFill(['last_used_at' => now()])->save();

        $user = $apiToken->user;

        // Asegurar que los roles y permisos estén cargados
        if (!$user->relationLoaded('roles')) {
            $user->load('roles');
        }
        if (!$user->relationLoaded('permissions')) {
            $user->load('permissions');
        }
        // Cargar permisos de los roles también
        $user->loadMissing('roles.permissions');

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        Log::debug('[API TOKEN AUTH] Usuario autenticado', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'roles_count' => $user->roles->count(),
            'permissions_count' => $user->getAllPermissions()->count(),
            'path' => $request->path(),
        ]);

        return $next($request);
    }

    private function unauthorizedResponse(string $message, array $context = []): JsonResponse
    {
        Log::warning('[API TOKEN AUTH] acceso no autorizado', array_merge($context, [
            'message' => $message,
        ]));

        return response()->json([
            'message' => 'No autorizado',
            'reason' => $message,
        ], 401);
    }
}
