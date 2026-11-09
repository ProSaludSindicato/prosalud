<?php

namespace App\Services;

/**
 * Normalizes delegate document numbers read from the assembly delegates spreadsheet.
 */
final class AssemblyDelegateDocument
{
    public static function normalize(string $document): string
    {
        $normalized = str_replace(["\xC2\xA0", ' '], '', trim($document));

        if ($normalized !== '' && is_numeric($normalized)) {
            return (string) (int) round((float) $normalized);
        }

        return $normalized;
    }
}
