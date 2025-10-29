<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVoteRequest;
use App\Models\Vote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class VoteController extends Controller
{
    /**
     * Store a new vote
     */
    public function store(StoreVoteRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            
            // Extract voter information
            $voter = $validatedData['voter'];
            $candidate = $validatedData['candidate'];
            // Parse timestamp and set to Colombia timezone
            $timestamp = Carbon::parse($validatedData['timestamp'])->setTimezone('America/Bogota');

            // Check if voter has already voted for this candidate
            if (Vote::hasVoted($voter['documentType'], $voter['documentNumber'], $candidate['id'])) {
                Log::warning('Intento de voto duplicado', [
                    'voter_document_type' => $voter['documentType'],
                    'voter_document_number' => $voter['documentNumber'],
                    'candidate_id' => $candidate['id'],
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Ya has votado por este candidato',
                    'error_code' => 'DUPLICATE_VOTE'
                ], 409);
            }

            // Create the vote
            $vote = Vote::create([
                'voter_document_type' => $voter['documentType'],
                'voter_document_number' => $voter['documentNumber'],
                'voter_hospital' => $voter['hospital'],
                'voter_position' => $voter['position'],
                'candidate_id' => $candidate['id'],
                'candidate_name' => $candidate['name'],
                'candidate_position' => $candidate['position'],
                'candidate_hospital' => $candidate['hospital'],
                'vote_timestamp' => $timestamp,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Log successful vote
            Log::info('Voto registrado exitosamente', [
                'vote_id' => $vote->id,
                'voter_document_type' => $voter['documentType'],
                'voter_document_number' => $voter['documentNumber'],
                'voter_hospital' => $voter['hospital'],
                'candidate_id' => $candidate['id'],
                'candidate_name' => $candidate['name'],
                'vote_timestamp' => $timestamp->toISOString(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Voto registrado exitosamente',
                'vote_id' => $vote->id,
                'vote' => [
                    'id' => $vote->id,
                    'voter' => $vote->voter,
                    'candidate' => $vote->candidate,
                    'timestamp' => $vote->vote_timestamp->setTimezone('America/Bogota')->format('Y-m-d\TH:i:s.vP'),
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error al registrar voto', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error_code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    /**
     * Get vote statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $candidateId = $request->query('candidate_id');
            $hospital = $request->query('hospital');
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            $query = Vote::query();

            if ($candidateId) {
                $query->byCandidate($candidateId);
            }

            if ($hospital) {
                $query->byHospital($hospital);
            }

            if ($startDate && $endDate) {
                $query->byDateRange($startDate, $endDate);
            }

            $totalVotes = $query->count();

            // Get votes by candidate
            $votesByCandidate = Vote::selectRaw('candidate_id, candidate_name, COUNT(*) as vote_count')
                ->groupBy('candidate_id', 'candidate_name')
                ->orderBy('vote_count', 'desc')
                ->get();

            // Get votes by hospital
            $votesByHospital = Vote::selectRaw('voter_hospital, COUNT(*) as vote_count')
                ->groupBy('voter_hospital')
                ->orderBy('vote_count', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'statistics' => [
                    'total_votes' => $totalVotes,
                    'votes_by_candidate' => $votesByCandidate,
                    'votes_by_hospital' => $votesByHospital,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error al obtener estadísticas de votación', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
                'error_code' => 'STATISTICS_ERROR'
            ], 500);
        }
    }

    /**
     * Check if voter has voted (regardless of candidate)
     */
    public function checkVote(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'document_type' => 'required|string',
                'document_number' => 'required|string',
            ]);

            $hasVoted = Vote::where('voter_document_type', $request->input('document_type'))
                           ->where('voter_document_number', $request->input('document_number'))
                           ->exists();

            return response()->json([
                'success' => true,
                'has_voted' => $hasVoted,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Error al verificar voto', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error_code' => 'CHECK_VOTE_ERROR'
            ], 500);
        }
    }

    /**
     * Get detailed audit trail of all votes for legal compliance and transparency
     */
    public function auditTrail(Request $request): JsonResponse
    {
        try {
            // Validate optional filters
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'candidate_id' => 'nullable|string',
                'voter_hospital' => 'nullable|string',
                'voter_document_type' => 'nullable|string',
                'voter_document_number' => 'nullable|string',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:1000',
            ]);

            $query = Vote::query();

            // Apply filters
            if ($request->has('start_date')) {
                $query->where('vote_timestamp', '>=', $request->input('start_date'));
            }

            if ($request->has('end_date')) {
                $query->where('vote_timestamp', '<=', $request->input('end_date'));
            }

            if ($request->has('candidate_id')) {
                $query->where('candidate_id', $request->input('candidate_id'));
            }

            if ($request->has('voter_hospital')) {
                $query->where('voter_hospital', $request->input('voter_hospital'));
            }

            if ($request->has('voter_document_type')) {
                $query->where('voter_document_type', $request->input('voter_document_type'));
            }

            if ($request->has('voter_document_number')) {
                $query->where('voter_document_number', $request->input('voter_document_number'));
            }

            // Pagination
            $perPage = $request->input('per_page', 100);
            $page = $request->input('page', 1);

            // Get total count before pagination
            $totalVotes = $query->count();

            // Apply pagination
            $votes = $query->orderBy('vote_timestamp', 'desc')
                          ->skip(($page - 1) * $perPage)
                          ->take($perPage)
                          ->get();

            // Transform votes for response
            $votesData = $votes->map(function ($vote) {
                return [
                    'vote_id' => $vote->id,
                    'voter' => [
                        'document_type' => $vote->voter_document_type,
                        'document_number' => $vote->voter_document_number,
                        'hospital' => $vote->voter_hospital,
                        'position' => $vote->voter_position,
                    ],
                    'candidate' => [
                        'id' => $vote->candidate_id,
                        'name' => $vote->candidate_name,
                        'position' => $vote->candidate_position,
                        'hospital' => $vote->candidate_hospital,
                    ],
                    'vote_timestamp' => $vote->vote_timestamp->setTimezone('America/Bogota')->format('Y-m-d\TH:i:s.vP'),
                    'ip_address' => $vote->ip_address,
                    'user_agent' => $vote->user_agent,
                    'created_at' => $vote->created_at->setTimezone('America/Bogota')->format('Y-m-d\TH:i:s.vP'),
                ];
            });

            // Get summary statistics
            $summaryStats = [
                'total_votes_in_period' => $totalVotes,
                'votes_by_candidate' => Vote::selectRaw('candidate_id, candidate_name, COUNT(*) as vote_count')
                    ->when($request->has('start_date'), function ($q) use ($request) {
                        return $q->where('vote_timestamp', '>=', $request->input('start_date'));
                    })
                    ->when($request->has('end_date'), function ($q) use ($request) {
                        return $q->where('vote_timestamp', '<=', $request->input('end_date'));
                    })
                    ->when($request->has('voter_hospital'), function ($q) use ($request) {
                        return $q->where('voter_hospital', $request->input('voter_hospital'));
                    })
                    ->groupBy('candidate_id', 'candidate_name')
                    ->orderBy('vote_count', 'desc')
                    ->get(),
                'votes_by_hospital' => Vote::selectRaw('voter_hospital, COUNT(*) as vote_count')
                    ->when($request->has('start_date'), function ($q) use ($request) {
                        return $q->where('vote_timestamp', '>=', $request->input('start_date'));
                    })
                    ->when($request->has('end_date'), function ($q) use ($request) {
                        return $q->where('vote_timestamp', '<=', $request->input('end_date'));
                    })
                    ->when($request->has('candidate_id'), function ($q) use ($request) {
                        return $q->where('candidate_id', $request->input('candidate_id'));
                    })
                    ->groupBy('voter_hospital')
                    ->orderBy('vote_count', 'desc')
                    ->get(),
                'votes_by_date' => Vote::selectRaw('DATE(vote_timestamp) as vote_date, COUNT(*) as vote_count')
                    ->when($request->has('start_date'), function ($q) use ($request) {
                        return $q->where('vote_timestamp', '>=', $request->input('start_date'));
                    })
                    ->when($request->has('end_date'), function ($q) use ($request) {
                        return $q->where('vote_timestamp', '<=', $request->input('end_date'));
                    })
                    ->groupBy('vote_date')
                    ->orderBy('vote_date', 'desc')
                    ->get(),
            ];

            // Log the audit request
            Log::info('Auditoría de votos solicitada', [
                'filters' => $request->only([
                    'start_date', 'end_date', 'candidate_id', 
                    'voter_hospital', 'voter_document_type', 'voter_document_number'
                ]),
                'total_results' => $totalVotes,
                'page' => $page,
                'per_page' => $perPage,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => true,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total_votes' => $totalVotes,
                    'total_pages' => ceil($totalVotes / $perPage),
                    'has_next_page' => $page < ceil($totalVotes / $perPage),
                    'has_prev_page' => $page > 1,
                ],
                'summary_statistics' => $summaryStats,
                'votes' => $votesData,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validación fallida en auditoría de votos', [
                'errors' => $e->errors(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Error al obtener auditoría de votos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $request->all(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error_code' => 'AUDIT_TRAIL_ERROR'
            ], 500);
        }
    }
}
