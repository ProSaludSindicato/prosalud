<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\{AuditLogService, UserInvitationService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function __construct(
        private AuditLogService $auditLogService,
        private UserInvitationService $userInvitationService,
    ) {
    }

    /**
     * Display a listing of users.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Pagination
        $perPage = $request->get('per_page', 15);
        $users = $query->paginate($perPage);

        Log::info('Lista de usuarios consultada', [
            'total_users' => $users->total(),
            'current_page' => $users->currentPage(),
            'per_page' => $users->perPage(),
            'filters' => $request->only(['search', 'is_active']),
        ]);

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $userData = $request->validated();
        $role = $userData['role'];

        unset($userData['role']);

        // El usuario se crea con una contraseña aleatoria que el usuario no conoce
        // y en estado inactivo. Luego definirá su propia contraseña mediante el enlace
        // enviado por correo.
        $userData['password'] = Str::random(40);
        $userData['is_active'] = false;

        $user = User::create($userData);

        $user->assignRole($role);

        // Enviar invitación para que el usuario defina su contraseña y active su cuenta.
        $this->userInvitationService->sendInvitation($user);

        Log::info('Usuario creado exitosamente', [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'role' => $role,
            'created_at' => $user->created_at,
        ]);

        $this->auditLogService->logAdministrativeAction('user_created', $this->auditLogService->addRequestContext($request, [
            'target_user_id' => $user->id,
            'target_user_email' => $user->email,
            'target_user_name' => $user->name,
            'assigned_role' => $role,
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Usuario creado exitosamente',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'role' => $role,
                'created_at' => $user->created_at,
            ],
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user): JsonResponse
    {
        Log::info('Usuario consultado', [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'roles' => $user->roles->pluck('name'),
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $userData = $request->validated();

        $user->update($userData);

        Log::info('Usuario actualizado exitosamente', [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'updated_at' => $user->updated_at,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Usuario actualizado exitosamente',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'updated_at' => $user->updated_at,
            ],
        ]);
    }

    /**
     * Change user status (activate/deactivate).
     */
    public function changeStatus(ChangeUserStatusRequest $request, User $user): JsonResponse
    {
        $isActive = $request->validated()['is_active'];

        $user->update(['is_active' => $isActive]);

        $statusText = $isActive ? 'activado' : 'desactivado';

        Log::info("Usuario {$statusText}", [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'new_status' => $isActive,
            'updated_at' => $user->updated_at,
        ]);

        $this->auditLogService->logAdministrativeAction('user_status_changed', $this->auditLogService->addRequestContext($request, [
            'target_user_id' => $user->id,
            'target_user_email' => $user->email,
            'target_user_name' => $user->name,
            'old_status' => !$isActive,
            'new_status' => $isActive,
            'action' => $statusText,
        ]));

        return response()->json([
            'success' => true,
            'message' => "Usuario {$statusText} exitosamente",
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'updated_at' => $user->updated_at,
            ],
        ]);
    }
}
