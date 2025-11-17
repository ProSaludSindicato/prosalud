<?php

namespace App\Services;

use App\Mail\UserPasswordReset;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PasswordResetService
{
    /**
     * Envía un correo de restablecimiento de contraseña al usuario.
     */
    public function sendResetLink(User $user): void
    {
        try {
            $token = $this->generateToken($user);

            Mail::to($user->email)->send(new UserPasswordReset(
                user: $user,
                token: $token,
                frontendUrl: $this->buildFrontendUrl($token)
            ));

            Log::info('Correo de restablecimiento de contraseña enviado', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error al enviar el correo de restablecimiento de contraseña', [
                'user_id' => $user->id ?? null,
                'email' => $user->email ?? null,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Genera un token firmado y con expiración para restablecer la contraseña.
     *
     * El token contiene: user_id|expires_at_timestamp y se cifra con la clave de la aplicación.
     */
    public function generateToken(User $user, ?int $ttlMinutes = null): string
    {
        $ttlMinutes = $ttlMinutes ?? config('auth.password_reset_ttl_minutes', 60); // 60 minutos por defecto

        $payload = implode('|', [
            $user->id,
            now()->addMinutes($ttlMinutes)->getTimestamp(),
        ]);

        return Crypt::encryptString($payload);
    }

    /**
     * Decodifica y valida el token de restablecimiento de contraseña.
     *
     * @return array{user: User, expires_at: \Carbon\CarbonInterface}
     *
     * @throws \RuntimeException
     */
    public function validateToken(string $token): array
    {
        try {
            $decoded = Crypt::decryptString($token);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Token inválido o manipulado.');
        }

        [$userId, $expiresAtTimestamp] = explode('|', $decoded) + [null, null];

        if (!$userId || !$expiresAtTimestamp) {
            throw new \RuntimeException('Token inválido.');
        }

        $expiresAt = now()->createFromTimestamp((int) $expiresAtTimestamp);

        if (now()->greaterThan($expiresAt)) {
            throw new \RuntimeException('El enlace ha expirado. Por favor, solicita un nuevo enlace de restablecimiento.');
        }

        /** @var User|null $user */
        $user = User::find($userId);

        if (!$user) {
            throw new \RuntimeException('Usuario no encontrado.');
        }

        if (!$user->is_active) {
            throw new \RuntimeException('El usuario no está activo.');
        }

        return [
            'user' => $user,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Construye el URL hacia el frontend donde el usuario restablecerá su contraseña.
     */
    private function buildFrontendUrl(string $token): string
    {
        // Usar siempre la URL configurada para el frontend (FRONTEND_URL),
        // normalizando los / para evitar dobles o faltantes.
        $baseUrl = config('services.frontend.url');
        $path = trim(config('services.frontend.password_reset_path'), '/');

        return rtrim($baseUrl, '/') . '/' . $path . '?token=' . urlencode($token);
    }
}

