<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWellnessListDeliveryRequest;
use App\Http\Requests\UploadWellnessDeliveryListRequest;
use App\Models\WellnessDeliveryType;
use App\Services\LogSanitizationService;
use App\Services\WellnessDeliveryListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Listado de cupos por campaña (modo entrega_listado): plantilla, carga del Excel y registro de entregas.
 */
class WellnessDeliveryListController extends Controller
{
    public function __construct(private WellnessDeliveryListService $listService) {}

    /**
     * Descargar la plantilla estándar del listado.
     * GET /api/wellness-delivery-types/listado/plantilla
     */
    public function template(): BinaryFileResponse
    {
        return response()->download(
            $this->listService->buildTemplate(),
            'Plantilla_Listado_Entregas_Bienestar.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    /**
     * Cargar (o reemplazar) el listado de cupos de una campaña.
     * POST /api/wellness-delivery-types/{wellness_delivery_type}/listado
     */
    public function upload(UploadWellnessDeliveryListRequest $request, WellnessDeliveryType $wellnessDeliveryType): JsonResponse
    {
        $file = $request->file('file');
        $parsed = $this->listService->parseFile($file->getRealPath());

        if ($parsed['errors'] !== []) {
            return response()->json([
                'success' => false,
                'message' => 'El listado tiene errores. Corríjalos y vuelva a cargarlo.',
                'errors' => array_slice($parsed['errors'], 0, 50),
                'total_errores' => count($parsed['errors']),
            ], 422);
        }

        $summary = $this->listService->replaceList(
            $wellnessDeliveryType,
            $parsed['entries'],
            $file->getClientOriginalName(),
            $request->user(),
        );

        Log::info('Listado de campaña de bienestar cargado', [
            'wellness_delivery_type_id' => $wellnessDeliveryType->id,
            'filas' => $summary['filas'],
            'afiliados' => $summary['afiliados'],
            'cupos' => $summary['cupos'],
            'user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Listado cargado: {$summary['afiliados']} afiliados, {$summary['cupos']} entregas.",
            'data' => [
                'listado' => $summary,
                'columnas_ignoradas' => $parsed['ignored_columns'],
            ],
        ], 201);
    }

    /**
     * Registrar una entrega validada contra el listado de la campaña (queda como entregada).
     * POST /api/wellness-delivery-requests/list-delivery
     */
    public function storeDelivery(StoreWellnessListDeliveryRequest $request): JsonResponse
    {
        $hoy = now(config('app.timezone', 'America/Bogota'))->toDateString();
        $tipo = WellnessDeliveryType::getActivosParaFecha($hoy)->firstWhere('id', (int) $request->input('wellness_delivery_type_id'));

        if (! $tipo || ! $tipo->esEntregaConListado()) {
            return response()->json([
                'success' => false,
                'message' => 'La campaña seleccionada no está activa hoy o no es de entrega directa con listado.',
            ], 422);
        }

        try {
            $deliveryRequest = $this->listService->registerDelivery(
                $tipo,
                $request->string('documento_afiliado')->trim()->toString(),
                $request->input('claves'),
                $request->string('firma')->trim()->toString(),
                $request->user(),
                [
                    'nombre_afiliado' => $request->input('nombre_afiliado'),
                    'hospital' => $request->input('hospital'),
                    'observaciones' => $request->input('observaciones'),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            );
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        }

        Log::info('Entrega de bienestar registrada (entrega con listado)', LogSanitizationService::sanitize([
            'delivery_request_id' => $deliveryRequest->id,
            'wellness_delivery_type_id' => $tipo->id,
            'documento_afiliado' => $deliveryRequest->documento_afiliado,
            'cantidad_entregada' => $deliveryRequest->cantidad_entregada,
            'entregado_por_user_id' => $deliveryRequest->entregado_por_user_id,
        ]));

        $quota = $this->listService->quotaFor($tipo, $deliveryRequest->documento_afiliado);

        return response()->json([
            'success' => true,
            'message' => 'Entrega registrada.',
            'data' => [
                'id' => $deliveryRequest->id,
                'wellness_delivery_type_id' => $tipo->id,
                'tipo_entrega_text' => $tipo->nombre,
                'documento_afiliado' => $deliveryRequest->documento_afiliado,
                'nombre_afiliado' => $deliveryRequest->nombre_afiliado,
                'estado' => $deliveryRequest->estado,
                'cantidad_entregada' => $deliveryRequest->cantidad_entregada,
                'cupo_disponible' => $quota['cupo_disponible'] ?? 0,
                'created_at' => $deliveryRequest->created_at->toISOString(),
            ],
        ], 201);
    }
}
