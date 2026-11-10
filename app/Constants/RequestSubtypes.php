<?php

namespace App\Constants;

use Illuminate\Support\Str;

class RequestSubtypes
{
    public const COMPENSACION_FINAL_LIQUIDACION = 'COMPENSACIÓN. FINAL (LIQUIDACIÓN)';

    public const COMPENSACION_ANUAL_DIFERIDA_DESCANSO = 'COMPENSACIÓN ANUAL DIFERIDA Y/O DESCANSO';

    public const COMPENSACION_POR_DESCANSO = 'COMPENSACIÓN POR DESCANSO';

    public const DESCUENTOS_SEGURIDAD_SOCIAL = 'DESCUENTOS SEGURIDAD SOCIAL';

    public const DUPLICADO_COLILLAS = 'DUPLICADO COLILLAS';

    public const VIATICOS = 'VIATICOS';

    public const CEIISAS = 'Ceiisas';

    public const COMPENSACION_MENSUAL = 'COMPENSACIÓN. MENSUAL';

    public const COMPENSACION_SEMESTRAL = 'COMPENSACIÓN SEMESTRAL';

    public const INCAPACIDADES = 'INCAPACIDADES';

    public const SUBSIDIOS = 'SUBSIDIOS';

    /**
     * Get all valid subtypes for verificacion-pagos.
     */
    public static function all(): array
    {
        return [
            self::COMPENSACION_FINAL_LIQUIDACION,
            self::COMPENSACION_ANUAL_DIFERIDA_DESCANSO,
            self::COMPENSACION_POR_DESCANSO,
            self::DESCUENTOS_SEGURIDAD_SOCIAL,
            self::DUPLICADO_COLILLAS,
            self::VIATICOS,
            self::CEIISAS,
            self::COMPENSACION_MENSUAL,
            self::COMPENSACION_SEMESTRAL,
            self::INCAPACIDADES,
            self::SUBSIDIOS,
        ];
    }

    /**
     * Get subtypes for a specific request type.
     */
    public static function forRequestType(string $requestType): array
    {
        if ($requestType === RequestTypes::VERIFICACION_PAGOS) {
            return self::all();
        }

        return [];
    }

    /**
     * Check if a subtype is valid for a request type.
     */
    public static function isValid(string $requestType, string $subtype): bool
    {
        if (! RequestTypes::hasSubtypes($requestType)) {
            return false;
        }

        return in_array($subtype, self::forRequestType($requestType));
    }

    /**
     * Resolve the canonical subtype for a stored value, ignoring accents, case and punctuation.
     * Also maps legacy values that were stored with the form label instead of the canonical value.
     */
    public static function normalize(?string $subtype): ?string
    {
        if ($subtype === null || trim($subtype) === '') {
            return null;
        }

        $subtypesByKey = [
            'COMPENSACION FINAL' => self::COMPENSACION_FINAL_LIQUIDACION,
            'LIQUIDACION' => self::COMPENSACION_FINAL_LIQUIDACION,
            'COMPENSACION ANUAL DIFERIDA' => self::COMPENSACION_ANUAL_DIFERIDA_DESCANSO,
            'DUPLICADO DE COLILLAS' => self::DUPLICADO_COLILLAS,
        ];

        foreach (self::all() as $canonicalSubtype) {
            $subtypesByKey[self::comparisonKey($canonicalSubtype)] = $canonicalSubtype;
        }

        return $subtypesByKey[self::comparisonKey($subtype)] ?? null;
    }

    private static function comparisonKey(string $subtype): string
    {
        return Str::of(Str::ascii($subtype))
            ->upper()
            ->replaceMatches('/[^A-Z0-9\/]+/', ' ')
            ->squish()
            ->toString();
    }
}
