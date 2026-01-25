<?php

namespace App\Console\Commands;

use App\Services\ConvenioGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\{IOFactory, Cell\Coordinate};

class GenerateConveniosFromExcelCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'convenios:generate-from-excel
                            {--limit= : Limitar número de filas a procesar}
                            {--skip= : Número de filas a saltar}
                            {--dry-run : Mostrar qué se procesaría sin generar archivos}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera convenios automáticamente desde el archivo Excel InfoConvenios.xlsx';

    /**
     * Mapeo de nombres de columnas del Excel (case-insensitive)
     */
    private const COLUMN_MAPPINGS = [
        'numero_documento' => ['num cedula', 'numero cedula', 'cedula', 'documento', 'num_cedula', 'numero_cedula', 'num cedula apellidos', 'numero documento'],
        'apellidos' => ['apellidos'],
        'nombres' => ['nombres'],
        'proceso' => ['cargo', 'proceso'],
        'ciudad' => ['ciudad'],
        'sede' => ['sede'],
        'fecha_inicio' => ['f.inicio', 'fecha de inicio', 'fecha_inicio', 'fecha inicio', 'fecha'],
        'fecha_finalizacion' => ['fecha de finalizacion', 'fecha de finalización', 'fecha_finalizacion', 'fecha finalizacion', 'fecha finalización'],
        'direccion' => ['direccion', 'dirección'],
        'telefono' => ['telefono', 'teléfono'],
        'celular' => ['celular'],
        'compensacion_basica_redactada' => ['compensacion basica redactada', 'compensación básica redactada', 'compensacion_basica_redactada', 'compensacion basica'],
        'nombre_archivo' => ['nombre archivo', 'nombre_archivo'],
    ];

    /**
     * Execute the console command.
     */
    public function handle(ConvenioGenerationService $convenioService): int
    {
        $startTime = microtime(true);
        
        Log::info('[CONVENIO EXCEL COMMAND] Iniciando generación de convenios desde Excel', [
            'limit' => $this->option('limit'),
            'skip' => $this->option('skip'),
            'dry_run' => $this->option('dry-run'),
        ]);

        $this->info('🚀 Generación de Convenios desde Excel');
        $this->line('=====================================');
        $this->line('');

        $excelPath = base_path('resources/templates/InfoConvenios.xlsx');

        if (!file_exists($excelPath)) {
            Log::error('[CONVENIO EXCEL COMMAND] Archivo Excel no encontrado', [
                'excel_path' => $excelPath,
            ]);
            $this->error("❌ Archivo Excel no encontrado en: {$excelPath}");
            return Command::FAILURE;
        }

        Log::info('[CONVENIO EXCEL COMMAND] Archivo Excel encontrado', [
            'excel_path' => $excelPath,
            'file_size' => filesize($excelPath),
        ]);

        $this->info("📄 Leyendo archivo: {$excelPath}");
        
        try {
            $reader = IOFactory::createReader('Xlsx');
            if (method_exists($reader, 'setReadDataOnly')) {
                $reader->setReadDataOnly(true);
            }

            Log::debug('[CONVENIO EXCEL COMMAND] Cargando archivo Excel', [
                'excel_path' => $excelPath,
            ]);

            $spreadsheet = $reader->load($excelPath);
            $sheet = $spreadsheet->getActiveSheet();

            // Construir mapeo de columnas
            Log::debug('[CONVENIO EXCEL COMMAND] Construyendo mapeo de columnas');
            $columnMapping = $this->buildColumnMapping($sheet);
            
            if (empty($columnMapping)) {
                Log::error('[CONVENIO EXCEL COMMAND] No se encontraron columnas válidas');
                $this->error('❌ No se encontraron columnas válidas en el Excel');
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
                return Command::FAILURE;
            }

            Log::info('[CONVENIO EXCEL COMMAND] Mapeo de columnas construido', [
                'columnas_encontradas' => array_keys($columnMapping),
                'total_columnas' => count($columnMapping),
            ]);

            $this->info('✅ Columnas encontradas: ' . implode(', ', array_keys($columnMapping)));
            $this->line('');

            // Validar columnas requeridas
            $requiredColumns = ['numero_documento', 'apellidos', 'nombres'];
            $missingColumns = [];
            foreach ($requiredColumns as $col) {
                if (!isset($columnMapping[$col])) {
                    $missingColumns[] = $col;
                }
            }

            if (!empty($missingColumns)) {
                Log::error('[CONVENIO EXCEL COMMAND] Columnas requeridas no encontradas', [
                    'columnas_faltantes' => $missingColumns,
                    'columnas_encontradas' => array_keys($columnMapping),
                ]);
                $this->error('❌ Columnas requeridas no encontradas: ' . implode(', ', $missingColumns));
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
                return Command::FAILURE;
            }

            // Procesar filas
            $highestRow = $sheet->getHighestRow();
            $skip = (int) $this->option('skip');
            $limit = $this->option('limit') ? (int) $this->option('limit') : null;
            $dryRun = $this->option('dry-run');
            $startRow = 2 + $skip; // Saltar header y filas especificadas
            $endRow = $limit ? min($startRow + $limit - 1, $highestRow) : $highestRow;

            Log::info('[CONVENIO EXCEL COMMAND] Iniciando procesamiento de filas', [
                'total_filas' => $highestRow,
                'fila_inicio' => $startRow,
                'fila_fin' => $endRow,
                'filas_a_procesar' => $endRow - $startRow + 1,
                'skip' => $skip,
                'limit' => $limit,
                'dry_run' => $dryRun,
            ]);

            $this->info("📊 Procesando filas {$startRow} a {$endRow} de {$highestRow} totales");
            if ($dryRun) {
                $this->warn('⚠️  MODO DRY-RUN: No se generarán archivos');
            }
            $this->line('');

            $procesados = 0;
            $exitosos = 0;
            $errores = 0;
            $filasVacias = 0;

            $progressBar = $this->output->createProgressBar($endRow - $startRow + 1);
            $progressBar->start();

            for ($rowIndex = $startRow; $rowIndex <= $endRow; $rowIndex++) {
                $procesados++;
                
                try {
                    Log::debug('[CONVENIO EXCEL COMMAND] Procesando fila', [
                        'fila' => $rowIndex,
                        'total_procesadas' => $procesados,
                    ]);

                    $rowData = $this->readRowData($sheet, $rowIndex, $columnMapping);
                    
                    // Saltar filas vacías
                    if (empty($rowData['numero_documento']) && empty($rowData['apellidos']) && empty($rowData['nombres'])) {
                        $filasVacias++;
                        Log::debug('[CONVENIO EXCEL COMMAND] Fila vacía, saltando', [
                            'fila' => $rowIndex,
                        ]);
                        $progressBar->advance();
                        continue;
                    }

                    Log::debug('[CONVENIO EXCEL COMMAND] Datos de fila leídos', [
                        'fila' => $rowIndex,
                        'documento' => $rowData['numero_documento'] ?? null,
                        'apellidos' => $rowData['apellidos'] ?? null,
                        'nombres' => $rowData['nombres'] ?? null,
                        'proceso' => $rowData['proceso'] ?? null,
                        'ciudad' => $rowData['ciudad'] ?? null,
                        'sede' => $rowData['sede'] ?? null,
                        'fecha_inicio' => $rowData['fecha_inicio'] ?? null,
                        'fecha_finalizacion' => $rowData['fecha_finalizacion'] ?? null,
                        'tiene_fecha_inicio' => !empty($rowData['fecha_inicio'] ?? null),
                        'tiene_fecha_finalizacion' => !empty($rowData['fecha_finalizacion'] ?? null),
                        'compensacion_basica_redactada' => !empty($rowData['compensacion_basica_redactada'] ?? null) ? 'presente' : 'vacío',
                    ]);

                    if ($dryRun) {
                        $this->line("\n📝 Fila {$rowIndex}: {$rowData['apellidos']} {$rowData['nombres']} - {$rowData['numero_documento']}");
                        Log::info('[CONVENIO EXCEL COMMAND] DRY-RUN: Fila procesada', [
                            'fila' => $rowIndex,
                            'documento' => $rowData['numero_documento'] ?? null,
                            'nombre_completo' => trim(($rowData['apellidos'] ?? '') . ' ' . ($rowData['nombres'] ?? '')),
                        ]);
                    } else {
                        $resultado = $convenioService->generarConvenio($rowData);
                        $exitosos++;
                        $this->line("\n✅ Generado: {$resultado['nombre']}");
                        Log::info('[CONVENIO EXCEL COMMAND] Convenio generado exitosamente', [
                            'fila' => $rowIndex,
                            'documento' => $rowData['numero_documento'] ?? null,
                            'nombre_archivo' => $resultado['nombre'],
                            'ruta' => $resultado['ruta'],
                        ]);
                    }
                } catch (\Exception $e) {
                    $errores++;
                    $this->line("\n❌ Error en fila {$rowIndex}: " . $e->getMessage());
                    Log::error('[CONVENIO EXCEL COMMAND] Error generando convenio desde Excel', [
                        'fila' => $rowIndex,
                        'documento' => $rowData['numero_documento'] ?? null,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->line('');
            $this->line('');

            $elapsedTime = round(microtime(true) - $startTime, 2);

            // Resumen
            $this->info('📈 Resumen:');
            $this->line("   Procesados: {$procesados}");
            if (!$dryRun) {
                $this->line("   Exitosos: {$exitosos}");
            }
            $this->line("   Errores: {$errores}");
            $this->line("   Filas vacías: {$filasVacias}");
            $this->line("   Tiempo: {$elapsedTime}s");

            Log::info('[CONVENIO EXCEL COMMAND] Procesamiento completado', [
                'procesados' => $procesados,
                'exitosos' => $exitosos,
                'errores' => $errores,
                'filas_vacias' => $filasVacias,
                'tiempo_segundos' => $elapsedTime,
                'dry_run' => $dryRun,
            ]);

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            Log::info('[CONVENIO EXCEL COMMAND] Comando ejecutado exitosamente');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $elapsedTime = round(microtime(true) - $startTime, 2);
            $this->error('❌ Error procesando Excel: ' . $e->getMessage());
            Log::error('[CONVENIO EXCEL COMMAND] Error procesando Excel para generación de convenios', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tiempo_segundos' => $elapsedTime,
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Prioridad de columnas para campos que pueden tener múltiples mapeos
     * El orden indica la prioridad (primero = mayor prioridad)
     */
    private const COLUMN_PRIORITIES = [
        'fecha_inicio' => ['f.inicio', 'fecha de inicio', 'fecha'],
    ];

    /**
     * Construye el mapeo de columnas del Excel
     * Almacena todas las columnas posibles para cada campo para poder priorizar
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @return array Mapeo de nombre interno => array de índices de columna (0-based) o índice único
     */
    private function buildColumnMapping($sheet): array
    {
        $mapping = [];
        $allMappings = []; // Almacena todos los mapeos posibles para cada campo
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        Log::debug('[CONVENIO EXCEL COMMAND] Construyendo mapeo de columnas', [
            'total_columnas' => $highestColumnIndex,
        ]);

        // Leer fila de encabezados (fila 1)
        for ($colIndex = 1; $colIndex <= $highestColumnIndex; $colIndex++) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            $cell = $sheet->getCell($colLetter . '1');
            $headerValue = $this->getCellValue($cell);
            $normalizedHeader = $this->normalizeColumnName($headerValue);

            if (empty($normalizedHeader)) {
                continue;
            }

            // Buscar en los mapeos
            foreach (self::COLUMN_MAPPINGS as $internalName => $possibleNames) {
                foreach ($possibleNames as $possibleName) {
                    $normalizedPossible = $this->normalizeColumnName($possibleName);
                    
                    if ($normalizedHeader === $normalizedPossible) {
                        // Almacenar todos los mapeos posibles
                        if (!isset($allMappings[$internalName])) {
                            $allMappings[$internalName] = [];
                        }
                        $allMappings[$internalName][] = [
                            'index' => $colIndex - 1, // 0-based index
                            'letter' => $colLetter,
                            'header' => $headerValue,
                            'normalized' => $normalizedHeader,
                            'priority' => $this->getColumnPriority($internalName, $normalizedHeader),
                        ];
                        Log::debug('[CONVENIO EXCEL COMMAND] Columna mapeada', [
                            'columna_excel' => $colLetter,
                            'header_original' => $headerValue,
                            'header_normalizado' => $normalizedHeader,
                            'nombre_interno' => $internalName,
                            'indice' => $colIndex - 1,
                        ]);
                        break 2; // Salir de ambos loops
                    }
                }
            }
        }

        // Seleccionar la columna con mayor prioridad para cada campo
        // Para campos con múltiples columnas, preferir la primera encontrada (más a la izquierda)
        foreach ($allMappings as $internalName => $columns) {
            if (count($columns) === 1) {
                $mapping[$internalName] = $columns[0]['index'];
            } else {
                // Ordenar por prioridad (mayor prioridad primero), luego por índice (más a la izquierda primero)
                usort($columns, function ($a, $b) {
                    // Primero por prioridad
                    $priorityCompare = $b['priority'] <=> $a['priority'];
                    if ($priorityCompare !== 0) {
                        return $priorityCompare;
                    }
                    // Si tienen la misma prioridad, usar la que está más a la izquierda (menor índice)
                    return $a['index'] <=> $b['index'];
                });
                $mapping[$internalName] = $columns[0]['index'];
                Log::info('[CONVENIO EXCEL COMMAND] Múltiples columnas encontradas, usando la de mayor prioridad', [
                    'campo' => $internalName,
                    'columna_seleccionada' => $columns[0]['letter'] . ' (' . $columns[0]['header'] . ')',
                    'indice_seleccionado' => $columns[0]['index'],
                    'todas_las_columnas' => array_map(function($c) {
                        return $c['letter'] . ' (' . $c['header'] . ', índice: ' . $c['index'] . ', prioridad: ' . $c['priority'] . ')';
                    }, $columns),
                ]);
            }
        }

        Log::info('[CONVENIO EXCEL COMMAND] Mapeo de columnas completado', [
            'total_mapeadas' => count($mapping),
            'columnas' => array_keys($mapping),
        ]);

        return $mapping;
    }

    /**
     * Obtiene la prioridad de una columna para un campo específico
     *
     * @param string $internalName Nombre interno del campo
     * @param string $normalizedHeader Header normalizado de la columna
     * @return int Prioridad (mayor número = mayor prioridad)
     */
    private function getColumnPriority(string $internalName, string $normalizedHeader): int
    {
        if (!isset(self::COLUMN_PRIORITIES[$internalName])) {
            return 0; // Sin prioridad especial
        }

        $priorities = self::COLUMN_PRIORITIES[$internalName];
        
        // Normalizar cada prioridad y buscar coincidencia
        foreach ($priorities as $index => $priorityName) {
            $normalizedPriority = $this->normalizeColumnName($priorityName);
            if ($normalizedHeader === $normalizedPriority) {
                // Prioridad inversa: el primero en la lista tiene mayor prioridad
                return count($priorities) - $index;
            }
        }

        return 0;
    }

    /**
     * Lee los datos de una fila del Excel
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param int $rowIndex Índice de la fila (1-based)
     * @param array $columnMapping Mapeo de columnas
     * @return array Datos de la fila
     */
    private function readRowData($sheet, int $rowIndex, array $columnMapping): array
    {
        $data = [];

        foreach ($columnMapping as $internalName => $colIndex) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
            $cell = $sheet->getCell($colLetter . $rowIndex);
            $value = $this->getCellValue($cell);
            
            // Procesar fechas si es necesario
            if (in_array($internalName, ['fecha_inicio', 'fecha_finalizacion'])) {
                $valueOriginal = $value;
                $value = $this->normalizeDate($value);
                
                Log::debug('[CONVENIO EXCEL COMMAND] Fecha normalizada', [
                    'campo' => $internalName,
                    'fila' => $rowIndex,
                    'columna' => $colLetter,
                    'valor_original' => $valueOriginal,
                    'valor_normalizado' => $value,
                ]);
            }

            $data[$internalName] = $value;
        }

        // Log de datos leídos para debugging
        Log::debug('[CONVENIO EXCEL COMMAND] Datos completos de fila leídos', [
            'fila' => $rowIndex,
            'datos' => $data,
            'fecha_inicio_valor' => $data['fecha_inicio'] ?? 'NO_ENCONTRADO',
            'fecha_finalizacion_valor' => $data['fecha_finalizacion'] ?? 'NO_ENCONTRADO',
            'fecha_inicio_vacio' => empty($data['fecha_inicio'] ?? null),
            'fecha_finalizacion_vacio' => empty($data['fecha_finalizacion'] ?? null),
        ]);

        return $data;
    }

    /**
     * Normaliza el nombre de una columna para comparación (case-insensitive, sin espacios extra)
     *
     * @param string $name Nombre de la columna
     * @return string Nombre normalizado
     */
    private function normalizeColumnName(string $name): string
    {
        // Convertir a minúsculas, remover acentos, espacios extra y caracteres especiales
        $name = mb_strtolower($name, 'UTF-8');
        $name = $this->removeAccents($name);
        $name = preg_replace('/\s+/', ' ', $name); // Normalizar espacios
        $name = trim($name);
        
        return $name;
    }

    /**
     * Remueve acentos de una cadena
     *
     * @param string $string Cadena con acentos
     * @return string Cadena sin acentos
     */
    private function removeAccents(string $string): string
    {
        $accents = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'n', 'Ñ' => 'N',
        ];

        return strtr($string, $accents);
    }

    /**
     * Obtiene el valor de una celda de Excel
     *
     * @param mixed $cell Celda de Excel
     * @return string Valor de la celda
     */
    private function getCellValue($cell): string
    {
        $value = $cell->getValue();
        
        if ($value === null) {
            return '';
        }

        // Si es una fórmula calculada, obtener el valor calculado
        if ($cell->getDataType() === 'f') {
            $value = $cell->getCalculatedValue();
        }

        return trim((string) $value);
    }

    /**
     * Normaliza una fecha desde Excel
     * Maneja múltiples formatos: Excel numérico, DD/MM/YYYY, DD/mes/YYYY, etc.
     *
     * @param mixed $value Valor de la celda
     * @return string Fecha normalizada (Y-m-d) o cadena vacía
     */
    private function normalizeDate($value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            // Intentar parsear como fecha Excel (número serial)
            if (is_numeric($value)) {
                $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);
                return $date->format('Y-m-d');
            }

            $valueStr = trim((string) $value);
            
            // Intentar parsear formatos comunes
            // Formato: DD/MM/YYYY o DD/MM/YY
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $valueStr, $matches)) {
                $day = (int) $matches[1];
                $month = (int) $matches[2];
                $year = (int) $matches[3];
                
                // Si el año tiene 2 dígitos, asumir 2000-2099
                if ($year < 100) {
                    $year += 2000;
                }
                
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            // Formato: DD/mes/YYYY (ej: 01/ene/2026)
            $meses = [
                'ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4,
                'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8,
                'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12
            ];
            
            if (preg_match('/^(\d{1,2})\/([a-z]{3})\/(\d{4})$/i', $valueStr, $matches)) {
                $day = (int) $matches[1];
                $mesNombre = strtolower($matches[2]);
                $year = (int) $matches[3];
                
                if (isset($meses[$mesNombre])) {
                    return sprintf('%04d-%02d-%02d', $year, $meses[$mesNombre], $day);
                }
            }

            // Intentar parsear con Carbon (maneja muchos formatos)
            $carbon = \Carbon\Carbon::parse($valueStr);
            return $carbon->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning('[CONVENIO EXCEL COMMAND] Error normalizando fecha', [
                'valor_original' => $value,
                'tipo' => gettype($value),
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }
}

