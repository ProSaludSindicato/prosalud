<?php

namespace App\Http\Controllers;

use App\Services\ExcelReaderService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Log;

class DelegadosController extends Controller
{
    private ExcelReaderService $excelReaderService;

    public function __construct(ExcelReaderService $excelReaderService)
    {
        $this->excelReaderService = $excelReaderService;
    }

    /**
     * Get all delegados candidates.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Check if the delegados file is available
            if (!$this->excelReaderService->isDelegadosFileAvailable()) {
                Log::error('Archivo de delegados no disponible');

                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                    'delegados' => [],
                ], 503);
            }

            $delegados = $this->excelReaderService->getAllDelegados();

            // Log the request
            Log::info('Consulta de delegados realizada', [
                'total_delegados' => count($delegados),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => true,
                'total' => count($delegados),
                'delegados' => $delegados,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener delegados', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'delegados' => [],
            ], 500);
        }
    }

    /**
     * Get delegados by sede (hospital).
     */
    public function getBySede(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'sede' => 'required|string|max:100',
            ]);

            $sede = $request->input('sede');

            // Check if the delegados file is available
            if (!$this->excelReaderService->isDelegadosFileAvailable()) {
                Log::error('Archivo de delegados no disponible');

                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                    'delegados' => [],
                ], 503);
            }

            $delegados = $this->excelReaderService->getDelegadosBySede($sede);

            // Log the request
            Log::info('Consulta de delegados por sede realizada', [
                'sede' => $sede,
                'total_delegados' => count($delegados),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => true,
                'sede' => $sede,
                'total' => count($delegados),
                'delegados' => $delegados,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validación fallida en consulta de delegados por sede', [
                'errors' => $e->errors(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
                'delegados' => [],
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al obtener delegados por sede', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'sede' => $request->input('sede'),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'delegados' => [],
            ], 500);
        }
    }

    /**
     * Get delegado by cedula.
     */
    public function getByCedula(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'cedula' => 'required|string|max:20',
            ]);

            $cedula = $request->input('cedula');

            // Check if the delegados file is available
            if (!$this->excelReaderService->isDelegadosFileAvailable()) {
                Log::error('Archivo de delegados no disponible');

                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                    'delegado' => null,
                ], 503);
            }

            $delegado = $this->excelReaderService->getDelegadoByCedula($cedula);

            // Log the request
            Log::info('Consulta de delegado por cédula realizada', [
                'cedula' => $cedula,
                'encontrado' => null !== $delegado,
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => true,
                'found' => null !== $delegado,
                'delegado' => $delegado,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validación fallida en consulta de delegado por cédula', [
                'errors' => $e->errors(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
                'delegado' => null,
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al obtener delegado por cédula', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'cedula' => $request->input('cedula'),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'delegado' => null,
            ], 500);
        }
    }

    /**
     * Get delegados grouped by sede.
     */
    public function getGroupedBySede(Request $request): JsonResponse
    {
        try {
            // Check if the delegados file is available
            if (!$this->excelReaderService->isDelegadosFileAvailable()) {
                Log::error('Archivo de delegados no disponible');

                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                    'delegados_grouped' => [],
                ], 503);
            }

            $delegadosGrouped = $this->excelReaderService->getDelegadosGroupedBySede();

            // Log the request
            Log::info('Consulta de delegados agrupados por sede realizada', [
                'sedes_count' => count($delegadosGrouped),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => true,
                'sedes_count' => count($delegadosGrouped),
                'delegados_grouped' => $delegadosGrouped,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener delegados agrupados por sede', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'delegados_grouped' => [],
            ], 500);
        }
    }
}
