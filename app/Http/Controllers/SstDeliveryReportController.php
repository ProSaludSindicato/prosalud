<?php

namespace App\Http\Controllers;

use App\Services\SstDeliveryReportService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SstDeliveryReportController extends Controller
{
    public function __construct(
        private readonly SstDeliveryReportService $reportService
    ) {
    }

    /**
     * Generate and download Excel report with delivery records.
     */
    public function download(Request $request): BinaryFileResponse|JsonResponse
    {
        try {
            // Get filters from request
            $filters = $request->only([
                'hospital',
                'startDate',
                'endDate',
                'documentNumber',
                'deliveredBy',
            ]);

            // Get options from request
            $options = [
                'includeSignatures' => $request->boolean('includeSignatures', true),
                'signatureSize' => [
                    'width' => $request->integer('signatureWidth', 100),
                    'height' => $request->integer('signatureHeight', 50),
                ],
            ];

            // Generate report
            $filePath = $this->reportService->generateReport($filters, $options);

            if (!file_exists($filePath)) {
                Log::error('Error generando reporte de entregas SST: archivo no creado');

                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar el reporte',
                ], 500);
            }

            $fileName = 'reporte-dotacion-epp-' . now()->setTimezone('America/Bogota')->format('Ymd-His') . '.xlsx';

            return response()->download($filePath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error generando reporte de entregas SST', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage(),
            ], 500);
        }
    }
}

