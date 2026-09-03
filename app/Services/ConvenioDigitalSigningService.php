<?php

namespace App\Services;

use App\Models\ConvenioEmailTracking;
use Illuminate\Support\Str;

class ConvenioDigitalSigningService
{
    public static function hashPlainToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public static function generatePlainToken(): string
    {
        return Str::random(64);
    }

    public function findByPlainToken(string $plainToken): ?ConvenioEmailTracking
    {
        if (strlen($plainToken) < 32) {
            return null;
        }

        return ConvenioEmailTracking::query()
            ->where('signing_token_hash', self::hashPlainToken($plainToken))
            ->first();
    }

    public function buildSigningUrl(string $plainToken): string
    {
        $base = rtrim((string) config('services.firma_digital.app_url'), '/');
        $path = trim((string) config('services.firma_digital.sign_path'), '/');

        return "{$base}/{$path}/{$plainToken}";
    }

    public function tokenExpiresAt(): \Illuminate\Support\Carbon
    {
        $days = (int) config('convenio_signing.token_expires_days', 30);

        return now()->addDays(max(1, $days));
    }

    public function affiliateCanSign(ConvenioEmailTracking $tracking): bool
    {
        if ($tracking->signing_token_hash === null || $tracking->signing_token_hash === '') {
            return false;
        }

        if ($tracking->signing_estado !== ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA) {
            return false;
        }

        if ($tracking->token_expires_at === null || $tracking->token_expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function affiliateCanRateSatisfaction(ConvenioEmailTracking $tracking): bool
    {
        return $tracking->canReceiveSigningSatisfactionRating();
    }
}
