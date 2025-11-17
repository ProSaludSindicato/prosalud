<?php

namespace App\Http\Controllers;

use App\Http\Resources\Auth\UserAuthResource;
use App\Models\ApiToken;
use App\Models\User;
use App\Rules\SecurePassword;
use App\Services\PasswordResetService;
use App\Services\UserInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private UserInvitationService $userInvitationService,
        private PasswordResetService $passwordResetService,
    ) {
    }

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

    /**
     * Define la contraseña de un usuario a partir de un token de invitación y activa su cuenta.
     */
    public function setPasswordFromInvitation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'max:128', 'confirmed', new SecurePassword()],
        ]);

        try {
            $result = $this->userInvitationService->validateToken($data['token']);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        /** @var \App\Models\User $user */
        $user = $result['user'];

        // Asignamos la nueva contraseña; el cast "hashed" del modelo se encarga de encriptarla.
        $user->password = $data['password'];
        $user->is_active = true;
        $user->save();

        return response()->json([
            'message' => 'Contraseña definida correctamente. Ya puedes iniciar sesión.',
        ]);
    }

    /**
     * Send password reset link to user's email.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Por seguridad, siempre devolvemos el mismo mensaje aunque el usuario no exista
        if (!$user) {
            return response()->json([
                'message' => 'Si el correo existe en nuestro sistema, recibirás un enlace para restablecer tu contraseña.',
            ]);
        }

        // Verificar que el usuario esté activo
        if (!$user->is_active) {
            return response()->json([
                'message' => 'Si el correo existe en nuestro sistema, recibirás un enlace para restablecer tu contraseña.',
            ]);
        }

        // Enviar email de restablecimiento usando el servicio
        try {
            $this->passwordResetService->sendResetLink($user);
        } catch (\Throwable $e) {
            Log::error('Error enviando email de restablecimiento de contraseña', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
            // No exponemos el error al cliente por seguridad
        }

        // Siempre devolvemos el mismo mensaje por seguridad
        return response()->json([
            'message' => 'Si el correo existe en nuestro sistema, recibirás un enlace para restablecer tu contraseña.',
        ]);
    }

    /**
     * Reset user password using token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'max:128', 'confirmed', new SecurePassword()],
        ]);

        try {
            $result = $this->passwordResetService->validateToken($data['token']);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        /** @var \App\Models\User $user */
        $user = $result['user'];

        // Asignamos la nueva contraseña; el cast "hashed" del modelo se encarga de encriptarla.
        $user->password = $data['password'];
        $user->save();

        return response()->json([
            'message' => 'Tu contraseña ha sido restablecida exitosamente. Ya puedes iniciar sesión.',
        ]);
    }
}


