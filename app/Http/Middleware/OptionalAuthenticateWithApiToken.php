<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Intenta autenticar por cookie de token o por Authorization: Bearer (misma lógica que auth.token) pero sin exigirla.
 * Si hay token válido, se asigna el usuario a la request; si no, se continúa sin usuario.
 * Útil para rutas que pueden usarse tanto por el panel (con sesión) como por el público (sin sesión).
 */
class OptionalAuthenticateWithApiToken
{
    public function handle(Request $request, \Closure $next)
    {
        $token = $this->getTokenFromRequest($request);

        if (!$token) {
            return $next($request);
        }

        $hashedToken = hash('sha256', $token);
        $apiToken = ApiToken::with('user.roles', 'user.permissions')
            ->where('token', $hashedToken)
            ->first();

        if (!$apiToken || !$apiToken->user || $apiToken->isExpired() || $apiToken->user->is_active === false) {
            return $next($request);
        }

        $apiToken->forceFill(['last_used_at' => now()])->save();
        $user = $apiToken->user;

        $cacheKey = "user:{$user->id}:permissions";
        $userPermissions = Cache::get($cacheKey);
        if ($userPermissions === null) {
            $userPermissions = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($user) {
                $user->loadMissing(['roles', 'permissions', 'roles.permissions']);
                return $user->getAllPermissions()->pluck('name')->toArray();
            });
        }

        $user->loadMissing(['roles', 'permissions']);

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    /**
     * Obtiene el token desde la cookie (prosalud_auth_token) o desde Authorization: Bearer.
     */
    private function getTokenFromRequest(Request $request): ?string
    {
        $token = $request->cookie('prosalud_auth_token');
        if ($token !== null && $token !== '') {
            return $token;
        }

        $auth = $request->header('Authorization');
        if ($auth && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
