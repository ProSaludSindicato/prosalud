<?php

namespace App\Http\Middleware;

use App\Models\VotingSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCandidateVotingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! VotingSetting::current()->isCandidateEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'La votación de candidatos no está habilitada en este momento.',
                'error_code' => 'CANDIDATE_VOTING_DISABLED',
            ], 403);
        }

        return $next($request);
    }
}
