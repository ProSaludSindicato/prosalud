<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCandidateVotingPeriodRequest;
use App\Models\CandidateVotingPeriod;
use App\Models\VotingSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CandidateVotingPeriodController extends Controller
{
    public function index(): JsonResponse
    {
        $periods = CandidateVotingPeriod::query()
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $periods->map(fn (CandidateVotingPeriod $p) => $this->toArray($p)),
        ]);
    }

    public function current(): JsonResponse
    {
        $period = CandidateVotingPeriod::current();

        return response()->json([
            'success' => true,
            'data' => $period ? $this->toArray($period) : null,
        ]);
    }

    public function store(StoreCandidateVotingPeriodRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $activate = (bool) ($validated['activate'] ?? false);
        $electionKey = Str::slug($validated['name']);

        $period = DB::transaction(function () use ($validated, $electionKey, $activate) {
            $period = CandidateVotingPeriod::query()->create([
                'election_key' => $electionKey,
                'name' => $validated['name'],
                'is_active' => false,
            ]);

            if ($activate) {
                $this->doActivate($period);
            }

            return $period;
        });

        $period->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Periodo creado correctamente.',
            'data' => $this->toArray($period),
        ], 201);
    }

    public function activate(int $id): JsonResponse
    {
        $period = CandidateVotingPeriod::query()->find($id);
        if (! $period) {
            return response()->json(['success' => false, 'message' => 'Periodo no encontrado.'], 404);
        }

        DB::transaction(fn () => $this->doActivate($period));
        $period->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Periodo activado correctamente.',
            'data' => $this->toArray($period),
        ]);
    }

    public function close(int $id): JsonResponse
    {
        $period = CandidateVotingPeriod::query()->find($id);
        if (! $period) {
            return response()->json(['success' => false, 'message' => 'Periodo no encontrado.'], 404);
        }

        if (! $period->is_active) {
            return response()->json(['success' => false, 'message' => 'El periodo no está activo.'], 422);
        }

        DB::transaction(function () use ($period): void {
            $period->update(['is_active' => false]);

            $setting = VotingSetting::current();
            if ($setting->active_candidate_election_key === $period->election_key) {
                $update = ['active_candidate_election_key' => null];
                if ($setting->active_mode === 'candidate') {
                    $update['active_mode'] = 'none';
                }
                $setting->update($update);
            }
        });

        $period->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Periodo cerrado correctamente.',
            'data' => $this->toArray($period),
        ]);
    }

    private function doActivate(CandidateVotingPeriod $period): void
    {
        CandidateVotingPeriod::query()
            ->where('id', '!=', $period->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $period->update(['is_active' => true]);

        VotingSetting::current()->update([
            'active_candidate_election_key' => $period->election_key,
            'updated_by' => auth()->id(),
        ]);

        Log::info('Periodo de votación de delegados activado', [
            'period_id' => $period->id,
            'election_key' => $period->election_key,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(CandidateVotingPeriod $period): array
    {
        return [
            'id' => $period->id,
            'election_key' => $period->election_key,
            'name' => $period->name,
            'is_active' => $period->is_active,
        ];
    }
}
