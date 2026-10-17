<?php

namespace App\Services;

use App\Mail\UserPasswordSetupInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class UserInvitationService
{
    private const TOKEN_PURPOSE = 'password_setup';

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
     * El token contiene: propósito|user_id|expires_at_timestamp|huella de la contraseña y se cifra con la clave de la aplicación.
     * La huella cambia cuando se define una nueva contraseña, por lo que el token es de un solo uso.
     */
    public function generateToken(User $user, ?int $ttlMinutes = null): string
    {
        $ttlMinutes = $ttlMinutes ?? config('auth.password_setup_ttl_minutes', 60 * 24); // 24 horas por defecto

        $payload = implode('|', [
            self::TOKEN_PURPOSE,
            $user->id,
            now()->addMinutes($ttlMinutes)->getTimestamp(),
            $this->passwordFingerprint($user),
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

        [$purpose, $userId, $expiresAtTimestamp, $fingerprint] = explode('|', $decoded) + [null, null, null, null];

        if ($purpose !== self::TOKEN_PURPOSE || ! $userId || ! $expiresAtTimestamp || ! $fingerprint) {
            throw new \RuntimeException('Token inválido.');
        }

        $expiresAt = now()->createFromTimestamp((int) $expiresAtTimestamp);

        if (now()->greaterThan($expiresAt)) {
            throw new \RuntimeException('El enlace ha expirado.');
        }

        /** @var User|null $user */
        $user = User::find($userId);

        if (! $user) {
            throw new \RuntimeException('Usuario no encontrado.');
        }

        if (! hash_equals($this->passwordFingerprint($user), $fingerprint)) {
            throw new \RuntimeException('Este enlace ya fue utilizado o ya no es válido. Solicita uno nuevo.');
        }

        return [
            'user' => $user,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Huella derivada del hash de contraseña actual: cambia en cuanto se define una nueva contraseña.
     */
    private function passwordFingerprint(User $user): string
    {
        return substr(hash_hmac('sha256', (string) $user->password, (string) config('app.key')), 0, 32);
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

        return rtrim($baseUrl, '/').'/'.$path.'?token='.urlencode($token);
    }
}
