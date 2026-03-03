<?php

namespace App\Services;

use App\Models\VaccinationSurvey;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class VaccinationSurveyExcelExportService
{
    private const SIGNATURE_DISK = 'prosalud-private';
    private const SIGNATURE_WIDTH = 100;
    private const SIGNATURE_HEIGHT = 50;
    private const MAX_IMAGE_SIZE = 2 * 1024 * 1024; // 2MB

    /** @var list<string> */
    private array $tempImageFiles = [];

    /**
     * Generar reporte Excel con una sola hoja: registros de encuesta de vacunación.
     */
    public function generateReport(array $filters): string
    {
        try {
            $this->validateFilters($filters);
            $surveys = $this->getSurveys($filters);

            $spreadsheet = new Spreadsheet();
            $spreadsheet->removeSheetByIndex(0);

            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Encuesta de vacunación');
            $this->buildRegistrosSheet($sheet, $surveys);

            $spreadsheet->setActiveSheetIndex(0);

            $tempFile = tempnam(sys_get_temp_dir(), 'vaccination_survey_report_') . '.xlsx';
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            $this->cleanupTempFiles();

            return $tempFile;
        } catch (\Exception $e) {
            $this->cleanupTempFiles();
            Log::error('Error generando reporte Excel de encuestas de vacunación', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $filters,
            ]);
            throw $e;
        }
    }

    private function validateFilters(array $filters): void
    {
        $dateRange = $filters['date_range'] ?? [];
        if (!($dateRange['include_all'] ?? true)) {
            if (isset($dateRange['start_date']) && isset($dateRange['end_date'])) {
                $startDate = Carbon::parse($dateRange['start_date']);
                $endDate = Carbon::parse($dateRange['end_date']);
                if ($startDate->gt($endDate)) {
                    throw new \InvalidArgumentException('La fecha inicial debe ser anterior o igual a la fecha final.');
                }
            }
        }
    }

    private function getSurveys(array $filters): Collection
    {
        $query = VaccinationSurvey::query();

        $dateRange = $filters['date_range'] ?? [];
        if (!($dateRange['include_all'] ?? true)) {
            if (isset($dateRange['start_date'])) {
                $startDate = Carbon::parse($dateRange['start_date'])->startOfDay();
                $query->where('created_at', '>=', $startDate);
            }
            if (isset($dateRange['end_date'])) {
                $endDate = Carbon::parse($dateRange['end_date'])->endOfDay();
                $query->where('created_at', '<=', $endDate);
            }
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    private const TITLE_ROW = 1;
    private const DESC_START_ROW = 2;
    private const DESC_END_ROW = 3;
    private const HEADER_ROW = 4;
    private const LIGHT_GREEN = 'C6EFCE';

    private function buildRegistrosSheet(Worksheet $sheet, Collection $surveys): void
    {
        $colWidths = [
            'A' => 28, 'B' => 18, 'C' => 22, 'D' => 20, 'E' => 20, 'F' => 20, 'G' => 20,
            'H' => 26, 'I' => 26, 'J' => 32, 'K' => 32,
        ];
        foreach ($colWidths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // --- Título (fila 1, fusionada, centrado, negrita, fondo verde, bordes) ---
        $titleText = 'REVISIÓN Y VERIFICACIÓN DEL ESTADO DE VACUNACIÓN DEL PERSONAL DE LA INSTITUCIÓN CONTRA FIEBRE AMARILLA Y SARAMPIÓN, EN CUMPLIMIENTO DE LAS NORMATIVAS VIGENTES PARA INSTITUCIONES DE SALUD.';
        $sheet->mergeCells('A' . self::TITLE_ROW . ':K' . self::TITLE_ROW);
        $sheet->setCellValue('A' . self::TITLE_ROW, $titleText);
        $sheet->getStyle('A' . self::TITLE_ROW . ':K' . self::TITLE_ROW)->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_GREEN]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $sheet->getRowDimension(self::TITLE_ROW)->setRowHeight(45);

        // --- Descripción (filas 2-3, fusionada, dos filas, centrada, fondo verde, bordes) ---
        $descText = 'En cumplimiento de los lineamientos del Programa Ampliado de Inmunizaciones (PAI) 2026, en especial lo establecido en la Circular 016 de febrero de 2025 sobre el fortalecimiento de la vacunación contra sarampión, rubéola y síndrome de rubéola congénita (SRC) en todo el territorio nacional y el inicio del plan de preparación ante eventos masivos por la Copa Mundial FIFA 2026; la Circular 012 y la Resolución 691 de 2025 relacionadas con las directrices por alerta de fiebre amarilla.';
        $sheet->mergeCells('A' . self::DESC_START_ROW . ':K' . self::DESC_END_ROW);
        $sheet->setCellValue('A' . self::DESC_START_ROW, $descText);
        $sheet->getStyle('A' . self::DESC_START_ROW . ':K' . self::DESC_END_ROW)->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_GREEN]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $sheet->getRowDimension(self::DESC_START_ROW)->setRowHeight(38);
        $sheet->getRowDimension(self::DESC_END_ROW)->setRowHeight(38);

        // --- Encabezados de columnas (fila 4, fondo verde, bordes) ---
        $headers = [
            'A' => 'FECHA DE CONSULTA',
            'B' => 'FECHA DE NACIMIENTO',
            'C' => 'DOCUMENTO DE IDENTIDAD',
            'D' => 'PRIMER NOMBRE',
            'E' => 'SEGUNDO NOMBRE',
            'F' => 'PRIMER APELLIDO',
            'G' => 'SEGUNDO APELLIDO',
            'H' => 'FECHA APLICACIÓN SRP',
            'I' => 'FECHA APLICACIÓN SR',
            'J' => 'FECHA APLICACIÓN FIEBRE AMARILLA',
            'K' => 'FIRMA DIGITAL',
        ];
        foreach ($headers as $col => $h) {
            $sheet->setCellValue($col . self::HEADER_ROW, $h);
        }
        $sheet->getStyle('A' . self::HEADER_ROW . ':K' . self::HEADER_ROW)->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::LIGHT_GREEN]],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        $dataStartRow = self::HEADER_ROW + 1;
        $lastDataRow = $dataStartRow + max(0, $surveys->count() - 1);
        if ($lastDataRow >= $dataStartRow) {
            $sheet->setAutoFilter('A' . self::HEADER_ROW . ':J' . $lastDataRow);
            $sheet->getStyle('A' . $dataStartRow . ':K' . $lastDataRow)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
        }

        $firmaCol = 'K';
        $row = $dataStartRow;
        foreach ($surveys as $s) {
            $sheet->setCellValue('A' . $row, $this->formatFechaConsulta($s->created_at));
            $sheet->setCellValue('B' . $row, $s->fecha_nacimiento?->format('Y-m-d'));
            $sheet->setCellValueExplicit(
                'C' . $row,
                trim($s->tipo_documento . ' ' . ($s->numero_documento ?? '')),
                DataType::TYPE_STRING
            );
            $sheet->setCellValue('D' . $row, $s->primer_nombre ?? '');
            $sheet->setCellValue('E' . $row, $s->segundo_nombre ?? '');
            $sheet->setCellValue('F' . $row, $s->primer_apellido ?? '');
            $sheet->setCellValue('G' . $row, $s->segundo_apellido ?? '');
            $sheet->setCellValue('H' . $row, $this->formatFechaLegible($s->fecha_aplicacion_srp));
            $sheet->setCellValue('I' . $row, $this->formatFechaLegible($s->fecha_aplicacion_sr));
            $sheet->setCellValue('J' . $row, $this->formatFechaLegible($s->fecha_aplicacion_fiebre_amarilla));

            if (!empty($s->firma_path)) {
                try {
                    $this->embedSignature($sheet, $s, $firmaCol . $row);
                    $sheet->getRowDimension($row)->setRowHeight(max(60, self::SIGNATURE_HEIGHT + 10));
                } catch (\Throwable $e) {
                    Log::warning('No se pudo embebir firma en reporte encuesta vacunación', [
                        'survey_id' => $s->id,
                        'path' => $s->firma_path,
                        'error' => $e->getMessage(),
                    ]);
                    $sheet->setCellValue('K' . $row, 'No disponible');
                }
            } else {
                $sheet->setCellValue('K' . $row, '');
            }
            $row++;
        }
    }

    /**
     * Fecha de consulta: solo fecha y hora (horas y minutos), sin segundos. Formato legible en español.
     */
    private function formatFechaConsulta(?Carbon $date): string
    {
        if ($date === null) {
            return '';
        }
        return $date->setTimezone('America/Bogota')->locale('es')->translatedFormat('d \d\e F \d\e Y H:i');
    }

    /**
     * Fecha solo (sin hora) en formato legible en español para evitar confusión (ej. 2 de marzo de 2026).
     */
    private function formatFechaLegible(?Carbon $date): string
    {
        if ($date === null) {
            return '';
        }
        return $date->locale('es')->translatedFormat('d \d\e F \d\e Y');
    }

    private function embedSignature(Worksheet $sheet, VaccinationSurvey $survey, string $cell): void
    {
        if (!$survey->firma_path) {
            return;
        }

        $disk = Storage::disk(self::SIGNATURE_DISK);
        if (!$disk->exists($survey->firma_path)) {
            $disk = Storage::disk('local');
        }
        if (!$disk->exists($survey->firma_path)) {
            throw new \Exception('Firma no encontrada en almacenamiento');
        }

        $imageContent = $disk->get($survey->firma_path);
        if (strlen($imageContent) > self::MAX_IMAGE_SIZE) {
            throw new \Exception('Imagen excede el tamaño máximo permitido');
        }

        $extension = strtolower(pathinfo($survey->firma_path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg'])) {
            $imageInfo = @getimagesizefromstring($imageContent);
            if ($imageInfo && isset($imageInfo['mime'])) {
                $mime = $imageInfo['mime'];
                $extension = ($mime === 'image/png') ? 'png' : (in_array($mime, ['image/jpeg', 'image/jpg']) ? 'jpg' : 'png');
            } else {
                $extension = 'png';
            }
        }

        $tempImageFile = tempnam(sys_get_temp_dir(), 'vaccination_signature_') . '.' . $extension;
        file_put_contents($tempImageFile, $imageContent);
        $this->tempImageFiles[] = $tempImageFile;

        $drawing = new Drawing();
        $drawing->setPath($tempImageFile);
        $drawing->setCoordinates($cell);
        $drawing->setWidth(self::SIGNATURE_WIDTH);
        $drawing->setHeight(self::SIGNATURE_HEIGHT);
        $drawing->setOffsetX(5);
        $drawing->setOffsetY(5);
        $drawing->setWorksheet($sheet);
    }

    private function cleanupTempFiles(): void
    {
        foreach ($this->tempImageFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempImageFiles = [];
    }
}
