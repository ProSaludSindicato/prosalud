<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\{Permission, Role};

class RoleController extends Controller
{
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

        if ($request->has('permissions')) {
            $permissions = Permission::whereIn('id', $request->permissions)->get();
            $role->syncPermissions($permissions);
        }

        $role->load('permissions');
        $users = User::role($role->name)->get(['id', 'name', 'email']);

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

        $role->update([
            'name' => $request->name,
            'description' => $request->description,
        ]);

        if ($request->has('permissions')) {
            $permissions = Permission::whereIn('id', $request->permissions)->get();
            $role->syncPermissions($permissions);
        }

        $role->load('permissions');
        $users = User::role($role->name)->get(['id', 'name', 'email']);

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
