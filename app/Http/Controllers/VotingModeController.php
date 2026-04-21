<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateVotingModeRequest;
use App\Models\VotingSetting;
use Illuminate\Http\JsonResponse;

class VotingModeController extends Controller
{
    /**
     * Return the current active voting mode (public endpoint).
     */
    public function show(): JsonResponse
    {
        $setting = VotingSetting::current();

        return response()->json([
            'active_mode' => $setting->active_mode,
            'active_candidate_election_key' => $setting->active_candidate_election_key,
        ]);
    }

    /**
     * Update the active voting mode (admin endpoint).
     */
    public function update(UpdateVotingModeRequest $request): JsonResponse
    {
        $setting = VotingSetting::current();

        $setting->update([
            'active_mode' => $request->validated()['active_mode'],
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'active_mode' => $setting->active_mode,
            'active_candidate_election_key' => $setting->active_candidate_election_key,
            'message' => 'Modo de votación actualizado correctamente.',
        ]);
    }
}
