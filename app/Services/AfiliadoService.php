<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Illuminate\Support\Facades\Log;

class AfiliadoService
{
    private const EXCEL_FILE_PATH = 'data/SIMPLE_PROSANET_INFORMACION_AFILIADOS.xlsx';
    private const SHEET_INFORMACION_GENERAL = 'INFORMACIÓN GENERAL';
    private const SHEET_CONVENIOS = 'CONVENIOS';
    private const SHEET_BENEFICIARIOS = 'BENEFICIARIOS';

    // Column indexes for INFORMACIÓN GENERAL sheet
    private const COL_TIPO_DOCUMENTO = 0;
    private const COL_DOCUMENTO = 1;
    private const COL_NOMBRES = 2;
    private const COL_APELLIDOS = 3;
    private const COL_ESTADO = 4;
    private const COL_FECHA_EXPEDICION = 5;
    private const COL_FECHA_NACIMIENTO = 6;
    private const COL_LUGAR_NACIMIENTO = 7;
    private const COL_SEXO = 8;
    private const COL_RH = 9;
    private const COL_FECHA_INGRESO = 10;
    private const COL_ESTADO_CIVIL = 11;
    private const COL_CARNET = 12;
    private const COL_DIRECCION = 13;
    private const COL_DEPARTAMENTO = 14;
    private const COL_MUNICIPIO = 15;
    private const COL_TELEFONO = 16;
    private const COL_CELULAR = 17;
    private const COL_CORREO_PERSONAL = 18;
    private const COL_ARCHIVO_LIQUIDADO = 19;
    private const COL_FECHA_LIQUIDACION = 20;
    private const COL_TALLA_UNIFORME = 21;
    private const COL_TALLA_CALZADO = 22;
    private const COL_NIVEL_EDUCACION = 23;
    private const COL_OTROS_ESTUDIOS = 24;
    private const COL_NUMERO_CUENTA = 25;
    private const COL_TIPO_CUENTA = 26;
    private const COL_BANCO = 27;
    private const COL_FECHA_RETHUS = 28;
    private const COL_COMPENSACION_BASICA = 29;
    private const COL_TIPO_AFILIACION = 30;
    private const COL_EPS = 31;
    private const COL_AFP = 32;
    private const COL_ARL = 33;
    private const COL_CAJA_COMPENSACION = 34;
    private const COL_NIVEL_RIESGO = 35;
    private const COL_FECHA_VENCIMIENTO_POLIZA = 36;
    private const COL_EMISOR_POLIZA = 37;
    private const COL_DETALLES = 38;

    // Column indexes for CONVENIOS sheet
    private const COL_CONV_DOCUMENTO_AFILIADO = 0;
    private const COL_CONV_NOMBRE_AFILIADO = 1;
    private const COL_CONV_APELLIDOS_AFILIADO = 2;
    private const COL_CONV_CLIENTE = 3;
    private const COL_CONV_SUCURSAL = 4;
    private const COL_CONV_PROCESO = 5;
    private const COL_CONV_ESTADO = 6;
    private const COL_CONV_FECHA_INGRESO = 7;
    private const COL_CONV_FECHA_FIN = 8;
    private const COL_CONV_NOTAS = 9;

    // Column indexes for BENEFICIARIOS sheet
    // Order: Documento afiliado, Tipo documento, Documento, Nombres, Apellidos, Fecha nacimiento, Sexo, Notas (parentesco)
    private const COL_BEN_DOCUMENTO_AFILIADO = 0;
    private const COL_BEN_TIPO_DOCUMENTO = 1;
    private const COL_BEN_DOCUMENTO = 2;
    private const COL_BEN_NOMBRES = 3;
    private const COL_BEN_APELLIDOS = 4;
    private const COL_BEN_FECHA_NACIMIENTO = 5;
    private const COL_BEN_SEXO = 6;
    private const COL_BEN_NOTAS_PARENTESCO = 7;

