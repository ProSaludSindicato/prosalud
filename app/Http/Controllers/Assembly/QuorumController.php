<?php

namespace App\Http\Controllers\Assembly;

use App\Http\Controllers\Controller;
use App\Models\QuorumConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class QuorumController extends Controller
{
    /**
     * Get quorum configuration
     */
    public function index(): JsonResponse
    {
        try {
            $quorum = QuorumConfig::getCurrent();

            if (!$quorum) {
                // Return default values if no config exists
                return response()->json([
                    'success' => true,
                    'data' => [
                        'totalDelegates' => 100,
                        'presentDelegates' => 0,
                        'requiredPercentage' => 50,
                        'verified' => false,
                    ],
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'totalDelegates' => $quorum->total_delegates,
                    'presentDelegates' => $quorum->present_delegates,
                    'requiredPercentage' => $quorum->required_percentage,
                    'verified' => $quorum->verified,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching quorum', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la configuración de quórum',
            ], 500);
        }
    }

    /**
     * Update quorum configuration
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'totalDelegates' => 'required|integer|min:1',
                'presentDelegates' => 'required|integer|min:0',
                'requiredPercentage' => 'required|integer|min:1|max:100',
            ]);

            $assembly = \App\Models\Assembly::getCurrent() ?? \App\Models\Assembly::getOrCreateDefault();

            $quorum = QuorumConfig::getCurrent();

            if (!$quorum || $quorum->assembly_id !== $assembly->id) {
                $quorum = new QuorumConfig();
                $quorum->assembly_id = $assembly->id;
            }

            $quorum->total_delegates = $validated['totalDelegates'];
            $quorum->present_delegates = $validated['presentDelegates'];
            $quorum->required_percentage = $validated['requiredPercentage'];
            $quorum->save();

            // Verify quorum
            $quorum->verify();

            Log::info('Quorum updated', [
                'total_delegates' => $quorum->total_delegates,
                'present_delegates' => $quorum->present_delegates,
                'verified' => $quorum->verified,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'totalDelegates' => $quorum->total_delegates,
                    'presentDelegates' => $quorum->present_delegates,
                    'requiredPercentage' => $quorum->required_percentage,
                    'verified' => $quorum->verified,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating quorum', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la configuración de quórum',
            ], 500);
        }
    }

    /**
     * Verify quorum
     */
    public function verify(): JsonResponse
    {
        try {
            $quorum = QuorumConfig::getCurrent();

            if (!$quorum) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay configuración de quórum',
                ], 400);
            }

            $quorum->verify();

            $message = $quorum->verified
                ? 'Quórum alcanzado'
                : 'Quórum no alcanzado';

            Log::info('Quorum verified', [
                'verified' => $quorum->verified,
                'present_delegates' => $quorum->present_delegates,
                'required' => $quorum->total_delegates * $quorum->required_percentage / 100,
            ]);

            return response()->json([
                'success' => true,
                'verified' => $quorum->verified,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            Log::error('Error verifying quorum', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al verificar el quórum',
            ], 500);
        }
    }
}
