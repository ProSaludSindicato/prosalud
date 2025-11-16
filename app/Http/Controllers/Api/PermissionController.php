<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    /**
     * Display a listing of permissions.
     */
    public function index(): JsonResponse
    {
        $permissions = Permission::all();

        return response()->json([
            'success' => true,
            'data' => $permissions->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'description' => $permission->description,
                    'guard_name' => $permission->guard_name,
                    'created_at' => $permission->created_at,
                    'updated_at' => $permission->updated_at,
                ];
            })
        ]);
    }

    /**
     * Display the specified permission.
     */
    public function show(Permission $permission): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $permission->id,
                'name' => $permission->name,
                'description' => $permission->description,
                'guard_name' => $permission->guard_name,
                'created_at' => $permission->created_at,
                'updated_at' => $permission->updated_at,
            ]
        ]);
    }

    /**
     * Update the specified permission.
     */
    public function update(Request $request, Permission $permission): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:permissions,name,' . $permission->id,
            'description' => 'nullable|string|max:500',
        ]);

        $permission->update([
            'name' => $request->name,
            'description' => $request->description,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permission updated successfully',
            'data' => [
                'id' => $permission->id,
                'name' => $permission->name,
                'description' => $permission->description,
                'guard_name' => $permission->guard_name,
                'created_at' => $permission->created_at,
                'updated_at' => $permission->updated_at,
            ]
        ]);
    }
}