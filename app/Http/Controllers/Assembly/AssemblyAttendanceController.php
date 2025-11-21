<?php

namespace App\Http\Controllers\Assembly;

use App\Http\Controllers\Controller;
use App\Models\AssemblyAttendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}

