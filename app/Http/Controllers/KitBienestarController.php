<?php

namespace App\Http\Controllers;

use App\Http\Requests\{ExportWellnessDeliveryExcelRequest, StoreKitBienestarRequest, UpdateWellnessDeliveryRequestStatusRequest};
use App\Models\WellnessDeliveryRequest;
use App\Services\{KitBienestarService, LogSanitizationService, WellnessDeliveryExcelExportService};
use Illuminate\Http\{BinaryFileResponse, JsonResponse, Request};
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\{DB, Log, Storage};
use Illuminate\Support\Str;

class KitBienestarController extends Controller
{
    private KitBienestarService $kitBienestarService;
    private WellnessDeliveryExcelExportService $excelExportService;

    public function __construct(
        KitBienestarService $kitBienestarService,
        WellnessDeliveryExcelExportService $excelExportService
    ) {
        $this->kitBienestarService = $kitBienestarService;
        $this->excelExportService = $excelExportService;
    }

    /**
     * Authenticate and get kit bienestar information.
     * Validates by documento (CC) and fecha_expedicion (dd/mm/aa format).
     * Does NOT validate tipo_documento.
     */
    public function authenticate(Request $request): JsonResponse
    {
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

            // Log the authentication attempt (sanitized)
            Log::info('Intento de autenticación de kit de bienestar', LogSanitizationService::sanitize([
                'tipo_documento' => $tipoDocumento,
                'documento' => $documento,
                'fecha_expedicion' => $fechaExpedicion,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]));

            // Check if the file is available
            if (!$this->kitBienestarService->isFileAvailable()) {
                Log::error('Archivo de kits escolares no disponible');

                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                    'data' => null,
                ], 503);
            }

            // Authenticate and get kit bienestar information
            // Note: tipo_documento is received but NOT validated
            $kitBienestar = $this->kitBienestarService->authenticateAndGetKitBienestar(
                $documento,
                $fechaExpedicion
            );

            if (null === $kitBienestar) {
                Log::warning('Autenticación fallida - persona no encontrada en archivo de kits escolares', LogSanitizationService::sanitize([
                    'documento' => $documento,
                    'fecha_expedicion' => $fechaExpedicion,
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                ]));

                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró la información en el archivo. La persona no cumple con los requisitos para solicitar el beneficio de ProSalud.',
                    'data' => null,
                ], 404);
            }

            // Log successful authentication (sanitized)
            Log::info('Autenticación exitosa de kit de bienestar', LogSanitizationService::sanitize([
                'documento' => $documento,
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Autenticación exitosa',
                'data' => $kitBienestar,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validación fallida en autenticación de kit de bienestar', LogSanitizationService::sanitize([
                'errors' => $e->errors(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]));

            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
                'data' => null,
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error inesperado en autenticación de kit de bienestar', LogSanitizationService::sanitize([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tipo_documento' => $request->input('tipo_documento'),
                'documento' => $request->input('documento'),
                'fecha_expedicion' => $request->input('fecha_expedicion'),
                'timestamp' => now()->toISOString(),
            ]));

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
            DB::beginTransaction();

            // Preparar datos para guardar
            $deliveryRequestData = [
                'tipo_entrega' => $request->input('tipo_entrega'),
                'documento_afiliado' => trim($request->input('documento_afiliado')),
                'nombre_afiliado' => trim($request->input('nombre_afiliado')),
                'hospital' => $request->filled('hospital') ? trim($request->input('hospital')) : null,
                'fecha_expedicion' => trim($request->input('fecha_expedicion')),
                'beneficiarios' => $request->input('beneficiarios'),
                'firma' => trim($request->input('firma')),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'estado' => 'pendiente',
            ];

            // Crear la solicitud
            $deliveryRequest = WellnessDeliveryRequest::create($deliveryRequestData);

            DB::commit();

            // Log exitoso (sin incluir la firma completa)
            Log::info('Nueva solicitud de entrega de bienestar creada', LogSanitizationService::sanitize([
                'delivery_request_id' => $deliveryRequest->id,
                'tipo_entrega' => $deliveryRequest->tipo_entrega,
                'documento_afiliado' => $deliveryRequest->documento_afiliado,
                'nombre_afiliado' => $deliveryRequest->nombre_afiliado,
                'beneficiarios_count' => count($deliveryRequest->beneficiarios ?? []),
                'ip_address' => $deliveryRequest->ip_address,
                'timestamp' => now()->toISOString(),
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Solicitud registrada exitosamente',
                'data' => [
                    'id' => $deliveryRequest->id,
                    'tipo_entrega' => $deliveryRequest->tipo_entrega,
                    'documento_afiliado' => $deliveryRequest->documento_afiliado,
                    'nombre_afiliado' => $deliveryRequest->nombre_afiliado,
                    'estado' => $deliveryRequest->estado,
                    'created_at' => $deliveryRequest->created_at->toISOString(),
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error al crear solicitud de entrega de bienestar', LogSanitizationService::sanitize([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tipo_entrega' => $request->input('tipo_entrega'),
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
     * List wellness delivery requests (with filters)
     * Requires authentication and permission
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = WellnessDeliveryRequest::with('entregadoPor');

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

            // Ordenamiento
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Paginación
            $perPage = min($request->input('per_page', 15), 100); // Máximo 100 por página
            $deliveryRequests = $query->paginate($perPage);

            // Formatear datos para incluir información del usuario
            $formattedData = $deliveryRequests->getCollection()->map(function ($deliveryRequest) {
                return [
                    'id' => $deliveryRequest->id,
                    'tipo_entrega' => $deliveryRequest->tipo_entrega,
                    'documento_afiliado' => $deliveryRequest->documento_afiliado,
                    'nombre_afiliado' => $deliveryRequest->nombre_afiliado,
                    'estado' => $deliveryRequest->estado,
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
            $deliveryRequest = WellnessDeliveryRequest::with('entregadoPor')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $deliveryRequest->id,
                    'tipo_entrega' => $deliveryRequest->tipo_entrega,
                    'tipo_entrega_text' => $deliveryRequest->tipo_entrega_text,
                    'documento_afiliado' => $deliveryRequest->documento_afiliado,
                    'nombre_afiliado' => $deliveryRequest->nombre_afiliado,
                    'hospital' => $deliveryRequest->hospital,
                    'fecha_expedicion' => $deliveryRequest->fecha_expedicion,
                    'beneficiarios' => $deliveryRequest->beneficiarios,
                    'beneficiarios_nombres' => $deliveryRequest->beneficiarios_nombres,
                    'firma' => $deliveryRequest->firma,
                    'firma_recibido' => $deliveryRequest->firma_recibido,
                    'estado' => $deliveryRequest->estado,
                    'estado_text' => $deliveryRequest->estado_text,
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

            // Si el estado es "entregado", guardar la firma de recibido
            if ($estado === 'entregado' && $firmaRecibido) {
                $updateData['firma_recibido'] = trim($firmaRecibido);
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
    public function exportExcel(ExportWellnessDeliveryExcelRequest $request): BinaryFileResponse|JsonResponse
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
}

