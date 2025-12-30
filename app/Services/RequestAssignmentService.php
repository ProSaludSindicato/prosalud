<?php

namespace App\Services;

use App\Constants\{RequestSubtypes, RequestTypes};
use App\Models\{RequestForm, RequestSubtypeAssignment, RequestTypeAssignment, User};
use Illuminate\Support\Facades\{Cache, DB, Log};
use Illuminate\Validation\ValidationException;

class RequestAssignmentService
{
    /**
     * Get all assignments formatted for API response.
     */
    public function getAllAssignments(): array
    {
        // Get all type assignments
        $typeAssignments = RequestTypeAssignment::with('user:id,name,email,is_active')
            ->get()
            ->groupBy('request_type')
            ->map(function ($assignments) {
                return $assignments->pluck('user_id')->map(fn ($id) => (string) $id)->toArray();
            })
            ->toArray();

        // Get all subtype assignments
        $subtypeAssignments = RequestSubtypeAssignment::with('user:id,name,email,is_active')
            ->get()
            ->groupBy('request_type')
            ->map(function ($assignments) {
                return $assignments->groupBy('subtype')
                    ->map(function ($subtypeAssignments) {
                        return $subtypeAssignments->pluck('user_id')->map(fn ($id) => (string) $id)->toArray();
                    })
                    ->toArray();
            })
            ->toArray();

        return [
            'assignments' => $typeAssignments,
            'subtype_assignments' => $subtypeAssignments,
        ];
    }

