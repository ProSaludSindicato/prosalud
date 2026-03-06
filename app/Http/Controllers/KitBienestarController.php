<?php

namespace App\Http\Controllers;

use App\Http\Requests\{ExportWellnessDeliveryExcelRequest, StoreKitBienestarRequest, UpdateWellnessDeliveryRequestStatusRequest, UploadKitBienestarFileRequest};
use App\Models\{KitBienestarFileVersion, WellnessDeliveryRequest, WellnessDeliveryType};
use App\Services\{AfiliadoService, KitBienestarService, LogSanitizationService, WellnessDeliveryExcelExportService};
use Carbon\Carbon;
use Illuminate\Http\{JsonResponse, Request};
use Symfony\Component\HttpFoundation\{BinaryFileResponse as SymfonyBinaryFileResponse, StreamedResponse};
use Illuminate\Support\Facades\{Cache, DB, Log, Storage};
use Illuminate\Support\Str;

class KitBienestarController extends Controller
{
    private KitBienestarService $kitBienestarService;
    private WellnessDeliveryExcelExportService $excelExportService;
    private AfiliadoService $afiliadoService;

    public function __construct(
        KitBienestarService $kitBienestarService,
        WellnessDeliveryExcelExportService $excelExportService,
        AfiliadoService $afiliadoService
    ) {
        $this->kitBienestarService = $kitBienestarService;
        $this->excelExportService = $excelExportService;
        $this->afiliadoService = $afiliadoService;
    }

