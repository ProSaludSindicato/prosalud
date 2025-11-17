<?php

namespace App\Constants;

class RequestTypes
{
    public const CERTIFICADO_CONVENIO = 'certificado-convenio';
    public const COMPENSACION_DESCANSO = 'compensacion-descanso';
    public const COMPENSACION_ANUAL = 'compensacion-anual';
    public const VERIFICACION_PAGOS = 'verificacion-pagos';
    public const ACTUALIZAR_DATOS_PERSONALES = 'actualizar-datos-personales';
    public const INCAPACIDADES_LICENCIAS = 'incapacidades-licencias';
    public const SOLICITUD_MICROCREDITO = 'solicitud-microcredito';
    public const SOLICITUD_RETIRO_SINDICAL = 'solicitud-retiro-sindical';

    /**
     * Get all valid request types
     */
    public static function all(): array
    {
        return [
            self::CERTIFICADO_CONVENIO,
            self::COMPENSACION_DESCANSO,
            self::COMPENSACION_ANUAL,
            self::VERIFICACION_PAGOS,
            self::ACTUALIZAR_DATOS_PERSONALES,
            self::INCAPACIDADES_LICENCIAS,
            self::SOLICITUD_MICROCREDITO,
            self::SOLICITUD_RETIRO_SINDICAL,
        ];
    }

    /**
     * Get request types with subtypes
     */
    public static function withSubtypes(): array
    {
        return [
            self::VERIFICACION_PAGOS,
        ];
    }

    /**
     * Check if a request type has subtypes
     */
    public static function hasSubtypes(string $requestType): bool
    {
        return in_array($requestType, self::withSubtypes());
    }
}

