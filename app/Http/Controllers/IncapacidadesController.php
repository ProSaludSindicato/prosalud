<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Http\Requests\IncapacidadSearchRequest;
use App\Services\IncapacidadService;

class IncapacidadesController extends Controller
{
    public function __construct(
        private IncapacidadService $incapacidadService
    ) {}

    /**
     * Search for disability records by document type and number
     *
     * @param IncapacidadSearchRequest $request
     * @return JsonResponse
     */
    public function search(IncapacidadSearchRequest $request): JsonResponse
    {
        $tipo = $request->validated('tipo');
        $numeroDocumento = $request->validated('numero_documento');
        $fechaExpedicion = $request->validated('fecha_expedicion');

        // Delegate business logic to service
        $result = $this->incapacidadService->searchByDocument(
            $tipo,
            $numeroDocumento,
            $fechaExpedicion
        );

        // Return appropriate HTTP status based on result
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