    /**
     * Authenticate and get affiliate information (optimized - reads only necessary rows)
     */
    public function authenticateAndGetAfiliado(
        string $tipoDocumento,
        string $documento,
        string $fechaExpedicion
    ): ?array {
        try {
            $excelPath = public_path(self::EXCEL_FILE_PATH);

            if (!file_exists($excelPath)) {
                Log::error('Archivo de afiliados no encontrado', ['path' => $excelPath]);
                return null;
            }

            if (!is_readable($excelPath)) {
                Log::error('Archivo de afiliados no es legible', ['path' => $excelPath]);
                return null;
            }

            // Load the Excel file with read-only mode for better performance
            $spreadsheet = IOFactory::load($excelPath);

            // Get INFORMACIÓN GENERAL sheet
            $informacionSheet = $spreadsheet->getSheetByName(self::SHEET_INFORMACION_GENERAL);
            if (!$informacionSheet) {
                Log::error('Pestaña INFORMACIÓN GENERAL no encontrada');
                return null;
            }

            // Optimized: Find and read only the matching row without loading all data
            $afiliadoRow = $this->findAfiliadoRowOptimized(
                $informacionSheet,
                $tipoDocumento,
                $documento,
                $fechaExpedicion
            );

            if ($afiliadoRow === null) {
                Log::info('Afiliado no encontrado', [
                    'tipo_documento' => $tipoDocumento,
                    'documento' => $documento,
                    'fecha_expedicion' => $fechaExpedicion,
                    'normalized' => [
                        'tipo_documento' => $this->normalizeValue($tipoDocumento),
                        'documento' => $this->normalizeValue($documento),
                        'fecha_expedicion' => $this->normalizeDate($fechaExpedicion),
                    ]
                ]);
                return null;
            }

            // Extract affiliate information
            $afiliadoFull = $this->extractAfiliadoInfo($afiliadoRow);

            // Get convenios for this affiliate (optimized - only matching rows)
            $conveniosSheet = $spreadsheet->getSheetByName(self::SHEET_CONVENIOS);
            if ($conveniosSheet) {
                $conveniosFull = $this->getConveniosByDocumentoOptimized($conveniosSheet, $documento);
            } else {
                $conveniosFull = [];
            }

            // Filter to return only required fields
            return $this->filterAfiliadoResponse($afiliadoFull, $conveniosFull);

        } catch (SpreadsheetException $e) {
            Log::error('Error al procesar archivo Excel de afiliados', [
                'error' => $e->getMessage(),
                'file_path' => public_path(self::EXCEL_FILE_PATH),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('Error inesperado al leer archivo Excel de afiliados', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Authenticate and get affiliate information (reads entire file - kept for future use)
     * This method loads the entire Excel file into memory.
     * Use authenticateAndGetAfiliado() for better performance with large files.
     */
    public function authenticateAndGetAfiliadoFull(
        string $tipoDocumento,
        string $documento,
        string $fechaExpedicion
    ): ?array {
        try {
            // Increase memory limit temporarily for large Excel files
            $originalMemoryLimit = ini_get('memory_limit');
            ini_set('memory_limit', '512M');

            // Increase execution time for large files
            $originalMaxExecutionTime = ini_get('max_execution_time');
            set_time_limit(60);

            $excelPath = public_path(self::EXCEL_FILE_PATH);

            if (!file_exists($excelPath)) {
                Log::error('Archivo de afiliados no encontrado', ['path' => $excelPath]);
                return null;
            }

            if (!is_readable($excelPath)) {
                Log::error('Archivo de afiliados no es legible', ['path' => $excelPath]);
                return null;
            }

            // Load the Excel file
            $spreadsheet = IOFactory::load($excelPath);

            // Get INFORMACIÓN GENERAL sheet
            $informacionSheet = $spreadsheet->getSheetByName(self::SHEET_INFORMACION_GENERAL);
            if (!$informacionSheet) {
                Log::error('Pestaña INFORMACIÓN GENERAL no encontrada');
                return null;
            }

            $informacionData = $informacionSheet->toArray();

            // Find the affiliate row index
            $afiliadoRowIndex = $this->findAfiliadoRow(
                $informacionData,
                $tipoDocumento,
                $documento,
                $fechaExpedicion
            );

            if ($afiliadoRowIndex === null) {
                Log::info('Afiliado no encontrado', [
                    'tipo_documento' => $tipoDocumento,
                    'documento' => $documento,
                    'fecha_expedicion' => $fechaExpedicion
                ]);
                return null;
            }

            // Get the actual row data
            $afiliadoRow = $informacionData[$afiliadoRowIndex];

            // Extract affiliate information
            $afiliadoFull = $this->extractAfiliadoInfo($afiliadoRow);

            // Get convenios for this affiliate
            $conveniosSheet = $spreadsheet->getSheetByName(self::SHEET_CONVENIOS);
            if ($conveniosSheet) {
                $conveniosData = $conveniosSheet->toArray();
                $conveniosFull = $this->getConveniosByDocumento($conveniosData, $documento);
            } else {
                $conveniosFull = [];
            }

            // Filter to return only required fields
            $result = $this->filterAfiliadoResponse($afiliadoFull, $conveniosFull);

            // Restore original settings
            ini_set('memory_limit', $originalMemoryLimit);
            set_time_limit($originalMaxExecutionTime);

            return $result;

        } catch (SpreadsheetException $e) {
            Log::error('Error al procesar archivo Excel de afiliados', [
                'error' => $e->getMessage(),
                'file_path' => public_path(self::EXCEL_FILE_PATH),
                'trace' => $e->getTraceAsString()
            ]);
            // Restore original settings on error
            if (isset($originalMemoryLimit)) {
                ini_set('memory_limit', $originalMemoryLimit);
            }
            if (isset($originalMaxExecutionTime)) {
                set_time_limit($originalMaxExecutionTime);
            }
            return null;
        } catch (\Throwable $e) {
            Log::error('Error inesperado al leer archivo Excel de afiliados', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            // Restore original settings on error
            if (isset($originalMemoryLimit)) {
                ini_set('memory_limit', $originalMemoryLimit);
            }
            if (isset($originalMaxExecutionTime)) {
                set_time_limit($originalMaxExecutionTime);
            }
            return null;
        }
    }

    /**
     * Find the row index of the affiliate matching the authentication criteria
     */
    private function findAfiliadoRow(
        array $data,
        string $tipoDocumento,
        string $documento,
        string $fechaExpedicion
    ): ?int {
        // Normalize input values once
        $normalizedTipoDocumento = $this->normalizeValue($tipoDocumento);
        $normalizedDocumento = $this->normalizeValue($documento);
        $normalizedFechaExpedicion = $this->normalizeDate($fechaExpedicion);

        // Search from row 1 onwards (skip header row at index 0)
        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Check if row has enough columns
            if (count($row) < 39) {
                continue;
            }

            // Check if this looks like a header row (contains header text)
            $firstCol = $this->normalizeValue($row[0] ?? '');
            if ($firstCol && (
                stripos($firstCol, 'tipo documento') !== false ||
                stripos($firstCol, 'documento') === 0 ||
                stripos($firstCol, 'nuip') !== false
            )) {
                continue;
            }

            $rowTipoDocumento = $this->normalizeValue($row[self::COL_TIPO_DOCUMENTO] ?? '');
            $rowDocumento = $this->normalizeValue($row[self::COL_DOCUMENTO] ?? '');
            $rowFechaExpedicion = $this->normalizeDate($row[self::COL_FECHA_EXPEDICION] ?? '');

            // Match authentication criteria
            if ($rowTipoDocumento === $normalizedTipoDocumento &&
                $rowDocumento === $normalizedDocumento &&
                $rowFechaExpedicion === $normalizedFechaExpedicion) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Find affiliate row using optimized iteration (reads only necessary rows)
     */
    private function findAfiliadoRowOptimized(
        $sheet,
        string $tipoDocumento,
        string $documento,
        string $fechaExpedicion
    ): ?array {
        // Normalize input values once
        $normalizedTipoDocumento = $this->normalizeValue($tipoDocumento);
        $normalizedDocumento = $this->normalizeValue($documento);
        $normalizedFechaExpedicion = $this->normalizeDate($fechaExpedicion);

        // Get highest row to know when to stop
        $highestRow = $sheet->getHighestRow();

        // Iterate through rows (skip header row at row 1)
        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            // Read only necessary columns first for matching (optimization)
            $colLetter = Coordinate::stringFromColumnIndex(self::COL_TIPO_DOCUMENTO + 1);
            $cell = $sheet->getCell($colLetter . $rowIndex);
            $rowTipoDocumentoRaw = $this->getCellValue($cell);

            $colLetter = Coordinate::stringFromColumnIndex(self::COL_DOCUMENTO + 1);
            $cell = $sheet->getCell($colLetter . $rowIndex);
            $rowDocumentoRaw = $this->getCellValue($cell);

            $colLetter = Coordinate::stringFromColumnIndex(self::COL_FECHA_EXPEDICION + 1);
            $cell = $sheet->getCell($colLetter . $rowIndex);
            $rowFechaExpedicionRaw = $this->getCellValue($cell);

            // Normalize values for comparison
            $rowTipoDocumento = $this->normalizeValue($rowTipoDocumentoRaw);
            $rowDocumento = $this->normalizeValue($rowDocumentoRaw);
            $rowFechaExpedicion = $this->normalizeDate($rowFechaExpedicionRaw);

            // Skip if row appears empty
            if (empty($rowTipoDocumento) && empty($rowDocumento) && empty($rowFechaExpedicion)) {
                continue;
            }

            // Check if this looks like a header row
            if ($rowTipoDocumento && (
                stripos($rowTipoDocumento, 'tipo documento') !== false ||
                stripos($rowTipoDocumento, 'documento') === 0 ||
                stripos($rowTipoDocumento, 'nuip') !== false
            )) {
                continue;
            }

            // Match authentication criteria
            if ($rowTipoDocumento === $normalizedTipoDocumento &&
                $rowDocumento === $normalizedDocumento &&
                $rowFechaExpedicion === $normalizedFechaExpedicion) {

                // Match found! Now read the complete row
                $rowData = [];
                for ($colIndex = 0; $colIndex < 39; $colIndex++) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                    $cell = $sheet->getCell($colLetter . $rowIndex);
                    $rowData[] = $this->getCellValue($cell);
                }
                return $rowData;
            }
        }

        return null;
    }

    /**
     * Get cell value handling different data types (dates, numbers, strings)
     */
    private function getCellValue($cell)
    {
        $value = $cell->getCalculatedValue();

        // Handle DateTime objects from Excel dates
        if ($value instanceof \DateTime) {
            return $value->format('Y-m-d');
        }

        // Handle numeric values (convert to string to maintain consistency)
        if (is_numeric($value) && !is_string($value)) {
            return (string) $value;
        }

        return $value;
    }

    /**
     * Get convenios by documento using optimized iteration (reads only matching rows)
     */
    private function getConveniosByDocumentoOptimized($sheet, string $documento): array
    {
        $convenios = [];
        $normalizedDocumento = $this->normalizeValue($documento);

        // Get highest row
        $highestRow = $sheet->getHighestRow();

        // Iterate through rows (skip header row at row 1)
        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            // Read only necessary columns for matching
            $colLetter = Coordinate::stringFromColumnIndex(self::COL_CONV_DOCUMENTO_AFILIADO + 1);
            $cell = $sheet->getCell($colLetter . $rowIndex);
            $rowDocumentoRaw = $this->getCellValue($cell);
            $rowDocumento = $this->normalizeValue($rowDocumentoRaw);

            // If document matches, read the full row
            if ($rowDocumento === $normalizedDocumento) {
                // Read all columns for this row
                $row = [];
                for ($colIndex = 0; $colIndex < 10; $colIndex++) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                    $cell = $sheet->getCell($colLetter . $rowIndex);
                    $row[] = $this->getCellValue($cell);
                }

                // Skip if row appears empty
                if (empty(array_filter($row))) {
                    continue;
                }

                // Check if this looks like a header row
                $firstCol = $this->normalizeValue($row[0] ?? '');
                if ($firstCol && (
                    stripos($firstCol, 'documento afiliado') !== false ||
                    stripos($firstCol, 'documento') === 0
                )) {
                    continue;
                }

                $convenios[] = [
                    'documento_afiliado' => $rowDocumento,
                    'nombre_afiliado' => $this->normalizeValue($row[self::COL_CONV_NOMBRE_AFILIADO] ?? ''),
                    'apellidos_afiliado' => $this->normalizeValue($row[self::COL_CONV_APELLIDOS_AFILIADO] ?? ''),
                    'cliente' => $this->normalizeValue($row[self::COL_CONV_CLIENTE] ?? ''),
                    'sucursal' => $this->normalizeValue($row[self::COL_CONV_SUCURSAL] ?? ''),
                    'proceso' => $this->normalizeValue($row[self::COL_CONV_PROCESO] ?? ''),
                    'estado' => $this->normalizeValue($row[self::COL_CONV_ESTADO] ?? ''),
                    'fecha_ingreso' => $this->normalizeDate($row[self::COL_CONV_FECHA_INGRESO] ?? ''),
                    'fecha_fin' => $this->normalizeDate($row[self::COL_CONV_FECHA_FIN] ?? ''),
                    'notas' => $this->normalizeValue($row[self::COL_CONV_NOTAS] ?? ''),
                ];
            }
        }

        return $convenios;
    }

    /**
     * Extract affiliate information from a row
     */
    private function extractAfiliadoInfo(array $row): array
    {
        // Handle E.P.S, A.F.P, A.R.L, Caja de Compensacion - they might be concatenated
        $epsRaw = $this->normalizeValue($row[self::COL_EPS] ?? '');
        $afpRaw = $this->normalizeValue($row[self::COL_AFP] ?? '');
        $arlRaw = $this->normalizeValue($row[self::COL_ARL] ?? '');
        $cajaCompensacionRaw = $this->normalizeValue($row[self::COL_CAJA_COMPENSACION] ?? '');

        // Check if EPS contains concatenated values (when other columns are empty)
        // Pattern from images: "NUEVA E.P.S PORVENIR COLMENA COMFENALC 3" or "EPS SURA (A COLPENSION COLMENA COMFENALC 3"
        if (!empty($epsRaw) && (empty($afpRaw) && empty($arlRaw) && empty($cajaCompensacionRaw))) {
            $parsed = $this->parseConcatenatedValues($epsRaw);
            if ($parsed) {
                $eps = $parsed['eps'];
                $afp = $parsed['afp'];
                $arl = $parsed['arl'];
                $cajaCompensacion = $parsed['caja_compensacion'];
            } else {
                $eps = $epsRaw;
                $afp = $afpRaw;
                $arl = $arlRaw;
                $cajaCompensacion = $cajaCompensacionRaw;
            }
        } else {
            // Use separate values if available
            $eps = $epsRaw;
            $afp = $afpRaw;
            $arl = $arlRaw;
            $cajaCompensacion = $cajaCompensacionRaw;
        }

        // Extract date from Banco column if it contains a date (Fecha Rethus might be embedded)
        $banco = $this->normalizeValue($row[self::COL_BANCO] ?? '');
        $fechaRethus = $this->normalizeValue($row[self::COL_FECHA_RETHUS] ?? '');

        // If fecha rethus is empty but banco contains a date, extract it
        if (empty($fechaRethus) && !empty($banco)) {
            $extractedDate = $this->extractDateFromString($banco);
            if ($extractedDate) {
                $fechaRethus = $extractedDate;
                // Remove date from banco
                $banco = preg_replace('/\s+\d{4}-\d{2}-\d{2}/', '', $banco);
            }
        }

        return [
            'tipo_documento' => $this->normalizeValue($row[self::COL_TIPO_DOCUMENTO] ?? ''),
            'documento' => $this->normalizeValue($row[self::COL_DOCUMENTO] ?? ''),
            'nombres' => $this->normalizeValue($row[self::COL_NOMBRES] ?? ''),
            'apellidos' => $this->normalizeValue($row[self::COL_APELLIDOS] ?? ''),
            'estado' => $this->normalizeValue($row[self::COL_ESTADO] ?? ''),
            'fecha_expedicion' => $this->normalizeDate($row[self::COL_FECHA_EXPEDICION] ?? ''),
            'fecha_nacimiento' => $this->normalizeDate($row[self::COL_FECHA_NACIMIENTO] ?? ''),
            'lugar_nacimiento' => $this->normalizeValue($row[self::COL_LUGAR_NACIMIENTO] ?? ''),
            'sexo' => $this->normalizeValue($row[self::COL_SEXO] ?? ''),
            'rh' => $this->normalizeValue($row[self::COL_RH] ?? ''),
            'fecha_ingreso' => $this->normalizeDate($row[self::COL_FECHA_INGRESO] ?? ''),
            'estado_civil' => $this->normalizeValue($row[self::COL_ESTADO_CIVIL] ?? ''),
            'carnet' => $this->normalizeValue($row[self::COL_CARNET] ?? ''),
            'direccion' => $this->normalizeValue($row[self::COL_DIRECCION] ?? ''),
            'departamento' => $this->normalizeValue($row[self::COL_DEPARTAMENTO] ?? ''),
            'municipio' => $this->normalizeValue($row[self::COL_MUNICIPIO] ?? ''),
            'telefono' => $this->normalizeValue($row[self::COL_TELEFONO] ?? ''),
            'celular' => $this->normalizeValue($row[self::COL_CELULAR] ?? ''),
            'correo_personal' => $this->normalizeValue($row[self::COL_CORREO_PERSONAL] ?? ''),
            'archivo_liquidado' => $this->normalizeDate($row[self::COL_ARCHIVO_LIQUIDADO] ?? ''),
            'fecha_liquidacion' => $this->normalizeDate($row[self::COL_FECHA_LIQUIDACION] ?? ''),
            'talla_uniforme' => $this->normalizeValue($row[self::COL_TALLA_UNIFORME] ?? ''),
            'talla_calzado' => $this->normalizeValue($row[self::COL_TALLA_CALZADO] ?? ''),
            'nivel_educacion' => $this->normalizeNivelEducacion($this->normalizeValue($row[self::COL_NIVEL_EDUCACION] ?? '')),
            'otros_estudios' => $this->normalizeValue($row[self::COL_OTROS_ESTUDIOS] ?? ''),
            'numero_cuenta' => $this->normalizeValue($row[self::COL_NUMERO_CUENTA] ?? ''),
            'tipo_cuenta' => $this->normalizeValue($row[self::COL_TIPO_CUENTA] ?? ''),
            'banco' => $banco,
            'fecha_rethus' => $fechaRethus,
            'compensacion_basica' => $this->normalizeNumeric($row[self::COL_COMPENSACION_BASICA] ?? ''),
            'tipo_afiliacion' => $this->normalizeValue($row[self::COL_TIPO_AFILIACION] ?? ''),
            'eps' => $eps,
            'afp' => $afp,
            'arl' => $arl,
            'caja_compensacion' => $cajaCompensacion,
            'nivel_riesgo' => $this->normalizeValue($row[self::COL_NIVEL_RIESGO] ?? ''),
            'fecha_vencimiento_poliza' => $this->normalizeDate($row[self::COL_FECHA_VENCIMIENTO_POLIZA] ?? ''),
            'emisor_poliza' => $this->normalizeValue($row[self::COL_EMISOR_POLIZA] ?? ''),
            'detalles' => $this->normalizeValue($row[self::COL_DETALLES] ?? ''),
        ];
    }

    /**
     * Get all convenios for a specific documento
     */
    private function getConveniosByDocumento(array $data, string $documento): array
    {
        $convenios = [];
        $normalizedDocumento = $this->normalizeValue($documento);

        // Skip header row(s)
        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Check if row has enough columns
            if (count($row) < 10) {
                continue;
            }

            // Check if this looks like a header row
            $firstCol = $this->normalizeValue($row[0] ?? '');
            if ($firstCol && (
                stripos($firstCol, 'documento afiliado') !== false ||
                stripos($firstCol, 'documento') === 0
            )) {
                continue;
            }

            $rowDocumento = $this->normalizeValue($row[self::COL_CONV_DOCUMENTO_AFILIADO] ?? '');

            if ($rowDocumento === $normalizedDocumento) {
                $convenios[] = [
                    'documento_afiliado' => $rowDocumento,
                    'nombre_afiliado' => $this->normalizeValue($row[self::COL_CONV_NOMBRE_AFILIADO] ?? ''),
                    'apellidos_afiliado' => $this->normalizeValue($row[self::COL_CONV_APELLIDOS_AFILIADO] ?? ''),
                    'cliente' => $this->normalizeValue($row[self::COL_CONV_CLIENTE] ?? ''),
                    'sucursal' => $this->normalizeValue($row[self::COL_CONV_SUCURSAL] ?? ''),
                    'proceso' => $this->normalizeValue($row[self::COL_CONV_PROCESO] ?? ''),
                    'estado' => $this->normalizeValue($row[self::COL_CONV_ESTADO] ?? ''),
                    'fecha_ingreso' => $this->normalizeDate($row[self::COL_CONV_FECHA_INGRESO] ?? ''),
                    'fecha_fin' => $this->normalizeDate($row[self::COL_CONV_FECHA_FIN] ?? ''),
                    'notas' => $this->normalizeValue($row[self::COL_CONV_NOTAS] ?? ''),
                ];
            }
        }

        return $convenios;
    }

    /**
     * Filter affiliate response to include only required fields
     */
    private function filterAfiliadoResponse(array $afiliadoFull, array $conveniosFull): array
    {
        // Fields required for afiliado
        $requiredAfiliadoFields = [
            'tipo_documento',
            'documento',
            'nombres',
            'apellidos',
            'estado',
            'celular',
            'correo_personal'
        ];

        // Filter afiliado data
        $afiliadoFiltered = [];
        foreach ($requiredAfiliadoFields as $field) {
            $afiliadoFiltered[$field] = $afiliadoFull[$field] ?? null;
        }

        // Filter convenios data with required cliente handling (no transformation)
        $conveniosFiltered = [];
        foreach ($conveniosFull as $convenio) {
            $clienteValue = $convenio['cliente'] ?? null;
            $clienteFinal = ($clienteValue === null || $clienteValue === '') ? 'SIN ASIGNAR' : $clienteValue;

            $convenioFiltered = [
                'cliente' => $clienteFinal,
                'proceso' => $this->normalizeValue($convenio['proceso'] ?? ''),
                'estado' => $this->normalizeValue($convenio['estado'] ?? ''),
                'fecha_fin' => $this->normalizeDate($convenio['fecha_fin'] ?? ''),
            ];

            $conveniosFiltered[] = $convenioFiltered;
        }

        // Elegir un solo convenio según reglas:
        // 1) Si existe alguno con estado "Activo" (insensible a mayúsculas), devolver ese (el primero encontrado)
        // 2) Si no hay "Activo", devolver el de mayor fecha_fin (YYYY-MM-DD). En empate, el primero
        $selectedConvenio = null;

        // Regla 1: buscar "Activo"
        foreach ($conveniosFiltered as $conv) {
            $estado = $conv['estado'] ?? null;
            if ($estado !== null && strcasecmp($estado, 'Activo') === 0) {
                $selectedConvenio = $conv;
                break;
            }
        }

        // Regla 2: si no hay Activo, escoger por fecha_fin más reciente
        if ($selectedConvenio === null && !empty($conveniosFiltered)) {
            $selectedConvenio = $conveniosFiltered[0];
            $bestTs = $selectedConvenio['fecha_fin'] ? strtotime($selectedConvenio['fecha_fin']) : null;
            foreach ($conveniosFiltered as $conv) {
                $ts = $conv['fecha_fin'] ? strtotime($conv['fecha_fin']) : null;
                if ($ts !== false && $ts !== null) {
                    if ($bestTs === null || $ts > $bestTs) {
                        $bestTs = $ts;
                        $selectedConvenio = $conv;
                    }
                }
            }
        }

        // Devolver arreglo con un solo convenio (o vacío si no hay)
        $afiliadoFiltered['convenios'] = $selectedConvenio ? [$selectedConvenio] : [];

        return $afiliadoFiltered;
    }

    /**
     * Transform cliente name according to mapping rules
     * Only exact matches are allowed to avoid errors with similar names
     */
    private function transformCliente(?string $cliente): string
    {
        if (empty($cliente)) {
            return 'SIN ASIGNAR';
        }

        $clienteUpper = strtoupper(trim($cliente));

        // Only exact matches - no partial matching to avoid errors
        $exactMappings = [
            'HMFS - BELLO' => 'Bello',
            'HLM - GRUPO 1' => 'La Maria',
            'HLM - GRUPO 3' => 'La Maria',
            'HLM - GRUPO 2' => 'La Maria',
            'HSJDRIONEGRO' => 'Rionegro',
            'LA MARIA - COOSALUD' => 'La Maria',
            'LA MARIA UPAI 245' => 'La Maria',
            'LA MARIA UPAI - 0028 - 2023' => 'La Maria',
            'LA MARIA - ENTERRITORIO' => 'La Maria',
            'LA MARIA - ENTERRITORIO 2' => 'La Maria',
            'LA MARIA - VIH - 1' => 'La Maria',
            'LA MARIA UPAI - 140 - 2023' => 'La Maria',
            'LA MARIA - TRANSMISIBLES 176' => 'La Maria',
            'LA MARIA - TRANSMISIBLES' => 'La Maria',
            'ADMON' => 'ADMON',
        ];

        // Check exact match only
        if (isset($exactMappings[$clienteUpper])) {
            return $exactMappings[$clienteUpper];
        }

        // If no exact match found, return SIN ASIGNAR
        return 'SIN ASIGNAR';
    }

    /**
     * Normalize a value (trim and handle empty values)
     */
    private function normalizeValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Normalize date format for comparison and storage
     */
    private function normalizeDate($date): ?string
    {
        if (empty($date)) {
            return null;
        }

        $dateStr = trim((string) $date);

        if ($dateStr === '') {
            return null;
        }

        // If already in YYYY-MM-DD format, return as is
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return $dateStr;
        }

        // Try to parse various date formats
        try {
            // Try YYYY-MM-DD format
            $parsedDate = \DateTime::createFromFormat('Y-m-d', $dateStr);
            if ($parsedDate) {
                return $parsedDate->format('Y-m-d');
            }

            // Try d/m/Y format
            $parsedDate = \DateTime::createFromFormat('d/m/Y', $dateStr);
            if ($parsedDate) {
                return $parsedDate->format('Y-m-d');
            }

            // Try m/d/Y format
            $parsedDate = \DateTime::createFromFormat('m/d/Y', $dateStr);
            if ($parsedDate) {
                return $parsedDate->format('Y-m-d');
            }

            // Try Excel serial date (numeric)
            if (is_numeric($dateStr)) {
                $excelBaseDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $dateStr);
                return $excelBaseDate->format('Y-m-d');
            }

            // If none work, return original (might be invalid date)
            return $dateStr;
        } catch (\Exception $e) {
            Log::warning('Error al normalizar fecha', [
                'date' => $dateStr,
                'error' => $e->getMessage()
            ]);
            return $dateStr;
        }
    }

