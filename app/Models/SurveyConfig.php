<?php

namespace App\Models;

/**
 * Helper class for survey configuration.
 * Uses the generic Configuration model internally.
 */
class SurveyConfig
{
    private const KEY_ALLOW_BULK_ENTRY_MODE = 'survey.allow_bulk_entry_mode';

    /**
     * Get the current survey configuration.
     */
    public static function getCurrent(): array
    {
        return [
            'allow_bulk_entry_mode' => static::isBulkEntryModeEnabled(),
        ];
    }

    /**
     * Check if bulk entry mode is enabled.
     */
    public static function isBulkEntryModeEnabled(): bool
    {
        return Configuration::get(self::KEY_ALLOW_BULK_ENTRY_MODE, false);
    }

    /**
     * Set bulk entry mode.
     */
    public static function setBulkEntryMode(bool $enabled): void
    {
        Configuration::set(
            self::KEY_ALLOW_BULK_ENTRY_MODE,
            $enabled,
            'boolean',
            'Permite modo ingreso masivo sin autenticación para encuestas sociodemográficas. Cuando está activo, no se requiere autenticación previa y todos los campos deben ser diligenciados manualmente.'
        );
    }
}
