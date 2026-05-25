<?php

namespace App\Support;

final class HospitalCatalog
{
    /**
     * Resolve a ProSanet / cliente hospital code to its display name.
     *
     * Trims the input; keys in config may include intentional spaces — matching is insensitive to leading/trailing spaces on keys and case-insensitive.
     *
     * @return string Display name, trimmed original if unknown, or empty if input is null/blank
     */
    public static function resolve(?string $code): string
    {
        if ($code === null) {
            return '';
        }

        $trimmed = trim($code);
        if ($trimmed === '') {
            return '';
        }

        /** @var array<string, string> $map */
        $map = config('hospital_codes.codes', []);

        if (isset($map[$trimmed])) {
            return $map[$trimmed];
        }

        $upper = mb_strtoupper($trimmed, 'UTF-8');

        foreach ($map as $key => $display) {
            if (mb_strtoupper(trim($key), 'UTF-8') === $upper) {
                return $display;
            }
        }

        return $trimmed;
    }
}
