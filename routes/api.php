<?php

use App\Http\Controllers\ActivosController;
use App\Http\Controllers\ActivosFileController;
use App\Http\Controllers\AfiliadoController;
use App\Http\Controllers\AfiliadosFileController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\AuthController as ApiAuthController;
use App\Http\Controllers\ChatbotConversationController;
use App\Http\Controllers\ComfenalcoEventController;
use App\Http\Controllers\DelegadosController;
use App\Http\Controllers\DelegadosFileController;
use App\Http\Controllers\DotacionEppController;
use App\Http\Controllers\IncapacidadesController;
use App\Http\Controllers\IncapacidadesFileController;
use App\Http\Controllers\Inventory\HospitalRequestController;
use App\Http\Controllers\Inventory\InventoryCategoryController;
use App\Http\Controllers\Inventory\InventoryColorController;
use App\Http\Controllers\Inventory\InventoryDashboardController;
use App\Http\Controllers\Inventory\InventoryEntryController;
use App\Http\Controllers\Inventory\InventoryLocationController;
use App\Http\Controllers\Inventory\InventoryProductController;
use App\Http\Controllers\Inventory\InventoryStockMovementController;
use App\Http\Controllers\LiquidacionesController;
use App\Http\Controllers\LiquidacionesFileController;
use App\Http\Controllers\Request\RequestAssignmentController;
use App\Http\Controllers\Request\RequestController;
use App\Http\Controllers\User\UserController;
use App\Http\Controllers\VoteController;
use App\Http\Controllers\WellnessActivityRealizedController;
use App\Http\Controllers\WellnessEventController;
use App\Http\Controllers\WellnessRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [ApiAuthController::class, 'login']);
    Route::post('/set-password', [ApiAuthController::class, 'setPasswordFromInvitation']);
    Route::post('/forgot-password', [ApiAuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [ApiAuthController::class, 'resetPassword']);
    Route::middleware('auth.token')->group(function () {
        Route::post('/logout', [ApiAuthController::class, 'logout']);
        Route::get('/me', [ApiAuthController::class, 'me']);
    });
});

// Request management routes
Route::post('/chatbot-conversations', [ChatbotConversationController::class, 'store']);
Route::post('/incapacidades/search', [IncapacidadesController::class, 'search']);
Route::post('/liquidaciones/search', [LiquidacionesController::class, 'search']);
Route::post('/activos/search-hospital', [ActivosController::class, 'searchHospital']);

// Public read-only endpoints
Route::get('/comfenalco-events', [ComfenalcoEventController::class, 'index']);
Route::get('/comfenalco-events/{comfenalco_event}', [ComfenalcoEventController::class, 'show']);
Route::get('/wellness-events', [WellnessEventController::class, 'index']);
Route::get('/wellness-events/{wellness_event}', [WellnessEventController::class, 'show']);
Route::get('/wellness-requests/{wellness_request_id}/activity-realized', [WellnessActivityRealizedController::class, 'show']);
Route::post('/votes', [VoteController::class, 'store']);
Route::get('/votes/check', [VoteController::class, 'checkVote']);
Route::get('/delegados', [DelegadosController::class, 'index']);
Route::get('/delegados/by-sede', [DelegadosController::class, 'getBySede']);
Route::get('/delegados/by-cedula', [DelegadosController::class, 'getByCedula']);
Route::get('/delegados/grouped-by-sede', [DelegadosController::class, 'getGroupedBySede']);
Route::post('/afiliados/authenticate', [AfiliadoController::class, 'authenticate']);
Route::post('/afiliados/request-otp', [AfiliadoController::class, 'requestOtp']);
Route::post('/afiliados/verify-otp', [AfiliadoController::class, 'verifyOtp']);

// Public route for creating requests (used by affiliates from public site)
Route::post('/requests', [RequestController::class, 'store']);

