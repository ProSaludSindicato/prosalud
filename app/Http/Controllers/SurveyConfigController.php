<?php

namespace App\Http\Controllers;

use App\Models\{Configuration, SurveyConfig};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Log;

class SurveyConfigController extends Controller
{
    /**
     * Get the current survey configuration.
     */
    public function show(): JsonResponse
    {
        try {
            $config = SurveyConfig::getCurrent();
            $configRecord = Configuration::where('key', 'survey.allow_bulk_entry_mode')->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'allow_bulk_entry_mode' => $config['allow_bulk_entry_mode'],
                    'updated_at' => $configRecord?->updated_at?->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener configuración de encuestas', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la configuración de encuestas',
            ], 500);
        }
    }

    /**
     * Update the survey configuration.
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'allow_bulk_entry_mode' => 'required|boolean',
            ]);

            SurveyConfig::setBulkEntryMode($request->input('allow_bulk_entry_mode'));

            $configRecord = Configuration::where('key', 'survey.allow_bulk_entry_mode')->first();

            Log::info('Configuración de encuestas actualizada', [
                'user_id' => auth()->id(),
                'allow_bulk_entry_mode' => $request->input('allow_bulk_entry_mode'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Configuración actualizada exitosamente',
                'data' => [
                    'allow_bulk_entry_mode' => $request->input('allow_bulk_entry_mode'),
                    'updated_at' => $configRecord?->updated_at?->toISOString(),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al actualizar configuración de encuestas', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la configuración de encuestas',
            ], 500);
        }
    }
}
