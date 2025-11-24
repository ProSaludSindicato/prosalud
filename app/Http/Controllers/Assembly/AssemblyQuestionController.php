<?php

namespace App\Http\Controllers\Assembly;

use App\Http\Controllers\Controller;
use App\Models\Assembly;
use App\Models\AssemblyQuestion;
use App\Models\QuorumConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssemblyQuestionController extends Controller
{
    /**
     * Get all questions for the active assembly
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $assembly = $this->getAssembly($request);
            
            $query = AssemblyQuestion::with('options');
            
            if ($assembly) {
                $query->where('assembly_id', $assembly->id);
            }
            
            $questions = $query->orderBy('order')
                ->get()
                ->map(function (AssemblyQuestion $question) {
                    return $this->formatQuestion($this->ensureDefaultOptions($question));
                });

            return response()->json($questions);
        } catch (\Exception $e) {
            Log::error('Error fetching questions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las preguntas',
            ], 500);
        }
    }

    /**
     * Get a specific question
     */
    public function show(string $id): JsonResponse
    {
        try {
            $question = AssemblyQuestion::with('options')->find($id);

            if (!$question) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pregunta no encontrada',
                ], 404);
            }

            return response()->json($this->formatQuestion($this->ensureDefaultOptions($question)));
        } catch (\Exception $e) {
            Log::error('Error fetching question', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la pregunta',
            ], 500);
        }
    }

    /**
     * Create a new question
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'nullable|string|max:500',
                'description' => 'nullable|string',
                'helpText' => 'nullable|string',
                'timeLimit' => 'nullable|integer|min:0',
                'majorityType' => 'nullable|in:SIMPLE,ABSOLUTE,TWO_THIRDS',
                'resultsVisible' => 'nullable|boolean',
                'quorumRequired' => 'boolean',
                'allowChangeVote' => 'boolean',
                'order' => 'nullable|integer',
                'type' => 'nullable|in:SINGLE',
            ]);

            DB::beginTransaction();

            $assembly = $this->getAssembly($request);
            
            if (!$assembly) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay asamblea activa. Por favor, active una asamblea primero.',
                ], 400);
            }

            // Calculate order if not provided
            if (!isset($validated['order'])) {
                $maxOrder = AssemblyQuestion::where('assembly_id', $assembly->id)->max('order') ?? 0;
                $validated['order'] = $maxOrder + 1;
            }

            $question = AssemblyQuestion::create([
                'id' => (string) Str::uuid(),
                'assembly_id' => $assembly->id,
                'title' => $validated['title'] ?? 'Pregunta #' . $validated['order'],
                'description' => $validated['description'] ?? null,
                'help_text' => $validated['helpText'] ?? null,
                'type' => 'SINGLE',
                'majority_type' => $validated['majorityType'] ?? 'SIMPLE',
                'time_limit' => $validated['timeLimit'] ?? 0,
                'quorum_required' => $validated['quorumRequired'] ?? false,
                'allow_change_vote' => $validated['allowChangeVote'] ?? false,
                'order' => $validated['order'],
                'status' => 'PENDING',
                'votes_count' => 0,
                'results_visible' => $validated['resultsVisible'] ?? false,
            ]);

            $question->syncDefaultOptions();
            DB::commit();

            Log::info('Question created', ['question_id' => $question->id]);

            return response()->json([
                'success' => true,
                'data' => $this->formatQuestion($question->fresh(['options'])),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating question', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la pregunta',
            ], 500);
        }
    }

    /**
     * Update a question
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $question = AssemblyQuestion::find($id);

            if (!$question) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pregunta no encontrada',
                ], 404);
            }

            $validated = $request->validate([
                'title' => 'sometimes|string|max:500',
                'description' => 'nullable|string',
                'helpText' => 'nullable|string',
                'majorityType' => 'sometimes|in:SIMPLE,ABSOLUTE,TWO_THIRDS',
                'timeLimit' => 'sometimes|integer|min:0',
                'quorumRequired' => 'boolean',
                'allowChangeVote' => 'boolean',
                'order' => 'integer',
                'status' => 'sometimes|in:PENDING,OPEN,CLOSED',
                'resultsVisible' => 'boolean',
            ]);

            $updateData = [];
            if (isset($validated['title'])) {
                $updateData['title'] = $validated['title'];
            }
            if (isset($validated['description'])) {
                $updateData['description'] = $validated['description'];
            }
            if (isset($validated['helpText'])) {
                $updateData['help_text'] = $validated['helpText'];
            }
            if (isset($validated['majorityType'])) {
                $updateData['majority_type'] = $validated['majorityType'];
            }
            if (isset($validated['timeLimit'])) {
                $updateData['time_limit'] = $validated['timeLimit'];
            }
            if (isset($validated['quorumRequired'])) {
                $updateData['quorum_required'] = $validated['quorumRequired'];
            }
            if (isset($validated['allowChangeVote'])) {
                $updateData['allow_change_vote'] = $validated['allowChangeVote'];
            }
            if (isset($validated['order'])) {
                $updateData['order'] = $validated['order'];
            }
            if (isset($validated['status'])) {
                $updateData['status'] = $validated['status'];
            }
            if (isset($validated['resultsVisible'])) {
                $updateData['results_visible'] = $validated['resultsVisible'];
            }

            $question->update($updateData);
            $question->syncDefaultOptions();

            Log::info('Question updated', ['question_id' => $question->id]);

            return response()->json([
                'success' => true,
                'data' => $this->formatQuestion($question->fresh(['options'])),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating question', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la pregunta',
            ], 500);
        }
    }

    /**
     * Delete a question
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $question = AssemblyQuestion::find($id);

            if (!$question) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pregunta no encontrada',
                ], 404);
            }

            $question->delete();

            Log::info('Question deleted', ['question_id' => $id]);

            return response()->json([
                'success' => true,
                'message' => 'Pregunta eliminada exitosamente',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting question', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la pregunta',
            ], 500);
        }
    }

    /**
     * Open a question
     */
    public function open(string $id): JsonResponse
    {
        try {
            $question = AssemblyQuestion::find($id);

            if (!$question) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pregunta no encontrada',
                ], 404);
            }

            $result = $this->openQuestion($question);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $result->id,
                    'status' => $result->status,
                    'openedAt' => $result->opened_at?->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error opening question', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al abrir la pregunta',
            ], 500);
        }
    }

    /**
     * Close a question
     */
    public function close(string $id): JsonResponse
    {
        try {
            $question = AssemblyQuestion::find($id);

            if (!$question) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pregunta no encontrada',
                ], 404);
            }

            if (!$question->isOpen()) {
                return response()->json([
                    'success' => false,
                    'message' => 'La pregunta no está abierta',
                ], 400);
            }

            $this->closeQuestion($question);

            Log::info('Question closed', ['question_id' => $question->id]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $question->id,
                    'status' => $question->status,
                    'closedAt' => $question->closed_at?->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error closing question', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al cerrar la pregunta',
            ], 500);
        }
    }

    /**
     * Format question for API response
     */
    private function formatQuestion(AssemblyQuestion $question): array
    {
        $options = $question->options->map(function ($option) {
            return [
                'id' => $option->option_key,
                'text' => $option->text,
                'description' => $option->description,
                'order' => $option->order,
            ];
        });

        if ($options->isEmpty()) {
            $options = AssemblyQuestion::defaultOptions();
        }

        return [
            'id' => $question->id,
            'title' => $question->title,
            'description' => $question->description,
            'helpText' => $question->help_text,
            'type' => $question->type,
            'majorityType' => $question->majority_type,
            'status' => $question->status,
            'timeLimit' => $question->time_limit,
            'quorumRequired' => $question->quorum_required,
            'allowChangeVote' => $question->allow_change_vote,
            'order' => $question->order,
            'openedAt' => $question->opened_at?->toISOString(),
            'closedAt' => $question->closed_at?->toISOString(),
            'votesCount' => $question->votes_count,
            'resultsVisible' => $question->results_visible,
            'options' => $options->map(function ($option) {
                return [
                    'id' => $option['id'],
                    'text' => $option['text'],
                    'description' => $option['description'] ?? null,
                ];
            })->values()->toArray(),
        ];
    }

    private function ensureDefaultOptions(AssemblyQuestion $question): AssemblyQuestion
    {
        $optionKeys = $question->options->pluck('option_key')->filter()->unique()->values()->all();
        if (count(array_intersect($optionKeys, AssemblyQuestion::defaultOptionKeys())) !== count(AssemblyQuestion::defaultOptionKeys())) {
            $question->syncDefaultOptions();
        }

        return $question->loadMissing('options');
    }

    /**
     * Create and open a new live question
     */
    public function startLiveQuestion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:500',
            'timeLimit' => 'nullable|integer|min:0',
            'majorityType' => 'nullable|in:SIMPLE,ABSOLUTE,TWO_THIRDS',
            'quorumRequired' => 'boolean',
            'allowChangeVote' => 'boolean',
            'resultsVisible' => 'boolean',
        ]);

        try {
            $assembly = $this->getAssembly($request);
            
            if (!$assembly) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay asamblea activa. Por favor, active una asamblea primero.',
                ], 400);
            }

            return DB::transaction(function () use ($validated, $assembly) {
                $nextOrder = (AssemblyQuestion::where('assembly_id', $assembly->id)->max('order') ?? 0) + 1;

                $question = AssemblyQuestion::create([
                    'id' => (string) Str::uuid(),
                    'assembly_id' => $assembly->id,
                    'title' => $validated['title'] ?? 'Pregunta #' . $nextOrder,
                    'description' => null,
                    'help_text' => null,
                    'type' => 'SINGLE',
                    'majority_type' => $validated['majorityType'] ?? 'SIMPLE',
                    'time_limit' => $validated['timeLimit'] ?? 0,
                    'quorum_required' => $validated['quorumRequired'] ?? false,
                    'allow_change_vote' => $validated['allowChangeVote'] ?? false,
                    'order' => $nextOrder,
                    'status' => 'PENDING',
                    'votes_count' => 0,
                    'results_visible' => $validated['resultsVisible'] ?? false,
                ]);

                $question->syncDefaultOptions();

                $openedQuestion = $this->openQuestion($question);

                Log::info('Live question started', ['question_id' => $openedQuestion->id]);

                return response()->json([
                    'success' => true,
                    'data' => $this->formatQuestion($openedQuestion),
                ], 201);
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error starting live question', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al iniciar la votación',
            ], 500);
        }
    }

    /**
     * Close the currently open question (if any)
     */
    public function closeActiveQuestion(Request $request): JsonResponse
    {
        try {
            $assembly = $this->getAssembly($request);
            
            $query = AssemblyQuestion::where('status', 'OPEN');
            
            if ($assembly) {
                $query->where('assembly_id', $assembly->id);
            }
            
            $question = $query->first();

            if (!$question) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay votaciones abiertas',
                ], 404);
            }

            $this->closeQuestion($question);

            Log::info('Active live question closed', ['question_id' => $question->id]);

            return response()->json([
                'success' => true,
                'data' => $this->formatQuestion($question),
            ]);
        } catch (\Exception $e) {
            Log::error('Error closing active question', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al cerrar la votación',
            ], 500);
        }
    }

    private function openQuestion(AssemblyQuestion $question): AssemblyQuestion
    {
        $currentOpen = AssemblyQuestion::where('status', 'OPEN')
            ->where('assembly_id', $question->assembly_id)
            ->where('id', '!=', $question->id)
            ->first();

        if ($currentOpen) {
            $currentOpen->update([
                'status' => 'CLOSED',
                'closed_at' => now(),
            ]);
        }

        if ($question->quorum_required) {
            $quorum = QuorumConfig::getCurrent();
            if (!$quorum || !$quorum->verified) {
                throw ValidationException::withMessages([
                    'quorum' => ['El quórum debe estar verificado para abrir esta pregunta'],
                ]);
            }
        }

        if ($question->isOpen()) {
            $question->update(['opened_at' => now()]);
        } else {
            $question->update([
                'status' => 'OPEN',
                'opened_at' => now(),
                'closed_at' => null,
            ]);
        }

        return $question->refresh();
    }

    private function closeQuestion(AssemblyQuestion $question): AssemblyQuestion
    {
        $question->update([
            'status' => 'CLOSED',
            'closed_at' => now(),
        ]);

        return $question->refresh();
    }

    /**
     * Get the active assembly from request or default
     */
    private function getAssembly(Request $request): ?Assembly
    {
        // Allow specifying assembly_id in query params for admin queries
        if ($request->has('assembly_id')) {
            return Assembly::find($request->input('assembly_id'));
        }
        
        // Default to active assembly
        return Assembly::getCurrent() ?? Assembly::getOrCreateDefault();
    }
}