Route::middleware(['auth.token', 'ensure.api.user'])->group(function () {
    // Request management routes
    Route::get('/requests', [RequestController::class, 'index'])->middleware('permission:requests.view');
    Route::get('/requests/{request}', [RequestController::class, 'show'])->middleware('permission:requests.view');
    Route::patch('/requests/{request}/status', [RequestController::class, 'changeStatus'])->middleware('permission:requests.respond');
    Route::post('/requests/{request}/respond', [RequestController::class, 'respond'])->middleware('permission:requests.respond');
    Route::patch('/requests/{request}/respond', [RequestController::class, 'respond'])->middleware('permission:requests.respond');
    Route::get('/requests/{request}/files/{fileKey}', [RequestController::class, 'downloadFile'])->middleware('permission:requests.view');

    // Votes admin reporting routes
    Route::get('/votes/statistics', [VoteController::class, 'statistics'])->middleware('permission:votes.statistics.view');
    Route::get('/votes/hospital-statistics', [VoteController::class, 'hospitalStatistics'])->middleware('permission:votes.statistics.view');
    Route::get('/votes/audit-trail', [VoteController::class, 'auditTrail'])->middleware('permission:votes.audit.view');
    Route::put('/votes/change-candidate', [VoteController::class, 'changeVoteCandidate'])->middleware('permission:votes.audit.view');

    // User management routes
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit');
    Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit');
    Route::patch('/users/{user}/status', [UserController::class, 'changeStatus'])->middleware('permission:users.change_status');

    // Request assignment routes
    Route::get('/request-assignments', [RequestAssignmentController::class, 'index'])->middleware('permission:users.view');
    Route::put('/request-assignments', [RequestAssignmentController::class, 'update'])->middleware('permission:users.edit');
    Route::post('/request-assignments', [RequestAssignmentController::class, 'store'])->middleware('permission:users.edit');

    // Wellness Events management routes
    Route::post('/wellness-events', [WellnessEventController::class, 'store'])->middleware('permission:wellness_events.create');
    Route::put('/wellness-events/{wellness_event}', [WellnessEventController::class, 'update'])->middleware('permission:wellness_events.edit');
    Route::patch('/wellness-events/{wellness_event}', [WellnessEventController::class, 'update'])->middleware('permission:wellness_events.edit');
    Route::delete('/wellness-events/{wellness_event}', [WellnessEventController::class, 'destroy'])->middleware('permission:wellness_events.edit');
    Route::patch('/wellness-events/{wellness_event}/visibility', [WellnessEventController::class, 'changeVisibility'])->middleware('permission:wellness_events.edit');
    Route::post('/wellness-events/{wellness_event}/images', [WellnessEventController::class, 'addImages'])->middleware('permission:wellness_events.edit');
    Route::delete('/wellness-events/{wellness_event}/images/{image}', [WellnessEventController::class, 'removeImage'])->middleware('permission:wellness_events.edit');

    // Wellness Requests management routes
    Route::get('/wellness-requests', [WellnessRequestController::class, 'index'])->middleware('permission:wellness_requests.view');
    Route::post('/wellness-requests', [WellnessRequestController::class, 'store'])->middleware('permission:wellness_requests.create');
    Route::get('/wellness-requests/{wellnessRequest}', [WellnessRequestController::class, 'show'])->middleware('permission:wellness_requests.view');
    Route::put('/wellness-requests/{wellnessRequest}', [WellnessRequestController::class, 'update'])->middleware('permission:wellness_requests.edit');
    Route::patch('/wellness-requests/{wellnessRequest}', [WellnessRequestController::class, 'update'])->middleware('permission:wellness_requests.edit');

    // Wellness Activity Realized routes
    Route::post('/wellness-requests/{wellness_request_id}/activity-realized', [WellnessActivityRealizedController::class, 'store'])->middleware('permission:wellness_requests.edit');
    Route::put('/wellness-requests/{wellness_request_id}/activity-realized', [WellnessActivityRealizedController::class, 'update'])->middleware('permission:wellness_requests.edit');
    Route::post('/wellness-requests/{wellness_request_id}/publish-to-gallery', [WellnessActivityRealizedController::class, 'publishToGallery'])->middleware('permission:wellness_activity.publish');

    // Comfenalco Events management routes (admin)
    Route::post('/comfenalco-events', [ComfenalcoEventController::class, 'store'])->middleware('permission:comfenalco_events.create');
    Route::put('/comfenalco-events/{comfenalco_event}', [ComfenalcoEventController::class, 'update'])->middleware('permission:comfenalco_events.edit');
    Route::patch('/comfenalco-events/{comfenalco_event}', [ComfenalcoEventController::class, 'update'])->middleware('permission:comfenalco_events.edit');
    Route::delete('/comfenalco-events/{comfenalco_event}', [ComfenalcoEventController::class, 'destroy'])->middleware('permission:comfenalco_events.delete');
    Route::patch('/comfenalco-events/{comfenalco_event}/visibility', [ComfenalcoEventController::class, 'changeVisibility'])->middleware('permission:comfenalco_events.edit');

    // Chatbot Conversations admin routes
    Route::get('/chatbot-conversations', [ChatbotConversationController::class, 'index'])->middleware('permission:chatbot.manage');
    Route::patch('/chatbot-conversations/{conversation}/feedback', [ChatbotConversationController::class, 'updateFeedback'])->middleware('permission:chatbot.manage');
    Route::patch('/chatbot-conversations/client/{client_turn_id}/feedback', [ChatbotConversationController::class, 'updateFeedbackByClientTurnId'])->middleware('permission:chatbot.manage');

    // Activos file management routes (for uploading ACTIVOS2.xlsx)
    Route::post('/activos-file/upload', [ActivosFileController::class, 'upload'])->middleware('permission:activos_files.manage');
    Route::get('/activos-file/info', [ActivosFileController::class, 'info'])->middleware('permission:activos_files.manage');

    // Afiliados file management routes (for uploading PROSANET afiliados file)
    Route::post('/afiliados-file/upload', [AfiliadosFileController::class, 'upload'])->middleware('permission:afiliados_files.manage');

    // Incapacidades, Liquidaciones y Delegados file management routes
    Route::post('/incapacidades-file/upload', [IncapacidadesFileController::class, 'upload'])->middleware('permission:incapacidades_files.manage');
    Route::post('/liquidaciones-file/upload', [LiquidacionesFileController::class, 'upload'])->middleware('permission:liquidaciones_files.manage');
    Route::post('/delegados-file/upload', [DelegadosFileController::class, 'upload'])->middleware('permission:delegados_files.manage');

    // Roles and Permissions management routes
    Route::apiResource('roles', RoleController::class)->middleware('permission:roles.manage');
    Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:roles.manage');
    Route::get('/permissions/{permission}', [PermissionController::class, 'show'])->middleware('permission:roles.manage');
    Route::put('/permissions/{permission}', [PermissionController::class, 'update'])->middleware('permission:roles.manage');

    // Dotación y EPP routes
    Route::prefix('dotacion-epp')->group(function () {
        Route::get('/affiliates', [DotacionEppController::class, 'affiliates'])->middleware('permission:dotacion.view');
        Route::get('/affiliates/{documentType}/{documentNumber}', [DotacionEppController::class, 'showAffiliate'])->middleware('permission:dotacion.view');
        Route::get('/inventory', [DotacionEppController::class, 'inventory'])->middleware('permission:dotacion.view');
        Route::get('/deliveries', [DotacionEppController::class, 'deliveries'])->middleware('permission:dotacion.view');
        Route::post('/deliveries', [DotacionEppController::class, 'storeDelivery'])->middleware(['permission:dotacion.view', 'permission:dotacion.deliveries.create']);
        Route::get('/returns', [DotacionEppController::class, 'returns'])->middleware('permission:dotacion.view');
        Route::post('/returns', [DotacionEppController::class, 'storeReturn'])->middleware(['permission:dotacion.view', 'permission:dotacion.deliveries.create']);
    });

    // Inventory Management routes
    Route::prefix('inventory')->group(function () {
        Route::get('/dashboard', [InventoryDashboardController::class, 'index'])->middleware('permission:inventory.view_dashboard');

        Route::get('/categories', [InventoryCategoryController::class, 'index'])->middleware('permission:inventory.categories.view');
        Route::post('/categories', [InventoryCategoryController::class, 'store'])->middleware('permission:inventory.categories.manage');
        Route::get('/categories/{category}', [InventoryCategoryController::class, 'show'])->middleware('permission:inventory.categories.view');
        Route::put('/categories/{category}', [InventoryCategoryController::class, 'update'])->middleware('permission:inventory.categories.manage');
        Route::delete('/categories/{category}', [InventoryCategoryController::class, 'destroy'])->middleware('permission:inventory.categories.manage');

        Route::post('/categories/{category}/subcategories', [InventoryCategoryController::class, 'storeSubcategory'])->middleware('permission:inventory.categories.manage');
        Route::put('/categories/{category}/subcategories/{subcategory}', [InventoryCategoryController::class, 'updateSubcategory'])->middleware('permission:inventory.categories.manage');
        Route::delete('/categories/{category}/subcategories/{subcategory}', [InventoryCategoryController::class, 'destroySubcategory'])->middleware('permission:inventory.categories.manage');

        Route::get('/products', [InventoryProductController::class, 'index'])->middleware('permission:inventory.products.view');
        Route::post('/products', [InventoryProductController::class, 'store'])->middleware('permission:inventory.products.manage');
        Route::get('/products/{product}', [InventoryProductController::class, 'show'])->middleware('permission:inventory.products.view');
        Route::put('/products/{product}', [InventoryProductController::class, 'update'])->middleware('permission:inventory.products.manage');
        Route::delete('/products/{product}', [InventoryProductController::class, 'destroy'])->middleware('permission:inventory.products.manage');

        Route::get('/colors', [InventoryColorController::class, 'index'])->middleware('permission:inventory.products.view');

        Route::get('/hospital-requests', [HospitalRequestController::class, 'index'])->middleware('permission:hospital_requests.view');
        Route::post('/hospital-requests', [HospitalRequestController::class, 'store'])->middleware('permission:hospital_requests.create');
        Route::get('/hospital-requests/{hospital_request}', [HospitalRequestController::class, 'show'])->middleware('permission:hospital_requests.view');
        Route::put('/hospital-requests/{hospital_request}/status', [HospitalRequestController::class, 'updateStatus'])->middleware('permission:hospital_requests.update_status');

        Route::get('/entries', [InventoryEntryController::class, 'index'])->middleware('permission:inventory.entries.view');
        Route::post('/entries', [InventoryEntryController::class, 'store'])->middleware('permission:inventory.entries.manage');
        Route::get('/entries/{entry}', [InventoryEntryController::class, 'show'])->middleware('permission:inventory.entries.view');

        Route::get('/locations', [InventoryLocationController::class, 'index'])->middleware('permission:inventory.locations.view');
        Route::get('/locations/{location}', [InventoryLocationController::class, 'show'])->middleware('permission:inventory.locations.view');

        Route::get('/stock-movements', [InventoryStockMovementController::class, 'index'])->middleware('permission:inventory.stock_movements.view');
    });
});
