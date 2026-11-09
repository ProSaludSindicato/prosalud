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

        if ($columnMap->hasProxyColumns()) {
            $this->validateProxyAssignments($data, $columnMap);
        }

        return $dataRowCount;
    }

    /**
     * Validate the "CC APODERADO" column: a delegate cannot represent themselves, a represented delegate
     * cannot be a proxy too (they are absent and cannot carry votes), and a proxy who is not a delegate
     * needs "FECHA EXPE APODERADO" to authenticate.
     *
     * @param  array<int, array<int, mixed>>  $data
     *
     * @throws \InvalidArgumentException
     */
    private function validateProxyAssignments(array $data, AssemblyDelegateColumnMap $columnMap): void
    {
        $delegateDocuments = [];
        $assignments = [];

        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];
            if (! is_array($row)) {
                continue;
            }

            $document = AssemblyDelegateDocument::normalize((string) ($row[$columnMap->cedula] ?? ''));
            if ($document === '') {
                continue;
            }

            $delegateDocuments[$document] = true;
            $proxyDocument = AssemblyDelegateDocument::normalize((string) ($row[$columnMap->apoderadoCedula] ?? ''));

            if ($proxyDocument !== '') {
                $assignments[] = [
                    'row' => $i + 1,
                    'represented' => $document,
                    'proxy' => $proxyDocument,
                    'proxyIssueDate' => $columnMap->apoderadoFechaExpedicion !== null
                        ? trim((string) ($row[$columnMap->apoderadoFechaExpedicion] ?? ''))
                        : '',
                ];
            }
        }

        $representedDocuments = array_fill_keys(array_column($assignments, 'represented'), true);
        $errors = [];

        foreach ($assignments as $assignment) {
            $rowNumber = $assignment['row'];

            if ($assignment['proxy'] === $assignment['represented']) {
                $errors[] = "Fila {$rowNumber}: el delegado {$assignment['represented']} figura como su propio apoderado.";

                continue;
            }

            if (isset($representedDocuments[$assignment['proxy']])) {
                $errors[] = "Fila {$rowNumber}: el apoderado {$assignment['proxy']} también delega su voto en otra persona y no puede representar a otros.";

                continue;
            }

            if (! isset($delegateDocuments[$assignment['proxy']]) && $assignment['proxyIssueDate'] === '') {
                $errors[] = "Fila {$rowNumber}: el apoderado {$assignment['proxy']} no es delegado y no tiene FECHA EXPE APODERADO para poder ingresar.";
            }
        }

        if ($errors !== []) {
            $shown = array_slice($errors, 0, 5);
            $remaining = count($errors) - count($shown);

            throw new \InvalidArgumentException(
                'Hay inconsistencias en las columnas de apoderados: '.implode(' ', $shown)
                .($remaining > 0 ? " Y {$remaining} más." : '')
            );
        }
    }

    public function validateDelegatesSpreadsheet(Spreadsheet $spreadsheet): int
    {
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray();

        return $this->validateDelegatesSheetData($data);
    }
}
