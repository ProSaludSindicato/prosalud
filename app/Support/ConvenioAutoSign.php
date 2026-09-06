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
}
