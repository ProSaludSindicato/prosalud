<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkPresidentSignRequest;
use App\Jobs\SignConvenioWithPresidentJob;
use App\Models\ConvenioEmailTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ConvenioPresidentSigningController extends Controller
{
    private const SIGNABLE_STATES = [
        ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
        ConvenioEmailTracking::SIGNING_ERROR_PRESIDENTE,
    ];

    /**
     * Encola la firma presidencial de un convenio individual.
     *
     * POST /api/convenios-manual/tracking/{tracking}/president-sign
     */
    public function signOne(ConvenioEmailTracking $tracking): JsonResponse
    {
        if (! in_array($tracking->signing_estado, self::SIGNABLE_STATES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'El convenio no está en un estado que permita la firma del presidente.',
                'current_estado' => $tracking->signing_estado,
            ], 422);
        }

        $tracking->update(['president_sign_queued_at' => now()]);

        SignConvenioWithPresidentJob::dispatch($tracking->id);

        Log::info('[PRESIDENT SIGN CONTROLLER] Job despachado', [
            'tracking_id' => $tracking->id,
            'signing_estado' => $tracking->signing_estado,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'El convenio ha sido enviado a la cola de firma presidencial.',
            'tracking_id' => $tracking->id,
        ]);
    }

    /**
     * Encola la firma presidencial de varios convenios (bulk).
     *
     * POST /api/convenios-manual/tracking/president-sign-bulk
     *
     * Response: { accepted: int, rejected: [{ tracking_id: int, reason: string }] }
     */
    public function signBulk(BulkPresidentSignRequest $request): JsonResponse
    {
        /** @var array<int> $trackingIds */
        $trackingIds = $request->validated('tracking_ids');

        $trackings = ConvenioEmailTracking::query()
            ->whereIn('id', $trackingIds)
            ->get();

        $accepted = 0;
        $rejected = [];

        foreach ($trackings as $tracking) {
            if (! in_array($tracking->signing_estado, self::SIGNABLE_STATES, true)) {
                $rejected[] = [
                    'tracking_id' => $tracking->id,
                    'reason' => "Estado '{$tracking->signing_estado}' no permite firma presidencial.",
                ];

                continue;
            }

            $tracking->update(['president_sign_queued_at' => now()]);
            SignConvenioWithPresidentJob::dispatch($tracking->id);
            $accepted++;
        }

        $notFoundIds = array_diff($trackingIds, $trackings->pluck('id')->toArray());
        foreach ($notFoundIds as $missingId) {
            $rejected[] = [
                'tracking_id' => $missingId,
                'reason' => 'Convenio no encontrado.',
            ];
        }

        Log::info('[PRESIDENT SIGN CONTROLLER] Bulk despachado', [
            'requested' => count($trackingIds),
            'accepted' => $accepted,
            'rejected' => count($rejected),
        ]);

        return response()->json([
            'success' => true,
            'accepted' => $accepted,
            'rejected' => $rejected,
        ]);
    }
}
