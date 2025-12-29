<?php

namespace App\Http\Controllers;

use App\Http\Resources\Auth\UserAuthResource;
use App\Models\{ApiToken, User};
use App\Rules\SecurePassword;
use App\Services\{PasswordResetService, UserInvitationService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Auth, Log};
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
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        /** @var User $user */
        $user = Auth::user();

        $plainToken = Str::random(60);
        $hashedToken = hash('sha256', $plainToken);

        $token = $user->apiTokens()->create([
            'name' => $credentials['device_name'] ?? $request->header('User-Agent'),
            'token' => $hashedToken,
            'abilities' => null,
            'expires_at' => now()->addHours(config('auth.api_token_ttl_hours', 12)),
        ]);

        // Calcular la expiración de la cookie (mismo tiempo que el token)
        // El método cookie() de Laravel espera minutos como entero positivo
        // Asegurar que siempre sea un valor positivo para evitar Max-Age=0
        if ($token->expires_at && $token->expires_at->isFuture()) {
            $cookieExpiration = (int) $token->expires_at->diffInMinutes(now());
            // Si el cálculo resulta en 0 o negativo, usar el valor por defecto
            if ($cookieExpiration <= 0) {
                $cookieExpiration = config('auth.api_token_ttl_hours', 12) * 60;
            }
        } else {
            $cookieExpiration = config('auth.api_token_ttl_hours', 12) * 60;
        }
        
        // Validación final: asegurar que siempre sea al menos 1 minuto (evitar Max-Age=0)
        $cookieExpiration = max(1, (int) $cookieExpiration);
        
        // Logging para diagnóstico
        Log::debug('[AUTH] Cookie expiration calculada', [
            'token_expires_at' => $token->expires_at?->toIso8601String(),
            'now' => now()->toIso8601String(),
            'cookie_expiration_minutes' => $cookieExpiration,
            'cookie_expiration_hours' => round($cookieExpiration / 60, 2),
        ]);

        // Crear respuesta JSON (sin token por seguridad - solo en cookie HttpOnly)
        $response = response()->json([
            'expires_at' => optional($token->expires_at)?->toIso8601String(),
            'user' => new UserAuthResource($user->loadMissing('roles', 'permissions')),
        ]);

        // Agregar token en cookie HttpOnly, Secure y SameSite=Lax
        // SameSite=Lax permite cookies en peticiones cross-site GET pero protege contra CSRF en POST
        // Si frontend y backend están en dominios diferentes, usar 'None' con Secure=true
        $sameSite = env('COOKIE_SAME_SITE', 'Lax'); // Lax, Strict, o None
        $cookieDomain = env('COOKIE_DOMAIN');
        $cookieDomain = $cookieDomain ?: null; // Convertir string vacío a null
        
        $response->cookie(
            'prosalud_auth_token',
            $plainToken,
            $cookieExpiration, // minutos
            '/', // path
            $cookieDomain, // domain (null = dominio actual, o especificar dominio compartido)
            true, // secure (solo HTTPS - requerido si SameSite=None)
            true, // httpOnly (no accesible desde JavaScript)
            false, // raw
            $sameSite // sameSite: Lax (permite cross-site GET), Strict (solo same-site), None (cross-site con Secure)
        );

        return $response;
    }

    /**
     * Logout current token.
     */
    public function logout(Request $request): JsonResponse
    {
        // Obtener el token de la cookie HttpOnly
        $token = $request->cookie('prosalud_auth_token');

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

        // Crear respuesta y eliminar la cookie
        $response = response()->json([
            'message' => 'Sesión cerrada correctamente',
        ]);

        // Eliminar la cookie estableciendo una expiración en el pasado
        $sameSite = env('COOKIE_SAME_SITE', 'Lax');
        $cookieDomain = env('COOKIE_DOMAIN');
        $cookieDomain = $cookieDomain ?: null; // Convertir string vacío a null
        
        $response->cookie(
            'prosalud_auth_token',
            '',
            -1, // expiración en el pasado
            '/',
            $cookieDomain,
            true, // secure
            true, // httpOnly
            false,
            $sameSite
        );

        return $response;
    }

    /**
     * Get authenticated user details.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        // Asegurar que los roles y permisos estén cargados
        $user->loadMissing('roles', 'permissions', 'roles.permissions');

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

        /** @var User $user */
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

        // Preparar la respuesta antes de enviar el correo
        $response = response()->json([
            'message' => 'Si el correo existe en nuestro sistema, recibirás un enlace para restablecer tu contraseña.',
        ]);

        // Enviar email de restablecimiento de forma asíncrona después de enviar la respuesta HTTP
        // Esto evita que el envío de correo bloquee la respuesta al frontend
        $userForEmail = $user;
        dispatch(function () use ($userForEmail) {
            try {
                $this->passwordResetService->sendResetLink($userForEmail);
            } catch (\Throwable $e) {
                Log::error('Error enviando email de restablecimiento de contraseña', [
                    'user_id' => $userForEmail->id,
                    'email' => $userForEmail->email,
                    'error' => $e->getMessage(),
                ]);
                // No exponemos el error al cliente por seguridad
            }
        })->afterResponse();

        return $response;
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

        /** @var User $user */
        $user = $result['user'];

        // Asignamos la nueva contraseña; el cast "hashed" del modelo se encarga de encriptarla.
        $user->password = $data['password'];
        $user->save();

        return response()->json([
            'message' => 'Tu contraseña ha sido restablecida exitosamente. Ya puedes iniciar sesión.',
        ]);
    }
}