    /**
     * Consulta de afiliado por documento (solo autenticado).
     * Devuelve la información necesaria para registrar una entrega de bienestar.
     * GET /api/wellness-delivery-requests/affiliate-lookup?documento=XXX
     */
    public function affiliateLookup(Request $request): JsonResponse
    {
        $request->validate([
            'documento' => 'required|string|max:50',
        ]);

        $documento = trim($request->query('documento'));

        if (!$this->afiliadoService->isFileAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Servicio temporalmente no disponible. El archivo de afiliados no está disponible.',
            ], 503);
        }

        $afiliado = $this->afiliadoService->getAfiliadoByDocumentoOnly($documento);

        if (null === $afiliado) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró un afiliado con el documento indicado.',
            ], 404);
        }

        $estado = isset($afiliado['estado']) ? trim((string) $afiliado['estado']) : null;
        if ($estado === null || strcasecmp($estado, 'Activo') !== 0) {
            return response()->json([
                'success' => false,
                'message' => 'El afiliado no tiene estado Activo. Solo los afiliados activos pueden recibir entregas de bienestar.',
            ], 403);
        }

        $data = [
            'documento_afiliado' => $afiliado['documento'] ?? $documento,
            'tipo_documento' => $afiliado['tipo_documento'] ?? null,
            'nombre_afiliado' => $afiliado['nombre_completo'] ?? trim(($afiliado['nombres'] ?? '') . ' ' . ($afiliado['apellidos'] ?? '')),
            'estado' => $estado,
            'hospital' => isset($afiliado['hospital']) && (string) $afiliado['hospital'] !== '' ? trim((string) $afiliado['hospital']) : null,
            'beneficiarios' => [],
        ];

        // Validar si ya existe una solicitud (pendiente o entregada) para este documento y el tipo de entrega activo
        $hoy = now(config('app.timezone', 'America/Bogota'))->toDateString();
        $tipoActivo = WellnessDeliveryType::getActivoParaFecha($hoy);
        if ($tipoActivo) {
            $solicitudExistente = WellnessDeliveryRequest::where('documento_afiliado', $documento)
                ->where('wellness_delivery_type_id', $tipoActivo->id)
                ->whereIn('estado', ['pendiente', 'entregado'])
                ->first();
            if ($solicitudExistente) {
                $estadoText = $solicitudExistente->estado === 'entregado' ? 'entregada' : 'pendiente';
                $data['solicitud_existente'] = true;
                $data['solicitud_estado'] = $solicitudExistente->estado;
                $data['solicitud_id'] = $solicitudExistente->id;
                $data['tipo_entrega_nombre'] = $solicitudExistente->tipo_entrega_text ?? $tipoActivo->nombre;
                $data['solicitud_existente_mensaje'] = "Ya existe una solicitud {$estadoText} para este documento y este tipo de entrega («{$data['tipo_entrega_nombre']}»). No se pueden crear solicitudes duplicadas.";
            } else {
                $data['solicitud_existente'] = false;
            }
        } else {
            $data['solicitud_existente'] = false;
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Authenticate and get kit bienestar information.
     * Validates by documento (CC) and fecha_expedicion (dd/mm/aa format).
     * Does NOT validate tipo_documento.
     */
    public function authenticate(Request $request): JsonResponse
    {
        // Variables para tracking (inicializadas para uso en catch blocks)
        $documento = null;
        $fechaExpedicion = null;
        $documentoHash = null;

        try {
            // Validate input
            $request->validate([
                'tipo_documento' => 'required|string|max:50', // Recibido pero no validado
                'documento' => 'required|string|max:50',
                'fecha_expedicion' => 'required|string|max:50',
            ]);

            $tipoDocumento = trim($request->input('tipo_documento'));
            $documento = trim($request->input('documento'));
            $fechaExpedicion = trim($request->input('fecha_expedicion'));

            // Log estructurado del intento de autenticación (documento completo para identificación)
            Log::info('KIT_BIENESTAR_AUTH: Intento de autenticación', [
                'event_type' => 'authentication_attempt',
                'status' => 'pending',
                'tipo_documento' => $tipoDocumento,
                'documento' => $documento, // Documento completo sin sanitizar
                'fecha_expedicion' => $fechaExpedicion,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
                'date' => now()->format('Y-m-d'),
                'hour' => now()->format('H:00:00'),
            ]);

            $hoy = now(config('app.timezone', 'America/Bogota'))->toDateString();
            $tipoActivo = WellnessDeliveryType::getActivoParaFecha($hoy);
            if (!$tipoActivo) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay una campaña de entregas activa en este momento.',
                    'data' => null,
                ], 422);
            }

            $requiereListado = ($tipoActivo->modo_acceso ?? 'listado') === 'listado';

            if ($requiereListado) {
                // Modo listado: obligatorio tener Excel y que la persona esté en el listado
                if (!$this->kitBienestarService->isFileAvailable()) {
                    Log::error('KIT_BIENESTAR_AUTH: Servicio no disponible (modo listado sin Excel)', [
                        'event_type' => 'service_unavailable',
                        'status' => 'error',
                        'documento' => $documento,
                        'ip_address' => $request->ip(),
                        'timestamp' => now()->toISOString(),
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Servicio temporalmente no disponible. Debe cargarse el listado de afiliados permitidos para esta campaña.',
                        'data' => null,
                    ], 503);
                }

                $kitBienestar = $this->kitBienestarService->authenticateAndGetKitBienestar(
                    $documento,
                    $fechaExpedicion
                );

                if (null === $kitBienestar) {
                    Log::warning('KIT_BIENESTAR_AUTH: Autenticación fallida - persona no encontrada en listado', [
                        'event_type' => 'authentication_failed',
                        'status' => 'failed',
                        'reason' => 'persona_no_encontrada',
                        'documento' => $documento,
                        'fecha_expedicion' => $fechaExpedicion,
                        'ip_address' => $request->ip(),
                        'timestamp' => now()->toISOString(),
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'No se encontró la información en el archivo. La persona no está en el listado de afiliados permitidos para esta campaña.',
                        'data' => null,
                    ], 404);
                }
            } else {
                // Modo abierto: cualquier afiliado puede reclamar; intentar pre-llenar desde Excel si existe
                $kitBienestar = null;
                if ($this->kitBienestarService->isFileAvailable()) {
                    $kitBienestar = $this->kitBienestarService->authenticateAndGetKitBienestar(
                        $documento,
                        $fechaExpedicion
                    );
                }
                if (null === $kitBienestar) {
                    // No está en Excel o no hay Excel: permitir igualmente con datos mínimos para que complete el formulario
                    $kitBienestar = [
                        'nombre' => '',
                        'hospital' => null,
                        'afiliado' => ['nombre' => '', 'hospital' => null],
                        'beneficiarios' => [],
                    ];
                }
            }

            // Preparar información del usuario para el log
            $userInfo = [
                'documento' => $documento, // Documento completo sin sanitizar
                'nombre_afiliado' => $kitBienestar['nombre'] ?? $kitBienestar['afiliado']['nombre'] ?? null,
                'hospital' => $kitBienestar['hospital'] ?? $kitBienestar['afiliado']['hospital'] ?? null,
            ];

            // Agregar información de beneficiarios
            if (isset($kitBienestar['beneficiario'])) {
                // Un solo beneficiario
                $userInfo['beneficiarios_count'] = 1;
                $userInfo['beneficiario'] = $kitBienestar['beneficiario'];
                $userInfo['parentesco'] = $kitBienestar['parentesco'] ?? null;
                $userInfo['edad'] = $kitBienestar['edad'] ?? null;
            } elseif (isset($kitBienestar['beneficiarios']) && is_array($kitBienestar['beneficiarios'])) {
                // Múltiples beneficiarios
                $userInfo['beneficiarios_count'] = count($kitBienestar['beneficiarios']);
                $userInfo['beneficiarios'] = array_map(function ($ben) {
                    return [
                        'nombre' => $ben['beneficiario'] ?? null,
                        'parentesco' => $ben['parentesco'] ?? null,
                        'edad' => $ben['edad'] ?? null,
                    ];
                }, $kitBienestar['beneficiarios']);
            }

            // Log estructurado de autenticación exitosa
            Log::info('KIT_BIENESTAR_AUTH: Autenticación exitosa', array_merge($userInfo, [
                'event_type' => 'authentication_success',
                'status' => 'success',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
                'date' => now()->format('Y-m-d'),
                'hour' => now()->format('H:00:00'),
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Autenticación exitosa',
                'data' => $kitBienestar,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Obtener datos del request
            $docInput = $request->input('documento');
            $fechaInput = $request->input('fecha_expedicion');

            // Log estructurado de error de validación
            Log::warning('KIT_BIENESTAR_AUTH: Error de validación', [
                'event_type' => 'validation_error',
                'status' => 'failed',
                'reason' => 'datos_invalidos',
                'errors' => $e->errors(),
                'tipo_documento' => $request->input('tipo_documento'),
                'documento' => $docInput ? trim($docInput) : null, // Documento completo sin sanitizar
                'fecha_expedicion' => $fechaInput ? trim($fechaInput) : null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
                'date' => now()->format('Y-m-d'),
                'hour' => now()->format('H:00:00'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
                'data' => null,
            ], 422);
        } catch (\Exception $e) {
            // Obtener datos del request
            $docInput = $request->input('documento');
            $fechaInput = $request->input('fecha_expedicion');

            // Log estructurado de error inesperado
            Log::error('KIT_BIENESTAR_AUTH: Error inesperado', [
                'event_type' => 'error',
                'status' => 'error',
                'reason' => 'error_interno',
                'tipo_documento' => $request->input('tipo_documento'),
                'documento' => $docInput ? trim($docInput) : null, // Documento completo sin sanitizar
                'fecha_expedicion' => $fechaInput ? trim($fechaInput) : null,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
                'date' => now()->format('Y-m-d'),
                'hour' => now()->format('H:00:00'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Store a new wellness delivery request (kit escolar, desayuno, lonchera, etc.)
     */
    public function store(StoreKitBienestarRequest $request): JsonResponse
    {
        try {
            $documentoAfiliado = trim($request->input('documento_afiliado'));
            $hoy = now(config('app.timezone', 'America/Bogota'))->toDateString();

            // Obtener el tipo de entrega activo para la fecha actual
            $tipoActivo = WellnessDeliveryType::getActivoParaFecha($hoy);
            if (!$tipoActivo) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay un tipo de entrega activo para la fecha actual. Contacte al administrador para que configure una campaña de entregas (tipo activo y rango de fechas).',
                ], 422);
            }

            // Validar que no exista una solicitud activa (pendiente o entregada) para el mismo documento y tipo de entrega
            $existingRequest = WellnessDeliveryRequest::where('documento_afiliado', $documentoAfiliado)
                ->where('wellness_delivery_type_id', $tipoActivo->id)
                ->whereIn('estado', ['pendiente', 'entregado'])
                ->first();

            if ($existingRequest) {
                $estadoText = $existingRequest->estado === 'pendiente' ? 'pendiente' : 'entregada';

                Log::warning('Intento de crear solicitud duplicada de kit de bienestar', LogSanitizationService::sanitize([
                    'documento_afiliado' => $documentoAfiliado,
                    'wellness_delivery_type_id' => $tipoActivo->id,
                    'existing_request_id' => $existingRequest->id,
                    'existing_estado' => $existingRequest->estado,
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                ]));

                return response()->json([
                    'success' => false,
                    'message' => "Ya existe una solicitud {$estadoText} para este documento y este tipo de entrega («{$tipoActivo->nombre}»). No se pueden crear solicitudes duplicadas.",
                    'data' => [
                        'existing_request_id' => $existingRequest->id,
                        'existing_estado' => $existingRequest->estado,
                    ],
                ], 409); // 409 Conflict
            }

            DB::beginTransaction();

            // En modo abierto, si un usuario interno registra la entrega (ruta autenticada), se crea directamente como "entregado" y se registra quién entregó
            $esRegistroInternoAbierto = ($tipoActivo->modo_acceso ?? 'listado') === 'abierto' && $request->user();

            $deliveryRequestData = [
                'wellness_delivery_type_id' => $tipoActivo->id,
                'documento_afiliado' => $documentoAfiliado,
                'nombre_afiliado' => trim($request->input('nombre_afiliado')),
                'hospital' => $request->filled('hospital') ? trim($request->input('hospital')) : null,
                'fecha_expedicion' => $request->filled('fecha_expedicion') ? trim($request->input('fecha_expedicion')) : null,
                'beneficiarios' => is_array($request->input('beneficiarios')) ? $request->input('beneficiarios') : [],
                'firma' => trim($request->input('firma')),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'estado' => $esRegistroInternoAbierto ? 'entregado' : 'pendiente',
            ];

            if ($esRegistroInternoAbierto) {
                $deliveryRequestData['entregado_por_user_id'] = $request->user()->id;
            }

            // Crear la solicitud
            $deliveryRequest = WellnessDeliveryRequest::create($deliveryRequestData);

            DB::commit();

            // Log exitoso (sin incluir la firma completa)
            Log::info('Nueva solicitud de entrega de bienestar creada', LogSanitizationService::sanitize([
                'delivery_request_id' => $deliveryRequest->id,
                'wellness_delivery_type_id' => $deliveryRequest->wellness_delivery_type_id,
                'documento_afiliado' => $deliveryRequest->documento_afiliado,
                'nombre_afiliado' => $deliveryRequest->nombre_afiliado,
                'beneficiarios_count' => count($deliveryRequest->beneficiarios ?? []),
                'ip_address' => $deliveryRequest->ip_address,
                'timestamp' => now()->toISOString(),
            ]));

            $data = [
                'id' => $deliveryRequest->id,
                'wellness_delivery_type_id' => $deliveryRequest->wellness_delivery_type_id,
                'tipo_entrega_text' => $deliveryRequest->tipo_entrega_text,
                'documento_afiliado' => $deliveryRequest->documento_afiliado,
                'nombre_afiliado' => $deliveryRequest->nombre_afiliado,
                'estado' => $deliveryRequest->estado,
                'created_at' => $deliveryRequest->created_at->toISOString(),
            ];

            // Pista para el frontend: en modo abierto, si no se detectó sesión, usar la ruta autenticada para que quede "entregado" con "entregado por"
            if ($deliveryRequest->estado === 'pendiente' && ($tipoActivo->modo_acceso ?? '') === 'abierto') {
                $data['_hint'] = 'Para que en modo abierto quede como entregado con "entregado por", usa POST /api/wellness-delivery-requests con la sesión del panel (misma cookie/token que el resto del panel).';
            }

            return response()->json([
                'success' => true,
                'message' => 'Solicitud registrada exitosamente',
                'data' => $data,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error al crear solicitud de entrega de bienestar', LogSanitizationService::sanitize([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'documento_afiliado' => $request->input('documento_afiliado'),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]));

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar la solicitud. Por favor, intenta nuevamente.',
            ], 500);
        }
    }

    /**
     * Registrar una entrega de bienestar en modo abierto (solo cuando el tipo activo tiene modo_acceso = abierto).
     * Requiere autenticación. Crea la solicitud directamente como "entregado" con el usuario actual como "entregado por".
     * Si el tipo activo no es abierto o no hay tipo activo, devuelve 422.
     */
    public function storeOpenMode(StoreKitBienestarRequest $request): JsonResponse
    {
        try {
            $documentoAfiliado = trim($request->input('documento_afiliado'));
            $hoy = now(config('app.timezone', 'America/Bogota'))->toDateString();

            $tipoActivo = WellnessDeliveryType::getActivoParaFecha($hoy);
            if (!$tipoActivo) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay un tipo de entrega activo para la fecha actual. No se puede registrar con este endpoint.',
                ], 422);
            }

            if (($tipoActivo->modo_acceso ?? 'listado') !== 'abierto') {
                return response()->json([
                    'success' => false,
                    'message' => 'Este endpoint es solo para tipos de entrega en modo abierto. El tipo activo actual («' . $tipoActivo->nombre . '») está en modo listado. Use el flujo o endpoint correspondiente al modo listado.',
                    'data' => [
                        'tipo_activo_id' => $tipoActivo->id,
                        'tipo_activo_nombre' => $tipoActivo->nombre,
                        'modo_acceso' => $tipoActivo->modo_acceso,
                    ],
                ], 422);
            }

            $existingRequest = WellnessDeliveryRequest::where('documento_afiliado', $documentoAfiliado)
                ->where('wellness_delivery_type_id', $tipoActivo->id)
                ->whereIn('estado', ['pendiente', 'entregado'])
                ->first();

            if ($existingRequest) {
                $estadoText = $existingRequest->estado === 'pendiente' ? 'pendiente' : 'entregada';
                Log::warning('Intento de crear solicitud duplicada (modo abierto)', LogSanitizationService::sanitize([
                    'documento_afiliado' => $documentoAfiliado,
                    'wellness_delivery_type_id' => $tipoActivo->id,
                    'existing_request_id' => $existingRequest->id,
                ]));

                return response()->json([
                    'success' => false,
                    'message' => "Ya existe una solicitud {$estadoText} para este documento y este tipo de entrega («{$tipoActivo->nombre}»). No se pueden crear solicitudes duplicadas.",
                    'data' => [
                        'existing_request_id' => $existingRequest->id,
                        'existing_estado' => $existingRequest->estado,
                    ],
                ], 409);
            }

            DB::beginTransaction();

            // En modo abierto la firma que envía el usuario es la de recibido (entrega en el acto), no la de solicitud
            $firmaRecibido = trim($request->input('firma'));

            $deliveryRequestData = [
                'wellness_delivery_type_id' => $tipoActivo->id,
                'documento_afiliado' => $documentoAfiliado,
                'nombre_afiliado' => trim($request->input('nombre_afiliado')),
                'hospital' => $request->filled('hospital') ? trim($request->input('hospital')) : null,
                'fecha_expedicion' => $request->filled('fecha_expedicion') ? trim($request->input('fecha_expedicion')) : null,
                'beneficiarios' => is_array($request->input('beneficiarios')) ? $request->input('beneficiarios') : [],
                'firma' => '', // En modo abierto no hay firma de solicitud; la firma va en firma_recibido
                'firma_recibido' => $firmaRecibido !== '' ? $firmaRecibido : null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'estado' => 'entregado',
                'cantidad_entregada' => 1,
                'entregado_por_user_id' => $request->user()->id,
            ];

            $deliveryRequest = WellnessDeliveryRequest::create($deliveryRequestData);

            DB::commit();

            Log::info('Nueva solicitud de entrega de bienestar creada (modo abierto)', LogSanitizationService::sanitize([
                'delivery_request_id' => $deliveryRequest->id,
                'wellness_delivery_type_id' => $deliveryRequest->wellness_delivery_type_id,
                'documento_afiliado' => $deliveryRequest->documento_afiliado,
                'entregado_por_user_id' => $deliveryRequest->entregado_por_user_id,
                'timestamp' => now()->toISOString(),
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Solicitud registrada y marcada como entregada.',
                'data' => [
                    'id' => $deliveryRequest->id,
                    'wellness_delivery_type_id' => $deliveryRequest->wellness_delivery_type_id,
                    'tipo_entrega_text' => $deliveryRequest->tipo_entrega_text,
                    'documento_afiliado' => $deliveryRequest->documento_afiliado,
                    'nombre_afiliado' => $deliveryRequest->nombre_afiliado,
                    'estado' => $deliveryRequest->estado,
                    'entregado_por_user_id' => $deliveryRequest->entregado_por_user_id,
                    'created_at' => $deliveryRequest->created_at->toISOString(),
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al crear solicitud de entrega (modo abierto)', LogSanitizationService::sanitize([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'documento_afiliado' => $request->input('documento_afiliado'),
                'ip_address' => $request->ip(),
            ]));

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar la solicitud. Por favor, intenta nuevamente.',
            ], 500);
        }
    }

    /**
     * Obtener el tipo de entrega activo para la fecha actual (público, para el formulario).
     * Si no hay tipo activo, devuelve success: false para que el front no muestre el formulario de solicitud.
     */
    public function currentType(Request $request): JsonResponse
    {
        $hoy = now(config('app.timezone', 'America/Bogota'))->toDateString();
        $tipo = WellnessDeliveryType::getActivoParaFecha($hoy);
        if (!$tipo) {
            return response()->json([
                'success' => false,
                'message' => 'No hay una campaña de entregas activa en este momento.',
                'data' => null,
            ], 200);
        }
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $tipo->id,
                'nombre' => $tipo->nombre,
                'modo_acceso' => $tipo->modo_acceso,
                'fecha_desde' => $tipo->fecha_desde->format('Y-m-d'),
                'fecha_hasta' => $tipo->fecha_hasta->format('Y-m-d'),
            ],
        ]);
    }

    /**
     * List wellness delivery requests (with filters)
     * Requires authentication and permission
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = WellnessDeliveryRequest::with(['entregadoPor', 'tipoEntrega']);

            // Filtros
            if ($request->has('tipo_entrega')) {
                $query->porTipoEntrega($request->input('tipo_entrega'));
            }

            if ($request->has('estado')) {
                $query->porEstado($request->input('estado'));
            }

            if ($request->has('documento')) {
                $query->porDocumento($request->input('documento'));
            }

            // Filtro por rango de fechas (fecha de creación)
            if ($request->has('fecha_desde') && $request->filled('fecha_desde')) {
                try {
                    $startDate = Carbon::parse($request->input('fecha_desde'))->startOfDay();
                    $query->where('created_at', '>=', $startDate);
                } catch (\Exception $e) {
                    // Ignorar fecha inválida
                }
            }

            if ($request->has('fecha_hasta') && $request->filled('fecha_hasta')) {
                try {
                    $endDate = Carbon::parse($request->input('fecha_hasta'))->endOfDay();
                    $query->where('created_at', '<=', $endDate);
                } catch (\Exception $e) {
                    // Ignorar fecha inválida
                }
            }

            // Ordenamiento
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Paginación
            $perPage = min($request->input('per_page', 15), 100); // Máximo 100 por página
            $deliveryRequests = $query->paginate($perPage);

            // Formatear datos para incluir información del usuario
            $formattedData = $deliveryRequests->getCollection()->map(function ($deliveryRequest) {
                $beneficiarios = $deliveryRequest->beneficiarios;

                return [
                    'id' => $deliveryRequest->id,
                    'wellness_delivery_type_id' => $deliveryRequest->wellness_delivery_type_id,
                    'tipo_entrega_text' => $deliveryRequest->tipo_entrega_text,
                    'documento_afiliado' => $deliveryRequest->documento_afiliado,
                    'nombre_afiliado' => $deliveryRequest->nombre_afiliado,
                    'hospital' => $deliveryRequest->hospital,
                    'estado' => $deliveryRequest->estado,
                    'beneficiarios_count' => is_array($beneficiarios) ? count($beneficiarios) : 0,
                    'cantidad_entregada' => $deliveryRequest->cantidad_entregada,
                    'entregado_por_user_id' => $deliveryRequest->entregado_por_user_id,
                    'entregado_por' => $deliveryRequest->entregadoPor ? [
                        'id' => $deliveryRequest->entregadoPor->id,
                        'name' => $deliveryRequest->entregadoPor->name,
                        'email' => $deliveryRequest->entregadoPor->email,
                    ] : null,
                    'created_at' => $deliveryRequest->created_at->toISOString(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedData->all(),
                'pagination' => [
                    'current_page' => $deliveryRequests->currentPage(),
                    'last_page' => $deliveryRequests->lastPage(),
                    'per_page' => $deliveryRequests->perPage(),
                    'total' => $deliveryRequests->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error al listar solicitudes de entrega de bienestar', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las solicitudes',
            ], 500);
        }
    }

    /**
     * Show a specific wellness delivery request
     * Requires authentication and permission
     */
    public function show(string $id): JsonResponse
    {
        try {
            $deliveryRequest = WellnessDeliveryRequest::with(['entregadoPor', 'tipoEntrega'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $deliveryRequest->id,
                    'wellness_delivery_type_id' => $deliveryRequest->wellness_delivery_type_id,
                    'tipo_entrega_text' => $deliveryRequest->tipo_entrega_text,
                    'documento_afiliado' => $deliveryRequest->documento_afiliado,
                    'nombre_afiliado' => $deliveryRequest->nombre_afiliado,
                    'hospital' => $deliveryRequest->hospital,
                    'fecha_expedicion' => $deliveryRequest->fecha_expedicion,
                    'beneficiarios' => $deliveryRequest->beneficiarios,
                    'beneficiarios_count' => is_array($deliveryRequest->beneficiarios) ? count($deliveryRequest->beneficiarios) : 0,
                    'beneficiarios_nombres' => $deliveryRequest->beneficiarios_nombres,
                    'firma' => $deliveryRequest->firma,
                    'firma_recibido' => $deliveryRequest->firma_recibido,
                    'estado' => $deliveryRequest->estado,
                    'estado_text' => $deliveryRequest->estado_text,
                    'cantidad_entregada' => $deliveryRequest->cantidad_entregada,
                    'observaciones' => $deliveryRequest->observaciones,
                    'entregado_por_user_id' => $deliveryRequest->entregado_por_user_id,
                    'entregado_por' => $deliveryRequest->entregadoPor ? [
                        'id' => $deliveryRequest->entregadoPor->id,
                        'name' => $deliveryRequest->entregadoPor->name,
                        'email' => $deliveryRequest->entregadoPor->email,
                    ] : null,
                    'ip_address' => $deliveryRequest->ip_address,
                    'user_agent' => $deliveryRequest->user_agent,
                    'created_at' => $deliveryRequest->created_at->toISOString(),
                    'updated_at' => $deliveryRequest->updated_at->toISOString(),
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Solicitud no encontrada',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error al obtener solicitud de entrega de bienestar', [
                'error' => $e->getMessage(),
                'id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la solicitud',
            ], 500);
        }
    }

    /**
     * Update the status of a wellness delivery request
     * Requires authentication and permission
     *
     * When status is "entregado", requires firma_recibido
     * When status is "cancelado", firma_recibido is not required
     */
    public function updateStatus(string $id, UpdateWellnessDeliveryRequestStatusRequest $request): JsonResponse
    {
        try {
            $deliveryRequest = WellnessDeliveryRequest::findOrFail($id);
            $estado = $request->input('estado');
            $firmaRecibido = $request->input('firma_recibido');
            $cantidadEntregada = $request->input('cantidad_entregada');
            $observaciones = $request->input('observaciones');
            $user = $request->user();

            DB::beginTransaction();

            // Preparar datos para actualizar
            $updateData = [
                'estado' => $estado,
                'observaciones' => $observaciones ? trim($observaciones) : null,
            ];

            // Si el estado es "entregado" o "cancelado", guardar el usuario que realizó la acción
            if (in_array($estado, ['entregado', 'cancelado']) && $user) {
                $updateData['entregado_por_user_id'] = $user->id;
            }

            // Si el estado es "entregado", guardar la firma de recibido y cantidad entregada (por defecto 1)
            if ($estado === 'entregado') {
                if ($firmaRecibido) {
                    $updateData['firma_recibido'] = trim($firmaRecibido);
                }
                $updateData['cantidad_entregada'] = $cantidadEntregada !== null && $cantidadEntregada !== '' ? (int) $cantidadEntregada : 1;
            } else {
                // Si el estado cambia a otro que no sea "entregado", limpiar cantidad_entregada
                $updateData['cantidad_entregada'] = null;
            }

            // Si el estado cambia a "cancelado", no se requiere firma
            // La firma_recibido se mantiene si ya existía, o se deja null

            $deliveryRequest->update($updateData);

            DB::commit();

            // Log exitoso
            Log::info('Estado de solicitud de entrega de bienestar actualizado', [
                'delivery_request_id' => $deliveryRequest->id,
                'estado_anterior' => $deliveryRequest->getOriginal('estado'),
                'estado_nuevo' => $estado,
                'tiene_firma_recibido' => !empty($firmaRecibido),
                'cantidad_entregada' => $deliveryRequest->cantidad_entregada,
                'updated_by' => $user?->id ?? 'system',
                'entregado_por_user_id' => $deliveryRequest->entregado_por_user_id,
            ]);

            // Cargar la relación del usuario si existe
            $deliveryRequest->load('entregadoPor');

            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado exitosamente',
                'data' => [
                    'id' => $deliveryRequest->id,
                    'estado' => $deliveryRequest->estado,
                    'estado_text' => $deliveryRequest->estado_text,
                    'cantidad_entregada' => $deliveryRequest->cantidad_entregada,
                    'observaciones' => $deliveryRequest->observaciones,
                    'tiene_firma_recibido' => !empty($deliveryRequest->firma_recibido),
                    'entregado_por_user_id' => $deliveryRequest->entregado_por_user_id,
                    'entregado_por' => $deliveryRequest->entregadoPor ? [
                        'id' => $deliveryRequest->entregadoPor->id,
                        'name' => $deliveryRequest->entregadoPor->name,
                        'email' => $deliveryRequest->entregadoPor->email,
                    ] : null,
                    'updated_at' => $deliveryRequest->updated_at->toISOString(),
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Solicitud no encontrada',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error al actualizar estado de solicitud de entrega de bienestar', [
                'error' => $e->getMessage(),
                'id' => $id,
                'estado' => $request->input('estado'),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el estado de la solicitud',
            ], 500);
        }
    }

    /**
     * Export wellness delivery requests to Excel file.
     * If signatures are included, the report is generated asynchronously.
     */
    public function exportExcel(ExportWellnessDeliveryExcelRequest $request): BinaryFileResponse|SymfonyBinaryFileResponse|JsonResponse
    {
        try {
            $user = $request->user();

            // Preparar filtros
            $filters = [
                'tipo_entrega' => $request->input('tipo_entrega'),
                'estado' => $request->input('estado'),
                'fecha_desde' => $request->input('fecha_desde'),
                'fecha_hasta' => $request->input('fecha_hasta'),
            ];

            $includeFirmas = $request->input('include_firmas', false);
            $options = [
                'include_firmas' => $includeFirmas,
            ];

            // If signatures are included, generate report asynchronously
            if ($includeFirmas) {
                $jobId = Str::uuid()->toString();

                // Store initial status in cache
                cache()->put(
                    "wellness_delivery_report:{$jobId}",
                    [
                        'status' => 'processing',
                        'created_at' => now()->toIso8601String(),
                    ],
                    now()->addHours(24)
                );

                // Generate report asynchronously after response (no workers needed)
                $excelExportService = $this->excelExportService;
                dispatch(function () use ($excelExportService, $filters, $options, $jobId, $user) {
                    try {
                        Log::info('Iniciando generación asíncrona de reporte de entregas de bienestar', [
                            'job_id' => $jobId,
                            'include_firmas' => true,
                            'user_id' => $user?->id,
                        ]);

                        // Generate report
                        $filePath = $excelExportService->generateReport($filters, $options);

                        if (!file_exists($filePath)) {
                            throw new \Exception('El archivo del reporte no fue creado');
                        }

                        // Generate file name
                        $fileName = 'Reporte_Entregas_Bienestar_ProSalud_' . now()->setTimezone('America/Bogota')->format('Y-m-d_His') . '.xlsx';

                        // Store file in storage for later download
                        $storagePath = 'reports/wellness-delivery/' . $jobId . '/' . $fileName;
                        $disk = Storage::disk('local');
                        $disk->put($storagePath, file_get_contents($filePath));

                        // Clean up temporary file
                        @unlink($filePath);

                        // Store metadata in cache for retrieval
                        cache()->put(
                            "wellness_delivery_report:{$jobId}",
                            [
                                'status' => 'completed',
                                'file_path' => $storagePath,
                                'file_name' => $fileName,
                                'created_at' => now()->toIso8601String(),
                            ],
                            now()->addHours(24) // Keep for 24 hours
                        );

                        Log::info('Reporte de entregas de bienestar generado exitosamente', [
                            'job_id' => $jobId,
                            'file_path' => $storagePath,
                            'file_name' => $fileName,
                            'user_id' => $user?->id,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('Error generando reporte de entregas de bienestar (background)', [
                            'job_id' => $jobId,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                            'user_id' => $user?->id,
                        ]);

                        // Store error in cache
                        cache()->put(
                            "wellness_delivery_report:{$jobId}",
                            [
                                'status' => 'failed',
                                'error' => $e->getMessage(),
                                'created_at' => now()->toIso8601String(),
                            ],
                            now()->addHours(24)
                        );
                    }
                })->afterResponse();

                Log::info('Reporte de entregas de bienestar encolado para generación asíncrona', [
                    'job_id' => $jobId,
                    'include_firmas' => true,
                    'user_id' => $user?->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'El reporte se está generando. Use el job_id para verificar el estado.',
                    'job_id' => $jobId,
                    'status' => 'processing',
                    'check_status_url' => url("/api/wellness-delivery-requests/export/status/{$jobId}"),
                ], 202);
            }

            // Generate report synchronously (without signatures)
            $filePath = $this->excelExportService->generateReport($filters, $options);

            if (!file_exists($filePath)) {
                Log::error('Error generando reporte Excel de entregas de bienestar: archivo no creado', [
                    'user_id' => $user?->id,
                    'filters' => $filters,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar el reporte',
                ], 500);
            }

            // Nombre del archivo
            $fileName = 'Reporte_Entregas_Bienestar_ProSalud_' . now()->setTimezone('America/Bogota')->format('Y-m-d_His') . '.xlsx';

            Log::info('Reporte Excel de entregas de bienestar generado', [
                'user_id' => $user?->id,
                'user_email' => $user?->email,
                'filters' => $filters,
                'file_name' => $fileName,
            ]);

            return response()->download($filePath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Error de validación al generar reporte Excel de entregas de bienestar', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error generando reporte Excel de entregas de bienestar', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte. Por favor, intente nuevamente.',
            ], 500);
        }
    }

    /**
     * Check the status of an async report generation job.
     */
    public function checkStatus(string $jobId): JsonResponse
    {
        $cacheKey = "wellness_delivery_report:{$jobId}";
        $status = cache()->get($cacheKey);

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'Job no encontrado o expirado',
            ], 404);
        }

        $response = [
            'success' => true,
            'job_id' => $jobId,
            'status' => $status['status'],
        ];

        if ($status['status'] === 'completed') {
            $response['download_url'] = url("/api/wellness-delivery-requests/export/download/{$jobId}");
            $response['file_name'] = $status['file_name'] ?? null;
            $response['created_at'] = $status['created_at'] ?? null;
        } elseif ($status['status'] === 'failed') {
            $response['error'] = $status['error'] ?? 'Error desconocido';
        }

        return response()->json($response);
    }

    /**
     * Download a completed report.
     */
    public function downloadReport(string $jobId): StreamedResponse|JsonResponse
    {
        $cacheKey = "wellness_delivery_report:{$jobId}";
        $status = cache()->get($cacheKey);

        if (!$status) {
            return response()->json([
                'success' => false,
                'message' => 'Job no encontrado o expirado',
            ], 404);
        }

        if ($status['status'] !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'El reporte aún no está listo. Estado: ' . ($status['status'] ?? 'unknown'),
                'status' => $status['status'],
            ], 400);
        }

        $filePath = $status['file_path'] ?? null;
        $fileName = $status['file_name'] ?? 'Reporte_Entregas_Bienestar_ProSalud.xlsx';

        if (!$filePath) {
            return response()->json([
                'success' => false,
                'message' => 'Ruta del archivo no encontrada',
            ], 404);
        }

        $disk = Storage::disk('local');

        if (!$disk->exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo no existe en el almacenamiento',
            ], 404);
        }

        try {
            $fileContent = $disk->get($filePath);

            Log::info('Reporte de entregas de bienestar descargado', [
                'job_id' => $jobId,
                'file_name' => $fileName,
            ]);

            return response()->streamDownload(function () use ($fileContent) {
                echo $fileContent;
            }, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (\Exception $e) {
            Log::error('Error descargando reporte de entregas de bienestar', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al descargar el reporte',
            ], 500);
        }
    }

    /**
     * Upload/Update the kit bienestar Excel file to S3
     * Requires authentication and permission
     */
    public function uploadFile(UploadKitBienestarFileRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $file = $request->file('file');

            if (!$file || !$file->isValid()) {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo no es válido',
                ], 400);
            }

            DB::beginTransaction();

            // Configurar disco S3
            $disk = 'prosalud-private';
            $fallbackDisk = 'local';

            // Generar nombre único para el archivo con timestamp
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $fileName = 'INFORMACION_PARA_KIT_ESCOLARES_' . now()->format('Y-m-d_His') . '.' . $extension;
            $s3Path = 'kit-bienestar/' . $fileName;

            // Intentar subir a S3
            $storedPath = Storage::disk($disk)->putFileAs(
                'kit-bienestar',
                $file,
                $fileName
            );

            // Si S3 falla, usar disco local como fallback
            if (false === $storedPath) {
                Log::warning('S3 upload failed, trying local disk', [
                    's3_disk' => $disk,
                    'fallback_disk' => $fallbackDisk,
                ]);

                $storedPath = Storage::disk($fallbackDisk)->putFileAs(
                    'kit-bienestar',
                    $file,
                    $fileName
                );
                $disk = $fallbackDisk;
            }

            if (false === $storedPath) {
                DB::rollBack();
                Log::error('Error al subir archivo de kit bienestar', [
                    'user_id' => $user->id,
                    'file_name' => $originalName,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al subir el archivo. Por favor, intente nuevamente.',
                ], 500);
            }

            // Desactivar todas las versiones anteriores
            KitBienestarFileVersion::where('is_active', true)->update(['is_active' => false]);

            // Crear registro de versión
            $fileVersion = KitBienestarFileVersion::create([
                'file_name' => $originalName,
                's3_path' => $storedPath,
                'is_active' => true,
                'uploaded_by_user_id' => $user->id,
            ]);

            // Limpiar caché de autenticaciones para forzar recarga con nuevo archivo
            Cache::tags(['kit_bienestar'])->flush();

            DB::commit();

            Log::info('Archivo de kit bienestar actualizado exitosamente', [
                'file_version_id' => $fileVersion->id,
                'file_name' => $originalName,
                's3_path' => $storedPath,
                'disk' => $disk,
                'uploaded_by_user_id' => $user->id,
                'user_email' => $user->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Archivo actualizado exitosamente',
                'data' => [
                    'id' => $fileVersion->id,
                    'file_name' => $fileVersion->file_name,
                    's3_path' => $fileVersion->s3_path,
                    'is_active' => $fileVersion->is_active,
                    'uploaded_by' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'created_at' => $fileVersion->created_at->toISOString(),
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error al subir archivo de kit bienestar', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo. Por favor, intente nuevamente.',
            ], 500);
        }
    }

    /**
     * Get list of file versions
     * Requires authentication and permission
     */
    public function getFileVersions(Request $request): JsonResponse
    {
        try {
            $versions = KitBienestarFileVersion::with('uploadedBy')
                ->orderBy('created_at', 'desc')
                ->get();

            $formattedVersions = $versions->map(function ($version) {
                return [
                    'id' => $version->id,
                    'file_name' => $version->file_name,
                    's3_path' => $version->s3_path,
                    'is_active' => $version->is_active,
                    'uploaded_by' => $version->uploadedBy ? [
                        'id' => $version->uploadedBy->id,
                        'name' => $version->uploadedBy->name,
                        'email' => $version->uploadedBy->email,
                    ] : null,
                    'created_at' => $version->created_at->toISOString(),
                    'updated_at' => $version->updated_at->toISOString(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedVersions->all(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener versiones de archivo de kit bienestar', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las versiones del archivo',
            ], 500);
        }
    }
}

