<?php

namespace App\Services;

use App\Models\Survey;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SurveyResponsesExcelExportService
{
    private const HEADER_BG_COLOR = '00529B';

    private const HEADER_TEXT_COLOR = 'FFFFFF';

    public function generateReport(Survey $survey, array $filters = []): string
    {
        try {
            $survey->loadMissing('questions');

            $query = $survey->responses()->with(['answers.question']);

            if (! empty($filters['hospital'])) {
                $query->byHospital($filters['hospital']);
            }

            if (! empty($filters['document'])) {
                $query->byDocument($filters['document']);
            }

            if (! empty($filters['start_date']) || ! empty($filters['end_date'])) {
                $query->byDateRange($filters['start_date'] ?? null, $filters['end_date'] ?? null);
            }

            $responses = $query->orderBy('submitted_at', 'desc')->get();

            $spreadsheet = new Spreadsheet;
            $spreadsheet->removeSheetByIndex(0);

            $resumenSheet = $spreadsheet->createSheet();
            $resumenSheet->setTitle('Resumen');
            $this->buildResumenSheet($resumenSheet, $survey, $responses);

            $respuestasSheet = $spreadsheet->createSheet();
            $respuestasSheet->setTitle('Respuestas');
            $this->buildRespuestasSheet($respuestasSheet, $survey, $responses);

            $spreadsheet->setActiveSheetIndex(0);

            $tempFile = tempnam(sys_get_temp_dir(), 'survey_responses_report_').'.xlsx';
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            return $tempFile;
        } catch (\Exception $e) {
            Log::error('Error generando reporte Excel de encuesta dinámica', [
                'survey_id' => $survey->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    private function buildResumenSheet(Worksheet $sheet, Survey $survey, \Illuminate\Support\Collection $responses): void
    {
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(50);

        $row = 1;
        $sheet->setCellValue("A{$row}", 'RESUMEN DE ENCUESTA');
        $sheet->mergeCells("A{$row}:B{$row}");
        $this->styleHeader($sheet, "A{$row}:B{$row}");
        $row++;

        $data = [
            ['Título', $survey->title],
            ['Estado', match ($survey->status) {
                'draft' => 'Borrador',
                'active' => 'Activa',
                'closed' => 'Cerrada',
                default => $survey->status,
            }],
            ['Tipo de acceso', match ($survey->access_type) {
                'public' => 'Pública',
                'authenticated' => 'Autenticada',
                'restricted' => 'Restringida por hospital',
                default => $survey->access_type,
            }],
            ['Requiere firma', $survey->requires_signature ? 'Sí' : 'No'],
            ['Total de respuestas', $responses->count()],
            ['Generado el', now()->setTimezone('America/Bogota')->format('d/m/Y H:i:s')],
        ];

        foreach ($data as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
            $row++;
        }

        $row++;
        $sheet->setCellValue("A{$row}", 'RESPUESTAS POR HOSPITAL');
        $sheet->mergeCells("A{$row}:B{$row}");
        $this->styleHeader($sheet, "A{$row}:B{$row}");
        $row++;

        $byHospital = $responses->groupBy(fn ($r) => $r->hospital ?? 'Sin hospital');
        foreach ($byHospital as $hospital => $group) {
            $sheet->setCellValue("A{$row}", $hospital);
            $sheet->setCellValue("B{$row}", $group->count());
            $row++;
        }
    }

    private function buildRespuestasSheet(Worksheet $sheet, Survey $survey, \Illuminate\Support\Collection $responses): void
    {
        $questions = $survey->questions;

        $fixedHeaders = ['ID Respuesta', 'Fecha', 'Tipo Documento', 'Número Documento', 'Nombre', 'Hospital'];
        $dynamicHeaders = $questions->pluck('label')->toArray();
        $allHeaders = array_merge($fixedHeaders, $dynamicHeaders);

        $col = 1;
        foreach ($allHeaders as $header) {
            $sheet->setCellValueByColumnAndRow($col, 1, $header);
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $this->styleHeader($sheet, "{$colLetter}1");
            $sheet->getColumnDimensionByColumn($col)->setWidth(22);
            $col++;
        }

        $row = 2;
        foreach ($responses as $response) {
            $sheet->setCellValueByColumnAndRow(1, $row, $response->id);
            $sheet->setCellValueByColumnAndRow(2, $row, $response->submitted_at?->setTimezone('America/Bogota')->format('d/m/Y H:i'));
            $sheet->setCellValueByColumnAndRow(3, $row, $response->respondent_document_type ?? '');
            $sheet->setCellValueByColumnAndRow(4, $row, $response->respondent_document_number ?? '');
            $sheet->setCellValueByColumnAndRow(5, $row, $response->respondent_name ?? '');
            $sheet->setCellValueByColumnAndRow(6, $row, $response->hospital ?? '');

            $answersByQuestion = $response->answers->keyBy('question_id');

            $col = 7;
            foreach ($questions as $question) {
                $answer = $answersByQuestion->get($question->id);
                $value = '';

                if ($answer) {
                    $decoded = $answer->decoded_value;
                    if ($question->type === 'yes_no') {
                        $value = match ((string) $decoded) {
                            'yes' => 'Sí',
                            'no' => 'No',
                            default => (string) ($decoded ?? ''),
                        };
                    } elseif ($question->type === 'ranking' && is_array($decoded)) {
                        $labelMap = $question->options
                            ? collect($question->options)->keyBy('value')->map(fn ($o) => $o['label'])->all()
                            : [];
                        $parts = [];
                        foreach ($decoded as $key => $rank) {
                            $label = $labelMap[$key] ?? $key;
                            $parts[] = $label.': '.(is_scalar($rank) ? (string) $rank : json_encode($rank));
                        }
                        $value = implode('; ', $parts);
                    } elseif (is_array($decoded)) {
                        if ($question->options) {
                            $labelMap = collect($question->options)->keyBy('value')->map(fn ($o) => $o['label']);
                            if (array_is_list($decoded)) {
                                $value = collect($decoded)->map(fn ($v) => $labelMap[$v] ?? $v)->join(', ');
                            } else {
                                $value = collect($decoded)->map(fn ($v, $k) => ($labelMap[$k] ?? $k).': '.$v)->join(', ');
                            }
                        } else {
                            $value = array_is_list($decoded)
                                ? implode(', ', $decoded)
                                : collect($decoded)->map(fn ($v, $k) => $k.': '.$v)->join(', ');
                        }
                    } else {
                        $value = (string) ($decoded ?? '');
                    }
                }

                $sheet->setCellValueByColumnAndRow($col, $row, $value);
                $col++;
            }

            $row++;
        }
    }

    private function styleHeader(Worksheet $sheet, string $cellOrRange): void
    {
        $sheet->getStyle($cellOrRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::HEADER_TEXT_COLOR]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER_BG_COLOR]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
    }
}
