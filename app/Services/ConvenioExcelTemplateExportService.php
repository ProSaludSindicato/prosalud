<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ConvenioExcelTemplateExportService
{
    /**
     * Genera una plantilla Excel para importación masiva de convenios
     *
     * @return string Ruta temporal del archivo generado
     */
    public function generateTemplate(): string
    {
        Log::info('[CONVENIO TEMPLATE EXPORT] Generando plantilla Excel para importación masiva');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Convenios');

        // Definir columnas con sus encabezados y descripciones
        $columns = $this->getColumnDefinitions();

        // Establecer encabezados
        $headerRow = 1;
        $colIndex = 1;

        foreach ($columns as $column) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue($colLetter.$headerRow, $column['header']);
            $colIndex++;
        }

        // Aplicar formato a los encabezados
        $headerRange = 'A1:'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns)).'1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 11,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        // Agregar comentarios/notas en los encabezados
        $colIndex = 1;
        foreach ($columns as $column) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
            if (! empty($column['description'])) {
                $sheet->getComment($colLetter.$headerRow)->getText()->createTextRun($column['description']);
            }
            $colIndex++;
        }

        // Ajustar ancho de columnas
        foreach (range(1, count($columns)) as $col) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        // Congelar primera fila (encabezados)
        $sheet->freezePane('A2');

        // Aplicar validación tipo checkbox a la columna "Enviar Email"
        $this->addCheckboxValidation($sheet, $columns);

        // Guardar en archivo temporal
        $tempPath = tempnam(sys_get_temp_dir(), 'convenio_template_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        Log::info('[CONVENIO TEMPLATE EXPORT] Plantilla Excel generada exitosamente', [
            'temp_path' => $tempPath,
            'total_columnas' => count($columns),
        ]);

        return $tempPath;
    }

    /**
     * Define las columnas de la plantilla con sus metadatos
     */
    private function getColumnDefinitions(): array
    {
        return [
            // Campos requeridos
            [
                'header' => 'Numero Documento',
                'field' => 'numero_documento',
                'description' => 'Número de documento del afiliado (requerido)',
                'example' => '1007223190',
                'required' => true,
            ],
            [
                'header' => 'Apellidos',
                'field' => 'apellidos',
                'description' => 'Apellidos del afiliado (requerido)',
                'example' => 'GALLEGO VASQUEZ',
                'required' => true,
            ],
            [
                'header' => 'Nombres',
                'field' => 'nombres',
                'description' => 'Nombres del afiliado (requerido)',
                'example' => 'LESLY NATALIA',
                'required' => true,
            ],
            [
                'header' => 'Fecha Nacimiento',
                'field' => 'fecha_nacimiento',
                'description' => 'Fecha de nacimiento en formato YYYY-MM-DD (requerido)',
                'example' => '1990-05-15',
                'required' => true,
            ],
            [
                'header' => 'Lugar Nacimiento',
                'field' => 'lugar_nacimiento',
                'description' => 'Lugar de nacimiento del afiliado (requerido)',
                'example' => 'MEDELLÍN',
                'required' => true,
            ],

            // Campos requeridos del afiliado y convenio
            [
                'header' => 'Proceso',
                'field' => 'proceso',
                'description' => 'Proceso o cargo del afiliado (requerido)',
                'example' => 'AUXILIAR DE ENFERMERIA - PISO - CIRUGIA',
                'required' => true,
            ],
            [
                'header' => 'Ciudad',
                'field' => 'ciudad',
                'description' => 'Ciudad donde se desempeña el afiliado (requerido)',
                'example' => 'MEDELLÍN (ANT)',
                'required' => true,
            ],
            [
                'header' => 'Sede',
                'field' => 'sede',
                'description' => 'Sede donde se desempeña el afiliado (requerido)',
                'example' => 'E.S.E. Hospital La Maria - Medellín (Ant)',
                'required' => true,
            ],
            [
                'header' => 'Fecha Inicio',
                'field' => 'fecha_inicio',
                'description' => 'Fecha de inicio del convenio en formato YYYY-MM-DD (requerido)',
                'example' => '2026-01-01',
                'required' => true,
            ],
            [
                'header' => 'Fecha Finalizacion',
                'field' => 'fecha_finalizacion',
                'description' => 'Fecha de finalización del convenio (YYYY-MM-DD)',
                'example' => '2026-12-31',
            ],
            [
                'header' => 'Direccion',
                'field' => 'direccion',
                'description' => 'Dirección de residencia del afiliado (requerido)',
                'example' => 'Calle 123 #45-67',
                'required' => true,
            ],
            [
                'header' => 'Celular',
                'field' => 'celular',
                'description' => 'Número de celular del afiliado (requerido)',
                'example' => '3001234567',
                'required' => true,
            ],

            // Compensación básica redactada (requerido O valores individuales)
            [
                'header' => 'Compensacion Basica Redactada',
                'field' => 'compensacion_basica_redactada',
                'description' => 'Texto completo de la compensación básica. REQUERIDO si no se proporcionan valores individuales de compensación. NO se puede usar junto con valores individuales.',
                'example' => '',
            ],

            // Campos de compensación
            [
                'header' => 'Basico',
                'field' => 'basico',
                'description' => 'Valor del salario básico',
                'example' => '1972038',
            ],
            [
                'header' => 'Auxilios',
                'field' => 'auxilios',
                'description' => 'Valor total de auxilios (auxilio no constitutivo de compensación básica)',
                'example' => '249095',
            ],
            [
                'header' => 'Manutencion',
                'field' => 'manutencion',
                'description' => 'Valor de manutención',
                'example' => '',
            ],
            [
                'header' => 'Provisiones',
                'field' => 'provisiones',
                'description' => 'Valor de provisiones (devolución de compensaciones)',
                'example' => '',
            ],
            [
                'header' => 'Horas',
                'field' => 'horas',
                'description' => 'Número de horas (por defecto 186 si no se especifica)',
                'example' => '186',
            ],
            [
                'header' => 'Valor Hora Diurna',
                'field' => 'valor_hora_diurna',
                'description' => 'Valor por hora diurna',
                'example' => '9600',
            ],
            [
                'header' => 'Valor Hora Nocturna',
                'field' => 'valor_hora_nocturna',
                'description' => 'Valor por hora nocturna',
                'example' => '12960',
            ],
            [
                'header' => 'Valor Hora Diurna Festiva',
                'field' => 'valor_hora_diurna_festiva',
                'description' => 'Valor por hora diurna festiva',
                'example' => '17280',
            ],
            [
                'header' => 'Valor Hora Nocturna Festiva',
                'field' => 'valor_hora_nocturna_festiva',
                'description' => 'Valor por hora nocturna festiva',
                'example' => '20640',
            ],
            [
                'header' => 'Auxilio De Transporte',
                'field' => 'auxilio_de_transporte',
                'description' => 'Valor del auxilio de transporte',
                'example' => '249100',
            ],
            [
                'header' => 'Auxilio De Manutencion',
                'field' => 'auxilio_de_manutencion',
                'description' => 'Valor del auxilio de manutención',
                'example' => '596730',
            ],
            [
                'header' => 'Auxilio De Encierro',
                'field' => 'auxilio_de_encierro',
                'description' => 'Valor del auxilio de encierro',
                'example' => '170000',
            ],
            [
                'header' => 'Auxilio De Rodamiento',
                'field' => 'auxilio_de_rodamiento',
                'description' => 'Valor del auxilio de rodamiento (legacy)',
                'example' => '',
            ],
            [
                'header' => 'Auxilio Especial',
                'field' => 'auxilio_especial',
                'description' => 'Valor del auxilio especial (diferente del auxilio general)',
                'example' => '2374523',
            ],
            [
                'header' => 'Auxilio Prosalud',
                'field' => 'auxilio_prosalud',
                'description' => 'Valor del auxilio Prosalud no constitutivo de compensación básica',
                'example' => '3253',
            ],
            [
                'header' => 'Valor Auxilio Diurno',
                'field' => 'valor_auxilio_diurno',
                'description' => 'Valor del auxilio diurno',
                'example' => '3978',
            ],
            [
                'header' => 'Valor Auxilio Recargo Nocturno',
                'field' => 'valor_auxilio_recargo_nocturno',
                'description' => 'Valor del auxilio con recargo nocturno',
                'example' => '5370',
            ],
            [
                'header' => 'Valor Auxilio Recargo Festivo',
                'field' => 'valor_auxilio_recargo_festivo',
                'description' => 'Valor del auxilio con recargo festivo',
                'example' => '7160',
            ],
            [
                'header' => 'Valor Auxilio Recargo Festivo Nocturno',
                'field' => 'valor_auxilio_recargo_festivo_nocturno',
                'description' => 'Valor del auxilio con recargo festivo nocturno',
                'example' => '8552',
            ],

            // Opciones de procesamiento
            [
                'header' => 'Email',
                'field' => 'email',
                'description' => 'Email del afiliado para envío del convenio (opcional)',
                'example' => 'afiliado@example.com',
            ],
            [
                'header' => 'Enviar Email',
                'field' => 'send_email',
                'description' => 'Seleccione "Si" para enviar el convenio por correo o "No" para no enviar',
                'example' => 'Si',
            ],
        ];
    }

    /**
     * Agrega validación tipo checkbox a la columna "Enviar Email"
     * Usa una lista desplegable con opciones "true" y "false"
     */
    private function addCheckboxValidation(Worksheet $sheet, array $columns): void
    {
        // Encontrar el índice de la columna "Enviar Email"
        $columnaEnviarEmail = null;
        foreach ($columns as $index => $column) {
            if ($column['field'] === 'send_email') {
                $columnaEnviarEmail = $index + 1; // 1-based index
                break;
            }
        }

        if (! $columnaEnviarEmail) {
            return; // No se encontró la columna
        }

        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnaEnviarEmail);
        $highestRow = max(2, $sheet->getHighestRow());
        $maxDataRow = max(1000, $highestRow + 100);
        $validationRange = $colLetter.'2:'.$colLetter.$maxDataRow;

        $validation = $sheet->getCell($colLetter.'2')->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(true);
        $validation->setErrorTitle('Valor inválido');
        $validation->setError('Por favor seleccione "Si" o "No", o deje la celda vacía para no enviar correo');
        $validation->setPromptTitle('Enviar Email');
        $validation->setPrompt('Seleccione "Si" para enviar el convenio por correo, "No" o vacío para no enviar');
        $validation->setFormula1('"Si,No"');
        $validation->setSqref($validationRange);

        Log::debug('[CONVENIO TEMPLATE EXPORT] Validación tipo checkbox aplicada a columna Enviar Email', [
            'columna' => $colLetter,
            'rango' => $validationRange,
        ]);
    }
}
