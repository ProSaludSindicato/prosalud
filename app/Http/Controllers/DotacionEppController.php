<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSstDeliveryRequest;
use App\Services\SstDotacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DotacionEppController extends Controller
{
    public function __construct(private readonly SstDotacionService $dotacionService)
    {
    }

    public function affiliates(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'page',
                'pageSize',
                'documentType',
                'documentNumber',
                'hospital',
                'status',
                'searchTerm',
            ]);

            if (!isset($filters['status'])) {
                $filters['status'] = 'active';
            }

            $result = $this->dotacionService->getAffiliates($filters);

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('Error al obtener afiliados para dotación/EPP', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Error al obtener el listado de afiliados',
            ], 500);
        }
    }

    public function showAffiliate(string $documentType, string $documentNumber): JsonResponse
    {
        try {
            $affiliate = $this->dotacionService->findAffiliate($documentType, $documentNumber);

            if (!$affiliate) {
                return response()->json([
                    'message' => 'Afiliado no encontrado',
                ], 404);
            }

            return response()->json($affiliate);
        } catch (\Throwable $e) {
            Log::error('Error al consultar afiliado para dotación/EPP', [
                'document_type' => $documentType,
                'document_number' => $documentNumber,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al consultar el afiliado',
            ], 500);
        }
    }

    public function inventory(): JsonResponse
    {
        try {
            return response()->json([
                'items' => $this->dotacionService->getInventoryItems(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error al obtener inventario de dotación/EPP', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al obtener el inventario',
            ], 500);
        }
    }

    public function deliveries(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['affiliateId', 'deliveredBy', 'page', 'pageSize']);
            $result = $this->dotacionService->getDeliveries($filters);

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('Error al obtener entregas de dotación/EPP', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Error al obtener el historial de entregas',
            ], 500);
        }
    }

    public function storeDelivery(StoreSstDeliveryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $expectedId = sprintf('%s-%s', strtoupper($data['affiliateDocumentType']), $data['affiliateDocumentNumber']);

        if ($expectedId !== $data['affiliateId']) {
            return response()->json([
                'message' => 'Los datos del afiliado no coinciden con el identificador proporcionado.',
            ], 422);
        }

        try {
            $record = $this->dotacionService->createDelivery($data);

            return response()->json([
                'message' => 'Entrega registrada exitosamente',
                'record' => $record,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Error al registrar entrega de dotación/EPP', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Error interno del servidor al registrar la entrega',
            ], 500);
        }
    }
}
