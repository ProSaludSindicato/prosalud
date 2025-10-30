<?php

use App\Http\Controllers\Request\RequestController;
use App\Http\Controllers\User\UserController;
use App\Http\Controllers\WellnessEventController;
use App\Http\Controllers\ComfenalcoEventController;
use App\Http\Controllers\ChatbotConversationController;
use App\Http\Controllers\IncapacidadesController;
use App\Http\Controllers\LiquidacionesController;
use App\Http\Controllers\ActivosController;
use App\Http\Controllers\ActivosFileController;
use App\Http\Controllers\VoteController;
use App\Http\Controllers\DelegadosController;
use App\Http\Controllers\AfiliadoController;
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

    \Illuminate\Support\Facades\Log::info('Intento de login', [
        'email' => $credentials['email'],
        'ip_address' => $request->ip(),
        'user_agent' => $request->userAgent(),
        'timestamp' => now()->toISOString(),
    ]);

    if (!Auth::attempt($credentials)) {
        \Illuminate\Support\Facades\Log::warning('Login fallido', [
            'email' => $credentials['email'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toISOString(),
        ]);

        return response()->json(['message' => 'Credenciales incorrectas'], 401);
    }

    $user = Auth::user();

    // Log successful login
    \Illuminate\Support\Facades\Log::info('Login exitoso', [
        'user_id' => $user->id,
        'email' => $user->email,
        'name' => $user->name,
        'ip_address' => $request->ip(),
        'user_agent' => $request->userAgent(),
        'timestamp' => now()->toISOString(),
    ]);

    $request->session()->regenerate();

    return response()->json([
        'user' => $user,
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

// Comfenalco Events management routes
Route::apiResource('comfenalco-events', ComfenalcoEventController::class);
Route::patch('/comfenalco-events/{comfenalco_event}/visibility', [ComfenalcoEventController::class, 'changeVisibility']);

// Chatbot Conversations routes
Route::get('/chatbot-conversations', [ChatbotConversationController::class, 'index']);
Route::post('/chatbot-conversations', [ChatbotConversationController::class, 'store']);
Route::patch('/chatbot-conversations/{conversation}/feedback', [ChatbotConversationController::class, 'updateFeedback']);
Route::patch('/chatbot-conversations/client/{client_turn_id}/feedback', [ChatbotConversationController::class, 'updateFeedbackByClientTurnId']);

// Incapacidades routes (public endpoint for chatbot)
Route::post('/incapacidades/search', [IncapacidadesController::class, 'search']);

// Liquidaciones routes (public endpoint for chatbot)
Route::post('/liquidaciones/search', [LiquidacionesController::class, 'search']);

// Activos routes (public endpoint for hospital search)
Route::post('/activos/search-hospital', [ActivosController::class, 'searchHospital']);

// Activos file management routes (for uploading ACTIVOS2.xlsx)
Route::post('/activos-file/upload', [ActivosFileController::class, 'upload']);
Route::get('/activos-file/info', [ActivosFileController::class, 'info']);

// Vote routes (public endpoints for assembly voting)
Route::post('/votes', [VoteController::class, 'store']);
Route::get('/votes/statistics', [VoteController::class, 'statistics']);
Route::get('/votes/hospital-statistics', [VoteController::class, 'hospitalStatistics']);
Route::get('/votes/check', [VoteController::class, 'checkVote']);
Route::get('/votes/audit-trail', [VoteController::class, 'auditTrail']);

// Delegados routes (public endpoints for delegates consultation)
Route::get('/delegados', [DelegadosController::class, 'index']);
Route::get('/delegados/by-sede', [DelegadosController::class, 'getBySede']);
Route::get('/delegados/by-cedula', [DelegadosController::class, 'getByCedula']);
Route::get('/delegados/grouped-by-sede', [DelegadosController::class, 'getGroupedBySede']);

// Afiliados routes (public endpoint for affiliate authentication and information)
Route::post('/afiliados/authenticate', [AfiliadoController::class, 'authenticate']);

// Roles and Permissions management routes
Route::apiResource('roles', RoleController::class);
Route::get('/permissions', [PermissionController::class, 'index']);
Route::get('/permissions/{permission}', [PermissionController::class, 'show']);
Route::put('/permissions/{permission}', [PermissionController::class, 'update']);
