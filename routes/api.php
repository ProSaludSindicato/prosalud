<?php

use App\Http\Controllers\Request\RequestController;
use App\Http\Controllers\User\UserController;
use App\Http\Controllers\WellnessEventController;
use App\Http\Controllers\ChatbotConversationController;
use App\Http\Controllers\IncapacidadesController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PermissionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Ruta para login
Route::post('/login', function (Request $request) {
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    if (!Auth::attempt($credentials)) {
        return response()->json(['message' => 'Credenciales incorrectas'], 401);
    }

    $request->session()->regenerate();

    return response()->json([
        'user' => Auth::user(),
    ]);
});

// Ruta para logout
Route::post('/logout', function (Request $request) {
    Auth::guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return response()->json(['message' => 'Logout exitoso']);
});

// Ruta para obtener usuario autenticado
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Request management routes
Route::get('/requests', [RequestController::class, 'index']);
Route::post('/requests', [RequestController::class, 'store']);
Route::get('/requests/{request}', [RequestController::class, 'show']);
Route::patch('/requests/{request}/status', [RequestController::class, 'changeStatus']);

// User management routes
Route::apiResource('users', UserController::class);
Route::patch('/users/{user}/status', [UserController::class, 'changeStatus']);

// Wellness Events management routes
Route::apiResource('wellness-events', WellnessEventController::class);
Route::patch('/wellness-events/{wellness_event}/visibility', [WellnessEventController::class, 'changeVisibility']);
Route::post('/wellness-events/{wellness_event}/images', [WellnessEventController::class, 'addImages']);
Route::delete('/wellness-events/{wellness_event}/images/{image}', [WellnessEventController::class, 'removeImage']);

// Chatbot Conversations routes
Route::get('/chatbot-conversations', [ChatbotConversationController::class, 'index']);
Route::post('/chatbot-conversations', [ChatbotConversationController::class, 'store']);
Route::patch('/chatbot-conversations/{conversation}/feedback', [ChatbotConversationController::class, 'updateFeedback']);
Route::patch('/chatbot-conversations/client/{client_turn_id}/feedback', [ChatbotConversationController::class, 'updateFeedbackByClientTurnId']);

// Incapacidades routes (public endpoint for chatbot)
Route::post('/incapacidades/search', [IncapacidadesController::class, 'search']);

// Roles and Permissions management routes
Route::apiResource('roles', RoleController::class);
Route::get('/permissions', [PermissionController::class, 'index']);
Route::get('/permissions/{permission}', [PermissionController::class, 'show']);
Route::put('/permissions/{permission}', [PermissionController::class, 'update']);
