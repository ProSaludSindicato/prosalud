<?php

namespace App\Services;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class AssemblyDelegatesFileService
{
    /**
     * Minimum columns per row (CEDULA, NOMBRE Y APELLIDOS, ESTADO BD, F. EXPEDICIÓN).
     */
    public const MIN_COLUMNS = 4;

    /**
     * Validate structure and return the number of data rows (excluding header).
     *
     * @param  array<int, array<int, mixed>>  $data
     *
     * @throws \InvalidArgumentException
     */
    public function validateDelegatesSheetData(array $data): int
    {
        if (count($data) < 2) {
            throw new \InvalidArgumentException(
                'El archivo debe incluir una fila de encabezados y al menos una fila de datos.'
            );
        }

        $header = $data[0] ?? [];
        if (count($header) < self::MIN_COLUMNS) {
            throw new \InvalidArgumentException(
                'La primera fila debe tener al menos '.self::MIN_COLUMNS.' columnas (CEDULA, NOMBRE Y APELLIDOS, ESTADO BD, F. EXPEDICIÓN).'
            );
        }

        $this->assertHeaderRowMatches($header);

        $dataRowCount = 0;
        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];
            if (! is_array($row) || count($row) < self::MIN_COLUMNS) {
                continue;
            }
            $cedula = trim((string) ($row[0] ?? ''));
            if ($cedula !== '') {
                $dataRowCount++;
            }
        }

        if ($dataRowCount < 1) {
            throw new \InvalidArgumentException(
                'Debe haber al menos un delegado con cédula indicada en las filas de datos.'
            );
        }

        return $dataRowCount;
    }

    /**
     * @param  array<int, mixed>  $headerRow
     */
    public function assertHeaderRowMatches(array $headerRow): void
    {
        $h0 = $this->normalizeHeaderText((string) ($headerRow[0] ?? ''));
        $h1 = $this->normalizeHeaderText((string) ($headerRow[1] ?? ''));
        $h2 = $this->normalizeHeaderText((string) ($headerRow[2] ?? ''));
        $h3 = $this->normalizeHeaderText((string) ($headerRow[3] ?? ''));

        if (! $this->headerMatchesCedula($h0)) {
            throw new \InvalidArgumentException(
                'La columna A debe corresponder a la cédula (ej. CEDULA o DOCUMENTO).'
            );
        }
        if (! $this->headerMatchesNombre($h1)) {
            throw new \InvalidArgumentException(
                'La columna B debe corresponder al nombre (ej. NOMBRE Y APELLIDOS).'
            );
        }
        if (! $this->headerMatchesEstado($h2)) {
            throw new \InvalidArgumentException(
                'La columna C debe corresponder al estado (ej. ESTADO BD).'
            );
        }
        if (! $this->headerMatchesExpedicion($h3)) {
            throw new \InvalidArgumentException(
                'La columna D debe corresponder a la fecha de expedición (ej. F. EXPEDICIÓN).'
            );
        }
    }

    public function validateDelegatesSpreadsheet(Spreadsheet $spreadsheet): int
    {
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray();

        return $this->validateDelegatesSheetData($data);
    }

    private function normalizeHeaderText(string $value): string
    {
        $lower = mb_strtolower(trim($value), 'UTF-8');

        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'n'],
            $lower
        );
    }

    private function headerMatchesCedula(string $normalized): bool
    {
        return Str::contains($normalized, ['cedula', 'documento', 'identificacion'])
            || str_contains($normalized, 'cédula');
    }

    private function headerMatchesNombre(string $normalized): bool
    {
        return Str::contains($normalized, ['nombre', 'apellido', 'delegado']);
    }

    private function headerMatchesEstado(string $normalized): bool
    {
        return str_contains($normalized, 'estado');
    }

    private function headerMatchesExpedicion(string $normalized): bool
    {
        return Str::contains($normalized, ['expedic', 'expedición', 'expedido', 'fecha exp']);
    }
}
