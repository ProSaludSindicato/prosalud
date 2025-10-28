<?php

namespace App\Http\Controllers;

use App\Services\ExcelReaderService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ActivosController extends Controller
{
    private ExcelReaderService $excelReaderService;

    public function __construct(ExcelReaderService $excelReaderService)
    {
        $this->excelReaderService = $excelReaderService;
    }

    /**
     * Transform hospital name to client expected format
     */
    private function transformHospitalName(string $hospital): string
    {
        $transformations = [
            'ADMON' => 'ADMON',
            'HMFS - BELLO' => 'Bello',
            'HLM - GRUPO 1' => 'La Maria',
            'HLM - GRUPO 3' => 'La Maria',
            'HSJDRionegro' => 'Rionegro',
            'LA MARIA - COOSALUD' => 'La Maria',
            'LA MARIA - ENTERRITORIO' => 'La Maria',
            'LA MARIA - ENTERRITORIO 2' => 'La Maria',
            'LA MARIA - VIH - 1' => 'La Maria',
        ];

        // Check for exact match first
        if (isset($transformations[$hospital])) {
            return $transformations[$hospital];
        }

        // Check for partial matches (case insensitive)
        $hospitalUpper = strtoupper(trim($hospital));
        foreach ($transformations as $key => $value) {
            if (strpos($hospitalUpper, strtoupper($key)) !== false) {
                return $value;
            }
        }

        // If no transformation found, return original
        return $hospital;
    }

    /**
     * Search for a person's hospital by document type, document number and expedition date
     */
    public function searchHospital(Request $request): JsonResponse
    {
        try {
            // Validate input
            $request->validate([
                'tipo_documento' => 'required|string|max:50',
                'documento' => 'required|string|max:50',
                'fecha_expedicion' => 'required|string|max:50'
            ]);

            $tipoDocumento = trim($request->input('tipo_documento'));
            $documento = trim($request->input('documento'));
            $fechaExpedicion = trim($request->input('fecha_expedicion'));

            // Log the search attempt
            Log::info('Búsqueda de hospital en activos', [
                'tipo_documento' => $tipoDocumento,
                'documento' => $documento,
                'fecha_expedicion' => $fechaExpedicion,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString()
            ]);

            // Check if the activos file is available
            if (!$this->excelReaderService->isActivosFileAvailable()) {
                Log::error('Archivo de activos no disponible para búsqueda');
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                    'hospital' => null
                ], 503);
            }

            // Search for the person
            $hospital = $this->excelReaderService->searchPersonInActivos(
                $tipoDocumento,
                $documento,
                $fechaExpedicion
            );

            // Transform hospital name if found
            $transformedHospital = $hospital ? $this->transformHospitalName($hospital) : null;

            $response = [
                'success' => true,
                'hospital' => $transformedHospital,
                'found' => $hospital !== null
            ];

            // Log the result
            Log::info('Resultado de búsqueda de hospital', [
                'tipo_documento' => $tipoDocumento,
                'documento' => $documento,
                'fecha_expedicion' => $fechaExpedicion,
                'hospital_original' => $hospital,
                'hospital_transformado' => $transformedHospital,
                'encontrado' => $hospital !== null,
                'timestamp' => now()->toISOString()
            ]);

            return response()->json($response);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validación fallida en búsqueda de hospital', [
                'errors' => $e->errors(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
                'hospital' => null
            ], 422);

        } catch (\Exception $e) {
            Log::error('Error inesperado en búsqueda de hospital', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tipo_documento' => $request->input('tipo_documento'),
                'documento' => $request->input('documento'),
                'fecha_expedicion' => $request->input('fecha_expedicion'),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'hospital' => null
            ], 500);
        }
    }
}
