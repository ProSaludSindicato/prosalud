<?php

namespace App\Http\Controllers\Assembly;

use App\Http\Controllers\Controller;
use App\Models\AssemblyAttendance;
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
        ]);

        $query = AssemblyAttendance::query()->orderByDesc('authenticated_at');

        if (!empty($validated['document'])) {
            $query->where('document_number', 'like', '%' . $validated['document'] . '%');
        }

        if (!empty($validated['from'])) {
            $query->whereDate('authenticated_at', '>=', $validated['from']);
        }

        if (!empty($validated['to'])) {
            $query->whereDate('authenticated_at', '<=', $validated['to']);
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

