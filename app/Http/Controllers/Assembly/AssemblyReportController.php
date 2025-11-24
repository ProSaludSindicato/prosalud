<?php

namespace App\Http\Controllers\Assembly;

use App\Http\Controllers\Controller;
use App\Models\Assembly;
use App\Services\AssemblyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * 
     * Query parameters:
     * - assembly_id (optional): ID of the assembly to generate report for. If not provided, uses the active assembly.
     */
    public function download(Request $request): BinaryFileResponse|JsonResponse
    {
        try {
            // Get assembly_id from request or use active assembly
            $assemblyId = $request->input('assembly_id');
            
            if ($assemblyId) {
                $assembly = Assembly::find($assemblyId);
                if (!$assembly) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Asamblea no encontrada',
                    ], 404);
                }
            } else {
                $assembly = Assembly::getCurrent();
                if (!$assembly) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No hay asamblea activa. Por favor, especifica un assembly_id o activa una asamblea.',
                    ], 404);
                }
                $assemblyId = $assembly->id;
            }

            $filePath = $this->reportService->generateReport($assemblyId);

            if (!file_exists($filePath)) {
                Log::error('Error generando reporte de asamblea: archivo no creado');

                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar el reporte',
                ], 500);
            }

            $fileName = 'reporte_asamblea_' . ($assembly->name ?? $assemblyId) . '_' . now()->format('Y-m-d_His') . '.xlsx';
            // Sanitize filename
            $fileName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $fileName);

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

