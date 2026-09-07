<?php

namespace App\Support;

use App\Services\AutoSignApiService;

class ConvenioAutoSign
{
    public static function enabled(): bool
    {
        if (! config('convenio_signing.enabled', true)) {
            return false;
        }

        if (! config('convenio_signing.auto_sign.enabled', false)) {
            return false;
        }

        return app(AutoSignApiService::class)->isConfigured();
    }

    public static function bulkEnabled(): bool
    {
        return self::enabled()
            && (bool) config('convenio_signing.president_sign_bulk_enabled', false);
    }

    public static function requireReview(): bool
    {
        return (bool) config('convenio_signing.president_sign_require_review', true);
    }

    public static function bulkMax(): int
    {
        return max(1, (int) config('convenio_signing.president_sign_bulk_max', 1000));
    }
}
