<?php

namespace App\Http\Controllers\Assembly;

use App\Events\VoteRegistered;
use App\Http\Controllers\Controller;
use App\Models\AssemblyProxy;
use App\Models\AssemblyQuestion;
use App\Models\AssemblyVote;
use App\Models\QuorumConfig;
use App\Services\AssemblyProxyService;
use App\Services\VoterSessionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AssemblyVoteController extends Controller
{
    public function __construct(
        private VoterSessionService $voterSessionService,
        private AssemblyProxyService $proxyService,
    ) {}

    /**
     * Submit a vote
     */
    public function store(Request $request, string $questionId): JsonResponse
    {
        try {
            $question = AssemblyQuestion::with('options')->find($questionId);

            if (! $question) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pregunta no encontrada',
                ], 404);
            }

            // Validate question is open
            if (! $question->isOpen()) {
                return response()->json([
                    'success' => false,
                    'message' => 'La pregunta no está abierta para votación',
                ], 400);
            }

            $session = $this->resolveVoterSession($request, $question->assembly_id);

            if ($session === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Su sesión de votación no es válida o expiró. Valide nuevamente su documento.',
                    'error_code' => 'VOTER_SESSION_INVALID',
                ], 401);
            }

            $validated = $request->validate([
                'selectedOptions' => 'required|array|size:1',
                'selectedOptions.*' => 'required|string',
            ]);

            $selectedOptionKeys = array_map('strval', $validated['selectedOptions']);
            $allowedOptions = AssemblyQuestion::defaultOptionKeys();
            $invalidOptions = array_diff($selectedOptionKeys, $allowedOptions);

            if (! empty($invalidOptions)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Una o más opciones seleccionadas no son válidas',
                    'errors' => [
                        'selectedOptions' => ['Las opciones seleccionadas no pertenecen a esta pregunta'],
                    ],
                ], 400);
            }

            $voterId = $session['document_number'];
            $voterName = $session['name'] !== '' ? $session['name'] : $voterId;
            $proxies = $this->proxyService->activeProxiesFor($question->assembly_id, $voterId);

            $ownBallot = $session['is_delegate'] ? [[
                'voter_id' => $voterId,
                'voter_name' => $voterName,
                'cast_by_document' => null,
                'proxy_id' => null,
            ]] : [];

            if ($ownBallot === [] && $proxies->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tiene votos activos: los delegados que representaba ya no están a su cargo.',
                    'error_code' => 'NO_ACTIVE_VOTES',
                ], 400);
            }

            $ballots = collect($ownBallot)->concat($proxies->map(fn (AssemblyProxy $proxy): array => [
                'voter_id' => $proxy->represented_document,
                'voter_name' => $proxy->represented_name ?: $proxy->represented_document,
                'cast_by_document' => $voterId,
                'proxy_id' => $proxy->id,
            ]));

            DB::beginTransaction();

            $existingVotes = AssemblyVote::query()
                ->where('question_id', $questionId)
                ->whereIn('voter_id', $ballots->pluck('voter_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('voter_id');

            $createdCount = 0;
            $updatedCount = 0;
            $castFor = [];

            foreach ($ballots as $ballot) {
                $existingVote = $existingVotes->get($ballot['voter_id']);

                if ($existingVote === null) {
                    AssemblyVote::create([
                        'question_id' => $questionId,
                        'assembly_id' => $question->assembly_id,
                        'voter_id' => $ballot['voter_id'],
                        'voter_name' => $ballot['voter_name'],
                        'cast_by_document' => $ballot['cast_by_document'],
                        'proxy_id' => $ballot['proxy_id'],
                        'selected_options' => $selectedOptionKeys,
                        'voted_at' => now(),
                    ]);
                    $createdCount++;
                    $castFor[] = ['document' => $ballot['voter_id'], 'name' => $ballot['voter_name']];

                    continue;
                }

                $castBySameVoter = ($existingVote->cast_by_document ?? $existingVote->voter_id) === $voterId;

                if ($question->allow_change_vote && $castBySameVoter) {
                    $existingVote->update([
                        'selected_options' => $selectedOptionKeys,
                        'voter_name' => $ballot['voter_name'],
                        'voted_at' => now(),
                    ]);
                    $updatedCount++;
                    $castFor[] = ['document' => $ballot['voter_id'], 'name' => $ballot['voter_name']];
                }
            }

            if ($createdCount === 0 && $updatedCount === 0) {
                DB::rollBack();

                $ownVote = $existingVotes->get($voterId);
                $castByProxy = $ownVote !== null && $ownVote->cast_by_document !== null && $ownVote->cast_by_document !== $voterId;

                return response()->json([
                    'success' => false,
                    'message' => $castByProxy
                        ? 'Su voto en esta pregunta ya fue emitido por su apoderado'
                        : 'No se permite cambiar el voto',
                    'errors' => [
                        'vote' => ['La pregunta no permite cambiar el voto o ya está cerrada'],
                    ],
                ], 400);
            }

            if ($createdCount > 0) {
                $question->incrementVotesCount($createdCount);
            }

            DB::commit();

            $this->broadcastResults($question);

            Log::info('Vote registered', [
                'question_id' => $questionId,
                'voter_id' => $voterId,
                'cast_for' => array_column($castFor, 'document'),
            ]);

            $vote = $this->findVoteCastBy($questionId, $voterId);

            return response()->json([
                'success' => true,
                'message' => count($castFor) > 1
                    ? 'Voto registrado exitosamente por '.count($castFor).' delegados'
                    : 'Voto registrado exitosamente',
                'data' => [
                    'id' => $vote->id,
                    'questionId' => $vote->question_id,
                    'voterId' => $vote->voter_id,
                    'voterName' => $vote->voter_name,
                    'selectedOptions' => $vote->selected_options,
                    'votedAt' => $vote->voted_at->toISOString(),
                    'castFor' => $castFor,
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
            ], 422);
        } catch (UniqueConstraintViolationException) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Su voto ya fue registrado para esta pregunta',
                'error_code' => 'VOTE_ALREADY_REGISTERED',
            ], 409);
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
            $question = AssemblyQuestion::find($questionId);
            $session = $question ? $this->resolveVoterSession($request, $question->assembly_id) : null;

            if ($session === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Su sesión de votación no es válida o expiró. Valide nuevamente su documento.',
                    'error_code' => 'VOTER_SESSION_INVALID',
                    'data' => null,
                ], 401);
            }

            $vote = $this->findVoteCastBy($questionId, $session['document_number']);

            if (! $vote) {
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

            if (! $question) {
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
     * Broadcast updated results without failing the request when the broadcaster or queue is unavailable.
     */
    private function broadcastResults(AssemblyQuestion $question): void
    {
        try {
            event(new VoteRegistered($question->id, $this->calculateResults($question)));
        } catch (\Throwable $e) {
            Log::warning('Vote saved but results broadcast failed', [
                'question_id' => $question->id,
                'error' => $e->getMessage(),
            ]);
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
        $quorum = QuorumConfig::query()
            ->where('assembly_id', $question->assembly_id)
            ->orderByDesc('updated_at')
            ->first();
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

    /**
     * The voter's own vote on a question or, for a proxy without a vote of their own, one cast on behalf of a represented delegate.
     */
    private function findVoteCastBy(string $questionId, string $voterId): ?AssemblyVote
    {
        return AssemblyVote::query()
            ->where('question_id', $questionId)
            ->where(fn ($query) => $query->where('voter_id', $voterId)->orWhere('cast_by_document', $voterId))
            ->orderByRaw('voter_id = ? desc', [$voterId])
            ->first();
    }

    /**
     * Resolve the voter identity from the signed session token and confirm attendance was recorded.
     *
     * @return array{document_type: string, document_number: string, hospital: string, name: string, position: string, scope: string}|null
     */
    private function resolveVoterSession(Request $request, int|string|null $assemblyId): ?array
    {
        return $this->voterSessionService->verifyAssemblyAttendee($request, $assemblyId);
    }
}
