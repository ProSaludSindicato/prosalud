<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;

class AssemblyDelegatesFileService
{
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

        $headerRow = $data[0] ?? [];
        $columnMap = AssemblyDelegateColumnResolver::resolveOrFail($headerRow);
        $maxIndex = $columnMap->maxColumnIndex();

        if (count($headerRow) < $maxIndex + 1) {
            throw new \InvalidArgumentException(
                'La primera fila no tiene suficientes columnas para los encabezados detectados. Asegúrese de que existan columnas vacías a la derecha si el Excel recortó celdas, o vuelva a exportar el archivo con todas las columnas visibles.'
            );
        }

        $dataRowCount = 0;
        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];
            if (! is_array($row)) {
                continue;
            }
            $cedula = trim((string) ($row[$columnMap->cedula] ?? ''));
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

    public function validateDelegatesSpreadsheet(Spreadsheet $spreadsheet): int
    {
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray();

        return $this->validateDelegatesSheetData($data);
    }
}
