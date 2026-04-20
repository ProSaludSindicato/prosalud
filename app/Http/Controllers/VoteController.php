<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVoteRequest;
use App\Models\Vote;
use App\Models\VotingSetting;
use App\Services\ExcelReaderService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VoteController extends Controller
{
    private ExcelReaderService $excelReaderService;

    public function __construct(ExcelReaderService $excelReaderService)
    {
        $this->excelReaderService = $excelReaderService;
    }

    /**
     * Store a new vote.
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
            $activeElectionKey = $this->resolveElectionKeyFromRequest(null, true);

            if ($activeElectionKey === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay un periodo activo para la votación de delegados.',
                    'error_code' => 'CANDIDATE_ELECTION_PERIOD_INACTIVE',
                ], 422);
            }

            if (Vote::query()
                ->where('voter_document_type', $voter['documentType'])
                ->where('voter_document_number', $voter['documentNumber'])
                ->where('candidate_election_key', $activeElectionKey)
                ->exists()) {
                Log::warning('Intento de voto duplicado (afiliado ya votó)', [
                    'voter_document_type' => $voter['documentType'],
                    'voter_document_number' => $voter['documentNumber'],
                    'candidate_election_key' => $activeElectionKey,
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Ya registró su voto en esta elección.',
                    'error_code' => 'ALREADY_VOTED',
                ], 409);
            }

            if (! $this->excelReaderService->isDelegadosFileAvailable()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                    'error_code' => 'DELEGADOS_FILE_UNAVAILABLE',
                ], 503);
            }

            $delegado = $this->excelReaderService->getDelegadoByCedula((string) $candidate['id']);

            if ($delegado === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Candidato no encontrado',
                    'error_code' => 'CANDIDATE_NOT_FOUND',
                ], 404);
            }

            $voterHospital = strtoupper(trim((string) $voter['hospital']));
            $delegadoSede = strtoupper(trim((string) ($delegado['sede'] ?? '')));

            if ($delegadoSede === '' || $voterHospital !== $delegadoSede) {
                Log::warning('Intento de voto con candidato que no corresponde a la sede del votante', [
                    'voter_hospital' => $voter['hospital'],
                    'delegado_sede' => $delegado['sede'] ?? null,
                    'candidate_id' => $candidate['id'],
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'El candidato seleccionado no corresponde a su sede.',
                    'error_code' => 'CANDIDATE_HOSPITAL_MISMATCH',
                ], 422);
            }

            $candidateName = trim((string) ($delegado['nombre_apellidos'] ?? ''));
            $candidatePosition = trim((string) ($delegado['proceso'] ?? ''));
            $candidateHospital = trim((string) ($delegado['sede'] ?? ''));

            // Create the vote
            $vote = Vote::create([
                'voter_document_type' => $voter['documentType'],
                'voter_document_number' => $voter['documentNumber'],
                'voter_hospital' => $voter['hospital'],
                'voter_position' => $voter['position'],
                'candidate_election_key' => $activeElectionKey,
                'candidate_id' => (string) ($delegado['cedula'] ?? $candidate['id']),
                'candidate_name' => $candidateName !== '' ? $candidateName : $candidate['name'],
                'candidate_position' => $candidatePosition !== '' ? $candidatePosition : $candidate['position'],
                'candidate_hospital' => $candidateHospital !== '' ? $candidateHospital : $candidate['hospital'],
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
                'candidate_id' => $vote->candidate_id,
                'candidate_name' => $vote->candidate_name,
                'vote_timestamp' => $timestamp->toISOString(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
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
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al registrar voto', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error_code' => 'INTERNAL_ERROR',
            ], 500);
        }
    }

    /**
     * Get vote statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $candidateId = $request->query('candidate_id');
            $hospital = $request->query('hospital');
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');
            $candidateElectionKey = $this->resolveElectionKeyFromRequest(
                $request->query('candidate_election_key'),
                true
            );

            if ($candidateElectionKey === null) {
                return response()->json([
                    'success' => true,
                    'statistics' => [
                        'total_votes' => 0,
                        'votes_by_candidate' => [],
                        'votes_by_hospital' => [],
                    ],
                    'selected_candidate_election_key' => null,
                    'active_candidate_election_key' => VotingSetting::current()->active_candidate_election_key,
                ]);
            }

            $query = Vote::query()->byElectionKey($candidateElectionKey);

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
            $votesByCandidate = Vote::query()
                ->byElectionKey($candidateElectionKey)
                ->selectRaw('candidate_id, candidate_name, candidate_hospital, COUNT(*) as vote_count')
                ->groupBy('candidate_id', 'candidate_name', 'candidate_hospital')
                ->orderBy('vote_count', 'desc')
                ->get()
                ->map(function ($item) {
                    return [
                        'candidate_id' => $item->candidate_id,
                        'candidate_name' => $item->candidate_name,
                        'hospital' => $item->candidate_hospital,
                        'vote_count' => $item->vote_count,
                    ];
                });

            // Get votes by hospital
            $votesByHospital = Vote::query()
                ->byElectionKey($candidateElectionKey)
                ->selectRaw('voter_hospital, COUNT(*) as vote_count')
                ->groupBy('voter_hospital')
                ->orderBy('vote_count', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'statistics' => [
                    'total_votes' => $totalVotes,
                    'votes_by_candidate' => $votesByCandidate,
                    'votes_by_hospital' => $votesByHospital,
                ],
                'selected_candidate_election_key' => $candidateElectionKey,
                'active_candidate_election_key' => VotingSetting::current()->active_candidate_election_key,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener estadísticas de votación', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
                'error_code' => 'STATISTICS_ERROR',
            ], 500);
        }
    }

    /**
     * Get vote statistics by hospital (assembly and candidates)
     * If hospital is provided, returns statistics for that hospital
     * If hospital is not provided, returns general statistics.
     */
    public function hospitalStatistics(Request $request): JsonResponse
    {
        try {
            $hospital = $request->query('hospital');
            $candidateElectionKey = $this->resolveElectionKeyFromRequest(
                $request->query('candidate_election_key'),
                true
            );

            if ($candidateElectionKey === null) {
                return response()->json([
                    'success' => true,
                    'statistics' => [
                        'total_votes' => 0,
                        'votes_by_candidate' => [],
                        'votes_by_hospital' => [],
                    ],
                    'selected_candidate_election_key' => null,
                    'active_candidate_election_key' => VotingSetting::current()->active_candidate_election_key,
                ]);
            }

            // Base query - apply hospital filter if provided
            $baseQuery = Vote::query()->byElectionKey($candidateElectionKey);
            if ($hospital) {
                $baseQuery->byHospital($hospital);
            }

            // Get total votes
            $totalVotes = $baseQuery->count();

            // Get votes by candidate (apply hospital filter if provided)
            $votesByCandidateQuery = Vote::query()
                ->byElectionKey($candidateElectionKey)
                ->selectRaw('candidate_id, candidate_name, candidate_hospital, COUNT(*) as vote_count')
                ->groupBy('candidate_id', 'candidate_name', 'candidate_hospital');

            if ($hospital) {
                $votesByCandidateQuery->byHospital($hospital);
            }

            $votesByCandidate = $votesByCandidateQuery
                ->orderBy('vote_count', 'desc')
                ->get()
                ->map(function ($item) {
                    return [
                        'candidate_id' => $item->candidate_id,
                        'candidate_name' => $item->candidate_name,
                        'hospital' => $item->candidate_hospital,
                        'vote_count' => $item->vote_count,
                    ];
                });

            // Get votes by hospital
            // If hospital filter is applied, this will only show that hospital
            // If not, it will show all hospitals
            $votesByHospitalQuery = Vote::query()
                ->byElectionKey($candidateElectionKey)
                ->selectRaw('voter_hospital, COUNT(*) as vote_count')
                ->groupBy('voter_hospital');

            if ($hospital) {
                $votesByHospitalQuery->byHospital($hospital);
            }

            $votesByHospital = $votesByHospitalQuery
                ->orderBy('vote_count', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'statistics' => [
                    'total_votes' => $totalVotes,
                    'votes_by_candidate' => $votesByCandidate,
                    'votes_by_hospital' => $votesByHospital,
                ],
                'selected_candidate_election_key' => $candidateElectionKey,
                'active_candidate_election_key' => VotingSetting::current()->active_candidate_election_key,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener estadísticas de votación por hospital', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'hospital' => $request->query('hospital'),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
                'error_code' => 'HOSPITAL_STATISTICS_ERROR',
            ], 500);
        }
    }

    /**
     * Check if voter has voted (regardless of candidate).
     */
    public function checkVote(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'document_type' => 'required|string',
                'document_number' => 'required|string',
                'candidate_election_key' => 'nullable|string|max:64',
            ]);

            $candidateElectionKey = $this->resolveElectionKeyFromRequest(
                $request->input('candidate_election_key'),
                true
            );
            if ($candidateElectionKey === null) {
                return response()->json([
                    'success' => true,
                    'has_voted' => false,
                    'active_candidate_election_key' => VotingSetting::current()->active_candidate_election_key,
                ]);
            }

            $hasVoted = Vote::where('voter_document_type', $request->input('document_type'))
                ->where('voter_document_number', $request->input('document_number'))
                ->where('candidate_election_key', $candidateElectionKey)
                ->exists();

            return response()->json([
                'success' => true,
                'has_voted' => $hasVoted,
                'selected_candidate_election_key' => $candidateElectionKey,
                'active_candidate_election_key' => VotingSetting::current()->active_candidate_election_key,
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
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error_code' => 'CHECK_VOTE_ERROR',
            ], 500);
        }
    }

    /**
     * Get detailed audit trail of all votes for legal compliance and transparency.
     */
    public function auditTrail(Request $request): JsonResponse
    {
        try {
            // Validate optional filters
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'candidate_id' => 'nullable|string',
                'candidate_election_key' => 'nullable|string|max:64',
                'voter_hospital' => 'nullable|string',
                'voter_document_type' => 'nullable|string',
                'voter_document_number' => 'nullable|string',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:1000',
            ]);

            $candidateElectionKey = $this->resolveElectionKeyFromRequest(
                $request->input('candidate_election_key'),
                true
            );
            if ($candidateElectionKey === null) {
                return response()->json([
                    'success' => true,
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => (int) $request->input('per_page', 100),
                        'total_votes' => 0,
                        'total_pages' => 0,
                        'has_next_page' => false,
                        'has_prev_page' => false,
                    ],
                    'summary_statistics' => [
                        'total_votes_in_period' => 0,
                        'votes_by_candidate' => [],
                        'votes_by_hospital' => [],
                        'votes_by_date' => [],
                    ],
                    'votes' => [],
                    'selected_candidate_election_key' => null,
                    'active_candidate_election_key' => VotingSetting::current()->active_candidate_election_key,
                ]);
            }

            $query = Vote::query()->byElectionKey($candidateElectionKey);

            // Apply filters
            if ($request->has('start_date') && $request->filled('start_date')) {
                try {
                    $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
                    $query->where('vote_timestamp', '>=', $startDate);
                } catch (\Exception $e) {
                    // Ignorar fecha inválida
                }
            }

            if ($request->has('end_date') && $request->filled('end_date')) {
                try {
                    $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
                    $query->where('vote_timestamp', '<=', $endDate);
                } catch (\Exception $e) {
                    // Ignorar fecha inválida
                }
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

            // Build a map of document -> full name from ACTIVOS2.xlsx for efficiency
            $nameMap = [];
            if ($this->excelReaderService->isActivosFileAvailable()) {
                try {
                    $activosData = $this->excelReaderService->readActivosFile();
                    if (! empty($activosData)) {
                        $rows = array_slice($activosData, 1); // Skip header
                        foreach ($rows as $row) {
                            if (count($row) >= 4) {
                                $tipoDocumento = trim($row[0] ?? '');
                                $documento = trim($row[1] ?? '');
                                $nombres = trim($row[2] ?? '');
                                $apellidos = trim($row[3] ?? '');

                                if (! empty($tipoDocumento) && ! empty($documento)) {
                                    $key = $tipoDocumento.'|'.$documento;

                                    // Build full name
                                    $fullName = null;
                                    if (! empty($nombres) && ! empty($apellidos)) {
                                        $fullName = trim($nombres.' '.$apellidos);
                                    } elseif (! empty($nombres)) {
                                        $fullName = $nombres;
                                    } elseif (! empty($apellidos)) {
                                        $fullName = $apellidos;
                                    }

                                    if ($fullName) {
                                        $nameMap[$key] = $fullName;
                                    }
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Error al construir mapa de nombres para auditoría', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Transform votes for response (with voter and candidate information)
            $votesData = $votes->map(function ($vote) use ($nameMap) {
                // Get full name from map
                $key = $vote->voter_document_type.'|'.$vote->voter_document_number;
                $fullName = $nameMap[$key] ?? null;

                return [
                    'vote_id' => $vote->id,
                    'voter' => [
                        'document_type' => $vote->voter_document_type,
                        'document_number' => $vote->voter_document_number,
                        'hospital' => $vote->voter_hospital,
                        'position' => $vote->voter_position,
                        'full_name' => $fullName,
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
                'votes_by_candidate' => Vote::query()
                    ->byElectionKey($candidateElectionKey)
                    ->selectRaw('candidate_id, candidate_name, candidate_hospital, COUNT(*) as vote_count')
                    ->when($request->has('start_date') && $request->filled('start_date'), function ($q) use ($request) {
                        try {
                            $startDate = Carbon::parse($request->input('start_date'))->startOfDay();

                            return $q->where('vote_timestamp', '>=', $startDate);
                        } catch (\Exception $e) {
                            return $q;
                        }
                    })
                    ->when($request->has('end_date') && $request->filled('end_date'), function ($q) use ($request) {
                        try {
                            $endDate = Carbon::parse($request->input('end_date'))->endOfDay();

                            return $q->where('vote_timestamp', '<=', $endDate);
                        } catch (\Exception $e) {
                            return $q;
                        }
                    })
                    ->when($request->has('voter_hospital'), function ($q) use ($request) {
                        return $q->where('voter_hospital', $request->input('voter_hospital'));
                    })
                    ->groupBy('candidate_id', 'candidate_name', 'candidate_hospital')
                    ->orderBy('vote_count', 'desc')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'candidate_id' => $item->candidate_id,
                            'candidate_name' => $item->candidate_name,
                            'hospital' => $item->candidate_hospital,
                            'vote_count' => $item->vote_count,
                        ];
                    }),
                'votes_by_hospital' => Vote::query()
                    ->byElectionKey($candidateElectionKey)
                    ->selectRaw('voter_hospital, COUNT(*) as vote_count')
                    ->when($request->has('start_date') && $request->filled('start_date'), function ($q) use ($request) {
                        try {
                            $startDate = Carbon::parse($request->input('start_date'))->startOfDay();

                            return $q->where('vote_timestamp', '>=', $startDate);
                        } catch (\Exception $e) {
                            return $q;
                        }
                    })
                    ->when($request->has('end_date') && $request->filled('end_date'), function ($q) use ($request) {
                        try {
                            $endDate = Carbon::parse($request->input('end_date'))->endOfDay();

                            return $q->where('vote_timestamp', '<=', $endDate);
                        } catch (\Exception $e) {
                            return $q;
                        }
                    })
                    ->when($request->has('candidate_id'), function ($q) use ($request) {
                        return $q->where('candidate_id', $request->input('candidate_id'));
                    })
                    ->groupBy('voter_hospital')
                    ->orderBy('vote_count', 'desc')
                    ->get(),
                'votes_by_date' => Vote::query()
                    ->byElectionKey($candidateElectionKey)
                    ->selectRaw('DATE(vote_timestamp) as vote_date, COUNT(*) as vote_count')
                    ->when($request->has('start_date') && $request->filled('start_date'), function ($q) use ($request) {
                        try {
                            $startDate = Carbon::parse($request->input('start_date'))->startOfDay();

                            return $q->where('vote_timestamp', '>=', $startDate);
                        } catch (\Exception $e) {
                            return $q;
                        }
                    })
                    ->when($request->has('end_date') && $request->filled('end_date'), function ($q) use ($request) {
                        try {
                            $endDate = Carbon::parse($request->input('end_date'))->endOfDay();

                            return $q->where('vote_timestamp', '<=', $endDate);
                        } catch (\Exception $e) {
                            return $q;
                        }
                    })
                    ->groupBy('vote_date')
                    ->orderBy('vote_date', 'desc')
                    ->get(),
            ];

            // Log the audit request
            Log::info('Auditoría de votos solicitada', [
                'filters' => $request->only([
                    'start_date', 'end_date', 'candidate_id', 'candidate_election_key',
                    'voter_hospital', 'voter_document_type', 'voter_document_number',
                ]),
                'total_results' => $totalVotes,
                'page' => $page,
                'per_page' => $perPage,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
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
                'selected_candidate_election_key' => $candidateElectionKey,
                'active_candidate_election_key' => VotingSetting::current()->active_candidate_election_key,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validación fallida en auditoría de votos', [
                'errors' => $e->errors(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
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
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error_code' => 'AUDIT_TRAIL_ERROR',
            ], 500);
        }
    }

    /**
     * Change the candidate for a specific voter's vote
     * This allows manipulation of votes by reassigning a vote to a different candidate.
     */
    public function changeVoteCandidate(Request $request): JsonResponse
    {
        try {
            // Validate input
            $request->validate([
                'voter_document_type' => 'required|string',
                'voter_document_number' => 'required|string',
                'new_candidate_id' => 'required|string',
                'candidate_election_key' => 'nullable|string|max:64',
            ]);

            $voterDocumentType = $request->input('voter_document_type');
            $voterDocumentNumber = $request->input('voter_document_number');
            $newCandidateId = $request->input('new_candidate_id');
            $candidateElectionKey = $this->resolveElectionKeyFromRequest(
                $request->input('candidate_election_key'),
                true
            );

            if ($candidateElectionKey === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay un periodo activo para modificar votos.',
                    'error_code' => 'CANDIDATE_ELECTION_PERIOD_INACTIVE',
                ], 422);
            }

            // Find the vote for this voter
            $vote = Vote::where('voter_document_type', $voterDocumentType)
                ->where('voter_document_number', $voterDocumentNumber)
                ->where('candidate_election_key', $candidateElectionKey)
                ->first();

            if (! $vote) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró un voto para este votante',
                    'error_code' => 'VOTE_NOT_FOUND',
                ], 404);
            }

            // Get candidate information from delegados file
            $delegados = $this->excelReaderService->getAllDelegados();
            $newCandidate = null;

            // Find candidate by ID (which is the cédula)
            foreach ($delegados as $delegado) {
                if ($delegado['cedula'] === $newCandidateId || (string) $delegado['id'] === $newCandidateId) {
                    $newCandidate = $delegado;
                    break;
                }
            }

            if (! $newCandidate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Candidato no encontrado',
                    'error_code' => 'CANDIDATE_NOT_FOUND',
                ], 404);
            }

            // Store old candidate info for logging
            $oldCandidate = [
                'id' => $vote->candidate_id,
                'name' => $vote->candidate_name,
                'position' => $vote->candidate_position,
                'hospital' => $vote->candidate_hospital,
            ];

            // Update the vote with new candidate information
            $vote->candidate_id = $newCandidate['cedula'];
            $vote->candidate_name = $newCandidate['nombre_apellidos'];
            $vote->candidate_position = $newCandidate['proceso'] ?? '';
            $vote->candidate_hospital = $newCandidate['sede'] ?? '';
            $vote->save();

            // Log the vote change
            Log::warning('Voto modificado - candidato cambiado', [
                'vote_id' => $vote->id,
                'voter_document_type' => $voterDocumentType,
                'voter_document_number' => $voterDocumentNumber,
                'candidate_election_key' => $candidateElectionKey,
                'old_candidate' => $oldCandidate,
                'new_candidate' => [
                    'id' => $vote->candidate_id,
                    'name' => $vote->candidate_name,
                    'position' => $vote->candidate_position,
                    'hospital' => $vote->candidate_hospital,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Voto actualizado exitosamente',
                'vote' => [
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
                    'updated_at' => $vote->updated_at->setTimezone('America/Bogota')->format('Y-m-d\TH:i:s.vP'),
                ],
                'previous_candidate' => $oldCandidate,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al cambiar candidato del voto', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error_code' => 'CHANGE_VOTE_CANDIDATE_ERROR',
            ], 500);
        }
    }

    private function resolveElectionKeyFromRequest(mixed $requestedElectionKey, bool $fallbackToActive): ?string
    {
        $requested = is_string($requestedElectionKey) ? trim($requestedElectionKey) : '';
        if ($requested !== '') {
            return $requested;
        }

        if (! $fallbackToActive) {
            return null;
        }

        $setting = VotingSetting::current();
        $activeKey = trim((string) ($setting->active_candidate_election_key ?? ''));

        return $activeKey !== '' ? $activeKey : null;
    }
}
