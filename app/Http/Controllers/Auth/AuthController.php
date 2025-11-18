<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Login del usuario.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $credentials = $request->only('email', 'password');

            if (!Auth::attempt($credentials)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incorrectas',
                ], 401);
            }

            $user = Auth::user();

            if (!$user->is_active) {
                Auth::logout();

                return response()->json([
                    'success' => false,
                    'message' => 'Tu cuenta está desactivada. Contacta al administrador.',
                ], 403);
            }

            $token = $user->createToken('admin-panel-token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login exitoso',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'is_active' => $user->is_active,
                        'created_at' => $user->created_at,
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno',
            ], 500);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout exitoso',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cerrar sesión',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno',
            ], 500);
        }
    }

    /**
     * Obtener información del usuario autenticado.
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'is_active' => $user->is_active,
                        'created_at' => $user->created_at,
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información del usuario',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno',
            ], 500);
        }
    }

    /**
     * Refrescar token (opcional).
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Revocar el token actual
            $request->user()->currentAccessToken()->delete();

            // Crear nuevo token
            $token = $user->createToken('admin-panel-token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Token refrescado exitosamente',
                'data' => [
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al refrescar el token',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno',
            ], 500);
        }
    }
}
