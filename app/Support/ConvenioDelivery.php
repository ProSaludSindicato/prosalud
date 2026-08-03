<?php

namespace App\Support;

class ConvenioDelivery
{
    public static function mode(): string
    {
        return (string) config('convenios.delivery_mode', 'production');
    }

    public static function isTestMode(): bool
    {
        return self::mode() === 'test';
    }

    public static function isProductionMode(): bool
    {
        return ! self::isTestMode();
    }
}
