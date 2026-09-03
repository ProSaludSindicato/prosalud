<?php

namespace App\Support;

class ConvenioPreGeneratedPdfFilename
{
    /**
     * @return array{documento: string, nombre_convenio: string, nombre_afiliado: string, periodo: string|null, filename: string}|null
     */
    public static function parse(string $filename): ?array
    {
        $basename = basename($filename);
        $filenameWithoutExt = preg_replace('/\.pdf$/i', '', $basename) ?? $basename;

        $parts = array_values(array_filter(
            array_map(
                static fn (string $part): string => trim($part),
                explode(' - ', $filenameWithoutExt),
            ),
            static fn (string $part): bool => $part !== '',
        ));

        if (count($parts) < 2) {
            return null;
        }

        $periodo = null;
        $lastPart = (string) end($parts);
        if (count($parts) >= 4 && preg_match('/^\d{4}[12]$/', $lastPart) === 1) {
            $periodo = $lastPart;
            array_pop($parts);
        }

        $documento = preg_replace('/[^0-9]/', '', (string) end($parts));
        $nombreConvenio = trim((string) $parts[0]);

        if ($documento === '' || $nombreConvenio === '') {
            return null;
        }

        $nombreAfiliado = '';
        if (count($parts) >= 3) {
            $nombreAfiliado = trim((string) $parts[1]);
        }

        return [
            'documento' => $documento,
            'nombre_convenio' => $nombreConvenio,
            'nombre_afiliado' => $nombreAfiliado,
            'periodo' => $periodo,
            'filename' => $basename,
        ];
    }

    public static function isValidPdfMagic(string $contents): bool
    {
        return str_starts_with($contents, '%PDF');
    }
}
