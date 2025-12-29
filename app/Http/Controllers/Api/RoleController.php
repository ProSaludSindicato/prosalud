<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\{Permission, Role};

class RoleController extends Controller
{
    public function __construct(
        private AuditLogService $auditLogService,
    ) {
    }
    /**
     * Display a listing of roles with their permissions.
     */
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->get();

        return response()->json([
            'success' => true,
            'data' => $roles->map(function ($role) {
                $users = User::role($role->name)->get(['id', 'name', 'email']);

                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'guard_name' => $role->guard_name,
                    'permissions' => $role->permissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'name' => $permission->name,
                            'description' => $permission->description,
                        ];
                    }),
                    'users' => $users->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                        ];
                    }),
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ];
            }),
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
            'description' => 'nullable|string|max:500',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $role = Role::create([
            'name' => $request->name,
            'description' => $request->description,
            'guard_name' => 'web',
        ]);

        $permissionsData = [];
        if ($request->has('permissions')) {
            $permissions = Permission::whereIn('id', $request->permissions)->get();
            $role->syncPermissions($permissions);
            $permissionsData = $permissions->pluck('name')->toArray();
        }

        $role->load('permissions');
        $users = User::role($role->name)->get(['id', 'name', 'email']);

        // Registrar en logs de auditoría
        $this->auditLogService->logAdministrativeAction('role_created', $this->auditLogService->addRequestContext($request, [
            'role_id' => $role->id,
            'role_name' => $role->name,
            'role_description' => $role->description,
            'permissions' => $permissionsData,
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'guard_name' => $role->guard_name,
                'permissions' => $role->permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'description' => $permission->description,
                    ];
                }),
                'users' => $users->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ];
                }),
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ],
        ], 201);
    }

    /**
     * Display the specified role.
     */
    public function show(Role $role): JsonResponse
    {
        $role->load('permissions');
        $users = User::role($role->name)->get(['id', 'name', 'email']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'guard_name' => $role->guard_name,
                'permissions' => $role->permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'description' => $permission->description,
                    ];
                }),
                'users' => $users->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ];
                }),
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ],
        ]);
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->ignore($role->id),
            ],
            'description' => 'nullable|string|max:500',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        // Capturar permisos anteriores antes de la actualización
        $previousPermissions = $role->permissions->pluck('name')->toArray();
        $previousName = $role->name;
        $previousDescription = $role->description;

        $role->update([
            'name' => $request->name,
            'description' => $request->description,
        ]);

        $permissionsChanged = false;
        $newPermissions = [];
        $addedPermissions = [];
        $removedPermissions = [];

        if ($request->has('permissions')) {
            $permissions = Permission::whereIn('id', $request->permissions)->get();
            $newPermissions = $permissions->pluck('name')->toArray();
            $role->syncPermissions($permissions);
            
            // Calcular permisos agregados y eliminados
            $addedPermissions = array_diff($newPermissions, $previousPermissions);
            $removedPermissions = array_diff($previousPermissions, $newPermissions);
            $permissionsChanged = !empty($addedPermissions) || !empty($removedPermissions);
            
            // Invalidar caché de permisos de todos los usuarios con este rol
            $users = User::role($role->name)->get(['id']);
            foreach ($users as $user) {
                \Illuminate\Support\Facades\Cache::forget("user:{$user->id}:permissions");
            }
        }

        $role->load('permissions');
        $users = User::role($role->name)->get(['id', 'name', 'email']);

        // Registrar en logs de auditoría
        $logContext = [
            'role_id' => $role->id,
            'role_name' => $role->name,
            'previous_name' => $previousName,
            'previous_description' => $previousDescription,
            'new_description' => $role->description,
        ];

        // Si el nombre o descripción cambiaron
        if ($previousName !== $role->name || $previousDescription !== $role->description) {
            $logContext['name_changed'] = $previousName !== $role->name;
            $logContext['description_changed'] = $previousDescription !== $role->description;
        }

        // Si los permisos cambiaron, agregar información detallada
        if ($permissionsChanged) {
            $logContext['permissions_changed'] = true;
            $logContext['previous_permissions'] = $previousPermissions;
            $logContext['new_permissions'] = $newPermissions;
            $logContext['added_permissions'] = array_values($addedPermissions);
            $logContext['removed_permissions'] = array_values($removedPermissions);
        } else {
            $logContext['permissions_changed'] = false;
        }

        $this->auditLogService->logAdministrativeAction('role_updated', $this->auditLogService->addRequestContext($request, $logContext));

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'guard_name' => $role->guard_name,
                'permissions' => $role->permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'description' => $permission->description,
                    ];
                }),
                'users' => $users->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ];
                }),
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ],
        ]);
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Role $role): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Los roles no se pueden eliminar por seguridad del sistema',
        ], 403);
    }
}
