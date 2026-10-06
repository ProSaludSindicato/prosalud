<?php

namespace App\Http\Controllers;

use App\Exceptions\AffiliateServiceUnavailableException;
use App\Http\Requests\StoreSstDeliveryRequest;
use App\Http\Requests\StoreSstReturnRequest;
use App\Services\SstDotacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DotacionEppController extends Controller
{
    public function __construct(private readonly SstDotacionService $dotacionService) {}

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

            if (! isset($filters['status'])) {
                $filters['status'] = 'active';
            }

            $result = $this->dotacionService->getAffiliates($filters);

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('Dotación/EPP: fallo HTTP al obtener listado de afiliados', [
                'dotacion_epp' => true,
                'lookup' => 'affiliates_list',
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

            if (! $affiliate) {
                $this->dotacionService->logAffiliateMissDiagnostics($documentType, $documentNumber);

                return response()->json([
                    'message' => 'Afiliado no encontrado',
                ], 404);
            }

            return response()->json($affiliate);
        } catch (AffiliateServiceUnavailableException $e) {
            Log::warning('Dotación/EPP: servicio de afiliados no disponible al consultar por tipo y documento', [
                'dotacion_epp' => true,
                'lookup' => 'find_affiliate',
                'document_type_param' => $documentType,
                'document_number_param' => $documentNumber,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 503);
        } catch (\Throwable $e) {
            Log::error('Dotación/EPP: fallo HTTP al consultar afiliado por tipo y documento', [
                'dotacion_epp' => true,
                'lookup' => 'find_affiliate',
                'document_type_param' => $documentType,
                'document_number_param' => $documentNumber,
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
            Log::warning('Dotación/EPP: datos de afiliado no coinciden con el identificador al registrar entrega', [
                'dotacion_epp' => true,
                'action' => 'delivery_failed',
                'cause' => 'affiliate_id_mismatch',
                'affiliate_id_provided' => $data['affiliateId'],
                'affiliate_id_expected' => $expectedId,
                'affiliate_document_type' => $data['affiliateDocumentType'],
                'affiliate_document_number' => $data['affiliateDocumentNumber'],
            ]);

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
            Log::warning('Dotación/EPP: validación fallida al registrar entrega', [
                'dotacion_epp' => true,
                'action' => 'delivery_failed',
                'cause' => 'validation_error',
                'affiliate_id' => $data['affiliateId'],
                'affiliate_document_type' => $data['affiliateDocumentType'],
                'affiliate_document_number' => $data['affiliateDocumentNumber'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (AffiliateServiceUnavailableException $e) {
            Log::warning('Dotación/EPP: servicio de afiliados no disponible al registrar entrega', [
                'dotacion_epp' => true,
                'action' => 'delivery_failed',
                'cause' => 'affiliate_service_unavailable',
                'affiliate_id' => $data['affiliateId'],
                'affiliate_document_type' => $data['affiliateDocumentType'],
                'affiliate_document_number' => $data['affiliateDocumentNumber'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 503);
        } catch (\RuntimeException $e) {
            Log::warning('Dotación/EPP: regla de negocio fallida al registrar entrega', [
                'dotacion_epp' => true,
                'action' => 'delivery_failed',
                'cause' => 'business_rule_violation',
                'affiliate_id' => $data['affiliateId'],
                'affiliate_document_type' => $data['affiliateDocumentType'],
                'affiliate_document_number' => $data['affiliateDocumentNumber'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Dotación/EPP: error interno al registrar entrega', [
                'dotacion_epp' => true,
                'action' => 'delivery_failed',
                'cause' => 'internal_error',
                'affiliate_id' => $data['affiliateId'],
                'affiliate_document_type' => $data['affiliateDocumentType'],
                'affiliate_document_number' => $data['affiliateDocumentNumber'],
                'delivery_type' => $data['deliveryType'] ?? null,
                'items_count' => count($data['items'] ?? []),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Error interno del servidor al registrar la entrega',
            ], 500);
        }
    }

    public function returns(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'affiliateId',
                'receivedBy',
                'hospital',
                'startDate',
                'endDate',
                'documentNumber',
                'searchTerm',
                'page',
                'pageSize',
            ]);
            $result = $this->dotacionService->getReturns($filters);

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('Error al obtener devoluciones de dotación/EPP', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Error al obtener el historial de devoluciones',
            ], 500);
        }
    }

    public function storeReturn(StoreSstReturnRequest $request): JsonResponse
    {
        $data = $request->validated();
        $expectedId = sprintf('%s-%s', strtoupper($data['affiliateDocumentType']), $data['affiliateDocumentNumber']);

        if ($expectedId !== $data['affiliateId']) {
            Log::warning('Dotación/EPP: datos de afiliado no coinciden con el identificador al registrar devolución', [
                'dotacion_epp' => true,
                'action' => 'return_failed',
                'cause' => 'affiliate_id_mismatch',
                'affiliate_id_provided' => $data['affiliateId'],
                'affiliate_id_expected' => $expectedId,
                'affiliate_document_type' => $data['affiliateDocumentType'],
                'affiliate_document_number' => $data['affiliateDocumentNumber'],
            ]);

            return response()->json([
                'message' => 'Los datos del afiliado no coinciden con el identificador proporcionado.',
            ], 422);
        }

        try {
            $record = $this->dotacionService->createReturn($data);

            return response()->json([
                'message' => 'Devolución registrada exitosamente',
                'record' => $record,
            ], 200);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Dotación/EPP: validación fallida al registrar devolución', [
                'dotacion_epp' => true,
                'action' => 'return_failed',
                'cause' => 'validation_error',
                'affiliate_id' => $data['affiliateId'],
                'affiliate_document_type' => $data['affiliateDocumentType'],
                'affiliate_document_number' => $data['affiliateDocumentNumber'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (AffiliateServiceUnavailableException $e) {
            Log::warning('Dotación/EPP: servicio de afiliados no disponible al registrar devolución', [
                'dotacion_epp' => true,
                'action' => 'return_failed',
                'cause' => 'affiliate_service_unavailable',
                'affiliate_id' => $data['affiliateId'],
                'affiliate_document_type' => $data['affiliateDocumentType'],
                'affiliate_document_number' => $data['affiliateDocumentNumber'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 503);
        } catch (\RuntimeException $e) {
            Log::warning('Dotación/EPP: regla de negocio fallida al registrar devolución', [
                'dotacion_epp' => true,
                'action' => 'return_failed',
                'cause' => 'business_rule_violation',
                'affiliate_id' => $data['affiliateId'],
                'affiliate_document_type' => $data['affiliateDocumentType'],
                'affiliate_document_number' => $data['affiliateDocumentNumber'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Dotación/EPP: error interno al registrar devolución', [
                'dotacion_epp' => true,
                'action' => 'return_failed',
                'cause' => 'internal_error',
                'affiliate_id' => $data['affiliateId'],
                'affiliate_document_type' => $data['affiliateDocumentType'],
                'affiliate_document_number' => $data['affiliateDocumentNumber'],
                'reason' => $data['reason'] ?? null,
                'items_count' => count($data['items'] ?? []),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Error interno del servidor al registrar la devolución',
            ], 500);
        }
    }
}
