<?php

namespace App\Services;

use App\Mail\UserPasswordSetupInvitation;
use App\Models\User;
use Illuminate\Support\Facades\{Crypt, Log, Mail};

class UserInvitationService
{
    /**
     * Envía un correo de invitación al usuario para que configure su contraseña.
     */
    public function sendInvitation(User $user): void
    {
        try {
            $token = $this->generateToken($user);

            Mail::to($user->email)->send(new UserPasswordSetupInvitation(
                user: $user,
                token: $token,
                frontendUrl: $this->buildFrontendUrl($token)
            ));

            Log::info('Invitación de configuración de contraseña enviada', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error al enviar la invitación de configuración de contraseña', [
                'user_id' => $user->id ?? null,
                'email' => $user->email ?? null,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Genera un token firmado y con expiración para que el usuario configure su contraseña.
     *
     * El token contiene: user_id|expires_at_timestamp y se cifra con la clave de la aplicación.
     */
    public function generateToken(User $user, ?int $ttlMinutes = null): string
    {
        $ttlMinutes = $ttlMinutes ?? config('auth.password_setup_ttl_minutes', 60 * 24); // 24 horas por defecto

        $payload = implode('|', [
            $user->id,
            now()->addMinutes($ttlMinutes)->getTimestamp(),
        ]);

        return Crypt::encryptString($payload);
    }

    /**
     * Decodifica y valida el token de invitación.
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
            throw new \RuntimeException('El enlace ha expirado.');
        }

        /** @var User|null $user */
        $user = User::find($userId);

        if (!$user) {
            throw new \RuntimeException('Usuario no encontrado.');
        }

        return [
            'user' => $user,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Construye el URL hacia el frontend donde el usuario definirá su contraseña.
     */
    private function buildFrontendUrl(string $token): string
    {
        // Usar siempre la URL configurada para el frontend (FRONTEND_URL),
        // normalizando los / para evitar dobles o faltantes.
        $baseUrl = config('services.frontend.url');
        $path = trim(config('services.frontend.password_setup_path'), '/');

        return rtrim($baseUrl, '/') . '/' . $path . '?token=' . urlencode($token);
    }
}
