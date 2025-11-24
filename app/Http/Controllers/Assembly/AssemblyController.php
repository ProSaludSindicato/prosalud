<?php

namespace App\Http\Controllers\Assembly;

use App\Http\Controllers\Controller;
use App\Models\Assembly;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AssemblyController extends Controller
{
    /**
     * List all assemblies
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Assembly::query();

            // Allow filtering by active status
            if ($request->has('active')) {
                $query->where('is_active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
            }

            $assemblies = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $assemblies->map(fn ($assembly) => $this->formatAssembly($assembly)),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching assemblies', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las asambleas',
            ], 500);
        }
    }

    /**
     * Get the current active assembly
     */
    public function current(): JsonResponse
    {
        try {
            $assembly = Assembly::getCurrent();

            if (!$assembly) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay asamblea activa',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatAssembly($assembly),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching current assembly', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la asamblea activa',
            ], 500);
        }
    }

    /**
     * Get a specific assembly
     */
    public function show(string $id): JsonResponse
    {
        try {
            $assembly = Assembly::with(['questions', 'attendances', 'quorumConfigs'])->find($id);

            if (!$assembly) {
                return response()->json([
                    'success' => false,
                    'message' => 'Asamblea no encontrada',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatAssembly($assembly, true),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching assembly', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la asamblea',
            ], 500);
        }
    }

    /**
     * Create a new assembly
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'startDate' => 'nullable|date',
                'start_date' => 'nullable|date',
                'endDate' => 'nullable|date',
                'end_date' => 'nullable|date',
                'activate' => 'nullable|boolean',
            ]);

            // Manejar tanto camelCase como snake_case del frontend
            $startDate = $validated['startDate'] ?? $validated['start_date'] ?? null;
            $endDate = $validated['endDate'] ?? $validated['end_date'] ?? null;

            // Validar que end_date sea después o igual a start_date si ambos existen
            if ($startDate && $endDate) {
                $startTimestamp = is_string($startDate) ? strtotime($startDate) : $startDate;
                $endTimestamp = is_string($endDate) ? strtotime($endDate) : $endDate;
                
                if ($endTimestamp < $startTimestamp) {
                    return response()->json([
                        'success' => false,
                        'message' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio',
                        'errors' => [
                            'endDate' => ['La fecha de fin debe ser posterior o igual a la fecha de inicio'],
                        ],
                    ], 422);
                }
            }

            // Si se va a activar, desactivar primero todas las demás (antes de crear)
            $willActivate = filter_var($validated['activate'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($willActivate) {
                Assembly::where('is_active', true)->update(['is_active' => false]);
            }

            // Normalizar fechas al formato correcto
            $startDateNormalized = null;
            $endDateNormalized = null;
            
            if ($startDate) {
                $startDateNormalized = is_string($startDate) 
                    ? date('Y-m-d', strtotime($startDate)) 
                    : (is_object($startDate) && method_exists($startDate, 'format') 
                        ? $startDate->format('Y-m-d') 
                        : $startDate);
            }
            
            if ($endDate) {
                $endDateNormalized = is_string($endDate) 
                    ? date('Y-m-d', strtotime($endDate)) 
                    : (is_object($endDate) && method_exists($endDate, 'format') 
                        ? $endDate->format('Y-m-d') 
                        : $endDate);
            }

            $assembly = Assembly::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'start_date' => $startDateNormalized,
                'end_date' => $endDateNormalized,
                'is_active' => $willActivate,
            ]);

            Log::info('Assembly created', ['assembly_id' => $assembly->id]);

            return response()->json([
                'success' => true,
                'data' => $this->formatAssembly($assembly),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating assembly', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la asamblea',
            ], 500);
        }
    }

    /**
     * Update an assembly
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $assembly = Assembly::find($id);

            if (!$assembly) {
                return response()->json([
                    'success' => false,
                    'message' => 'Asamblea no encontrada',
                ], 404);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'startDate' => 'nullable|date',
                'start_date' => 'nullable|date',
                'endDate' => 'nullable|date',
                'end_date' => 'nullable|date',
            ]);

            // Manejar tanto camelCase como snake_case
            $updateData = [];
            if (isset($validated['name'])) {
                $updateData['name'] = $validated['name'];
            }
            if (isset($validated['description'])) {
                $updateData['description'] = $validated['description'];
            }
            
            $startDate = $validated['startDate'] ?? $validated['start_date'] ?? null;
            $endDate = $validated['endDate'] ?? $validated['end_date'] ?? null;
            
            // Normalizar fechas al formato correcto
            if ($startDate !== null) {
                $updateData['start_date'] = is_string($startDate) 
                    ? date('Y-m-d', strtotime($startDate)) 
                    : (is_object($startDate) && method_exists($startDate, 'format') 
                        ? $startDate->format('Y-m-d') 
                        : $startDate);
            }
            if ($endDate !== null) {
                $updateData['end_date'] = is_string($endDate) 
                    ? date('Y-m-d', strtotime($endDate)) 
                    : (is_object($endDate) && method_exists($endDate, 'format') 
                        ? $endDate->format('Y-m-d') 
                        : $endDate);
            }

            // Validar que end_date sea después o igual a start_date si ambos existen
            $finalStartDate = $updateData['start_date'] ?? $assembly->start_date?->format('Y-m-d');
            $finalEndDate = $updateData['end_date'] ?? $assembly->end_date?->format('Y-m-d');
            
            if ($finalStartDate && $finalEndDate && strtotime($finalEndDate) < strtotime($finalStartDate)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio',
                    'errors' => [
                        'endDate' => ['La fecha de fin debe ser posterior o igual a la fecha de inicio'],
                    ],
                ], 422);
            }

            $assembly->update($updateData);

            Log::info('Assembly updated', ['assembly_id' => $assembly->id]);

            return response()->json([
                'success' => true,
                'data' => $this->formatAssembly($assembly),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating assembly', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la asamblea',
            ], 500);
        }
    }

    /**
     * Activate an assembly (deactivates all others)
     */
    public function activate(string $id): JsonResponse
    {
        try {
            $assembly = Assembly::find($id);

            if (!$assembly) {
                return response()->json([
                    'success' => false,
                    'message' => 'Asamblea no encontrada',
                ], 404);
            }

            $assembly->activate();

            Log::info('Assembly activated', ['assembly_id' => $assembly->id]);

            return response()->json([
                'success' => true,
                'message' => 'Asamblea activada correctamente',
                'data' => $this->formatAssembly($assembly),
            ]);
        } catch (\Exception $e) {
            Log::error('Error activating assembly', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al activar la asamblea',
            ], 500);
        }
    }

    /**
     * Deactivate an assembly
     */
    public function deactivate(string $id): JsonResponse
    {
        try {
            $assembly = Assembly::find($id);

            if (!$assembly) {
                return response()->json([
                    'success' => false,
                    'message' => 'Asamblea no encontrada',
                ], 404);
            }

            if (!$assembly->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'La asamblea ya está desactivada',
                ], 422);
            }

            $assembly->update(['is_active' => false]);
            $assembly->refresh();

            Log::info('Assembly deactivated', ['assembly_id' => $assembly->id]);

            return response()->json([
                'success' => true,
                'message' => 'Asamblea desactivada correctamente',
                'data' => $this->formatAssembly($assembly),
            ]);
        } catch (\Exception $e) {
            Log::error('Error deactivating assembly', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al desactivar la asamblea',
            ], 500);
        }
    }

    /**
     * Format assembly for API response
     */
    private function formatAssembly(Assembly $assembly, bool $includeRelations = false): array
    {
        $data = [
            'id' => $assembly->id,
            'name' => $assembly->name,
            'description' => $assembly->description,
            'startDate' => $assembly->start_date?->toDateString(),
            'endDate' => $assembly->end_date?->toDateString(),
            'isActive' => $assembly->is_active,
            'createdAt' => $assembly->created_at->toISOString(),
            'updatedAt' => $assembly->updated_at->toISOString(),
        ];

        if ($includeRelations) {
            $data['questionsCount'] = $assembly->questions()->count();
            $data['attendancesCount'] = $assembly->attendances()->count();
            $data['quorumConfigsCount'] = $assembly->quorumConfigs()->count();
        }

        return $data;
    }
}
