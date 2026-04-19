<?php

namespace App\Http\Middleware;

use App\Models\VotingSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAssemblyVotingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! VotingSetting::current()->isAssemblyEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'La votación de asamblea en vivo no está habilitada en este momento.',
                'error_code' => 'ASSEMBLY_VOTING_DISABLED',
            ], 403);
        }

        return $next($request);
    }
}
