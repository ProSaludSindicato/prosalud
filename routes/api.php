<?php

use App\Http\Controllers\{ActivosController, ActivosFileController, AfiliadoController, AfiliadosFileController, AuthController as ApiAuthController, CertificadoConvenioController, ChatbotConversationController, ComfenalcoEventController, CompensacionesFileController, DelegadosController, DelegadosFileController, DocuSignController, DocuSignWebhookController, DotacionEppController, IncapacidadesController, IncapacidadesFileController, LiquidacionesController, LiquidacionesFileController, SstDeliveryReportController, SurveyConfigController, VoteController, WellnessActivityRealizedController, WellnessEventController, WellnessRequestController, SocioDemographicSurveyController};
use App\Http\Controllers\Api\{PermissionController, RoleController};
use App\Http\Controllers\Assembly\{AssemblyAttendanceController, AssemblyController, AssemblyQuestionController, AssemblyReportController, AssemblyVoteController, QuorumController};
use App\Http\Controllers\Inventory\{HospitalRequestController, InventoryCategoryController, InventoryColorController, InventoryDashboardController, InventoryEntryController, InventoryLocationController, InventoryProductController, InventoryReportController, InventoryStockMovementController};
use App\Http\Controllers\Request\{RequestAssignmentController, RequestController};
use App\Http\Controllers\User\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Endpoints de autenticación con rate limiting + reCAPTCHA
    Route::post('/login', [ApiAuthController::class, 'login'])
        ->middleware(['throttle:5,1', 'recaptcha:login']);
    Route::post('/set-password', [ApiAuthController::class, 'setPasswordFromInvitation']);
    Route::post('/forgot-password', [ApiAuthController::class, 'forgotPassword'])
        ->middleware(['throttle:5,1', 'recaptcha:forgot_password']);
    Route::post('/reset-password', [ApiAuthController::class, 'resetPassword'])
        ->middleware(['throttle:5,1', 'recaptcha:reset_password']);
    Route::middleware('auth.token')->group(function () {
        Route::post('/logout', [ApiAuthController::class, 'logout']);
        Route::get('/me', [ApiAuthController::class, 'me']);
    });
});

// Request management routes - Rate limiting: 20 requests per minute
Route::middleware('throttle:public-endpoints')->group(function () {
    Route::post('/chatbot-conversations', [ChatbotConversationController::class, 'store']);
    Route::post('/incapacidades/search', [IncapacidadesController::class, 'search']);
    Route::post('/liquidaciones/search', [LiquidacionesController::class, 'search']);
    Route::post('/activos/search-hospital', [ActivosController::class, 'searchHospital']);
});

// Public read-only endpoints
Route::get('/comfenalco-events', [ComfenalcoEventController::class, 'index']);
Route::get('/comfenalco-events/{comfenalco_event}', [ComfenalcoEventController::class, 'show']);
// Public API - Wellness Events (for public website)
Route::get('/public/wellness-events', [WellnessEventController::class, 'publicIndex']);
Route::get('/public/wellness-events/{wellness_event}', [WellnessEventController::class, 'publicShow']);

// Private API - Wellness Events (requires authentication and permissions)
// These routes are moved inside the authenticated group below
Route::get('/wellness-requests/{wellness_request_id}/activity-realized', [WellnessActivityRealizedController::class, 'show']);
Route::post('/votes', [VoteController::class, 'store']);
Route::get('/votes/check', [VoteController::class, 'checkVote']);
Route::get('/delegados', [DelegadosController::class, 'index']);
Route::get('/delegados/by-sede', [DelegadosController::class, 'getBySede']);
Route::get('/delegados/by-cedula', [DelegadosController::class, 'getByCedula']);
Route::get('/delegados/grouped-by-sede', [DelegadosController::class, 'getGroupedBySede']);

// Afiliados authentication routes - Rate limiting aplicado
Route::middleware('throttle:public-endpoints')->group(function () {
    Route::post('/afiliados/authenticate', [AfiliadoController::class, 'authenticate']);
    Route::post('/afiliados/authenticate-for-data-update', [AfiliadoController::class, 'authenticateForDataUpdate']);
    Route::post('/afiliados/verify-otp', [AfiliadoController::class, 'verifyOtp']);
});

