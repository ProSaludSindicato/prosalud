<?php

namespace App\Http\Controllers\Assembly;

use App\Http\Controllers\Controller;
use App\Models\Assembly;
use App\Models\AssemblyAttendance;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AssemblyAttendanceController extends Controller
{
    /**
     * List authenticated delegates.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'assembly_id' => ['nullable', 'string'],
        ]);

        $query = AssemblyAttendance::query();

        // Filter by assembly (default to active assembly)
        $assembly = null;
        if ($request->has('assembly_id')) {
            $assembly = Assembly::find($request->input('assembly_id'));
        } else {
            $assembly = Assembly::getCurrent();
        }

        if ($assembly) {
            $query->where('assembly_id', $assembly->id);
        }

        $query->orderByDesc('authenticated_at');

        if (!empty($validated['document'])) {
            $query->where('document_number', 'like', '%' . $validated['document'] . '%');
        }

        if (!empty($validated['from'])) {
            try {
                $startDate = Carbon::parse($validated['from'])->startOfDay();
                $query->where('authenticated_at', '>=', $startDate);
            } catch (\Exception $e) {
                // Ignorar fecha inválida
            }
        }

        if (!empty($validated['to'])) {
            try {
                $endDate = Carbon::parse($validated['to'])->endOfDay();
                $query->where('authenticated_at', '<=', $endDate);
            } catch (\Exception $e) {
                // Ignorar fecha inválida
            }
        }

        $perPage = $validated['perPage'] ?? 50;

        return response()->json(
            $query->paginate($perPage)
        );
    }

    /**
     * Delete an attendance record.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $attendance = AssemblyAttendance::find($id);

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Registro de asistencia no encontrado',
                ], 404);
            }

            // Delete signature file if exists
            if ($attendance->signature_path) {
                try {
                    $disk = Storage::disk('prosalud-private');
                    if ($disk->exists($attendance->signature_path)) {
                        $disk->delete($attendance->signature_path);
                    }
                } catch (\Exception $e) {
                    Log::warning('Error al eliminar archivo de firma', [
                        'attendance_id' => $attendance->id,
                        'signature_path' => $attendance->signature_path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Delete attendance record
            $attendance->delete();

            Log::info('Registro de asistencia eliminado', [
                'attendance_id' => $id,
                'document_number' => $attendance->document_number,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Registro de asistencia eliminado correctamente',
            ]);
        } catch (\Exception $e) {
            Log::error('Error al eliminar registro de asistencia', [
                'attendance_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el registro de asistencia',
            ], 500);
        }
    }
}

