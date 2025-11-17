<?php

namespace App\Services;

use App\Constants\RequestSubtypes;
use App\Constants\RequestTypes;
use App\Models\RequestForm;
use App\Models\RequestSubtypeAssignment;
use App\Models\RequestTypeAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RequestAssignmentService
{
    /**
     * Get all assignments formatted for API response
     */
    public function getAllAssignments(): array
    {
        // Get all type assignments
        $typeAssignments = RequestTypeAssignment::with('user:id,name,email,is_active')
            ->get()
            ->groupBy('request_type')
            ->map(function ($assignments) {
                return $assignments->pluck('user_id')->map(fn($id) => (string) $id)->toArray();
            })
            ->toArray();

        // Get all subtype assignments
        $subtypeAssignments = RequestSubtypeAssignment::with('user:id,name,email,is_active')
            ->get()
            ->groupBy('request_type')
            ->map(function ($assignments) {
                return $assignments->groupBy('subtype')
                    ->map(function ($subtypeAssignments) {
                        return $subtypeAssignments->pluck('user_id')->map(fn($id) => (string) $id)->toArray();
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
     * Save or update assignments
     */
    public function saveAssignments(array $assignments, array $subtypeAssignments): array
    {
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

            return $this->getAllAssignments();
        });
    }

    /**
     * Validate assignments before saving
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
            if ($requestType === RequestTypes::VERIFICACION_PAGOS) {
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
            ->map(fn($id) => (string) $id)
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
     * Get request types that a user can access
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
     * Check if a user can access a specific request
     */
    public function canUserAccessRequest(User $user, RequestForm $requestForm): bool
    {
        // Admins can access everything
        if ($user->hasRole('admin')) {
            return true;
        }

        $userId = $user->id;
        $requestType = $requestForm->request_type;

        // Check if user has direct type assignment
        $hasTypeAssignment = RequestTypeAssignment::where('user_id', $userId)
            ->where('request_type', $requestType)
            ->exists();

        if ($hasTypeAssignment) {
            // For types without subtypes, type assignment is enough
            if (!RequestTypes::hasSubtypes($requestType)) {
                return true;
            }

            // For types with subtypes, check if there's a specific subtype assignment
            // If there's no subtype in the request, fall back to type assignment
            $subtype = $this->getSubtypeFromRequest($requestForm);
            
            if (empty($subtype)) {
                // No subtype in request, use type assignment as fallback
                return true;
            }

            // Check if user has specific subtype assignment
            $hasSubtypeAssignment = RequestSubtypeAssignment::where('user_id', $userId)
                ->where('request_type', $requestType)
                ->where('subtype', $subtype)
                ->exists();

            if ($hasSubtypeAssignment) {
                return true;
            }

            // Check if there's a general type assignment (fallback) but no specific subtype assignment
            // If no specific subtype assignments exist for this type, use type assignment as fallback
            $hasAnySubtypeAssignment = RequestSubtypeAssignment::where('request_type', $requestType)
                ->exists();

            if (!$hasAnySubtypeAssignment) {
                // No subtype assignments exist, use type assignment as fallback
                return true;
            }

            // Specific subtype assignment exists but user doesn't have it
            return false;
        }

        // Check if user has subtype assignment
        if (RequestTypes::hasSubtypes($requestType)) {
            $subtype = $this->getSubtypeFromRequest($requestForm);
            
            if (!empty($subtype)) {
                $hasSubtypeAssignment = RequestSubtypeAssignment::where('user_id', $userId)
                    ->where('request_type', $requestType)
                    ->where('subtype', $subtype)
                    ->exists();

                return $hasSubtypeAssignment;
            }
        }

        return false;
    }

    /**
     * Get subtype from request form
     */
    private function getSubtypeFromRequest(RequestForm $requestForm): ?string
    {
        $payload = $requestForm->payload ?? [];
        return $payload['solicitudRelacionadaCon'] ?? null;
    }
}

