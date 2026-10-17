<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Copias temporales del PDF recién generado, una por solicitud (token) y usuario.
 */
class ConvenioPreviewStorage
{
    public const DIRECTORY = 'app/temp/convenios/previews';

    public const MAX_AGE_SECONDS = 86400;

    public static function newToken(): string
    {
        return (string) Str::uuid();
    }

    public static function isValidToken(mixed $token): bool
    {
        return is_string($token) && Str::isUuid($token);
    }

    public static function path(int $userId, string $token): string
    {
        return storage_path(self::DIRECTORY.'/'.$userId.'/'.$token.'.pdf');
    }

    /**
     * Borra vistas previas antiguas del usuario para que no se acumulen.
     */
    public static function purgeExpired(int $userId): void
    {
        $dir = dirname(self::path($userId, self::newToken()));
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - self::MAX_AGE_SECONDS) {
                @unlink($file);
            }
        }
    }
}