    /**
     * Save or update assignments.
     */
    public function saveAssignments(array $assignments, array $subtypeAssignments): array
    {
        // Normalize request types before validation and saving
        $assignments = $this->normalizeAssignments($assignments);
        $subtypeAssignments = $this->normalizeSubtypeAssignments($subtypeAssignments);

        // Validate assignments first (before starting transaction)
        $this->validateAssignments($assignments, $subtypeAssignments);

        // Use DB::transaction() which handles commit/rollback automatically
        return DB::transaction(function () use ($assignments, $subtypeAssignments) {
            // Delete all existing assignments (using delete instead of truncate for transaction safety)
            RequestTypeAssignment::query()->delete();
            RequestSubtypeAssignment::query()->delete();

            // Insert new type assignments
            $typeAssignmentData = [];
            foreach ($assignments as $requestType => $userIds) {
                foreach ($userIds as $userId) {
                    $typeAssignmentData[] = [
                        'request_type' => $requestType,
                        'user_id' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if (!empty($typeAssignmentData)) {
                RequestTypeAssignment::insert($typeAssignmentData);
            }

            // Insert new subtype assignments
            $subtypeAssignmentData = [];
            foreach ($subtypeAssignments as $requestType => $subtypes) {
                foreach ($subtypes as $subtype => $userIds) {
                    foreach ($userIds as $userId) {
                        $subtypeAssignmentData[] = [
                            'request_type' => $requestType,
                            'subtype' => $subtype,
                            'user_id' => $userId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            }

            if (!empty($subtypeAssignmentData)) {
                RequestSubtypeAssignment::insert($subtypeAssignmentData);
            }

            Log::info('Asignaciones de solicitudes guardadas exitosamente', [
                'type_assignments_count' => count($typeAssignmentData),
                'subtype_assignments_count' => count($subtypeAssignmentData),
            ]);

            // Invalidar caché de asignaciones de todos los usuarios afectados
            $this->clearAllUserAssignmentsCache();

            return $this->getAllAssignments();
        });
    }

    /**
     * Clear cache for all user assignments.
     * Note: This is a simple implementation. For production with many users,
     * consider using cache tags or tracking affected user IDs.
     */
    private function clearAllUserAssignmentsCache(): void
    {
        // Get all unique user IDs that have assignments
        $userIds = array_unique(array_merge(
            RequestTypeAssignment::pluck('user_id')->toArray(),
            RequestSubtypeAssignment::pluck('user_id')->toArray()
        ));

        foreach ($userIds as $userId) {
            Cache::forget("user:{$userId}:request_assignments");
        }
    }

    /**
     * Get request types that a user can access.
     */
    public function getAccessibleRequestTypesForUser(int $userId): array
    {
        $typeAssignments = RequestTypeAssignment::where('user_id', $userId)
            ->pluck('request_type')
            ->toArray();

        $subtypeAssignments = RequestSubtypeAssignment::where('user_id', $userId)
            ->select('request_type', 'subtype')
            ->get()
            ->groupBy('request_type')
            ->map(function ($assignments) {
                return $assignments->pluck('subtype')->toArray();
            })
            ->toArray();

        return [
            'types' => $typeAssignments,
            'subtypes' => $subtypeAssignments,
        ];
    }

    /**
     * Check if a user can access a specific request.
     */
    public function canUserAccessRequest(User $user, RequestForm $requestForm): bool
    {
        // Admins can access everything
        if ($user->hasRole('admin')) {
            return true;
        }

        $userId = $user->id;
        $requestType = $requestForm->request_type;
        
        // Normalize request type to handle aliases/variants
        $normalizedRequestType = RequestTypes::normalize($requestType);

        // Obtener asignaciones del usuario desde caché
        $assignments = $this->getUserAssignments($userId);

        // Check if user has direct type assignment (check both original and normalized)
        $hasTypeAssignment = in_array($requestType, $assignments['types']) 
            || in_array($normalizedRequestType, $assignments['types']);
        
        // Also check if the assignment has aliases that match this request type
        if (!$hasTypeAssignment) {
            foreach ($assignments['types'] as $assignedType) {
                $normalizedAssigned = RequestTypes::normalize($assignedType);
                // Check if the normalized assigned type matches the normalized request type
                if ($normalizedAssigned === $normalizedRequestType) {
                    $hasTypeAssignment = true;
                    break;
                }
                // Also check aliases: if assigned type is incapacidades-licencias, also match incapacidad-licencia
                if ($assignedType === RequestTypes::INCAPACIDADES_LICENCIAS 
                    && in_array($requestType, ['incapacidad-licencia', 'incapacidad-laboral'])) {
                    $hasTypeAssignment = true;
                    break;
                }
            }
        }

        if ($hasTypeAssignment) {
            // If user has type assignment, they can access ALL requests of that type
            // regardless of subtype assignments (type assignment has priority)
            return true;
        }

        // If user doesn't have type assignment, check subtype assignments
        // Only filter by subtypes if user does NOT have type assignment
        if (RequestTypes::hasSubtypes($requestType)) {
            $subtype = $this->getSubtypeFromRequest($requestForm);

            if (!empty($subtype)) {
                // Check if user has this specific subtype assigned
                // Use flexible comparison to handle variations in casing and format
                $normalizedSubtype = $this->normalizeSubtypeValue($subtype);
                $userSubtypes = $assignments['subtypes'][$requestType] ?? [];
                
                foreach ($userSubtypes as $userSubtype) {
                    $normalizedUserSubtype = $this->normalizeSubtypeValue($userSubtype);
                    // Check if they match exactly or if one contains the other (for variations)
                    if ($normalizedSubtype === $normalizedUserSubtype 
                        || str_contains($normalizedSubtype, $normalizedUserSubtype)
                        || str_contains($normalizedUserSubtype, $normalizedSubtype)) {
                        return true;
                    }
                }
                
                return false;
            }
            
            // If request has no subtype but user only has subtype assignments (no type assignment),
            // they cannot access requests without subtypes
            return false;
        }

        // No type assignment and no subtype assignments (or type doesn't have subtypes)
        return false;
    }

    /**
     * Get user assignments from cache or database.
     */
    private function getUserAssignments(int $userId): array
    {
        $cacheKey = "user:{$userId}:request_assignments";
        
        // Verificar si existe en caché
        $cachedAssignments = Cache::get($cacheKey);
        if ($cachedAssignments !== null) {
            Log::debug('[CACHE HIT] Asignaciones obtenidas desde caché', [
                'cache_key' => $cacheKey,
                'user_id' => $userId,
                'types_count' => count($cachedAssignments['types'] ?? []),
                'subtypes_count' => count($cachedAssignments['subtypes'] ?? []),
            ]);
            return $cachedAssignments;
        }

        Log::debug('[CACHE MISS] Consultando asignaciones desde BD', [
            'cache_key' => $cacheKey,
            'user_id' => $userId,
        ]);
        
        $assignments = Cache::remember($cacheKey, now()->addHours(1), function () use ($userId) {
            $typeAssignments = RequestTypeAssignment::where('user_id', $userId)
                ->pluck('request_type')
                ->toArray();

            $subtypeAssignments = RequestSubtypeAssignment::where('user_id', $userId)
                ->select('request_type', 'subtype')
                ->get()
                ->groupBy('request_type')
                ->map(function ($assignments) {
                    return $assignments->pluck('subtype')->toArray();
                })
                ->toArray();

            return [
                'types' => $typeAssignments,
                'subtypes' => $subtypeAssignments,
            ];
        });
        
        Log::debug('[CACHE STORED] Asignaciones guardadas en caché', [
            'cache_key' => $cacheKey,
            'user_id' => $userId,
            'types_count' => count($assignments['types'] ?? []),
            'subtypes_count' => count($assignments['subtypes'] ?? []),
        ]);
        
        return $assignments;
    }

    /**
     * Normalize assignments by converting aliases to canonical request types.
     */
    private function normalizeAssignments(array $assignments): array
    {
        $normalized = [];
        foreach ($assignments as $requestType => $userIds) {
            $normalizedType = RequestTypes::normalize($requestType);
            // If multiple aliases map to the same canonical type, merge user IDs
            if (isset($normalized[$normalizedType])) {
                $normalized[$normalizedType] = array_unique(array_merge($normalized[$normalizedType], $userIds));
            } else {
                $normalized[$normalizedType] = $userIds;
            }
        }
        return $normalized;
    }

    /**
     * Normalize subtype assignments by converting aliases to canonical request types.
     */
    private function normalizeSubtypeAssignments(array $subtypeAssignments): array
    {
        $normalized = [];
        foreach ($subtypeAssignments as $requestType => $subtypes) {
            $normalizedType = RequestTypes::normalize($requestType);
            $normalized[$normalizedType] = $subtypes;
        }
        return $normalized;
    }

    /**
     * Validate assignments before saving.
     */
    private function validateAssignments(array $assignments, array $subtypeAssignments): void
    {
        $errors = [];

        // Get all valid request types
        $validRequestTypes = RequestTypes::all();
        $requestTypesWithSubtypes = RequestTypes::withSubtypes();
        $validSubtypes = RequestSubtypes::all();

        // Validate request types (without subtypes)
        $requestTypesWithoutSubtypes = array_diff($validRequestTypes, $requestTypesWithSubtypes);

        foreach ($requestTypesWithoutSubtypes as $requestType) {
            if (!isset($assignments[$requestType]) || empty($assignments[$requestType])) {
                $errors["assignments.{$requestType}"] = ['Debe tener al menos un usuario asignado'];
            }
        }

        // Validate all assigned request types are valid
        foreach (array_keys($assignments) as $requestType) {
            if (!in_array($requestType, $validRequestTypes)) {
                $errors["assignments.{$requestType}"] = ['Tipo de solicitud no válido'];
            }
        }

        // Validate subtype assignments
        foreach ($subtypeAssignments as $requestType => $subtypes) {
            if (!in_array($requestType, $validRequestTypes)) {
                $errors["subtype_assignments.{$requestType}"] = ['Tipo de solicitud no válido'];
                continue;
            }

            if (!RequestTypes::hasSubtypes($requestType)) {
                $errors["subtype_assignments.{$requestType}"] = ['Este tipo de solicitud no tiene subtipos'];
                continue;
            }

            // Validate all subtypes for this request type
            foreach ($subtypes as $subtype => $userIds) {
                // Validate subtype is valid
                if (!RequestSubtypes::isValid($requestType, $subtype)) {
                    $errors["subtype_assignments.{$requestType}.{$subtype}"] = ['Subtipo no válido para este tipo de solicitud'];
                    continue;
                }

                // Validate at least one user assigned
                if (empty($userIds)) {
                    $errors["subtype_assignments.{$requestType}.{$subtype}"] = ['Debe tener al menos un usuario asignado'];
                }
            }

            // For request types with subtypes, ensure all subtypes have assignments
            if (RequestTypes::VERIFICACION_PAGOS === $requestType) {
                foreach ($validSubtypes as $subtype) {
                    if (!isset($subtypes[$subtype]) || empty($subtypes[$subtype])) {
                        $errors["subtype_assignments.{$requestType}.{$subtype}"] = ['Debe tener al menos un usuario asignado'];
                    }
                }
            }
        }

        // Validate all user IDs exist and are active
        $allUserIds = [];
        foreach ($assignments as $userIds) {
            $allUserIds = array_merge($allUserIds, $userIds);
        }
        foreach ($subtypeAssignments as $subtypes) {
            foreach ($subtypes as $userIds) {
                $allUserIds = array_merge($allUserIds, $userIds);
            }
        }
        $allUserIds = array_unique($allUserIds);

        $activeUsers = User::whereIn('id', $allUserIds)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->toArray();

        $inactiveUserIds = array_diff($allUserIds, $activeUsers);

        foreach ($inactiveUserIds as $userId) {
            // Find which assignment has this inactive user
            foreach ($assignments as $requestType => $userIds) {
                if (in_array($userId, $userIds)) {
                    $errors["assignments.{$requestType}"] = array_merge(
                        $errors["assignments.{$requestType}"] ?? [],
                        ["Usuario con ID {$userId} no existe o está inactivo"]
                    );
                }
            }

            foreach ($subtypeAssignments as $requestType => $subtypes) {
                foreach ($subtypes as $subtype => $userIds) {
                    if (in_array($userId, $userIds)) {
                        $errors["subtype_assignments.{$requestType}.{$subtype}"] = array_merge(
                            $errors["subtype_assignments.{$requestType}.{$subtype}"] ?? [],
                            ["Usuario con ID {$userId} no existe o está inactivo"]
                        );
                    }
                }
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Get subtype from request form.
     */
    private function getSubtypeFromRequest(RequestForm $requestForm): ?string
    {
        $payload = $requestForm->payload ?? [];

        return $payload['solicitudRelacionadaCon'] ?? null;
    }

    /**
     * Normalize subtype value for comparison.
     * Handles variations in casing and common format differences.
     */
    private function normalizeSubtypeValue(string $subtype): string
    {
        // Normalize to uppercase and trim
        $normalized = mb_strtoupper(trim($subtype));
        
        // Remove common variations that don't affect matching
        // For example: "Y/O DESCANSO" variations
        $normalized = preg_replace('/\s*Y\/O\s*DESCANSO\s*/i', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized); // Normalize multiple spaces
        $normalized = trim($normalized);
        
        return $normalized;
    }
}