// OTP requests - Rate limiting híbrido (doble capa) + reCAPTCHA
// Capa 1: 5/min por documento | Capa 2: 30/min por IP
Route::post('/afiliados/request-otp', [AfiliadoController::class, 'requestOtp'])
    ->middleware(['dual-rate-limit:otp', 'recaptcha:request_otp']);

// Public route for creating requests (used by affiliates from public site) - Rate limiting: 20 requests per minute
Route::post('/requests', [RequestController::class, 'store'])->middleware('throttle:public-endpoints');

// Public route for getting survey configuration (to check if bulk entry mode is enabled)
Route::get('/survey-config/public', [SurveyConfigController::class, 'show']);

// Public route for creating socio-demographic surveys (used by affiliates from public site) - Rate limiting: 20 requests per minute
Route::post('/socio-demographic-surveys', [SocioDemographicSurveyController::class, 'store'])->middleware('throttle:public-endpoints');

// RUTA TEMPORAL: Reintentar generación de certificado cuando falló por intermitencia del servicio Word a PDF
// TODO: Eliminar esta ruta después de resolver el problema de intermitencia
Route::post('/requests/{requestId}/retry-certificate-generation', [RequestController::class, 'retryCertificateGeneration'])
    ->middleware('throttle:public-endpoints');

// Certificados de Convenio routes (públicas, sin autenticación)
Route::prefix('certificados')->group(function () {
    // Endpoints críticos con rate limiting híbrido (doble capa) + reCAPTCHA
    // Capa 1: 5/min por documento | Capa 2: 50/min por IP
    Route::middleware(['dual-rate-limit:critical', 'recaptcha:solicitar_certificado'])->group(function () {
        // Route::post('/convenio/generar', [CertificadoConvenioController::class, 'generar']);
        // Route::post('/convenio/generar-word', [CertificadoConvenioController::class, 'generarWord']);
        Route::post('/convenio/solicitar', [CertificadoConvenioController::class, 'solicitarAutomatico']);
    });

    // Endpoints menos críticos con rate limiting moderado (20 por minuto)
    Route::middleware('throttle:public-endpoints')->group(function () {
        Route::post('/convenio/consultar', [CertificadoConvenioController::class, 'consultar']);
        Route::get('/convenio/estadisticas', [CertificadoConvenioController::class, 'estadisticas']);
    });
});

// DocuSign Embedded Signing routes (públicas, sin autenticación)
// Rate limiting: 20 requests per minute
Route::post('/firma', [DocuSignController::class, 'createSignature'])
    ->middleware('throttle:public-endpoints');

// DocuSign Webhook (público, sin autenticación, sin CSRF)
// No rate limiting - DocuSign needs reliable delivery
Route::post('/webhooks/docusign', [DocuSignWebhookController::class, 'handle'])
    ->withoutMiddleware(['csrf', 'auth.token']);

