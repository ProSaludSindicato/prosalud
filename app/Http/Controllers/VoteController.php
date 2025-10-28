<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVoteRequest;
use App\Models\Vote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class feat extends Controller
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
            $timestamp = Carbon::parse($validatedData['timestamp']);

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
                    'timestamp' => $vote->vote_timestamp->toISOString(),
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
}