    /**
     * Normalize numeric value
     */
    private function normalizeNumeric($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        // Remove any non-numeric characters except decimal point
        $normalized = preg_replace('/[^0-9.]/', '', $normalized);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Normalize nivel educativo value
     * Replaces: Secundaria -> Bachiller, Pregrado -> Profesional, Especialización -> Especialista
     * Keeps: Tecnico and Tecnologo without accents (as they come from Excel)
     */
    private function normalizeNivelEducacion(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        
        if ($normalized === '') {
            return null;
        }

        // Case-insensitive replacements
        $replacements = [
            'Secundaria' => 'Bachiller',
            'secundaria' => 'Bachiller',
            'SECUNDARIA' => 'Bachiller',
            'Pregrado' => 'Profesional',
            'pregrado' => 'Profesional',
            'PREGrado' => 'Profesional',
            'Especialización' => 'Especialista',
            'especialización' => 'Especialista',
            'Especializacion' => 'Especialista',
            'especializacion' => 'Especialista',
            'ESPECIALIZACIÓN' => 'Especialista',
        ];

        // Check for exact match (case-insensitive)
        foreach ($replacements as $old => $new) {
            if (strcasecmp($normalized, $old) === 0) {
                return $new;
            }
        }

        // If contains the word, try to replace
        foreach ($replacements as $old => $new) {
            if (stripos($normalized, $old) !== false) {
                return str_ireplace($old, $new, $normalized);
            }
        }

        // Tecnico and Tecnologo come without accents from Excel, so keep as is
        return $normalized;
    }

    /**
     * Parse concatenated values from EPS column (if EPS, AFP, ARL, Caja are in one cell)
     * Examples: "NUEVA E.P.S PORVENIR COLMENA COMFENALC 3"
     *           "EPS SURA (A COLPENSION COLMENA COMFENALC 3"
     *           "FOSYGA COLPENSION COLMENA COMFENALC 3"
     */
    private function parseConcatenatedValues(string $concatenated): ?array
    {
        if (empty($concatenated)) {
            return null;
        }

        $eps = '';
        $afp = '';
        $arl = '';
        $cajaCompensacion = '';

        // Common AFP names
        $afpPatterns = ['PORVENIR', 'COLPENSION', 'COLFONDOS', 'NINGUNA'];

        // Common ARL names
        $arlPatterns = ['COLMENA'];

        // Common Caja de Compensacion names
        $cajaPatterns = ['COMFENALC'];

        // Common EPS patterns (might be multi-word)
        $text = $concatenated;

        // Try to find patterns from end to beginning (Caja, ARL, AFP, then EPS is what's left)

        // Find Caja de Compensacion (usually ends with "COMFENALC 3" or "COMFENALC 4")
        if (preg_match('/\b(COMFENALC\s+\d+)\b/i', $text, $matches)) {
            $cajaCompensacion = trim($matches[1]);
            $text = preg_replace('/\b' . preg_quote($matches[1], '/') . '\b/i', '', $text);
        }

        // Find ARL (usually "COLMENA")
        if (preg_match('/\b(COLMENA)\b/i', $text, $matches)) {
            $arl = trim($matches[1]);
            $text = preg_replace('/\b' . preg_quote($matches[1], '/') . '\b/i', '', $text);
        }

        // Find AFP (PORVENIR, COLPENSION, COLFONDOS, or NINGUNA)
        foreach ($afpPatterns as $pattern) {
            if (preg_match('/\b(' . preg_quote($pattern, '/') . ')\b/i', $text, $matches)) {
                $afp = trim($matches[1]);
                $text = preg_replace('/\b' . preg_quote($matches[1], '/') . '\b/i', '', $text);
                break;
            }
        }

        // What's left should be EPS
        $eps = trim(preg_replace('/\s+/', ' ', $text));

        // If we successfully extracted at least ARL or AFP, return parsed values
        if (!empty($arl) || !empty($afp) || !empty($cajaCompensacion)) {
            return [
                'eps' => $eps ?: null,
                'afp' => $afp ?: null,
                'arl' => $arl ?: null,
                'caja_compensacion' => $cajaCompensacion ?: null,
            ];
        }

        return null;
    }

    /**
     * Extract date from a string (e.g., "BANCOLOME 2022-11-11")
     */
    private function extractDateFromString(string $value): ?string
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Validate credentials and get email for OTP (lightweight validation)
     * Returns array with email and nombre if valid, null otherwise
     */
    public function validateCredentialsAndGetEmail(
        string $tipoDocumento,
        string $documento,
        string $fechaExpedicion
    ): ?array {
        try {
            $excelPath = public_path(self::EXCEL_FILE_PATH);

            if (!file_exists($excelPath) || !is_readable($excelPath)) {
                Log::error('Archivo de afiliados no disponible para validación', ['path' => $excelPath]);
                return null;
            }

            $spreadsheet = IOFactory::load($excelPath);
            $informacionSheet = $spreadsheet->getSheetByName(self::SHEET_INFORMACION_GENERAL);
            
            if (!$informacionSheet) {
                Log::error('Pestaña INFORMACIÓN GENERAL no encontrada');
                return null;
            }

            // Find the affiliate row
            $afiliadoRow = $this->findAfiliadoRowOptimized(
                $informacionSheet,
                $tipoDocumento,
                $documento,
                $fechaExpedicion
            );

            if ($afiliadoRow === null) {
                return null;
            }

            // Extract only necessary fields
            $correo = $this->normalizeValue($afiliadoRow[self::COL_CORREO_PERSONAL] ?? '');
            $nombres = $this->normalizeValue($afiliadoRow[self::COL_NOMBRES] ?? '');
            $apellidos = $this->normalizeValue($afiliadoRow[self::COL_APELLIDOS] ?? '');

            // Check if email exists
            if (empty($correo)) {
                Log::warning('Afiliado encontrado pero sin correo electrónico', [
                    'documento' => $documento,
                ]);
                return null;
            }

            return [
                'correo' => $correo,
                'nombre' => trim(($nombres ?? '') . ' ' . ($apellidos ?? '')),
                'documento' => $this->normalizeValue($documento),
            ];

        } catch (\Throwable $e) {
            Log::error('Error al validar credenciales de afiliado', [
                'error' => $e->getMessage(),
                'tipo_documento' => $tipoDocumento,
                'documento' => $documento,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Get complete affiliate information including all details and convenios
     * This method returns full information without filtering
     */
    public function getCompleteAfiliadoInfo(
        string $tipoDocumento,
        string $documento,
        string $fechaExpedicion
    ): ?array {
        try {
            $excelPath = public_path(self::EXCEL_FILE_PATH);

            if (!file_exists($excelPath) || !is_readable($excelPath)) {
                Log::error('Archivo de afiliados no disponible', ['path' => $excelPath]);
                return null;
            }

            $spreadsheet = IOFactory::load($excelPath);
            $informacionSheet = $spreadsheet->getSheetByName(self::SHEET_INFORMACION_GENERAL);
            
            if (!$informacionSheet) {
                Log::error('Pestaña INFORMACIÓN GENERAL no encontrada');
                return null;
            }

            // Find the affiliate row
            $afiliadoRow = $this->findAfiliadoRowOptimized(
                $informacionSheet,
                $tipoDocumento,
                $documento,
                $fechaExpedicion
            );

            if ($afiliadoRow === null) {
                return null;
            }

            // Extract complete affiliate information
            $afiliadoFull = $this->extractAfiliadoInfo($afiliadoRow);

            // Get convenios for this affiliate
            $conveniosSheet = $spreadsheet->getSheetByName(self::SHEET_CONVENIOS);
            $conveniosFull = [];
            
            if ($conveniosSheet) {
                $conveniosFull = $this->getConveniosByDocumentoOptimized($conveniosSheet, $documento);
            }

            // Check for beneficiarios sheet (if exists in future)
            $beneficiarios = $this->getBeneficiariosByDocumento($spreadsheet, $documento);

            // Return complete information
            return [
                'afiliado' => $afiliadoFull,
                'convenios' => $conveniosFull,
                'beneficiarios' => $beneficiarios,
            ];

        } catch (\Throwable $e) {
            Log::error('Error al obtener información completa de afiliado', [
                'error' => $e->getMessage(),
                'tipo_documento' => $tipoDocumento,
                'documento' => $documento,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Get beneficiarios for a specific documento (if sheet exists)
     */
    private function getBeneficiariosByDocumento($spreadsheet, string $documento): array
    {
        try {
            // Check if BENEFICIARIOS sheet exists
            $beneficiariosSheet = $spreadsheet->getSheetByName(self::SHEET_BENEFICIARIOS);
            
            if (!$beneficiariosSheet) {
                Log::info('Pestaña BENEFICIARIOS no encontrada en el archivo Excel');
                return [];
            }

            // Get beneficiarios using optimized method
            return $this->getBeneficiariosByDocumentoOptimized($beneficiariosSheet, $documento);

        } catch (\Throwable $e) {
            Log::warning('Error al obtener beneficiarios', [
                'error' => $e->getMessage(),
                'documento' => $documento,
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Get beneficiarios by documento using optimized iteration (reads only matching rows)
     */
    private function getBeneficiariosByDocumentoOptimized($sheet, string $documento): array
    {
        $beneficiarios = [];
        $normalizedDocumento = $this->normalizeValue($documento);

        // Get highest row
        $highestRow = $sheet->getHighestRow();

        // Iterate through rows (skip header row at row 1)
        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            // Read only necessary columns for matching
            $colLetter = Coordinate::stringFromColumnIndex(self::COL_BEN_DOCUMENTO_AFILIADO + 1);
            $cell = $sheet->getCell($colLetter . $rowIndex);
            $rowDocumentoRaw = $this->getCellValue($cell);
            $rowDocumento = $this->normalizeValue($rowDocumentoRaw);

            // If document matches, read the full row
            if ($rowDocumento === $normalizedDocumento) {
                // Read all columns for this row (8 columns: Documento afiliado, Tipo documento, Documento, Nombres, Apellidos, Fecha nacimiento, Sexo, Notas)
                $row = [];
                for ($colIndex = 0; $colIndex < 8; $colIndex++) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                    $cell = $sheet->getCell($colLetter . $rowIndex);
                    $row[] = $this->getCellValue($cell);
                }

                // Skip if row appears empty
                if (empty(array_filter($row))) {
                    continue;
                }

                // Check if this looks like a header row
                $firstCol = $this->normalizeValue($row[0] ?? '');
                if ($firstCol && (
                    stripos($firstCol, 'documento afiliado') !== false ||
                    stripos($firstCol, 'documento') === 0 ||
                    stripos($firstCol, 'tipo documento') !== false
                )) {
                    continue;
                }

                $beneficiarios[] = [
                    'documento_afiliado' => $rowDocumento,
                    'tipo_documento' => $this->normalizeValue($row[self::COL_BEN_TIPO_DOCUMENTO] ?? ''),
                    'documento' => $this->normalizeValue($row[self::COL_BEN_DOCUMENTO] ?? ''),
                    'nombres' => $this->normalizeValue($row[self::COL_BEN_NOMBRES] ?? ''),
                    'apellidos' => $this->normalizeValue($row[self::COL_BEN_APELLIDOS] ?? ''),
                    'fecha_nacimiento' => $this->normalizeDate($row[self::COL_BEN_FECHA_NACIMIENTO] ?? ''),
                    'sexo' => $this->normalizeValue($row[self::COL_BEN_SEXO] ?? ''),
                    'parentesco' => $this->normalizeValue($row[self::COL_BEN_NOTAS_PARENTESCO] ?? ''),
                ];
            }
        }

        return $beneficiarios;
    }

    /**
     * Check if the Excel file exists and is readable
     */
    public function isFileAvailable(): bool
    {
        $excelPath = public_path(self::EXCEL_FILE_PATH);
        return file_exists($excelPath) && is_readable($excelPath);
    }
}