// Assembly Voting System Routes
Route::prefix('assembly')->group(function () {
    // Public routes - Questions (read-only for voters)
    Route::get('/questions', [AssemblyQuestionController::class, 'index']);
    Route::get('/questions/{id}', [AssemblyQuestionController::class, 'show']);

    // Public routes - Votes
    Route::post('/questions/{questionId}/votes', [AssemblyVoteController::class, 'store']);
    Route::get('/questions/{questionId}/votes/me', [AssemblyVoteController::class, 'getMyVote']);

    // Public routes - Results
    Route::get('/questions/{questionId}/results', [AssemblyVoteController::class, 'getResults']);

    // Public routes - Quorum
    Route::get('/quorum', [QuorumController::class, 'index']);

    // Public routes - Current Assembly
    Route::get('/current', [AssemblyController::class, 'current']);

    Route::middleware(['auth.token', 'ensure.api.user'])->group(function () {
        // Admin routes - Assembly management
        Route::get('/assemblies', [AssemblyController::class, 'index'])->middleware('permission:assembly.questions.manage');
        Route::post('/assemblies', [AssemblyController::class, 'store'])->middleware('permission:assembly.questions.manage');
        Route::get('/assemblies/{id}', [AssemblyController::class, 'show'])->middleware('permission:assembly.questions.manage');
        Route::put('/assemblies/{id}', [AssemblyController::class, 'update'])->middleware('permission:assembly.questions.manage');
        Route::post('/assemblies/{id}/activate', [AssemblyController::class, 'activate'])->middleware('permission:assembly.questions.manage');
        Route::post('/assemblies/{id}/deactivate', [AssemblyController::class, 'deactivate'])->middleware('permission:assembly.questions.manage');

        // Admin routes - Questions management
        // Admin routes - Questions management
        Route::post('/questions', [AssemblyQuestionController::class, 'store'])->middleware('permission:assembly.questions.manage');
        Route::put('/questions/{id}', [AssemblyQuestionController::class, 'update'])->middleware('permission:assembly.questions.manage');
        Route::patch('/questions/{id}', [AssemblyQuestionController::class, 'update'])->middleware('permission:assembly.questions.manage');
        Route::delete('/questions/{id}', [AssemblyQuestionController::class, 'destroy'])->middleware('permission:assembly.questions.manage');
        Route::post('/questions/{id}/open', [AssemblyQuestionController::class, 'open'])->middleware('permission:assembly.questions.manage');
        Route::post('/questions/{id}/close', [AssemblyQuestionController::class, 'close'])->middleware('permission:assembly.questions.manage');
        Route::post('/live-question/start', [AssemblyQuestionController::class, 'startLiveQuestion'])->middleware('permission:assembly.questions.manage');
        Route::post('/live-question/close', [AssemblyQuestionController::class, 'closeActiveQuestion'])->middleware('permission:assembly.questions.manage');
        Route::get('/attendance', [AssemblyAttendanceController::class, 'index'])->middleware('permission:assembly.questions.manage');
        Route::delete('/attendance/{id}', [AssemblyAttendanceController::class, 'destroy'])->middleware('permission:assembly.questions.manage');
        Route::get('/report/download', [AssemblyReportController::class, 'download'])->middleware('permission:assembly.questions.manage');

        // Admin routes - Quorum management
        Route::put('/quorum', [QuorumController::class, 'update'])->middleware('permission:assembly.quorum.manage');
        Route::patch('/quorum', [QuorumController::class, 'update'])->middleware('permission:assembly.quorum.manage');
        Route::post('/quorum/verify', [QuorumController::class, 'verify'])->middleware('permission:assembly.quorum.manage');
    });
});

