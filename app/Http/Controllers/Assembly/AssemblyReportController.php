<?php

namespace App\Http\Controllers\Assembly;

use App\Http\Controllers\Controller;
use App\Services\AssemblyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssemblyReportController extends Controller
{
    public function __construct(
        private AssemblyReportService $reportService
    ) {
    }

    /**
     * Generate and download Excel report with attendance and voting results.
     */
    public function download(): BinaryFileResponse|JsonResponse
    {
        try {
            $filePath = $this->reportService->generateReport();

            if (!file_exists($filePath)) {
                Log::error('Error generando reporte de asamblea: archivo no creado');

                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar el reporte',
                ], 500);
            }

            $fileName = 'reporte_asamblea_' . now()->format('Y-m-d_His') . '.xlsx';

            return response()->download($filePath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('Error generando reporte de asamblea', [
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

