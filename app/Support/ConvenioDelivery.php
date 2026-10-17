<?php

namespace App\Support;

use Illuminate\Http\Request;

class ConvenioDelivery
{
    public static function mode(): string
    {
        return (string) config('convenios.delivery_mode', 'production');
    }

    public static function isProductionMode(): bool
    {
        return self::mode() === 'production';
    }

    /**
     * Modo TEST habilitado: el panel debe pedir al usuario si cada convenio creado/enviado es de
     * prueba o real. Si la solicitud no lo indica, se asume TEST (comportamiento histórico y seguro).
     */
    public static function isTestMode(): bool
    {
        return ! self::isProductionMode();
    }

    /**
     * Resuelve si una operación nueva (generar, importar) es TEST.
     *
     * - Si el cliente lo indica explícitamente, se respeta (TEST nunca llega al afiliado).
     * - Si no lo indica, se usa el modo global (TEST cuando CONVENIO_DELIVERY_MODE ≠ production).
     */
    public static function resolveIsTest(?bool $requested): bool
    {
        return $requested ?? self::isTestMode();
    }

    /**
     * Lee el parámetro `is_test` de la solicitud. Devuelve null si no se envió, para que
     * {@see resolveIsTest()} aplique el valor por defecto.
     */
    public static function requestedIsTest(Request $request): ?bool
    {
        $value = $request->input('is_test');

        if ($value === null || $value === '') {
            return null;
        }

        return $request->boolean('is_test');
    }

    /**
     * Normaliza `is_test` enviado como texto (multipart/form-data) a booleano para validar.
     */
    public static function normalizeBooleanInput(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match (mb_strtolower(trim($value), 'UTF-8')) {
            'si', 'sí', 'yes', '1', 'true' => true,
            'no', '0', 'false' => false,
            default => $value,
        };
    }
}