Route::middleware(['auth.token', 'ensure.api.user'])->group(function () {
    // Certificados de Convenio - Listado (requiere autenticación)
    Route::get('/certificados/convenio', [CertificadoConvenioController::class, 'index'])->middleware('permission:requests.view');

    // Request management routes
    Route::get('/requests', [RequestController::class, 'index'])->middleware('permission:requests.view');
    Route::get('/requests/pending-personal-data-updates', [RequestController::class, 'pendingPersonalDataUpdates'])->middleware('permission:requests.view');
    
    // Rutas específicas (sin parámetros dinámicos) - DEBEN IR ANTES de las rutas con {request}
    Route::get('/requests/bulk-response-template', [RequestController::class, 'exportBulkResponseTemplate'])->middleware('permission:requests.respond');
    Route::post('/requests/bulk-response', [RequestController::class, 'processBulkResponse'])->middleware('permission:requests.respond');
    Route::post('/requests/export/excel', [RequestController::class, 'exportExcel'])->middleware('permission:requests.view');
    Route::get('/requests/responses/{response}/attachments/{attachment}', [RequestController::class, 'downloadResponseAttachment'])->middleware('permission:requests.view');
    
    // Rutas con parámetros dinámicos - DESPUÉS de las específicas
    Route::get('/requests/{request}', [RequestController::class, 'show'])->middleware('permission:requests.view');
    Route::post('/requests/{request}/validate', [RequestController::class, 'validate'])->middleware('permission:requests.respond');
    Route::patch('/requests/{request}/status', [RequestController::class, 'changeStatus'])->middleware('permission:requests.respond');
    Route::patch('/requests/{request}/redirect-subtype', [RequestController::class, 'redirectSubtype'])->middleware('permission:requests.respond');
    Route::post('/requests/{request}/respond', [RequestController::class, 'respond'])->middleware('permission:requests.respond');
    Route::patch('/requests/{request}/respond', [RequestController::class, 'respond'])->middleware('permission:requests.respond');
    Route::post('/requests/{request}/respond-with-compensaciones', [RequestController::class, 'respondWithCompensaciones'])->middleware('permission:requests.respond');
    Route::patch('/requests/{request}/respond-with-compensaciones', [RequestController::class, 'respondWithCompensaciones'])->middleware('permission:requests.respond');
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
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.change_status');

    // Request assignment routes
    Route::get('/request-assignments', [RequestAssignmentController::class, 'index'])->middleware('permission:users.view');
    Route::put('/request-assignments', [RequestAssignmentController::class, 'update'])->middleware('permission:users.edit');
    Route::post('/request-assignments', [RequestAssignmentController::class, 'store'])->middleware('permission:users.edit');

    // Wellness Events management routes
    Route::get('/wellness-events', [WellnessEventController::class, 'index'])->middleware('permission:wellness_events.view');
    Route::get('/wellness-events/{wellness_event}', [WellnessEventController::class, 'show'])->middleware('permission:wellness_events.view');
    Route::post('/wellness-events', [WellnessEventController::class, 'store'])->middleware('permission:wellness_events.create');
    Route::put('/wellness-events/{wellness_event}', [WellnessEventController::class, 'update'])->middleware('permission:wellness_events.edit');
    Route::patch('/wellness-events/{wellness_event}', [WellnessEventController::class, 'update'])->middleware('permission:wellness_events.edit');
    Route::delete('/wellness-events/{wellness_event}', [WellnessEventController::class, 'destroy'])->middleware('permission:wellness_events.edit');
    Route::patch('/wellness-events/{wellness_event}/visibility', [WellnessEventController::class, 'changeVisibility'])->middleware('permission:wellness_events.edit');
    Route::post('/wellness-events/{wellness_event}/images', [WellnessEventController::class, 'addImages'])->middleware('permission:wellness_events.edit');
    Route::delete('/wellness-events/{wellness_event}/images/{image}', [WellnessEventController::class, 'removeImage'])->middleware('permission:wellness_events.edit');
    Route::post('/wellness-events/{wellness_event}/review', [WellnessEventController::class, 'review'])->middleware('permission:wellness_events.edit');
    Route::post('/wellness-events/{wellness_event}/approve', [WellnessEventController::class, 'approve'])->middleware('permission:wellness_events.edit');
    Route::post('/wellness-events/{wellness_event}/reject', [WellnessEventController::class, 'reject'])->middleware('permission:wellness_events.edit');

    // Wellness Requests management routes
    Route::get('/wellness-requests', [WellnessRequestController::class, 'index'])->middleware('permission:wellness_requests.view');
    Route::post('/wellness-requests', [WellnessRequestController::class, 'store'])->middleware('permission:wellness_requests.create');
    Route::get('/wellness-requests/{wellnessRequest}', [WellnessRequestController::class, 'show'])->middleware('permission:wellness_requests.view');
    Route::put('/wellness-requests/{wellnessRequest}', [WellnessRequestController::class, 'update'])->middleware('permission:wellness_requests.edit');
    Route::patch('/wellness-requests/{wellnessRequest}', [WellnessRequestController::class, 'update'])->middleware('permission:wellness_requests.edit');
    Route::post('/wellness-requests/export/excel', [WellnessRequestController::class, 'exportExcel'])->middleware('permission:wellness_requests.view');
    Route::get('/wellness-requests/completed/without-activities', [WellnessRequestController::class, 'getCompletedWithoutActivities'])->middleware('permission:wellness_requests.view');

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
    Route::get('/afiliados-file/info', [AfiliadosFileController::class, 'info'])->middleware('permission:afiliados_files.manage');
    Route::get('/afiliados-file/download', [AfiliadosFileController::class, 'download'])->middleware('permission:afiliados_files.manage');

    // Incapacidades, Liquidaciones, Delegados y Compensaciones file management routes
    Route::post('/incapacidades-file/upload', [IncapacidadesFileController::class, 'upload'])->middleware('permission:incapacidades_files.manage');
    Route::get('/incapacidades-file/info', [IncapacidadesFileController::class, 'info'])->middleware('permission:incapacidades_files.manage');
    Route::get('/incapacidades-file/download', [IncapacidadesFileController::class, 'download'])->middleware('permission:incapacidades_files.manage');
    Route::post('/liquidaciones-file/upload', [LiquidacionesFileController::class, 'upload'])->middleware('permission:liquidaciones_files.manage');
    Route::get('/liquidaciones-file/info', [LiquidacionesFileController::class, 'info'])->middleware('permission:liquidaciones_files.manage');
    Route::get('/liquidaciones-file/download', [LiquidacionesFileController::class, 'download'])->middleware('permission:liquidaciones_files.manage');
    Route::post('/delegados-file/upload', [DelegadosFileController::class, 'upload'])->middleware('permission:delegados_files.manage');
    Route::post('/compensaciones-file/upload', [CompensacionesFileController::class, 'upload'])->middleware('permission:compensaciones_files.manage');
    Route::get('/compensaciones-file/info', [CompensacionesFileController::class, 'info'])->middleware('permission:compensaciones_files.manage');
    Route::get('/compensaciones-file/download', [CompensacionesFileController::class, 'download'])->middleware('permission:compensaciones_files.manage');

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
        Route::get('/reports/deliveries/excel', [SstDeliveryReportController::class, 'download'])->middleware('permission:dotacion.view');
        Route::get('/reports/deliveries/status/{jobId}', [SstDeliveryReportController::class, 'checkStatus'])->middleware('permission:dotacion.view');
        Route::get('/reports/deliveries/download/{jobId}', [SstDeliveryReportController::class, 'downloadReport'])->middleware('permission:dotacion.view');
    });

    // Socio-Demographic Surveys management routes (admin)
    Route::get('/socio-demographic-surveys', [SocioDemographicSurveyController::class, 'index'])->middleware('permission:socio_demographic_surveys.view');
    Route::get('/socio-demographic-surveys/{survey}', [SocioDemographicSurveyController::class, 'show'])->middleware('permission:socio_demographic_surveys.view');
    Route::get('/socio-demographic-surveys/{survey}/signature', [SocioDemographicSurveyController::class, 'downloadSignature'])->middleware('permission:socio_demographic_surveys.view');
    Route::post('/socio-demographic-surveys/export/excel', [SocioDemographicSurveyController::class, 'exportExcel'])->middleware('permission:socio_demographic_surveys.view');
    Route::get('/socio-demographic-surveys/export/status/{jobId}', [SocioDemographicSurveyController::class, 'checkStatus'])->middleware('permission:socio_demographic_surveys.view');
    Route::get('/socio-demographic-surveys/export/download/{jobId}', [SocioDemographicSurveyController::class, 'downloadReport'])->middleware('permission:socio_demographic_surveys.view');

    // Survey Configuration management routes (admin)
    Route::get('/survey-config', [SurveyConfigController::class, 'show'])->middleware('permission:socio_demographic_surveys.config.manage');
    Route::put('/survey-config', [SurveyConfigController::class, 'update'])->middleware('permission:socio_demographic_surveys.config.manage');
    Route::patch('/survey-config', [SurveyConfigController::class, 'update'])->middleware('permission:socio_demographic_surveys.config.manage');

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

        Route::post('/reports/excel', [InventoryReportController::class, 'generateExcel']);
    });
});
