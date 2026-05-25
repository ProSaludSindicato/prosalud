<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Maps delegate spreadsheet headers to column indices by name (case and accent insensitive).
 */
final class AssemblyDelegateColumnResolver
{
    /**
     * @param  array<int, mixed>  $headerRow
     *
     * @throws \InvalidArgumentException
     */
    public static function resolveOrFail(array $headerRow): AssemblyDelegateColumnMap
    {
        $normalizedByIndex = [];
        foreach ($headerRow as $index => $cell) {
            $normalizedByIndex[(int) $index] = self::normalizeHeaderText((string) ($cell ?? ''));
        }

        $usedIndices = [];

        $pickFirst = function (callable $matches) use (&$usedIndices, $normalizedByIndex): ?int {
            ksort($normalizedByIndex);
            foreach ($normalizedByIndex as $idx => $text) {
                if ($text === '' || in_array($idx, $usedIndices, true)) {
                    continue;
                }
                if ($matches($text)) {
                    $usedIndices[] = $idx;

                    return $idx;
                }
            }

            return null;
        };

        $fechaIdx = $pickFirst(fn (string $h): bool => self::headerMatchesFechaExpedicion($h));
        $nombreIdx = $pickFirst(fn (string $h): bool => self::headerMatchesNombreApellidos($h));
        $procesoIdx = $pickFirst(fn (string $h): bool => self::headerMatchesProceso($h));
        $estadoIdx = $pickFirst(fn (string $h): bool => self::headerMatchesEstadoBd($h));
        $sedeIdx = $pickFirst(fn (string $h): bool => self::headerMatchesSede($h));
        $cedulaIdx = $pickFirst(fn (string $h): bool => self::headerMatchesCedula($h));

        $missingCore = [];
        if ($cedulaIdx === null) {
            $missingCore[] = 'CEDULA (o DOCUMENTO / IDENTIFICACIÓN)';
        }
        if ($nombreIdx === null) {
            $missingCore[] = 'NOMBRE Y APELLIDOS';
        }
        if ($estadoIdx === null) {
            $missingCore[] = 'ESTADO BD';
        }
        if ($fechaIdx === null) {
            $missingCore[] = 'FECHA DE EXPEDICIÓN (o F. EXPEDICIÓN)';
        }

        if ($missingCore !== []) {
            throw new \InvalidArgumentException(
                'No se reconocen todas las columnas obligatorias del listado de delegados. Falta o no coincide el encabezado de: '
                .implode('; ', $missingCore)
                .'. Revise la primera fila del Excel. El formato esperado incluye: CEDULA, NOMBRE Y APELLIDOS, SEDE, ESTADO BD, PROCESO, FECHA DE EXPEDICION (o el formato clásico de cuatro columnas en A–D: CEDULA, NOMBRE Y APELLIDOS, ESTADO BD, fecha de expedición).'
            );
        }

        $coreMaxIndex = max($cedulaIdx, $nombreIdx, $estadoIdx, $fechaIdx);

        if ($sedeIdx === null && $procesoIdx === null) {
            if ($coreMaxIndex <= 3) {
                return new AssemblyDelegateColumnMap(
                    $cedulaIdx,
                    $nombreIdx,
                    null,
                    $estadoIdx,
                    null,
                    $fechaIdx,
                );
            }

            throw new \InvalidArgumentException(
                'El archivo tiene columnas más allá de la columna D pero no se encontraron encabezados reconocibles para SEDE y PROCESO. '
                .'Incluya las columnas SEDE y PROCESO con esos nombres (después de NOMBRE Y APELLIDOS), o bien use solo cuatro columnas en A–D con el formato clásico de delegados.'
            );
        }

        if ($sedeIdx === null || $procesoIdx === null) {
            $missingOne = $sedeIdx === null ? 'SEDE' : 'PROCESO';

            throw new \InvalidArgumentException(
                "El listado de delegados debe incluir las columnas SEDE y PROCESO de forma pareja. Falta la columna {$missingOne} o su encabezado no es reconocible."
            );
        }

        return new AssemblyDelegateColumnMap(
            $cedulaIdx,
            $nombreIdx,
            $sedeIdx,
            $estadoIdx,
            $procesoIdx,
            $fechaIdx,
        );
    }

    public static function normalizeHeaderText(string $value): string
    {
        $lower = mb_strtolower(trim($value), 'UTF-8');

        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'u'],
            $lower
        );
    }

    private static function headerMatchesCedula(string $normalized): bool
    {
        return Str::contains($normalized, ['cedula', 'documento', 'identificacion'])
            || str_contains($normalized, 'cédula');
    }

    private static function headerMatchesNombreApellidos(string $normalized): bool
    {
        $hasNombre = str_contains($normalized, 'nombre');
        $hasApellidos = str_contains($normalized, 'apellido');
        $hasNombreYApellidos = str_contains($normalized, 'nombre') && str_contains($normalized, ' y ');

        return ($hasNombre && $hasApellidos) || $hasNombreYApellidos || (str_contains($normalized, 'delegado') && $hasNombre);
    }

    private static function headerMatchesSede(string $normalized): bool
    {
        return $normalized === 'sede' || str_starts_with($normalized, 'sede ');
    }

    private static function headerMatchesEstadoBd(string $normalized): bool
    {
        return str_contains($normalized, 'estado');
    }

    private static function headerMatchesProceso(string $normalized): bool
    {
        return str_contains($normalized, 'proceso');
    }

    private static function headerMatchesFechaExpedicion(string $normalized): bool
    {
        if (str_contains($normalized, 'fecha') && (str_contains($normalized, 'expedic') || str_contains($normalized, 'expedición'))) {
            return true;
        }

        if (str_contains($normalized, 'expedic') || str_contains($normalized, 'expedición')) {
            return true;
        }

        return Str::contains($normalized, ['fecha exp', 'f. exp', 'f exp']);
    }
}
