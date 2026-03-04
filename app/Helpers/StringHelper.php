<?php

namespace App\Helpers;

class StringHelper
{
    /**
     * Normaliza un texto removiendo tildes y caracteres especiales (p. ej. para nombres y apellidos).
     * Convierte vocales acentuadas y ñ a su equivalente ASCII; elimina otros caracteres no alfabéticos
     * excepto espacios.
     *
     * @param string|null $value Texto a normalizar
     * @return string|null Mismo valor si es null; texto normalizado sin tildes ni caracteres especiales
     */
    public static function normalizeForApi(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'n', 'Ñ' => 'N',
            'ü' => 'u', 'Ü' => 'U',
        ];

        $normalized = strtr($value, $map);
        // Quitar cualquier otro carácter que no sea letra ASCII o espacio
        $normalized = preg_replace('/[^A-Za-z\s]/u', '', $normalized);

        return $normalized === '' ? null : trim(preg_replace('/\s+/', ' ', $normalized));
    }
}
