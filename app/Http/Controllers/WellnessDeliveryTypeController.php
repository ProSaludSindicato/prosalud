<?php

namespace App\Http\Controllers;

use App\Http\Requests\{StoreWellnessDeliveryTypeRequest, UpdateWellnessDeliveryTypeRequest};
use App\Models\WellnessDeliveryType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WellnessDeliveryTypeController extends Controller
{
    /**
     * Listar tipos de entrega (para admin y filtros).
     */
    public function index(Request $request): JsonResponse
    {
        $query = WellnessDeliveryType::query()->with('createdBy');

        if ($request->filled('activo')) {
            $query->where('activo', $request->boolean('activo'));
        }
        if ($request->filled('fecha')) {
            $fecha = $request->input('fecha');
            $query->activoParaFecha($fecha);
        }

        $types = $query->orderByDesc('fecha_desde')->get();

        $data = $types->map(fn (WellnessDeliveryType $t) => [
            'id' => $t->id,
            'nombre' => $t->nombre,
            'activo' => $t->activo,
            'modo_acceso' => $t->modo_acceso,
            'fecha_desde' => $t->fecha_desde->format('Y-m-d'),
            'fecha_hasta' => $t->fecha_hasta->format('Y-m-d'),
            'created_by' => $t->createdBy ? [
                'id' => $t->createdBy->id,
                'name' => $t->createdBy->name,
            ] : null,
            'created_at' => $t->created_at->toISOString(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Crear un nuevo tipo de entrega.
     */
    public function store(StoreWellnessDeliveryTypeRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->only(['nombre', 'modo_acceso', 'fecha_desde', 'fecha_hasta']);
        $data['activo'] = $request->boolean('activo', true);
        $data['modo_acceso'] = $data['modo_acceso'] ?? 'listado';
        $data['created_by'] = $user?->id;

        $type = WellnessDeliveryType::create($data);

        Log::info('Tipo de entrega de bienestar creado', [
            'wellness_delivery_type_id' => $type->id,
            'nombre' => $type->nombre,
            'user_id' => $user?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tipo de entrega creado correctamente.',
            'data' => [
                'id' => $type->id,
                'nombre' => $type->nombre,
                'activo' => $type->activo,
                'modo_acceso' => $type->modo_acceso,
                'fecha_desde' => $type->fecha_desde->format('Y-m-d'),
                'fecha_hasta' => $type->fecha_hasta->format('Y-m-d'),
                'created_at' => $type->created_at->toISOString(),
            ],
        ], 201);
    }

    /**
     * Actualizar un tipo de entrega.
     */
    public function update(UpdateWellnessDeliveryTypeRequest $request, WellnessDeliveryType $wellnessDeliveryType): JsonResponse
    {
        $wellnessDeliveryType->update($request->only(['nombre', 'activo', 'modo_acceso', 'fecha_desde', 'fecha_hasta']));

        Log::info('Tipo de entrega de bienestar actualizado', [
            'wellness_delivery_type_id' => $wellnessDeliveryType->id,
            'user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tipo de entrega actualizado correctamente.',
            'data' => [
                'id' => $wellnessDeliveryType->id,
                'nombre' => $wellnessDeliveryType->nombre,
                'activo' => $wellnessDeliveryType->activo,
                'modo_acceso' => $wellnessDeliveryType->modo_acceso,
                'fecha_desde' => $wellnessDeliveryType->fecha_desde->format('Y-m-d'),
                'fecha_hasta' => $wellnessDeliveryType->fecha_hasta->format('Y-m-d'),
                'updated_at' => $wellnessDeliveryType->updated_at->toISOString(),
            ],
        ]);
    }

    /**
     * Ver un tipo de entrega.
     */
    public function show(WellnessDeliveryType $wellnessDeliveryType): JsonResponse
    {
        $wellnessDeliveryType->load('createdBy');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $wellnessDeliveryType->id,
                'nombre' => $wellnessDeliveryType->nombre,
                'activo' => $wellnessDeliveryType->activo,
                'modo_acceso' => $wellnessDeliveryType->modo_acceso,
                'fecha_desde' => $wellnessDeliveryType->fecha_desde->format('Y-m-d'),
                'fecha_hasta' => $wellnessDeliveryType->fecha_hasta->format('Y-m-d'),
                'created_by' => $wellnessDeliveryType->createdBy ? [
                    'id' => $wellnessDeliveryType->createdBy->id,
                    'name' => $wellnessDeliveryType->createdBy->name,
                ] : null,
                'created_at' => $wellnessDeliveryType->created_at->toISOString(),
                'updated_at' => $wellnessDeliveryType->updated_at->toISOString(),
            ],
        ]);
    }
}
