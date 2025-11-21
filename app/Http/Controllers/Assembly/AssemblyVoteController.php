<?php

namespace App\Http\Controllers\Assembly;

use App\Events\VoteRegistered;
use App\Http\Controllers\Controller;
use App\Models\AssemblyQuestion;
use App\Models\AssemblyVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AssemblyVoteController extends Controller
{
    /**
     * Submit a vote
     */
    public function store(Request $request, string $questionId): JsonResponse
    {
        try {
            $question = AssemblyQuestion::with('options')->find($questionId);

            if (!$question) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pregunta no encontrada',
                ], 404);
            }

            // Validate question is open
            if (!$question->isOpen()) {
                return response()->json([
                    'success' => false,
                    'message' => 'La pregunta no está abierta para votación',
                ], 400);
            }

            $validated = $request->validate([
                'voterId' => 'required|string|max:255',
                'voterName' => 'required|string|max:255',
                'selectedOptions' => 'required|array|size:1',
                'selectedOptions.*' => 'required|string',
            ]);

            $selectedOptionKeys = array_map('strval', $validated['selectedOptions']);
            $allowedOptions = AssemblyQuestion::defaultOptionKeys();
            $invalidOptions = array_diff($selectedOptionKeys, $allowedOptions);

            if (!empty($invalidOptions)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Una o más opciones seleccionadas no son válidas',
                    'errors' => [
                        'selectedOptions' => ['Las opciones seleccionadas no pertenecen a esta pregunta'],
                    ],
                ], 400);
            }

            DB::beginTransaction();

            // Check if user already voted
            $existingVote = AssemblyVote::where('question_id', $questionId)
                ->where('voter_id', $validated['voterId'])
                ->first();

            if ($existingVote) {
                if (!$question->allow_change_vote) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'No se permite cambiar el voto',
                        'errors' => [
                            'vote' => ['La pregunta no permite cambiar el voto o ya está cerrada'],
                        ],
                    ], 400);
                }

                // Update existing vote
                $existingVote->update([
                    'selected_options' => $selectedOptionKeys,
                    'voter_name' => $validated['voterName'],
                    'voted_at' => now(),
                ]);

                $vote = $existingVote;
            } else {
                // Create new vote
                $vote = AssemblyVote::create([
                    'question_id' => $questionId,
                    'voter_id' => $validated['voterId'],
                    'voter_name' => $validated['voterName'],
                    'selected_options' => $selectedOptionKeys,
                    'voted_at' => now(),
                ]);

                $question->incrementVotesCount();
            }

            DB::commit();

            // Calculate and broadcast results
            $results = $this->calculateResults($question);
            event(new VoteRegistered($questionId, $results));

            Log::info('Vote registered', [
                'question_id' => $questionId,
                'voter_id' => $validated['voterId'],
            ]);

            $question->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Voto registrado exitosamente',
                'data' => [
                    'id' => $vote->id,
                    'questionId' => $vote->question_id,
                    'voterId' => $vote->voter_id,
                    'voterName' => $vote->voter_name,
                    'selectedOptions' => $vote->selected_options,
                    'votedAt' => $vote->voted_at->toISOString(),
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error registering vote', [
                'question_id' => $questionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar el voto',
            ], 500);
        }
    }

    /**
     * Get user's vote for a question
     */
    public function getMyVote(Request $request, string $questionId): JsonResponse
    {
        try {
            $request->validate([
                'voterId' => 'required|string',
            ]);

            $vote = AssemblyVote::where('question_id', $questionId)
                ->where('voter_id', $request->input('voterId'))
                ->first();

            if (!$vote) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $vote->id,
                    'questionId' => $vote->question_id,
                    'voterId' => $vote->voter_id,
                    'voterName' => $vote->voter_name,
                    'selectedOptions' => $vote->selected_options,
                    'votedAt' => $vote->voted_at->toISOString(),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error fetching vote', [
                'question_id' => $questionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el voto',
            ], 500);
        }
    }

    /**
     * Get results for a question
     */
    public function getResults(string $questionId): JsonResponse
    {
        try {
            $question = AssemblyQuestion::with('options')->find($questionId);

            if (!$question) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pregunta no encontrada',
                ], 404);
            }

            $results = $this->calculateResults($question);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching results', [
                'question_id' => $questionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los resultados',
            ], 500);
        }
    }

    /**
     * Calculate results for a question
     */
    private function calculateResults(AssemblyQuestion $question): array
    {
        $votes = AssemblyVote::where('question_id', $question->id)->get();
        $totalVotes = $votes->count();

        $optionTexts = $question->options->pluck('text', 'option_key');

        $optionResults = AssemblyQuestion::defaultOptions()->map(function ($option) use ($votes, $totalVotes, $optionTexts) {
            $optionVotes = $votes->filter(function ($vote) use ($option) {
                return in_array($option['id'], $vote->selected_options);
            })->count();

            $percentage = $totalVotes > 0 ? ($optionVotes / $totalVotes) * 100 : 0;

            return [
                'optionId' => $option['id'],
                'optionText' => $optionTexts[$option['id']] ?? $option['text'],
                'votes' => $optionVotes,
                'percentage' => round($percentage, 2),
            ];
        })->toArray();

        $maxVotes = $totalVotes > 0 ? max(array_column($optionResults, 'votes')) : 0;
        $quorum = \App\Models\QuorumConfig::getCurrent();
        $presentDelegates = $quorum ? $quorum->present_delegates : 0;

        $majorityAchieved = false;
        switch ($question->majority_type) {
            case 'SIMPLE':
                $majorityAchieved = $maxVotes > ($totalVotes - $maxVotes);
                break;
            case 'ABSOLUTE':
                $majorityAchieved = $maxVotes > ($presentDelegates / 2);
                break;
            case 'TWO_THIRDS':
                $majorityAchieved = $maxVotes >= ($presentDelegates * 2 / 3);
                break;
        }

        return [
            'questionId' => $question->id,
            'totalVotes' => $totalVotes,
            'optionResults' => $optionResults,
            'majorityAchieved' => $majorityAchieved,
            'majorityType' => $question->majority_type,
        ];
    }
}
