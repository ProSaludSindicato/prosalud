<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Http\Requests\LiquidacionSearchRequest;
use App\Services\LiquidacionService;

class LiquidacionesController extends Controller
{
    public function __construct(
        private LiquidacionService $liquidacionService
    ) {}

    /**
     * Search for liquidation records by document type and number
     *
     * @param LiquidacionSearchRequest $request
     * @return JsonResponse
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
     * Build HTTP response based on service result
     */
    private function buildResponse(array $result): JsonResponse
    {
        $status = $result['status'];

        return match ($status) {
            'success' => response()->json($result),
            'not_found' => response()->json($result, 404),
            'error' => response()->json($result, 500),
            default => response()->json([
                'status' => 'error',
                'message' => 'Respuesta inesperada del servicio'
            ], 500)
        };
    }
}
