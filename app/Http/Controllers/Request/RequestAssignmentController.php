<?php

namespace App\Http\Controllers\Request;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRequestAssignmentRequest;
use App\Services\RequestAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RequestAssignmentController extends Controller
{
    public function __construct(
        private RequestAssignmentService $assignmentService,
    ) {
    }

    /**
     * Get all request assignments
     * Requires: users.view permission.
     */
    public function index(): JsonResponse
    {
        try {
            $assignments = $this->assignmentService->getAllAssignments();

            Log::info('Asignaciones de solicitudes consultadas');

            return response()->json([
                'success' => true,
                'data' => $assignments,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al consultar asignaciones de solicitudes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al consultar las asignaciones',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Store or update request assignments
     * Requires: users.edit permission.
     */
    public function store(StoreRequestAssignmentRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $assignments = $validated['assignments'] ?? [];
            $subtypeAssignments = $validated['subtype_assignments'] ?? [];

            $result = $this->assignmentService->saveAssignments($assignments, $subtypeAssignments);

            Log::info('Asignaciones de solicitudes guardadas exitosamente');

            return response()->json([
                'success' => true,
                'message' => 'Asignaciones guardadas correctamente',
                'data' => $result,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al guardar asignaciones de solicitudes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al guardar las asignaciones',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Update request assignments (alias for store)
     * Requires: users.edit permission.
     */
    public function update(StoreRequestAssignmentRequest $request): JsonResponse
    {
        return $this->store($request);
    }
}
