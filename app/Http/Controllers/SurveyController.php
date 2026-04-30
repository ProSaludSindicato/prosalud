<?php

namespace App\Http\Controllers;

use App\Http\Requests\Survey\StoreSurveyRequest;
use App\Http\Requests\Survey\UpdateSurveyRequest;
use App\Http\Resources\SurveyResource;
use App\Models\Hospital;
use App\Models\Survey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SurveyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 100);

        $query = Survey::query()->withCount('responses')->with('hospitals');

        if ($request->filled('status')) {
            $query->byStatus($request->input('status'));
        }

        if ($request->filled('access_type')) {
            $query->byAccessType($request->input('access_type'));
        }

        $surveys = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => SurveyResource::collection($surveys->items()),
            'meta' => [
                'current_page' => $surveys->currentPage(),
                'last_page' => $surveys->lastPage(),
                'per_page' => $surveys->perPage(),
                'total' => $surveys->total(),
            ],
        ]);
    }

    public function store(StoreSurveyRequest $request): JsonResponse
    {
        $validated = $request->validated();

        DB::beginTransaction();
        try {
            $survey = Survey::create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? 'draft',
                'access_type' => $validated['access_type'],
                'allowed_affiliate_statuses' => $validated['allowed_affiliate_statuses'] ?? ['activo'],
                'requires_signature' => $validated['requires_signature'] ?? false,
                'allows_multiple_responses' => $validated['allows_multiple_responses'] ?? true,
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncQuestions($survey, $validated['questions']);

            if ($validated['access_type'] === 'restricted' && ! empty($validated['hospital_ids'])) {
                $survey->hospitals()->sync($validated['hospital_ids']);
            }

            DB::commit();

            $survey->load(['questions', 'hospitals']);

            Log::info('[Encuestas dinámicas] Encuesta creada', [
                'survey_id' => $survey->id,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Encuesta creada correctamente.',
                'data' => new SurveyResource($survey),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Encuestas dinámicas] Error creando encuesta', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la encuesta. Por favor, intente nuevamente.',
            ], 500);
        }
    }

    public function show(Survey $survey): JsonResponse
    {
        $survey->load(['questions', 'hospitals']);
        $survey->loadCount('responses');

        return response()->json([
            'success' => true,
            'data' => new SurveyResource($survey),
        ]);
    }

    public function update(UpdateSurveyRequest $request, Survey $survey): JsonResponse
    {
        $validated = $request->validated();

        DB::beginTransaction();
        try {
            $survey->update(collect($validated)->except(['questions', 'hospital_ids'])->toArray());

            if (isset($validated['questions'])) {
                $this->syncQuestions($survey, $validated['questions']);
            }

            if (isset($validated['hospital_ids'])) {
                $survey->hospitals()->sync($validated['hospital_ids'] ?? []);
            }

            DB::commit();

            $survey->load(['questions', 'hospitals']);
            $survey->loadCount('responses');

            Log::info('[Encuestas dinámicas] Encuesta actualizada', [
                'survey_id' => $survey->id,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Encuesta actualizada correctamente.',
                'data' => new SurveyResource($survey),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Encuestas dinámicas] Error actualizando encuesta', [
                'survey_id' => $survey->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la encuesta. Por favor, intente nuevamente.',
            ], 500);
        }
    }

    public function updateStatus(Request $request, Survey $survey): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:draft,active,closed',
        ]);

        $survey->update(['status' => $validated['status']]);

        $survey->load(['questions', 'hospitals']);
        $survey->loadCount('responses');

        Log::info('[Encuestas dinámicas] Estado de encuesta actualizado', [
            'survey_id' => $survey->id,
            'new_status' => $validated['status'],
            'user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Estado de la encuesta actualizado correctamente.',
            'data' => new SurveyResource($survey),
        ]);
    }

    public function duplicate(Request $request, Survey $survey): JsonResponse
    {
        DB::beginTransaction();
        try {
            $copy = Survey::create([
                'title' => 'Copia de '.$survey->title,
                'description' => $survey->description,
                'status' => 'draft',
                'access_type' => $survey->access_type,
                'allowed_affiliate_statuses' => $survey->allowed_affiliate_statuses ?? ['activo'],
                'requires_signature' => $survey->requires_signature,
                'allows_multiple_responses' => $survey->allows_multiple_responses,
                'start_date' => null,
                'end_date' => null,
                'created_by' => $request->user()?->id,
            ]);

            $survey->load('questions');

            $toInsert = $survey->questions->map(fn ($q) => [
                'survey_id' => $copy->id,
                'type' => $q->type,
                'label' => $q->label,
                'help_text' => $q->help_text,
                'is_required' => $q->is_required,
                'order' => $q->order,
                'options' => $q->options ? json_encode($q->options) : null,
                'ranking_unique_priority' => $q->ranking_unique_priority,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            if (! empty($toInsert)) {
                $copy->questions()->insert($toInsert);
            }

            if ($survey->access_type === 'restricted') {
                $survey->load('hospitals');
                $copy->hospitals()->sync($survey->hospitals->pluck('id'));
            }

            DB::commit();

            $copy->load(['questions', 'hospitals']);

            Log::info('[Encuestas dinámicas] Encuesta duplicada', [
                'original_id' => $survey->id,
                'copy_id' => $copy->id,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Encuesta duplicada correctamente.',
                'data' => new SurveyResource($copy),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Encuestas dinámicas] Error duplicando encuesta', [
                'survey_id' => $survey->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al duplicar la encuesta. Por favor, intente nuevamente.',
            ], 500);
        }
    }

    public function destroy(Request $request, Survey $survey): JsonResponse
    {
        if ($survey->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar una encuesta activa. Ciérrela primero.',
            ], 409);
        }

        $survey->delete();

        Log::info('[Encuestas dinámicas] Encuesta eliminada', [
            'survey_id' => $survey->id,
            'user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Encuesta eliminada correctamente.',
        ]);
    }

    public function filterOptions(): JsonResponse
    {
        $hospitals = Hospital::orderBy('name')->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => [
                'hospitals' => $hospitals,
                'statuses' => [
                    ['value' => 'draft', 'label' => 'Borrador'],
                    ['value' => 'active', 'label' => 'Activa'],
                    ['value' => 'closed', 'label' => 'Cerrada'],
                ],
                'access_types' => [
                    ['value' => 'public', 'label' => 'Pública'],
                    ['value' => 'authenticated', 'label' => 'Autenticada'],
                    ['value' => 'restricted', 'label' => 'Restringida por hospital'],
                ],
            ],
        ]);
    }

    private function syncQuestions(Survey $survey, array $questions): void
    {
        $survey->questions()->delete();

        $toInsert = array_map(function (array $q, int $index) use ($survey) {
            $type = $q['type'];

            return [
                'survey_id' => $survey->id,
                'type' => $type,
                'label' => $q['label'],
                'help_text' => $q['help_text'] ?? null,
                'is_required' => (bool) ($q['is_required'] ?? false),
                'order' => $q['order'] ?? $index,
                'options' => isset($q['options']) ? json_encode($q['options']) : null,
                'ranking_unique_priority' => $type === 'ranking' ? (bool) ($q['ranking_unique_priority'] ?? true) : true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $questions, array_keys($questions));

        $survey->questions()->insert($toInsert);
    }
}
