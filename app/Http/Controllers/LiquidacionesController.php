<?php

namespace App\Http\Controllers;

use App\Http\Requests\LiquidacionSearchRequest;
use App\Services\LiquidacionService;
use Illuminate\Http\JsonResponse;

class LiquidacionesController extends Controller
{
    public function __construct(
        private LiquidacionService $liquidacionService,
    ) {
    }

    /**
     * Search for liquidation records by document type and number.
     */
    public function search(LiquidacionSearchRequest $request): JsonResponse
    {
        $tipo = $request->validated('tipo');
        $numeroDocumento = $request->validated('numero_documento');
        $fechaExpedicion = $request->validated('fecha_expedicion');

        \Illuminate\Support\Facades\Log::info('Búsqueda de liquidaciones iniciada', [
            'tipo_documento' => $tipo,
            'numero_documento' => $numeroDocumento,
            'fecha_expedicion' => $fechaExpedicion,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toISOString(),
        ]);

        $startTime = microtime(true);

        $result = $this->liquidacionService->searchByDocument(
            $tipo,
            $numeroDocumento,
            $fechaExpedicion
        );

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        \Illuminate\Support\Facades\Log::info('Búsqueda de liquidaciones completada', [
            'tipo_documento' => $tipo,
            'numero_documento' => $numeroDocumento,
            'result_status' => $result['status'],
            'execution_time_ms' => $executionTime,
            'records_found' => isset($result['data']) ? count($result['data']) : 0,
            'ip_address' => $request->ip(),
            'timestamp' => now()->toISOString(),
        ]);

        return $this->buildResponse($result);
    }

    /**
     * Build HTTP response based on service result.
     */
    private function buildResponse(array $result): JsonResponse
    {
        $status = $result['status'];
        $message = $result['message'] ?? '';

        return match ($status) {
            'success' => response()->json($result),
            'not_found' => response()->json($result, 404),
            'error' => $this->determineErrorStatusCode($message),
            default => response()->json([
                'status' => 'error',
                'message' => 'Respuesta inesperada del servicio',
            ], 500),
        };
    }

    /**
     * Determine the appropriate HTTP status code for error responses.
     * Returns 422 for validation errors, 500 for server errors.
     */
    private function determineErrorStatusCode(string $message): JsonResponse
    {
        // Check if the error message indicates a validation error
        $validationKeywords = [
            'no son válidos',
            'verifique la información',
            'documento o la fecha',
        ];

        $isValidationError = false;
        foreach ($validationKeywords as $keyword) {
            if (stripos($message, $keyword) !== false) {
                $isValidationError = true;
                break;
            }
        }

        $statusCode = $isValidationError ? 422 : 500;
        
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], $statusCode);
    }
}
