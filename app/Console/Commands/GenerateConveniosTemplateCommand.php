<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill, Font};
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GenerateConveniosTemplateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'convenios:generate-template
                            {--output= : Ruta donde guardar la plantilla (default: resources/templates/Plantilla_InfoConvenios.xlsx)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera una plantilla Excel para la creación masiva de convenios';

    /**
     * Columnas de la plantilla con sus definiciones
     */
    private const COLUMNS = [
        [
            'name' => 'CEDULA',
            'required' => true,
            'description' => 'Número de documento de identidad del afiliado (sin puntos ni comas)',
            'example' => '1152467606',
            'width' => 15,
        ],
        [
            'name' => 'APELLIDOS',
            'required' => true,
            'description' => 'Apellidos del afiliado',
            'example' => 'OSPINA SUAREZ',
            'width' => 25,
        ],
        [
            'name' => 'NOMBRES',
            'required' => true,
            'description' => 'Nombres del afiliado',
            'example' => 'ALEXANDRA',
            'width' => 25,
        ],
        [
            'name' => 'HOSPITAL',
            'required' => true,
            'description' => 'Nombre del hospital o entidad',
            'example' => 'HLM - GRUPO 1',
            'width' => 20,
        ],
        [
            'name' => 'CARGO',
            'required' => true,
            'description' => 'Cargo o proceso que desempeña el afiliado',
            'example' => 'AUXILIAR DE ENFERMERIA - PISO',
            'width' => 35,
        ],
        [
            'name' => 'FECHA DE INICIO',
            'required' => true,
            'description' => 'Fecha de inicio del convenio (formato: DD/MM/YYYY, DD/mes/YYYY o número de Excel)',
            'example' => '01/01/2026',
            'width' => 18,
        ],
        [
            'name' => 'CORREO',
            'required' => true,
            'description' => 'Correo electrónico del afiliado',
            'example' => 'alexaospinasuarez@hotmail.com',
            'width' => 30,
        ],
        [
            'name' => 'SEDE',
            'required' => true,
            'description' => 'Sede donde se desempeña el afiliado',
            'example' => 'E.S.E. Hospital La Maria - Medellín (Ant)',
            'width' => 40,
        ],
        [
            'name' => 'CIUDAD',
            'required' => true,
            'description' => 'Ciudad donde se desempeña el afiliado',
            'example' => 'Medellín (Ant)',
            'width' => 20,
        ],
        [
            'name' => 'FECHA DE FINALIZACION',
            'required' => true,
            'description' => 'Fecha de finalización del convenio (formato: DD/MM/YYYY). Si se deja vacío, se usará el mensaje de duración por defecto',
            'example' => '30/04/2026',
            'width' => 22,
        ],
        [
            'name' => 'DIRECCION',
            'required' => false,
            'description' => 'Dirección del afiliado. Si se deja vacío, se buscará en el archivo de afiliados',
            'example' => 'Calle 123 #45-67',
            'width' => 35,
        ],
        [
            'name' => 'TELEFONO',
            'required' => false,
            'description' => 'Teléfono fijo del afiliado. Si se deja vacío, se buscará en el archivo de afiliados',
            'example' => '6041234567',
            'width' => 15,
        ],
        [
            'name' => 'CELULAR',
            'required' => false,
            'description' => 'Número de celular del afiliado. Si se deja vacío, se buscará en el archivo de afiliados',
            'example' => '3001234567',
            'width' => 15,
        ],
        [
            'name' => 'COMPENSACION BASICA REDACTADA',
            'required' => true,
            'description' => 'Texto descriptivo de la compensación básica y auxilios',
            'example' => 'Hora Diurna $9,600; Hora Nocturna $12,960...',
            'width' => 60,
        ],
        [
            'name' => 'NOMBRE ARCHIVO',
            'required' => false,
            'description' => 'Nombre personalizado para el archivo generado (sin extensión). Si se deja vacío, se generará automáticamente',
            'example' => 'HLM-ASIS - OSPINA SUAREZ ALEXANDRA - 1152467606.pdf',
            'width' => 50,
        ],
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('📋 Generando plantilla Excel para convenios');
        $this->line('==========================================');
        $this->line('');

        $outputPath = $this->option('output') 
            ? base_path($this->option('output'))
            : base_path('resources/templates/Plantilla_InfoConvenios.xlsx');

        try {
            $spreadsheet = new Spreadsheet();

            // Crear hoja de plantilla
            $templateSheet = $spreadsheet->getActiveSheet();
            $templateSheet->setTitle('Plantilla');

            // Crear hoja de instructivo
            $instructiveSheet = $spreadsheet->createSheet();
            $instructiveSheet->setTitle('Instructivo');

            // Generar hoja de plantilla
            $this->generateTemplateSheet($templateSheet);

            // Generar hoja de instructivo
            $this->generateInstructiveSheet($instructiveSheet);

            // Guardar archivo
            $writer = new Xlsx($spreadsheet);
            $writer->save($outputPath);

            $this->info("✅ Plantilla generada exitosamente en: {$outputPath}");
            $this->line('');
            $this->line("📊 Columnas incluidas: " . count(self::COLUMNS));
            $this->line("📝 Hojas creadas: Plantilla, Instructivo");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Error generando plantilla: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Genera la hoja de plantilla con las columnas
     */
    private function generateTemplateSheet(Worksheet $sheet): void
    {
        $row = 1;

        // Título
        $sheet->setCellValue('A1', 'PLANTILLA PARA GENERACIÓN MASIVA DE CONVENIOS');
        $sheet->mergeCells('A1:' . $this->getColumnLetter(count(self::COLUMNS)) . '1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 16,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $row = 3;

        // Encabezados de columnas
        $colIndex = 1;
        foreach (self::COLUMNS as $column) {
            $colLetter = $this->getColumnLetter($colIndex);
            $cell = $sheet->getCell($colLetter . $row);

            $cell->setValue($column['name']);
            
            // Estilo para encabezados
            $style = [
                'font' => [
                    'bold' => true,
                    'size' => 11,
                    'color' => ['rgb' => $column['required'] ? 'FFFFFF' : '000000'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => $column['required'] ? 'C00000' : 'FFC000'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ];

            $sheet->getStyle($colLetter . $row)->applyFromArray($style);
            $sheet->getColumnDimension($colLetter)->setWidth($column['width']);

            // Agregar indicador de requerido/opcional
            $indicatorCell = $sheet->getCell($colLetter . ($row + 1));
            $indicatorCell->setValue($column['required'] ? 'REQUERIDO' : 'OPCIONAL');
            $sheet->getStyle($colLetter . ($row + 1))->applyFromArray([
                'font' => [
                    'bold' => true,
                    'size' => 9,
                    'color' => ['rgb' => $column['required'] ? 'C00000' : '0070C0'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                ],
            ]);

            $colIndex++;
        }

        $row = 5;

        // Fila de ejemplo
        $colIndex = 1;
        foreach (self::COLUMNS as $column) {
            $colLetter = $this->getColumnLetter($colIndex);
            $sheet->setCellValue($colLetter . $row, $column['example']);
            
            $sheet->getStyle($colLetter . $row)->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E7E6E6'],
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
                'alignment' => [
                    'wrapText' => true,
                ],
            ]);

            $colIndex++;
        }

        // Congelar paneles (mantener encabezados visibles)
        $sheet->freezePane('A5');

        // Ajustar altura de filas
        $sheet->getRowDimension(3)->setRowHeight(40);
        $sheet->getRowDimension(4)->setRowHeight(20);
        $sheet->getRowDimension(5)->setRowHeight(30);
    }

    /**
     * Genera la hoja de instructivo
     */
    private function generateInstructiveSheet(Worksheet $sheet): void
    {
        $row = 1;

        // Título
        $sheet->setCellValue('A1', 'INSTRUCTIVO - GENERACIÓN MASIVA DE CONVENIOS');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 16,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(60);
        $sheet->getColumnDimension('D')->setWidth(30);

        $row = 3;

        // Información general
        $this->addSection($sheet, $row, 'INFORMACIÓN GENERAL', [
            'Este archivo Excel permite generar convenios de forma masiva.',
            'Complete las columnas según se indica a continuación.',
            'Los campos marcados como REQUERIDO son obligatorios.',
            'Los campos marcados como OPCIONAL pueden dejarse vacíos.',
        ]);
        $row += 5;

        // Campos requeridos
        $requiredFields = array_filter(self::COLUMNS, fn($col) => $col['required']);
        $this->addSection($sheet, $row, 'CAMPOS REQUERIDOS', [
            'Los siguientes campos son obligatorios y deben estar completos:',
            '',
            ...array_map(fn($col) => "• {$col['name']}: {$col['description']}", $requiredFields),
        ]);
        $row += count($requiredFields) + 3;

        // Campos opcionales
        $optionalFields = array_filter(self::COLUMNS, fn($col) => !$col['required']);
        $this->addSection($sheet, $row, 'CAMPOS OPCIONALES', [
            'Los siguientes campos son opcionales:',
            '',
            ...array_map(fn($col) => "• {$col['name']}: {$col['description']}", $optionalFields),
        ]);
        $row += count($optionalFields) + 3;

        // Notas importantes
        $this->addSection($sheet, $row, 'NOTAS IMPORTANTES', [
            '1. FORMATO DE FECHAS:',
            '   - Puede usar formato DD/MM/YYYY (ej: 01/01/2026)',
            '   - O formato DD/mes/YYYY (ej: 01/ene/2026)',
            '   - O número serial de Excel',
            '',
            '2. BÚSQUEDA AUTOMÁTICA:',
            '   - Si DIRECCION, TELEFONO o CELULAR están vacíos,',
            '     el sistema buscará estos datos en el archivo de afiliados.',
            '   - Si no se encuentran, se dejará una línea para llenar manualmente.',
            '',
            '3. FECHA DE FINALIZACIÓN:',
            '   - Si se completa, el convenio mostrará el rango de fechas.',
            '   - Si se deja vacío, se usará el mensaje de duración por defecto.',
            '',
            '4. NOMBRE DE ARCHIVO:',
            '   - Si se deja vacío, se generará automáticamente con el formato:',
            '     Convenio_{documento}_{apellidos}_{nombres}.docx',
            '',
            '5. GENERACIÓN:',
            '   - Use el comando: php artisan convenios:generate-from-excel',
            '   - Los archivos se generarán en: resources/convenios/',
        ]);

        // Ajustar altura de filas automáticamente
        for ($i = 1; $i <= $sheet->getHighestRow(); $i++) {
            $sheet->getRowDimension($i)->setRowHeight(-1); // Auto-height
        }
    }

    /**
     * Agrega una sección al instructivo
     */
    private function addSection(Worksheet $sheet, int &$row, string $title, array $content): void
    {
        // Título de sección
        $sheet->setCellValue('A' . $row, $title);
        $sheet->mergeCells('A' . $row . ':D' . $row);
        $sheet->getStyle('A' . $row)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 14,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '70AD47'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $row++;

        // Contenido
        foreach ($content as $line) {
            $sheet->setCellValue('A' . $row, $line);
            $sheet->mergeCells('A' . $row . ':D' . $row);
            $sheet->getStyle('A' . $row)->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_TOP,
                    'wrapText' => true,
                ],
            ]);
            $row++;
        }

        // Espacio reducido entre secciones
        $row += 1;
    }

    /**
     * Obtiene la letra de columna para un índice (1-based)
     */
    private function getColumnLetter(int $colIndex): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
    }
}

