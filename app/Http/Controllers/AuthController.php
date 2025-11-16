<?php

namespace App\Http\Controllers;

use App\Http\Resources\Auth\UserAuthResource;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle user login and issue an API token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        if (!Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ])) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $plainToken = Str::random(60);
        $hashedToken = hash('sha256', $plainToken);

        $token = $user->apiTokens()->create([
            'name' => $credentials['device_name'] ?? $request->header('User-Agent'),
            'token' => $hashedToken,
            'abilities' => null,
            'expires_at' => now()->addHours(config('auth.api_token_ttl_hours', 12)),
        ]);

        return response()->json([
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => optional($token->expires_at)?->toIso8601String(),
            'user' => new UserAuthResource($user->loadMissing('roles', 'permissions')),
        ]);
    }

    /**
     * Logout current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'message' => 'Token no proporcionado',
            ], 400);
        }

        $hashedToken = hash('sha256', $token);

        $apiToken = ApiToken::where('token', $hashedToken)->first();

        if ($apiToken) {
            $apiToken->delete();
        }

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
        ]);
    }

    /**
     * Get authenticated user details.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        // Asegurar que los roles y permisos estén cargados
        $user->loadMissing('roles', 'permissions', 'roles.permissions');

        // Log para diagnóstico de permisos
        \Illuminate\Support\Facades\Log::info('[AUTH ME] Información del usuario autenticado', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'is_active' => $user->is_active,
            'roles' => $user->getRoleNames()->toArray(),
            'permissions_count' => $user->getAllPermissions()->count(),
            'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
        ]);

        return response()->json([
            'user' => new UserAuthResource($user),
        ]);
    }
}


