<?php

namespace App\Http\Controllers;

use App\Services\AssemblyAttendanceService;
use App\Services\ExcelReaderService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Log;

class ActivosController extends Controller
{
    public function __construct(
        private ExcelReaderService $excelReaderService,
        private AssemblyAttendanceService $attendanceService,
    ) {
    }

    /**
     * Search for an affiliate by document number and expedition date in the asamblea delegados file.
     */
    public function searchHospital(Request $request): JsonResponse
    {
        try {
            // Validate input
            $request->validate([
                'documento' => 'required|string|max:50',
                'fecha_expedicion' => 'required|string|max:50',
            ]);

            $documento = trim($request->input('documento'));
            $fechaExpedicion = trim($request->input('fecha_expedicion'));

            // Log the search attempt
            Log::info('Búsqueda de afiliado en asamblea delegados', [
                'documento' => $documento,
                'fecha_expedicion' => $fechaExpedicion,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]);

            // Check if the asamblea delegados file is available
            if (!$this->excelReaderService->isAsambleaDelegadosFileAvailable()) {
                Log::error('Archivo de asamblea delegados no disponible para búsqueda');

                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                    'data' => null,
                ], 503);
            }

            // Search for the affiliate
            $afiliado = $this->excelReaderService->searchAfiliadoInAsamblea(
                $documento,
                $fechaExpedicion
            );

            $response = [
                'success' => true,
                'data' => $afiliado,
                'found' => null !== $afiliado,
            ];

            if ($afiliado) {
                $this->attendanceService->record(
                    $afiliado['cedula'] ?? $documento,
                    $afiliado['nombre_apellidos'] ?? null,
                    $afiliado['fecha_expedicion'] ?? $fechaExpedicion,
                    $request
                );
            }

            // Log the result
            Log::info('Resultado de búsqueda de afiliado', [
                'documento' => $documento,
                'fecha_expedicion' => $fechaExpedicion,
                'encontrado' => null !== $afiliado,
                'afiliado' => $afiliado,
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json($response);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validación fallida en búsqueda de afiliado', [
                'errors' => $e->errors(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
                'data' => null,
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error inesperado en búsqueda de afiliado', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'documento' => $request->input('documento'),
                'fecha_expedicion' => $request->input('fecha_expedicion'),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'data' => null,
            ], 500);
        }
    }

}
