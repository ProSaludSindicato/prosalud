<?php

namespace App\Services;

use App\Models\AssemblyAttendance;
use App\Models\AssemblyQuestion;
use App\Models\AssemblyVote;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill};
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Chart\{Chart, DataSeries, DataSeriesValues, Legend, PlotArea, Title};
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class AssemblyReportService
{
    /**
     * Temporary image files to clean up after report generation.
     */
    private array $tempImageFiles = [];

    /**
     * Generate Excel report with attendance and voting results.
     */
    public function generateReport(): string
    {
        try {
            $spreadsheet = new Spreadsheet();
            
            // Remove default sheet
            $spreadsheet->removeSheetByIndex(0);
            
            // Create attendance sheet
            $attendanceSheet = $spreadsheet->createSheet();
            $attendanceSheet->setTitle('Listado de Asistencia');
            $this->buildAttendanceSheet($attendanceSheet);
            
            // Create voting results sheet
            $votingSheet = $spreadsheet->createSheet();
            $votingSheet->setTitle('Resultados de Votación');
            $this->buildVotingResultsSheet($votingSheet);
            
            // Set first sheet as active
            $spreadsheet->setActiveSheetIndex(0);
            
            // Save to temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'assembly_report_') . '.xlsx';
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            $writer->save($tempFile);
            
            // Clean up temporary image files
            $this->cleanupTempFiles();
            
            return $tempFile;
        } catch (\Exception $e) {
            // Clean up temporary image files on error
            $this->cleanupTempFiles();
            throw $e;
        }
    }
    
    /**
     * Clean up temporary image files.
     */
    private function cleanupTempFiles(): void
    {
        foreach ($this->tempImageFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->tempImageFiles = [];
    }
    
    /**
     * Build attendance sheet with signatures embedded.
     */
    private function buildAttendanceSheet(Worksheet $sheet): void
    {
        // Headers
        $headers = [
            'N°',
            'Documento',
            'Nombre Completo',
            'Fecha Expedición',
            'Fecha Autenticación',
            'Dirección IP',
            'User Agent',
            'Firma',
        ];
        
        $sheet->fromArray([$headers], null, 'A1');
        
        // Style headers
        $headerRange = 'A1:H1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);
        
        // Get all attendance records
        $attendances = AssemblyAttendance::query()->orderByDesc('authenticated_at')->get();
        
        $row = 2;
        
        foreach ($attendances as $index => $attendance) {
            $sheet->setCellValue('A' . $row, $index + 1);
            $sheet->setCellValue('B' . $row, $attendance->document_number);
            $sheet->setCellValue('C' . $row, $attendance->full_name ?? '');
            $sheet->setCellValue('D' . $row, $attendance->issue_date_normalized?->format('d/m/Y') ?? '');
            $sheet->setCellValue('E' . $row, $attendance->authenticated_at->format('d/m/Y H:i:s'));
            $sheet->setCellValue('F' . $row, $attendance->ip_address ?? '');
            $sheet->setCellValue('G' . $row, $attendance->user_agent ?? '');
            
            // Embed signature image if available
            if ($attendance->signature_path) {
                try {
                    $this->embedSignature($sheet, $attendance, 'H' . $row);
                } catch (\Exception $e) {
                    Log::warning('No se pudo embebir firma en reporte', [
                        'attendance_id' => $attendance->id,
                        'error' => $e->getMessage(),
                    ]);
                    $sheet->setCellValue('H' . $row, 'Firma no disponible');
                }
            } else {
                $sheet->setCellValue('H' . $row, 'Sin firma');
            }
            
            // Style row
            $rowRange = 'A' . $row . ':H' . $row;
            $sheet->getStyle($rowRange)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            ]);
            
            // Set row height for signature column
            $sheet->getRowDimension($row)->setRowHeight(60);
            
            $row++;
        }
        
        // Auto-size columns
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Set signature column width
        $sheet->getColumnDimension('H')->setWidth(20);
        
        // Wrap text for user agent column
        if ($row > 2) {
            $sheet->getStyle('G2:G' . ($row - 1))->getAlignment()->setWrapText(true);
        }
        
        // Add autofilter to columns A (N°) and B (Documento)
        if ($row > 2) {
            $sheet->setAutoFilter('A1:H1');
        }
        
        // Freeze first row
        $sheet->freezePane('A2');
    }
    
    /**
     * Embed signature image in Excel cell.
     */
    private function embedSignature(Worksheet $sheet, AssemblyAttendance $attendance, string $cell): void
    {
        $disk = Storage::disk('prosalud-private');
        
        if (!$disk->exists($attendance->signature_path)) {
            throw new \Exception('Firma no encontrada en almacenamiento');
        }
        
        // Get file content
        $imageContent = $disk->get($attendance->signature_path);
        
        // Detect image type from path or content
        $extension = strtolower(pathinfo($attendance->signature_path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg'])) {
            // Try to detect from content
            $imageInfo = @getimagesizefromstring($imageContent);
            if ($imageInfo && isset($imageInfo['mime'])) {
                $mime = $imageInfo['mime'];
                if ($mime === 'image/png') {
                    $extension = 'png';
                } elseif (in_array($mime, ['image/jpeg', 'image/jpg'])) {
                    $extension = 'jpg';
                } else {
                    $extension = 'png'; // Default
                }
            } else {
                $extension = 'png'; // Default
            }
        }
        
        // Create temporary file for image
        $tempImageFile = tempnam(sys_get_temp_dir(), 'signature_') . '.' . $extension;
        file_put_contents($tempImageFile, $imageContent);
        
        // Track temp file for cleanup
        $this->tempImageFiles[] = $tempImageFile;
        
        // Create drawing object
        $drawing = new Drawing();
        $drawing->setPath($tempImageFile);
        $drawing->setCoordinates($cell);
        $drawing->setWidth(150);
        $drawing->setHeight(50);
        $drawing->setOffsetX(5);
        $drawing->setOffsetY(5);
        $drawing->setWorksheet($sheet);
    }
    
    /**
     * Build voting results sheet.
     */
    private function buildVotingResultsSheet(Worksheet $sheet): void
    {
        // Headers
        $headers = [
            'N°',
            'Pregunta',
            'Tipo de Mayoría',
            'Estado',
            'Opción',
            'Votos',
            'Porcentaje',
            'Total Votos',
            'Mayoría Alcanzada',
            'Fecha Apertura',
            'Fecha Cierre',
        ];
        
        $sheet->fromArray([$headers], null, 'A1');
        
        // Style headers
        $headerRange = 'A1:K1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '70AD47'],
            ],
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);
        
        // Get all questions with their votes
        $questions = AssemblyQuestion::with(['options', 'votes'])->orderBy('order')->get();
        
        $row = 2;
        $questionNumber = 1;
        $questionChartData = [];
        
        foreach ($questions as $question) {
            $votes = $question->votes;
            $totalVotes = $votes->count();
            
            // Get option texts
            $optionTexts = $question->options->pluck('text', 'option_key')->toArray();
            
            // Calculate results for each option
            $optionResults = [];
            foreach (AssemblyQuestion::defaultOptions() as $defaultOption) {
                $optionKey = $defaultOption['id'];
                $optionVotes = $votes->filter(function ($vote) use ($optionKey) {
                    return in_array($optionKey, $vote->selected_options ?? []);
                })->count();
                
                $percentage = $totalVotes > 0 ? ($optionVotes / $totalVotes) * 100 : 0;
                
                $optionResults[] = [
                    'text' => $optionTexts[$optionKey] ?? $defaultOption['text'],
                    'votes' => $optionVotes,
                    'percentage' => round($percentage, 2),
                ];
            }
            
            // Calculate if majority was achieved
            $maxVotes = $totalVotes > 0 ? max(array_column($optionResults, 'votes')) : 0;
            $majorityAchieved = $this->calculateMajority($question, $maxVotes, $totalVotes);
            
            // Map majority type
            $majorityTypeMap = [
                'SIMPLE' => 'Mayoría Simple',
                'ABSOLUTE' => 'Mayoría Absoluta',
                'TWO_THIRDS' => 'Mayoría de Dos Tercios',
            ];
            
            // Map status
            $statusMap = [
                'PENDING' => 'Pendiente',
                'OPEN' => 'Abierta',
                'CLOSED' => 'Cerrada',
            ];
            
            // Store chart data for this question
            $questionChartData[$questionNumber] = [
                'firstRow' => $row,
                'lastRow' => $row + count($optionResults) - 1,
                'optionResults' => $optionResults,
                'questionTitle' => $question->title,
            ];
            
            // Write question data for each option
            $firstOptionRow = $row;
            foreach ($optionResults as $index => $optionResult) {
                if ($index === 0) {
                    // First row: full question info
                    $sheet->setCellValue('A' . $row, $questionNumber);
                    $sheet->setCellValue('B' . $row, $question->title);
                    $sheet->setCellValue('C' . $row, $majorityTypeMap[$question->majority_type] ?? $question->majority_type);
                    $sheet->setCellValue('D' . $row, $statusMap[$question->status] ?? $question->status);
                    $sheet->setCellValue('H' . $row, $totalVotes);
                    $sheet->setCellValue('I' . $row, $majorityAchieved ? 'Sí' : 'No');
                    $sheet->setCellValue('J' . $row, $question->opened_at?->format('d/m/Y H:i:s') ?? '');
                    $sheet->setCellValue('K' . $row, $question->closed_at?->format('d/m/Y H:i:s') ?? '');
                } else {
                    // Merge cells for question info in subsequent rows
                    $sheet->mergeCells('A' . $firstOptionRow . ':A' . $row);
                    $sheet->mergeCells('B' . $firstOptionRow . ':B' . $row);
                    $sheet->mergeCells('C' . $firstOptionRow . ':C' . $row);
                    $sheet->mergeCells('D' . $firstOptionRow . ':D' . $row);
                    $sheet->mergeCells('H' . $firstOptionRow . ':H' . $row);
                    $sheet->mergeCells('I' . $firstOptionRow . ':I' . $row);
                    $sheet->mergeCells('J' . $firstOptionRow . ':J' . $row);
                    $sheet->mergeCells('K' . $firstOptionRow . ':K' . $row);
                }
                
                $sheet->setCellValue('E' . $row, $optionResult['text']);
                $sheet->setCellValue('F' . $row, $optionResult['votes']);
                $sheet->setCellValue('G' . $row, $optionResult['percentage'] . '%');
                
                // Style row
                $rowRange = 'A' . $row . ':K' . $row;
                $sheet->getStyle($rowRange)->applyFromArray([
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                    ],
                    'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
                ]);
                
                // Center align numeric columns
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('G' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('H' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('I' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                
                $row++;
            }
            
            $questionNumber++;
        }
        
        // Auto-size columns
        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Set specific widths for better readability
        $sheet->getColumnDimension('B')->setWidth(50);
        $sheet->getColumnDimension('E')->setWidth(25);
        
        // Freeze first row
        $sheet->freezePane('A2');
        
        // Wrap text for question column
        $sheet->getStyle('B2:B' . ($row - 1))->getAlignment()->setWrapText(true);
        
        // Add autofilter to columns A (N°) and B (Pregunta)
        if ($row > 2) {
            $sheet->setAutoFilter('A1:K1');
        }
        
        // Add charts for each question
        $this->addChartsToVotingSheet($sheet, $questionChartData, $row);
    }
    
    /**
     * Add charts to voting results sheet.
     */
    private function addChartsToVotingSheet(Worksheet $sheet, array $questionChartData, int $lastDataRow): void
    {
        if (empty($questionChartData)) {
            return;
        }
        
        $chartStartColumn = 'M'; // Start charts after column K
        $chartRow = 2;
        $chartWidth = 8; // 8 columns wide
        $chartHeight = 12; // 12 rows high
        
        // Get sheet name and escape it properly
        $sheetName = $sheet->getTitle();
        $sheetNameEscaped = "'" . str_replace("'", "''", $sheetName) . "'";
        
        foreach ($questionChartData as $questionNum => $chartData) {
            $firstRow = $chartData['firstRow'];
            $lastRow = $chartData['lastRow'];
            $optionResults = $chartData['optionResults'];
            $questionTitle = $chartData['questionTitle'];
            
            // Skip if no data
            if (empty($optionResults)) {
                continue;
            }
            
            // Prepare data for chart - use direct values instead of cell references
            $optionLabels = [];
            $voteValues = [];
            
            foreach ($optionResults as $option) {
                $optionLabels[] = $option['text'];
                $voteValues[] = $option['votes'];
            }
            
            // Create chart data series with direct values
            $dataSeriesLabels = [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1),
            ];
            
            // X-axis labels (option names) - use direct values
            // Constructor: (dataType, dataSource, formatCode, pointCount, dataValues)
            $xAxisTickValues = [
                new DataSeriesValues(
                    DataSeriesValues::DATASERIES_TYPE_STRING,
                    null,
                    null,
                    count($optionLabels),
                    $optionLabels
                ),
            ];
            
            // Y-axis values (vote counts) - use direct values
            $dataSeriesValues = [
                new DataSeriesValues(
                    DataSeriesValues::DATASERIES_TYPE_NUMBER,
                    null,
                    null,
                    count($voteValues),
                    $voteValues
                ),
            ];
            
            // Create the data series
            $series = new DataSeries(
                DataSeries::TYPE_BARCHART,
                DataSeries::GROUPING_CLUSTERED,
                range(0, count($dataSeriesValues) - 1),
                $dataSeriesLabels,
                $xAxisTickValues,
                $dataSeriesValues
            );
            
            $series->setPlotDirection(DataSeries::DIRECTION_VERTICAL);
            
            // Create plot area
            $plotArea = new PlotArea(null, [$series]);
            
            // Create legend
            $legend = new Legend(Legend::POSITION_RIGHT, null, false);
            
            // Create title
            $title = new Title('Pregunta ' . $questionNum . ': ' . mb_substr($questionTitle, 0, 50) . (mb_strlen($questionTitle) > 50 ? '...' : ''));
            
            // Create chart
            $chart = new Chart(
                'chart_' . $questionNum,
                $title,
                $legend,
                $plotArea,
                true,
                0,
                new Title('Opciones'),
                new Title('Votos')
            );
            
            // Calculate chart position using proper Excel column calculation
            $chartCol = $chartStartColumn;
            $chartTopLeft = $chartCol . $chartRow;
            
            // Calculate end column properly
            $startColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($chartCol);
            $endColIndex = $startColIndex + $chartWidth - 1;
            $endCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($endColIndex);
            $chartBottomRight = $endCol . ($chartRow + $chartHeight - 1);
            
            $chart->setTopLeftPosition($chartTopLeft);
            $chart->setBottomRightPosition($chartBottomRight);
            
            // Add chart to sheet
            $sheet->addChart($chart);
            
            // Move to next chart position (below current chart)
            $chartRow += $chartHeight + 2;
            
            // If we run out of space, move to next column
            if ($chartRow + $chartHeight > $lastDataRow) {
                $currentColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($chartStartColumn);
                $nextColIndex = $currentColIndex + $chartWidth + 1;
                $chartStartColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($nextColIndex);
                $chartRow = 2;
            }
        }
    }
    
    /**
     * Calculate if majority was achieved.
     */
    private function calculateMajority(AssemblyQuestion $question, int $maxVotes, int $totalVotes): bool
    {
        $quorum = \App\Models\QuorumConfig::getCurrent();
        $presentDelegates = $quorum ? $quorum->present_delegates : 0;
        
        switch ($question->majority_type) {
            case 'SIMPLE':
                return $maxVotes > ($totalVotes - $maxVotes);
            case 'ABSOLUTE':
                return $maxVotes > ($presentDelegates / 2);
            case 'TWO_THIRDS':
                return $maxVotes >= ($presentDelegates * 2 / 3);
            default:
                return false;
        }
    }
}

