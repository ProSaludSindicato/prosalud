<?php

namespace App\Support;

class ConvenioPreGeneratedPdfFilename
{
    /**
     * @return array{documento: string, nombre_convenio: string, nombre_afiliado: string, filename: string}|null
     */
    public static function parse(string $filename): ?array
    {
        $basename = basename($filename);
        $filenameWithoutExt = preg_replace('/\.pdf$/i', '', $basename) ?? $basename;

        $parts = explode(' - ', $filenameWithoutExt);

        if (count($parts) < 2) {
            return null;
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
            'filename' => $basename,
        ];
    }

    public static function isValidPdfMagic(string $contents): bool
    {
        return str_starts_with($contents, '%PDF');
    }
}
