<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

class ConvenioRateLimiter
{
    private const PROSANET_KEY = 'convenio-prosanet-lookup';

    private const EMAIL_KEY = 'convenio-email-send';

    public static function prosanetPerMinute(): int
    {
        return max(1, (int) config('convenios.prosanet_per_minute', 20));
    }

    public static function emailsPerMinute(): int
    {
        return max(1, (int) config('convenios.emails_per_minute', 30));
    }

    public static function tooManyProsanetAttempts(): bool
    {
        return RateLimiter::tooManyAttempts(self::PROSANET_KEY, self::prosanetPerMinute());
    }

    public static function prosanetAvailableIn(): int
    {
        return RateLimiter::availableIn(self::PROSANET_KEY);
    }

    public static function hitProsanet(): void
    {
        RateLimiter::hit(self::PROSANET_KEY, 60);
    }

    public static function tooManyEmailAttempts(): bool
    {
        return RateLimiter::tooManyAttempts(self::EMAIL_KEY, self::emailsPerMinute());
    }

    public static function emailAvailableIn(): int
    {
        return RateLimiter::availableIn(self::EMAIL_KEY);
    }

    public static function hitEmail(): void
    {
        RateLimiter::hit(self::EMAIL_KEY, 60);
    }
}
