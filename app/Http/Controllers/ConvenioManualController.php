<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteConvenioBulkRequest;
use App\Http\Requests\ExportConvenioHistoryExcelRequest;
use App\Http\Requests\InvalidateConvenioRequest;
use App\Http\Requests\PresidentSignBulkPreviewRequest;
use App\Http\Requests\PresidentSignBulkRequest;
use App\Http\Requests\PresidentSignCampaignRequest;
use App\Http\Requests\RetryFailedConvenioEmailsRequest;
use App\Http\Requests\ReviewErrorConvenioBulkRequest;
use App\Http\Requests\UploadConvenioPdfZipRequest;
use App\Jobs\GenerateConvenioJob;
use App\Jobs\ProcessConvenioPdfZipJob;
use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ConvenioEmailTracking;
use App\Models\ConvenioPresidentSignBatch;
use App\Services\ConvenioDuplicateDetectionService;
use App\Services\ConvenioExcelTemplateExportService;
use App\Services\ConvenioFailedEmailRetryService;
use App\Services\ConvenioGenerationService;
use App\Services\ConvenioHistoryExcelExportService;
use App\Services\ConvenioInvalidationService;
use App\Services\ConvenioPdfStorageService;
use App\Services\ConvenioPdfZipImportService;
use App\Services\ConvenioPresidentSignReviewService;
use App\Services\ConvenioPresidentSignService;
use App\Support\ConvenioAutoSign;
use App\Support\ConvenioDataLabels;
use App\Support\ConvenioDelivery;
use App\Support\ConvenioDisplayFilename;
use App\Support\ConvenioHistoryUi;
use App\Support\ConvenioSemesterPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ConvenioManualController extends Controller
{
    public function __construct(
        private readonly ConvenioGenerationService $convenioGenerationService,
        private readonly ConvenioExcelTemplateExportService $templateExportService,
        private readonly ConvenioPdfZipImportService $pdfZipImportService,
    ) {}

    /**
     * @deprecated Use import-bulk with send_email=true or resend-emails from history.
     */
    public function sendBulkEmails(Request $request): JsonResponse
    {
        Log::warning('[CONVENIO API] Endpoint deprecado send-bulk-emails invocado', [
            'user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'El envío masivo desde PDFs preexistentes fue reemplazado. Use importación de ZIP de PDFs, importación masiva Excel, o reenvíe desde el historial.',
            'deprecated' => true,
            'delivery_mode' => config('convenios.delivery_mode'),
            'alternatives' => [
                'import_pdf_zip' => [
                    'endpoint' => '/api/convenios-manual/import-pdf-zip',
                    'description' => 'Suba un ZIP con PDFs nombrados SEDE - NOMBRE - DOCUMENTO.pdf para almacenar y enviar.',
                ],
                'import_bulk' => [
                    'endpoint' => '/api/convenios-manual/import-bulk',
                    'description' => 'Suba un Excel para generar PDFs y opcionalmente enviar correos al procesar.',
                ],
                'history_resend' => [
                    'endpoint' => '/api/convenios-manual/resend-emails',
                    'description' => 'Seleccione uno o más registros del historial y reenvíe con tracking_ids.',
                ],
                'history' => [
                    'endpoint' => '/api/convenios-manual/email-history',
                    'description' => 'Consulte el historial, filtre por estado y use acciones masivas.',
                ],
                'cli_legacy' => [
                    'command' => 'convenios:send-manual-emails',
                    'description' => 'Solo operaciones de TI con PDFs en resources/convenios/.',
                ],
            ],
        ], 410);
    }

    /**
     * List email tracking history with filters.
     */
    public function listEmailHistory(Request $request): JsonResponse
    {
        $digitalSigningEnabled = (bool) config('convenio_signing.enabled', true);

        $validator = Validator::make($request->all(), [
            'q' => 'nullable|string|max:200',
            'documento' => 'nullable|string|max:50',
            'estado' => 'nullable|string|in:pendiente,enviado,fallido,verificacion',
            'signing_estado' => 'nullable|string|in:pendiente_firma,firmado_afiliado,firmando_presidente,pendiente_revision,error_firma_presidente,completado,rechazado',
            'estado_filtro' => 'nullable|string|in:todos,pendiente,enviado,fallido,verificacion,test,firma_pendiente_firma,firma_firmado_afiliado,firma_pendiente_revision,firma_completado,firma_error_presidente,firma_rechazado',
            'is_test' => 'nullable|boolean',
            'sede' => 'nullable|string|max:255',
            'nombre_convenio' => 'nullable|string|max:255',
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date',
            'periodo' => ['nullable', 'string', 'regex:/^(todos|\d{4}[12])$/'],
            'calificacion' => 'nullable|string|in:1,2,3,4,5,sin_calificar',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = ConvenioEmailTracking::query()
            ->forHistoryList()
            ->with(['parentTracking:id,pdf_original_path'])
            ->applyHistoryFilters($request->all(), $digitalSigningEnabled)
            ->orderByDesc('created_at');

        // Paginate
        $perPage = $request->input('per_page', 15);
        $trackings = $query->paginate($perPage);

        $trackings->through(function (ConvenioEmailTracking $tracking) use ($digitalSigningEnabled): ConvenioEmailTracking {
            if (! $digitalSigningEnabled) {
                $tracking->signing_estado = null;
            }

            $tracking->setAttribute(
                'available_actions',
                $tracking->resolveAvailableActions($digitalSigningEnabled),
            );

            $tracking->setAttribute(
                'error_message',
                $tracking->resolveVisibleErrorMessage(),
            );

            if ($digitalSigningEnabled) {
                $tracking->setAttribute(
                    'integrity_badge_label',
                    $tracking->resolveIntegrityBadgeLabel(),
                );
            }

            $tracking->setAttribute(
                'download_filename',
                $tracking->resolveDownloadFilename(),
            );

            return $tracking;
        });

        $autoSignEnabled = ConvenioAutoSign::enabled();

        return response()->json([
            'success' => true,
            'delivery_mode' => config('convenios.delivery_mode'),
            'digital_signing_enabled' => $digitalSigningEnabled,
            'auto_sign_enabled' => $autoSignEnabled,
            'president_sign_bulk_enabled' => ConvenioAutoSign::bulkEnabled(),
            'president_sign_require_review' => ConvenioAutoSign::requireReview(),
            'ui' => ConvenioHistoryUi::metadata($digitalSigningEnabled, $autoSignEnabled),
            'filter_options' => ConvenioSemesterPeriod::filterOptions(),
            'data' => $trackings,
        ])->header('Cache-Control', 'private, no-store, no-cache, must-revalidate');
    }

    /**
     * Detalle de un registro del historial, incluyendo los datos usados para generar el convenio.
     */
    public function showTracking(Request $request, ConvenioEmailTracking $tracking): JsonResponse
    {
        $digitalSigningEnabled = (bool) config('convenio_signing.enabled', true);

        if (! $digitalSigningEnabled) {
            $tracking->signing_estado = null;
        }

        $convenioData = $tracking->convenio_data;
        if (($convenioData === null || $convenioData === []) && $tracking->parent_tracking_id) {
            $tracking->loadMissing('parentTracking');
            $convenioData = $tracking->parentTracking?->convenio_data;
        }

        $tracking->loadMissing('generatedBy:id,name,email');
        $tracking->setAttribute('error_message', $tracking->resolveVisibleErrorMessage());

        return response()->json([
            'success' => true,
            'delivery_mode' => config('convenios.delivery_mode'),
            'digital_signing_enabled' => $digitalSigningEnabled,
            'auto_sign_enabled' => ConvenioAutoSign::enabled(),
            'data' => [
                'tracking' => array_merge($tracking->makeHidden([
                    'signing_audit_log',
                    'signed_user_agent',
                ])->toArray(), [
                    'available_actions' => $tracking->resolveAvailableActions($digitalSigningEnabled, true),
                    'download_filename' => $tracking->resolveDownloadFilename(),
                    'integrity_badge_label' => $digitalSigningEnabled
                        ? $tracking->resolveIntegrityBadgeLabel()
                        : null,
                ]),
                'integrity' => $digitalSigningEnabled ? $tracking->resolveIntegrityPayload() : null,
                'convenio_data' => $convenioData,
                'convenio_data_fields' => ConvenioDataLabels::present(is_array($convenioData) ? $convenioData : null),
                'generated_by' => $tracking->generatedBy ? [
                    'id' => $tracking->generatedBy->id,
                    'name' => $tracking->generatedBy->name,
                    'email' => $tracking->generatedBy->email,
                ] : null,
            ],
        ])->header('Cache-Control', 'private, no-store, no-cache, must-revalidate');
    }

    /**
     * Resend emails to one or multiple affiliates.
     */
    public function resendEmails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tracking_ids' => 'required|array',
            'tracking_ids.*' => 'required|integer|exists:convenio_email_tracking,id',
            'emails' => 'nullable|array',
            'emails.*' => 'nullable|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $trackingIds = $request->input('tracking_ids');
        $emails = $request->input('emails', []);
        $testMode = ConvenioDelivery::isTestMode();
        $testRecipientEmail = $testMode ? $request->user()?->email : null;

        // Build email map: tracking_id => email (optional)
        // If emails array is provided, map it to tracking_ids
        // It can be an associative array (tracking_id => email) or indexed array
        $emailMap = [];
        if (! empty($emails)) {
            // Check if emails is associative (keys are tracking IDs) or indexed
            $keys = array_keys($emails);
            $isAssociative = array_keys($keys) !== $keys;

            if ($isAssociative) {
                // Associative array: tracking_id => email
                $emailMap = array_filter($emails, function ($email) {
                    return ! empty($email);
                });
            } else {
                // Indexed array: map by position
                foreach ($trackingIds as $index => $trackingId) {
                    if (isset($emails[$index]) && ! empty($emails[$index])) {
                        $emailMap[$trackingId] = $emails[$index];
                    }
                }
            }
        }

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($trackingIds as $trackingId) {
            try {
                $tracking = ConvenioEmailTracking::findOrFail($trackingId);
                $pdfStorage = app(ConvenioPdfStorageService::class);

                if (! $pdfStorage->hasOriginal($tracking)) {
                    $results['failed'][] = [
                        'tracking_id' => $trackingId,
                        'error' => 'Archivo PDF no encontrado para este registro.',
                    ];

                    continue;
                }

                if ($tracking->isInvalidated()) {
                    $results['failed'][] = [
                        'tracking_id' => $trackingId,
                        'error' => 'No se puede reenviar un convenio invalidado.',
                    ];

                    continue;
                }

                if (config('convenio_signing.enabled', true) && $tracking->affiliateHasSigned()) {
                    $results['failed'][] = [
                        'tracking_id' => $trackingId,
                        'error' => 'No se puede reenviar un convenio ya firmado por el afiliado.',
                    ];

                    continue;
                }

                $rutaParaEnvio = is_string($tracking->ruta_archivo_pdf) && is_file($tracking->ruta_archivo_pdf)
                    ? $tracking->ruta_archivo_pdf
                    : '';

                if ($tracking->signing_token_hash !== null
                    && $tracking->signing_estado === ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA) {
                    $tracking->update([
                        'signing_token_hash' => null,
                        'token_expires_at' => null,
                    ]);
                }

                if ($tracking->estado === 'fallido') {
                    $updates = [
                        'estado' => 'pendiente',
                        'error_message' => null,
                    ];

                    if (empty($tracking->pdf_original_path)) {
                        $parent = $tracking->parentTracking;
                        if ($parent !== null && ! empty($parent->pdf_original_path)) {
                            $updates['pdf_original_path'] = $parent->pdf_original_path;
                            $updates['pdf_original_sha256'] = $parent->pdf_original_sha256;
                        }
                    }

                    $tracking->update($updates);
                }

                // Incrementar intentos en el tracking que se reutiliza
                $tracking->incrementarIntentos();

                // Get optional email for this tracking if provided
                $optionalEmail = $testRecipientEmail ?? ($emailMap[$trackingId] ?? null);

                SendConvenioManualEmailJob::dispatch(
                    $tracking->documento,
                    $tracking->nombre_archivo,
                    $rutaParaEnvio,
                    $tracking->nombre_convenio,
                    null,
                    $optionalEmail,
                    $tracking->sede,
                    $tracking->convenio_data,
                    $request->user()?->id ?? $tracking->generated_by_user_id,
                    $trackingId,
                );

                $results['success'][] = [
                    'tracking_id' => $trackingId,
                    'documento' => $tracking->documento,
                    'intentos' => $tracking->fresh()->intentos,
                ];

                Log::info('Email resend job dispatched', [
                    'tracking_id' => $trackingId,
                    'documento' => $tracking->documento,
                    'intentos' => $tracking->fresh()->intentos,
                    'email_provided' => ! empty($optionalEmail),
                    'email_used' => $optionalEmail ?? 'will use affiliate email',
                ]);
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'tracking_id' => $trackingId,
                    'error' => $e->getMessage(),
                ];

                Log::error('Failed to resend email', [
                    'tracking_id' => $trackingId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'delivery_mode' => config('convenios.delivery_mode'),
            'message' => count($trackingIds) > 1
                ? 'Se encolaron '.count($results['success']).' reenvíos. '.(ConvenioDelivery::isTestMode()
                    ? 'En modo TEST los correos llegarán al usuario que realiza la solicitud y quedarán marcados como TEST.'
                    : 'Consulte el historial para ver el estado.')
                : 'Reenvío encolado correctamente.',
            'data' => [
                'total' => count($trackingIds),
                'success_count' => count($results['success']),
                'failed_count' => count($results['failed']),
                'results' => $results,
            ],
        ]);
    }

    public function listFailedEmailDays(ConvenioFailedEmailRetryService $retryService): JsonResponse
    {
        $days = $retryService->failedDays();

        return response()->json([
            'success' => true,
            'data' => [
                'days' => $days,
                'total' => array_sum(array_column($days, 'total')),
            ],
        ])->header('Cache-Control', 'private, no-store, no-cache, must-revalidate');
    }

    public function retryFailedEmails(
        RetryFailedConvenioEmailsRequest $request,
        ConvenioFailedEmailRetryService $retryService,
    ): JsonResponse {
        $result = $retryService->retry(
            [
                'tracking_ids' => $request->input('tracking_ids'),
                'fechas' => $request->input('fechas'),
                'fecha_desde' => $request->input('fecha_desde'),
                'fecha_hasta' => $request->input('fecha_hasta'),
                'sede' => $request->input('sede'),
                'q' => $request->input('q'),
            ],
            $request->user()?->id,
            $request->user()?->email,
        );

        $queued = $result['success_count'];

        return response()->json([
            'success' => true,
            'delivery_mode' => config('convenios.delivery_mode'),
            'message' => $queued === 0
                ? 'No se encontraron convenios fallidos para reintentar en los días seleccionados.'
                : 'Se encolaron '.$queued.' convenios fallidos. Los envíos respetan el límite por minuto; consulte el historial para ver el avance.',
            'data' => $result,
        ]);
    }

    /**
     * Get email tracking statistics.
     */
    public function getStatistics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date',
            'periodo' => ['nullable', 'string', 'regex:/^(todos|\d{4}[12])$/'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $digitalSigningEnabled = (bool) config('convenio_signing.enabled', true);

        $baseQuery = ConvenioEmailTracking::query()->real();

        $periodo = $request->input('periodo');
        if (is_string($periodo) && ConvenioSemesterPeriod::isValid($periodo)) {
            $baseQuery->byPeriodo($periodo);
        }

        if ($request->has('fecha_desde') || $request->has('fecha_hasta')) {
            $fechaInicio = $request->input('fecha_desde') ?: '1970-01-01';
            $fechaFin = $request->input('fecha_hasta') ?: now()->format('Y-m-d');
            $baseQuery->byFechaRango($fechaInicio, $fechaFin);
        }

        $table = (new ConvenioEmailTracking)->getTable();
        $sedeKeySql = "COALESCE(NULLIF(TRIM(`{$table}`.`sede`), ''), 'Sin sede')";

        $signingStats = null;
        $signingDerived = null;

        if ($digitalSigningEnabled) {
            $signingPendienteFirma = (clone $baseQuery)->where('signing_estado', ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA)->count();
            $signingFirmadoAfiliado = (clone $baseQuery)->where('signing_estado', ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO)->count();
            $signingFirmandoPresidente = (clone $baseQuery)->where('signing_estado', ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE)->count();
            $signingPendienteRevision = (clone $baseQuery)->where('signing_estado', ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION)->count();
            $signingErrorPresidente = (clone $baseQuery)->where('signing_estado', ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE)->count();
            $signingCompletado = (clone $baseQuery)->where('signing_estado', ConvenioEmailTracking::SIGNING_COMPLETADO)->count();
            $signingRechazado = (clone $baseQuery)->where('signing_estado', ConvenioEmailTracking::SIGNING_RECHAZADO)->count();

            $signingStats = [
                'pendiente_firma' => $signingPendienteFirma,
                'firmado_afiliado' => $signingFirmadoAfiliado,
                'firmando_presidente' => $signingFirmandoPresidente,
                'pendiente_revision' => $signingPendienteRevision,
                'error_firma_presidente' => $signingErrorPresidente,
                'completado' => $signingCompletado,
                'rechazado' => $signingRechazado,
            ];

            $signingDerived = [
                'pendientes_firma' => $signingPendienteFirma,
                'firmados_afiliado_o_finalizados' => $signingFirmadoAfiliado + $signingFirmandoPresidente + $signingPendienteRevision + $signingErrorPresidente + $signingCompletado,
                'por_firmar_presidente' => $signingFirmadoAfiliado + $signingErrorPresidente,
                'firmando_presidente' => $signingFirmandoPresidente,
                'pendiente_revision' => $signingPendienteRevision,
                'error_firma_presidente' => $signingErrorPresidente,
            ];
        }

        $satisfactionStats = null;
        if ($digitalSigningEnabled) {
            $eligibleForSatisfaction = (clone $baseQuery)->whereIn('signing_estado', [
                ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
                ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
                ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
                ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE,
                ConvenioEmailTracking::SIGNING_COMPLETADO,
            ])->count();
            $ratingsCount = (clone $baseQuery)->whereNotNull('signing_satisfaction_score')->count();
            $averageRaw = (clone $baseQuery)->whereNotNull('signing_satisfaction_score')->avg('signing_satisfaction_score');
            $distributionRows = (clone $baseQuery)
                ->whereNotNull('signing_satisfaction_score')
                ->selectRaw('signing_satisfaction_score as score, COUNT(*) as count')
                ->groupBy('signing_satisfaction_score')
                ->pluck('count', 'score');

            $distribution = [];
            foreach ([1, 2, 3, 4, 5] as $score) {
                $distribution[$score] = (int) ($distributionRows[$score] ?? 0);
            }

            $satisfactionStats = [
                'ratings_count' => $ratingsCount,
                'eligible_count' => $eligibleForSatisfaction,
                'response_rate' => $eligibleForSatisfaction > 0
                    ? round(($ratingsCount / $eligibleForSatisfaction) * 100, 1)
                    : null,
                'average' => $ratingsCount > 0 ? round((float) $averageRaw, 2) : null,
                'distribution' => $distribution,
            ];
        }

        $bySedeQuery = (clone $baseQuery)
            ->selectRaw("{$sedeKeySql} as sede_label")
            ->selectRaw('COUNT(*) as total');

        if ($digitalSigningEnabled) {
            $bySedeQuery
                ->selectRaw('SUM(CASE WHEN signing_estado = ? THEN 1 ELSE 0 END) as pendiente_firma', [ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA])
                ->selectRaw('SUM(CASE WHEN signing_estado = ? THEN 1 ELSE 0 END) as firmado_afiliado', [ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO])
                ->selectRaw('SUM(CASE WHEN signing_estado = ? THEN 1 ELSE 0 END) as completado', [ConvenioEmailTracking::SIGNING_COMPLETADO])
                ->selectRaw('SUM(CASE WHEN signing_estado = ? THEN 1 ELSE 0 END) as rechazado', [ConvenioEmailTracking::SIGNING_RECHAZADO])
                ->selectRaw('AVG(signing_satisfaction_score) as satisfaction_average')
                ->selectRaw('SUM(CASE WHEN signing_satisfaction_score IS NOT NULL THEN 1 ELSE 0 END) as satisfaction_count');
        }

        $bySede = $bySedeQuery
            ->groupByRaw($sedeKeySql)
            ->orderByDesc('total')
            ->limit(50)
            ->get()
            ->map(static function ($row) use ($digitalSigningEnabled): array {
                $entry = [
                    'sede' => (string) $row->sede_label,
                    'total' => (int) $row->total,
                ];

                if ($digitalSigningEnabled) {
                    $entry['pendiente_firma'] = (int) $row->pendiente_firma;
                    $entry['firmado_afiliado'] = (int) $row->firmado_afiliado;
                    $entry['completado'] = (int) $row->completado;
                    $entry['rechazado'] = (int) $row->rechazado;
                    $entry['satisfaction_count'] = (int) $row->satisfaction_count;
                    $entry['satisfaction_average'] = (int) $row->satisfaction_count > 0
                        ? round((float) $row->satisfaction_average, 2)
                        : null;
                }

                return $entry;
            })
            ->values()
            ->all();

        $stats = [
            'digital_signing_enabled' => $digitalSigningEnabled,
            'auto_sign_enabled' => ConvenioAutoSign::enabled(),
            'president_sign_bulk_enabled' => ConvenioAutoSign::bulkEnabled(),
            'president_sign_require_review' => ConvenioAutoSign::requireReview(),
            'total' => (clone $baseQuery)->count(),
            'by_status' => (clone $baseQuery)->selectRaw('estado, COUNT(*) as count')
                ->groupBy('estado')
                ->pluck('count', 'estado')
                ->toArray(),
            'sent_today' => (clone $baseQuery)->whereDate('enviado_at', today())->count(),
            'pending' => (clone $baseQuery)->where('estado', 'pendiente')->count(),
            'sent' => (clone $baseQuery)->where('estado', 'enviado')->count(),
            'failed' => (clone $baseQuery)->where('estado', 'fallido')->count(),
            'signing' => $signingStats,
            'signing_derived' => $signingDerived,
            'satisfaction' => $satisfactionStats,
            'by_sede' => $bySede,
        ];

        return response()->json([
            'success' => true,
            'delivery_mode' => config('convenios.delivery_mode'),
            'filter_options' => ConvenioSemesterPeriod::filterOptions(),
            'data' => $stats,
        ]);
    }

    /**
     * Descarga el PDF firmado por el afiliado, o el PDF final histórico (estado completado) si existía.
     */
    public function downloadConvenioFinal(int $tracking): Response|JsonResponse
    {
        if (! config('convenio_signing.enabled', true)) {
            return response()->json([
                'success' => false,
                'message' => 'La funcionalidad de firma digital no está habilitada.',
            ], 404);
        }

        $tracking = ConvenioEmailTracking::findOrFail($tracking);
        $pdfStorage = app(ConvenioPdfStorageService::class);

        if (in_array($tracking->signing_estado, [
            ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
            ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE,
        ], true)) {
            $contents = $pdfStorage->get($tracking->pdf_firmado_afiliado_path);
            if ($contents === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró el PDF firmado por el afiliado.',
                ], 404);
            }

            return $pdfStorage->downloadResponse(
                $contents,
                ConvenioDisplayFilename::fromTracking($tracking),
            );
        }

        if (in_array($tracking->signing_estado, [
            ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
            ConvenioEmailTracking::SIGNING_COMPLETADO,
        ], true) && $tracking->pdf_final_path) {
            $contents = $pdfStorage->get($tracking->pdf_final_path);
            if ($contents === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Archivo final no encontrado.',
                ], 404);
            }

            return $pdfStorage->downloadResponse(
                $contents,
                ConvenioDisplayFilename::fromTracking($tracking),
            );
        }

        return response()->json([
            'success' => false,
            'message' => 'El convenio aún no tiene PDF firmado por el afiliado disponible para descarga.',
        ], 422);
    }

    /**
     * Descarga el PDF tal como se generó o almacenó para el envío (copia persistente o archivo en disco),
     * útil para auditoría antes de que el afiliado firme.
     */
    public function downloadConvenioOriginal(int $tracking): Response|JsonResponse
    {
        $tracking = ConvenioEmailTracking::findOrFail($tracking);
        $pdfStorage = app(ConvenioPdfStorageService::class);
        $contents = $pdfStorage->originalContents($tracking);

        if ($contents !== null) {
            return $pdfStorage->downloadResponse(
                $contents,
                ConvenioDisplayFilename::fromTracking($tracking),
            );
        }

        return response()->json([
            'success' => false,
            'message' => 'No se encontró el PDF original del convenio para este registro.',
        ], 404);
    }

    public function signAsPresident(
        Request $request,
        ConvenioEmailTracking $tracking,
        ConvenioPresidentSignService $presidentSign,
    ): JsonResponse {
        if (! $presidentSign->isEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'La autofirma del presidente no está habilitada.',
            ], 503);
        }

        try {
            $presidentSign->queue($tracking, auth()->id());
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'El convenio fue enviado a la cola de firma presidencial.',
        ], 202);
    }

    public function signAsPresidentBulk(
        PresidentSignBulkRequest $request,
        ConvenioPresidentSignService $presidentSign,
    ): JsonResponse {
        if (! $presidentSign->isEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'La autofirma del presidente no está habilitada.',
            ], 503);
        }

        if (! $presidentSign->isBulkEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'La firma masiva del presidente no está habilitada.',
            ], 503);
        }

        try {
            $result = $presidentSign->queueMany($request->validated('tracking_ids'), (int) auth()->id());
        } catch (\InvalidArgumentException $exception) {
            $active = $presidentSign->findProcessingBatch();
            if ($active !== null && str_contains($exception->getMessage(), 'lote')) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'batch_id' => $active->id,
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'accepted' => $result['accepted'],
            'rejected' => $result['rejected'],
            'batch_id' => $result['batch_id'],
        ]);
    }

    public function previewPresidentSignCampaign(
        PresidentSignBulkPreviewRequest $request,
        ConvenioPresidentSignService $presidentSign,
    ): JsonResponse {
        if (! $presidentSign->isBulkEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'La firma masiva del presidente no está habilitada.',
            ], 503);
        }

        $validated = $request->validated();
        $result = $presidentSign->previewCampaign(
            $validated['scope'],
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
            (bool) ($validated['include_errors'] ?? false),
        );

        if ($result['count'] > ConvenioAutoSign::bulkMax()) {
            return response()->json([
                'success' => false,
                'message' => sprintf(
                    'Hay %d convenios elegibles; el máximo permitido es %d. Acote el rango de fechas.',
                    $result['count'],
                    ConvenioAutoSign::bulkMax(),
                ),
                'count' => $result['count'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'count' => $result['count'],
        ]);
    }

    public function startPresidentSignCampaign(
        PresidentSignCampaignRequest $request,
        ConvenioPresidentSignService $presidentSign,
    ): JsonResponse {
        if (! $presidentSign->isBulkEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'La firma masiva del presidente no está habilitada.',
            ], 503);
        }

        $validated = $request->validated();

        try {
            $result = $presidentSign->queueCampaign(
                $validated['scope'],
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null,
                (bool) ($validated['include_errors'] ?? false),
                (int) auth()->id(),
            );
        } catch (\InvalidArgumentException $exception) {
            $active = $presidentSign->findProcessingBatch();
            if ($active !== null && str_contains($exception->getMessage(), 'lote')) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'batch_id' => $active->id,
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'batch_id' => $result['batch_id'],
            'accepted' => $result['accepted'],
        ], 202);
    }

    public function activePresidentSignBatch(ConvenioPresidentSignService $presidentSign): JsonResponse
    {
        $batch = $presidentSign->findActiveBatch();

        if ($batch === null) {
            return response()->json([
                'success' => true,
                'batch' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'batch' => $batch->toProgressPayload(),
        ]);
    }

    public function showPresidentSignBatch(ConvenioPresidentSignBatch $batch): JsonResponse
    {
        return response()->json([
            'success' => true,
            'batch' => $batch->toProgressPayload(),
        ]);
    }

    public function listPresidentSignBatchTrackings(Request $request, ConvenioPresidentSignBatch $batch): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'q' => 'nullable|string|max:200',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'ids_only' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = $this->pendingPresidentReviewTrackingsQuery($batch, $request->string('q')->trim()->toString());

        if ($request->boolean('ids_only')) {
            $ids = $query->pluck('id')->all();

            return response()->json([
                'success' => true,
                'data' => $ids,
                'total' => count($ids),
            ]);
        }

        $trackings = $query
            ->paginate(
                $request->integer('per_page', 30),
                [
                    'id',
                    'documento',
                    'nombre_afiliado',
                    'nombre_convenio',
                    'signing_estado',
                    'firmado_presidente_at',
                ],
            );

        $trackings->through(function (ConvenioEmailTracking $tracking): array {
            return [
                'id' => $tracking->id,
                'documento' => $tracking->documento,
                'nombre_afiliado' => $tracking->nombre_afiliado,
                'nombre_convenio' => $tracking->nombre_convenio,
                'signing_estado' => $tracking->signing_estado,
                'firmado_presidente_at' => $tracking->firmado_presidente_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $trackings,
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\ConvenioEmailTracking, \App\Models\ConvenioPresidentSignBatch>
     */
    private function pendingPresidentReviewTrackingsQuery(
        ConvenioPresidentSignBatch $batch,
        ?string $search = null,
    ) {
        $query = $batch->trackings()
            ->where('signing_estado', ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION)
            ->orderBy('id');

        if ($search !== null && $search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function ($builder) use ($like): void {
                $builder->where('nombre_afiliado', 'like', $like)
                    ->orWhere('documento', 'like', $like)
                    ->orWhere('nombre_convenio', 'like', $like);
            });
        }

        return $query;
    }

    public function previewConvenioPdf(int $tracking, ConvenioPdfStorageService $pdfStorage): Response|JsonResponse
    {
        $tracking = ConvenioEmailTracking::findOrFail($tracking);

        if ($tracking->signing_estado === ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO) {
            $contents = $pdfStorage->get($tracking->pdf_firmado_afiliado_path);
            if ($contents === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró el PDF firmado por el afiliado.',
                ], 404);
            }

            return $pdfStorage->downloadResponse(
                $contents,
                ConvenioDisplayFilename::fromTracking($tracking),
                'inline',
            );
        }

        if (in_array($tracking->signing_estado, [
            ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
            ConvenioEmailTracking::SIGNING_COMPLETADO,
        ], true) && filled($tracking->pdf_final_path)) {
            $contents = $pdfStorage->get($tracking->pdf_final_path);
            if ($contents === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Archivo final no encontrado.',
                ], 404);
            }

            return $pdfStorage->downloadResponse(
                $contents,
                ConvenioDisplayFilename::fromTracking($tracking),
                'inline',
            );
        }

        return response()->json([
            'success' => false,
            'message' => 'El convenio no tiene PDF disponible para previsualización.',
        ], 422);
    }

    public function completeConvenio(
        ConvenioEmailTracking $tracking,
        ConvenioPresidentSignReviewService $reviewService,
    ): JsonResponse {
        try {
            $reviewService->complete($tracking, (int) auth()->id());
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Convenio completado y correo enviado al afiliado.',
        ]);
    }

    public function completeConvenioBulk(
        CompleteConvenioBulkRequest $request,
        ConvenioPresidentSignReviewService $reviewService,
    ): JsonResponse {
        $result = $reviewService->completeMany($request->validated('tracking_ids'), (int) auth()->id());

        return response()->json([
            'success' => true,
            'accepted' => $result['accepted'],
            'rejected' => $result['rejected'],
        ]);
    }

    public function markConvenioReviewError(
        Request $request,
        ConvenioEmailTracking $tracking,
        ConvenioPresidentSignReviewService $reviewService,
    ): JsonResponse {
        $reason = $request->input('reason');

        try {
            $reviewService->markReviewError(
                $tracking,
                is_string($reason) ? $reason : null,
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Convenio marcado con error de revisión.',
        ]);
    }

    public function invalidateConvenio(
        InvalidateConvenioRequest $request,
        ConvenioEmailTracking $tracking,
        ConvenioInvalidationService $invalidationService,
    ): JsonResponse {
        try {
            $invalidationService->invalidate(
                $tracking,
                auth()->id(),
                $request->validated('reason'),
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'El convenio quedó invalidado. El afiliado ya no puede firmarlo.',
        ]);
    }

    public function markConvenioReviewErrorBulk(
        ReviewErrorConvenioBulkRequest $request,
        ConvenioPresidentSignReviewService $reviewService,
    ): JsonResponse {
        $validated = $request->validated();
        $result = $reviewService->markReviewErrorMany(
            $validated['tracking_ids'],
            $validated['reason'] ?? null,
        );

        return response()->json([
            'success' => true,
            'accepted' => $result['accepted'],
            'rejected' => $result['rejected'],
        ]);
    }

    /**
     * Generate and optionally send convenio from frontend data.
     *
     * This endpoint allows the frontend to send convenio data via API
     * instead of relying only on Excel files.
     */
    public function generateAndSendConvenio(Request $request): JsonResponse|BinaryFileResponse
    {
        // Normalizar send_email antes de validar (convertir "Si"/"No" a booleanos)
        $requestData = $request->all();
        if (isset($requestData['send_email'])) {
            $sendEmailValue = $requestData['send_email'];
            if (is_string($sendEmailValue)) {
                $normalized = mb_strtolower(trim($sendEmailValue), 'UTF-8');
                if ($normalized === 'si' || $normalized === 'sí' || $normalized === 'yes' || $normalized === '1') {
                    $requestData['send_email'] = true;
                } elseif ($normalized === 'no' || $normalized === 'false' || $normalized === '0') {
                    $requestData['send_email'] = false;
                }
                // Si no coincide con ninguno, dejar el valor original para que la validación lo maneje
            }
        }

        $validator = Validator::make($requestData, [
            // Campos requeridos básicos
            'numero_documento' => 'required|string|max:50',
            'apellidos' => 'required|string|max:255',
            'nombres' => 'required|string|max:255',
            'fecha_nacimiento' => 'required|date',
            'lugar_nacimiento' => 'required|string|max:255',

            // Campos requeridos del afiliado y convenio
            'proceso' => 'required|string|max:255',
            'ciudad' => 'required|string|max:255',
            'sede' => 'required|string|max:255',
            'fecha_inicio' => 'required|date',
            'fecha_finalizacion' => 'nullable|date',
            'direccion' => 'required|string|max:500',
            'celular' => 'required|string|max:50',

            // Compensación básica redactada (requerido O valores individuales, pero no ambos)
            'compensacion_basica_redactada' => 'nullable|string',

            // Nuevos campos de compensación (todos opcionales)
            'basico' => 'nullable|numeric',
            'auxilios' => 'nullable|numeric',
            'manutencion' => 'nullable|numeric',
            'provisiones' => 'nullable|numeric',
            'horas' => 'nullable|numeric',
            'valor_hora_diurna' => 'nullable|numeric',
            'valor_hora_nocturna' => 'nullable|numeric',
            'valor_hora_diurna_festiva' => 'nullable|numeric',
            'valor_hora_nocturna_festiva' => 'nullable|numeric',
            'auxilio_de_transporte' => 'nullable|numeric',
            'auxilio_de_manutencion' => 'nullable|numeric',
            'auxilio_de_encierro' => 'nullable|numeric',
            'auxilio_de_rodamiento' => 'nullable|numeric',
            'auxilio_especial' => 'nullable|numeric', // Auxilio especial (diferente del auxilio general)
            'auxilio_prosalud' => 'nullable|numeric', // Auxilio Prosalud no constitutivo de compensación básica
            'valor_auxilio_diurno' => 'nullable|numeric',
            'valor_auxilio_recargo_nocturno' => 'nullable|numeric',
            'valor_auxilio_recargo_festivo' => 'nullable|numeric',
            'valor_auxilio_recargo_festivo_nocturno' => 'nullable|numeric',

            // Opciones de procesamiento
            // Opciones de procesamiento (envío de correo se activará cuando exista PDF)
            // send_email se normaliza antes de la validación (acepta "Si"/"No" y los convierte a booleanos)
            'send_email' => 'nullable|boolean',
            'email' => 'nullable|email|max:255',
            // Control de descarga directa del archivo Word
            'download' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Usar los datos normalizados para el resto del proceso
        $request->merge($requestData);

        // Validar que haya compensacion_basica_redactada O valores individuales, pero no ambos
        $tieneCompensacionRedactada = ! empty(trim($request->input('compensacion_basica_redactada', '')));
        $tieneValoresIndividuales = $this->tieneValoresCompensacionIndividuales($request);

        if ($tieneCompensacionRedactada && $tieneValoresIndividuales) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede proporcionar "compensacion_basica_redactada" junto con valores individuales de compensación. Debe usar uno u otro, no ambos.',
                'errors' => [
                    'compensacion_basica_redactada' => ['No se puede usar junto con valores individuales de compensación'],
                ],
            ], 422);
        }

        if (! $tieneCompensacionRedactada && ! $tieneValoresIndividuales) {
            return response()->json([
                'success' => false,
                'message' => 'Debe proporcionar "compensacion_basica_redactada" o al menos un valor individual de compensación (basico, auxilios, valor_hora_diurna, etc.)',
                'errors' => [
                    'compensacion_basica_redactada' => ['Requerido si no se proporcionan valores individuales'],
                ],
            ], 422);
        }

        try {
            $download = $request->boolean('download', false);
            $sendEmail = $request->boolean('send_email', false);

            Log::info('Generando convenio desde API', [
                'documento' => $request->input('numero_documento'),
                'send_email' => $sendEmail,
                'download' => $download,
                'modo' => $download ? 'síncrono (descarga directa)' : 'asíncrono (job)',
            ]);

            // Preparar datos para el servicio de generación (todos los campos que se usan en la plantilla Word)
            $convenioData = [
                'numero_documento' => $request->input('numero_documento'),
                'apellidos' => $request->input('apellidos'),
                'nombres' => $request->input('nombres'),
                'proceso' => $request->input('proceso'),
                'ciudad' => $request->input('ciudad'),
                'sede' => $request->input('sede'),
                'fecha_nacimiento' => $request->input('fecha_nacimiento'),
                'lugar_nacimiento' => $request->input('lugar_nacimiento'),
                'fecha_inicio' => $request->input('fecha_inicio'),
                'fecha_finalizacion' => $request->input('fecha_finalizacion'),
                'direccion' => $request->input('direccion'),
                'celular' => $request->input('celular'),
                'compensacion_basica_redactada' => $request->input('compensacion_basica_redactada'),
                // Nuevos campos de compensación
                'basico' => $request->input('basico'),
                'auxilios' => $request->input('auxilios'),
                'manutencion' => $request->input('manutencion'),
                'provisiones' => $request->input('provisiones'),
                'horas' => $request->input('horas'),
                'valor_hora_diurna' => $request->input('valor_hora_diurna'),
                'valor_hora_nocturna' => $request->input('valor_hora_nocturna'),
                'valor_hora_diurna_festiva' => $request->input('valor_hora_diurna_festiva'),
                'valor_hora_nocturna_festiva' => $request->input('valor_hora_nocturna_festiva'),
                'auxilio_de_transporte' => $request->input('auxilio_de_transporte'),
                'auxilio_de_manutencion' => $request->input('auxilio_de_manutencion'),
                'auxilio_de_encierro' => $request->input('auxilio_de_encierro'),
                'auxilio_de_rodamiento' => $request->input('auxilio_de_rodamiento'),
                'auxilio_especial' => $request->input('auxilio_especial'),
                'auxilio_prosalud' => $request->input('auxilio_prosalud'),
                'valor_auxilio_diurno' => $request->input('valor_auxilio_diurno'),
                'valor_auxilio_recargo_nocturno' => $request->input('valor_auxilio_recargo_nocturno'),
                'valor_auxilio_recargo_festivo' => $request->input('valor_auxilio_recargo_festivo'),
                'valor_auxilio_recargo_festivo_nocturno' => $request->input('valor_auxilio_recargo_festivo_nocturno'),
            ];

            // Si se solicita descarga directa, procesar de forma síncrona (aumentar timeout)
            if ($download) {
                // Aumentar timeout para proceso síncrono
                set_time_limit(300); // 5 minutos
                ini_set('max_execution_time', '300');

                Log::info('[CONVENIO API] Procesando de forma síncrona para descarga directa', [
                    'documento' => $request->input('numero_documento'),
                ]);

                // Generar convenio Word y convertir a PDF
                $resultadoWord = $this->convenioGenerationService->generarConvenio($convenioData);
                $resultado = $this->convenioGenerationService->finalizeConvenioPdf($resultadoWord['ruta']);

                if (! file_exists($resultado['ruta'])) {
                    Log::error('Archivo de convenio no encontrado para descarga', [
                        'ruta' => $resultado['ruta'],
                        'nombre_archivo' => $resultado['nombre'],
                        'documento' => $request->input('numero_documento'),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'El archivo del convenio no se encontró en el servidor.',
                    ], 500);
                }

                // Descargar y eliminar archivo después de enviarlo (es temporal)
                return response()->download(
                    $resultado['ruta'],
                    $resultado['nombre'],
                    ['Content-Type' => 'application/pdf']
                )->deleteFileAfterSend(true);
            }

            // Si NO se solicita descarga, procesar de forma asíncrona (job)
            Log::info('[CONVENIO API] Enviando generación a cola de trabajos (asíncrono)', [
                'documento' => $request->input('numero_documento'),
            ]);

            GenerateConvenioJob::dispatch(
                $convenioData,
                $request->input('email'),
                $sendEmail,
                $request->user()?->id,
            );

            $message = ConvenioDelivery::isTestMode() && $sendEmail
                ? 'La generación del convenio ha sido encolada. En modo TEST el correo (con enlace de firma o PDF adjunto) llegará a tu usuario y el registro quedará marcado como TEST en el historial.'
                : 'La generación del convenio ha sido encolada y se procesará de forma asíncrona. El archivo estará disponible en breve.';

            return response()->json([
                'success' => true,
                'message' => $message,
                'delivery_mode' => config('convenios.delivery_mode'),
                'next_step' => $sendEmail ? 'email-history' : 'download-generated',
                'data' => [
                    'documento' => $request->input('numero_documento'),
                    'procesando' => true,
                    'modo' => 'asíncrono',
                ],
                'warnings' => [],
            ], 202); // 202 Accepted - request accepted for processing

        } catch (\Exception $e) {
            Log::error('Error generando convenio desde API', [
                'documento' => $request->input('numero_documento'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el convenio: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Permite descargar un convenio generado previamente de forma asíncrona.
     *
     * El frontend puede usar este endpoint después de encolar la generación
     * (cuando download=false en /generate-and-send) para descargar el archivo
     * una vez esté disponible.
     *
     * GET /api/convenios-manual/download-generated?numero_documento=XXXX
     */
    public function downloadGeneratedConvenio(Request $request): BinaryFileResponse|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'numero_documento' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $numeroDocumentoOriginal = $request->query('numero_documento');
        $numeroDocumentoNormalizado = preg_replace('/[^0-9]/', '', $numeroDocumentoOriginal);

        Log::info('[CONVENIO API] Solicitud de descarga de convenio generado', [
            'numero_documento_original' => $numeroDocumentoOriginal,
            'numero_documento_normalizado' => $numeroDocumentoNormalizado,
        ]);

        // Directorio donde se guardan los convenios generados (temporal en storage/app/temp/convenios)
        $outputDir = storage_path('app/temp/convenios');

        // Crear directorio si no existe (puede que el job aún no lo haya creado)
        if (! is_dir($outputDir)) {
            Log::debug('[CONVENIO API] Directorio de convenios no existe, intentando crearlo', [
                'output_dir' => $outputDir,
            ]);

            $creado = mkdir($outputDir, 0755, true);
            if (! $creado || ! is_dir($outputDir)) {
                Log::error('[CONVENIO API] No se pudo crear el directorio de convenios', [
                    'output_dir' => $outputDir,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al acceder al directorio de convenios. Por favor, intente nuevamente.',
                ], 500);
            }
        }

        // Buscar el archivo PDF más reciente para ese documento (formato nemotécnico o legado)
        $legacyPattern = sprintf('%s/Convenio_%s_*.pdf', $outputDir, $numeroDocumentoNormalizado);
        $files = glob($legacyPattern) ?: [];

        if ($files === []) {
            $allPdfs = glob($outputDir.'/*.pdf') ?: [];
            $documentPattern = '/ - '.preg_quote($numeroDocumentoNormalizado, '/').'( - \d{4}[12])?\.pdf$/i';
            $files = array_values(array_filter(
                $allPdfs,
                static fn (string $file): bool => preg_match($documentPattern, basename($file)) === 1,
            ));
        }

        if (empty($files) && ConvenioDelivery::isTestMode() && $request->user()) {
            $tracking = ConvenioEmailTracking::query()
                ->where('documento', $numeroDocumentoNormalizado)
                ->where('generated_by_user_id', $request->user()->id)
                ->test()
                ->whereNotNull('ruta_archivo_pdf')
                ->orderByDesc('created_at')
                ->first();

            if ($tracking && is_file($tracking->ruta_archivo_pdf)) {
                $filePath = $tracking->ruta_archivo_pdf;
                $fileName = basename($filePath);

                return response()->download(
                    $filePath,
                    $fileName,
                    ['Content-Type' => 'application/pdf']
                );
            }
        }

        if (empty($files)) {
            Log::info('[CONVENIO API] Convenio no encontrado aún para descarga', [
                'numero_documento_normalizado' => $numeroDocumentoNormalizado,
                'legacy_pattern' => $legacyPattern,
                'output_dir' => $outputDir,
                'directorio_existe' => is_dir($outputDir),
                'directorio_escribible' => is_dir($outputDir) ? is_writable($outputDir) : false,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se encontró un convenio generado para el documento especificado. El convenio puede estar aún en proceso de generación. Por favor, espere unos momentos e intente nuevamente.',
                'status' => 'processing', // Indicar que está en proceso
            ], 404);
        }

        // Ordenar por fecha de modificación (más reciente primero)
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $filePath = $files[0];
        $fileName = basename($filePath);

        if (! file_exists($filePath)) {
            Log::error('[CONVENIO API] Archivo de convenio no encontrado al intentar descargar', [
                'file_path' => $filePath,
                'numero_documento_normalizado' => $numeroDocumentoNormalizado,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'El archivo del convenio no se encontró en el servidor. Puede estar aún en proceso de generación.',
                'status' => 'processing',
            ], 404);
        }

        if (ConvenioDelivery::isTestMode() && $request->user()) {
            $hasTestAccess = ConvenioEmailTracking::query()
                ->where('documento', $numeroDocumentoNormalizado)
                ->where('generated_by_user_id', $request->user()->id)
                ->test()
                ->exists();

            if ($hasTestAccess) {
                $trackingForFile = ConvenioEmailTracking::query()
                    ->where('documento', $numeroDocumentoNormalizado)
                    ->where('generated_by_user_id', $request->user()->id)
                    ->test()
                    ->where('ruta_archivo_pdf', $filePath)
                    ->exists();

                if (! $trackingForFile) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tiene permiso para descargar este convenio de verificación.',
                    ], 403);
                }
            }
        }

        Log::info('[CONVENIO API] Descargando convenio generado', [
            'numero_documento_normalizado' => $numeroDocumentoNormalizado,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'tamaño_bytes' => filesize($filePath),
        ]);

        // Descargar y eliminar archivo después de enviarlo (es temporal, no se guarda permanentemente)
        return response()->download(
            $filePath,
            $fileName,
            ['Content-Type' => 'application/pdf']
        )->deleteFileAfterSend(true);
    }

    /**
     * Importa PDFs pregenerados desde un archivo ZIP, los almacena en S3 y opcionalmente encola envíos.
     */
    public function importPdfZip(
        UploadConvenioPdfZipRequest $request,
        ConvenioDuplicateDetectionService $duplicateDetection,
    ): JsonResponse {
        $sendEmail = $request->boolean('send_email', true);
        $file = $request->file('file');

        if ($file === null) {
            return response()->json([
                'success' => false,
                'message' => 'No se recibió el archivo ZIP.',
            ], 422);
        }

        $realPath = $file->getRealPath();
        if (! is_string($realPath) || ! is_readable($realPath)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo leer el archivo ZIP subido.',
            ], 500);
        }

        try {
            $scan = $this->pdfZipImportService->scanZipEntries($realPath);
        } catch (\Throwable $e) {
            Log::error('[CONVENIO ZIP] Error escaneando ZIP', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo ZIP: '.$e->getMessage(),
            ], 422);
        }

        if ($scan['valid'] === []) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron PDFs válidos en el ZIP. Verifique el formato de nombres: SEDE - NOMBRE COMPLETO - DOCUMENTO.pdf',
                'data' => [
                    'validos' => 0,
                    'rechazados' => count($scan['rejected']),
                    'rejected' => $scan['rejected'],
                ],
            ], 422);
        }

        $incoming = [];
        foreach ($scan['valid'] as $entry) {
            $incoming[] = [
                'documento' => $entry['documento'],
                'sede' => $entry['nombre_convenio'],
                'incoming_label' => $entry['filename'],
            ];
        }

        $validEntries = $scan['valid'];
        $omitidos = 0;

        // TODO: reactivar cuando retomen confirmación de duplicados en importación ZIP.
        if (config('convenios.duplicate_import_check_enabled', false)) {
            try {
                $decision = $duplicateDetection->resolveImportDecision(
                    $duplicateDetection->findConflicts($incoming),
                    $request->boolean('confirm_duplicates'),
                    $this->duplicateActionsFromRequest($request),
                    $request->input('invalidation_reason'),
                );
            } catch (\InvalidArgumentException $exception) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                ], 422);
            }

            if ($decision['needs_confirmation']) {
                return $this->duplicateConveniosResponse($decision['duplicates']);
            }

            try {
                $duplicateDetection->applyInvalidations(
                    $decision['invalidate_entries'],
                    $request->user()?->id,
                    $request->input('invalidation_reason'),
                );
            } catch (\InvalidArgumentException $exception) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                ], 422);
            }

            $skipKeys = array_flip($decision['skip_keys']);
            $validEntries = [];
            foreach ($scan['valid'] as $entry) {
                $key = ConvenioDuplicateDetectionService::conflictKey(
                    $entry['documento'],
                    $entry['nombre_convenio'],
                );
                if (isset($skipKeys[$key])) {
                    continue;
                }
                $validEntries[] = $entry;
            }

            $omitidos = count($decision['skip_keys']);

            if ($validEntries === []) {
                return response()->json([
                    'success' => false,
                    'message' => 'No quedaron PDFs para importar. Todos los duplicados fueron omitidos.',
                    'data' => [
                        'validos' => 0,
                        'omitidos' => $omitidos,
                        'rechazados' => count($scan['rejected']),
                        'rejected' => $scan['rejected'],
                    ],
                ], 422);
            }
        }

        try {
            $storedZipPath = $this->pdfZipImportService->storeZipOnDisk($realPath);
        } catch (\Throwable $e) {
            Log::error('[CONVENIO ZIP] Error almacenando ZIP en S3', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al almacenar el ZIP: '.$e->getMessage(),
            ], 500);
        }

        $batchId = (string) Str::uuid();

        ProcessConvenioPdfZipJob::dispatch(
            batchId: $batchId,
            storedZipPath: $storedZipPath,
            validEntries: $validEntries,
            sendEmail: $sendEmail,
            generatedByUserId: $request->user()?->id,
        );

        $message = $sendEmail
            ? (ConvenioDelivery::isTestMode()
                ? 'Se encolaron '.count($validEntries).' PDFs. En modo TEST los correos llegarán a tu usuario.'
                : 'Se encolaron '.count($validEntries).' PDFs para almacenamiento y envío. Consulte el historial.')
            : 'Se encolaron '.count($validEntries).' PDFs para almacenamiento. Puede reenviar desde el historial.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'delivery_mode' => config('convenios.delivery_mode'),
            'next_step' => 'email-history',
            'ui' => ConvenioHistoryUi::metadata(
                (bool) config('convenio_signing.enabled', true),
                ConvenioAutoSign::enabled(),
            ),
            'data' => [
                'batch_id' => $batchId,
                'validos' => count($validEntries),
                'omitidos' => $omitidos,
                'rechazados' => count($scan['rejected']),
                'send_email' => $sendEmail,
                'rejected' => $scan['rejected'],
            ],
        ], 202);
    }

    public function exportHistoryExcel(
        ExportConvenioHistoryExcelRequest $request,
        ConvenioHistoryExcelExportService $excelExportService,
    ): BinaryFileResponse|JsonResponse {
        try {
            $filters = $request->validated();
            $filePath = $excelExportService->generateReport($filters);

            if (! is_file($filePath)) {
                Log::error('[CONVENIO API] Error generando reporte Excel de historial: archivo no creado', [
                    'user_id' => $request->user()?->id,
                    'filters' => $filters,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar el reporte',
                ], 500);
            }

            $fileName = 'Reporte_Convenios_ProSalud_'.now()->setTimezone('America/Bogota')->format('Y-m-d_His').'.xlsx';

            Log::info('[CONVENIO API] Reporte Excel de historial generado', [
                'user_id' => $request->user()?->id,
                'filters' => $filters,
                'file_name' => $fileName,
            ]);

            return response()->download($filePath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'X-Download-Filename' => rawurlencode($fileName),
            ])->deleteFileAfterSend(true);
        } catch (\InvalidArgumentException $e) {
            Log::warning('[CONVENIO API] Error de validación al generar reporte Excel de historial', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('[CONVENIO API] Error generando reporte Excel de historial', [
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
     * Exporta una plantilla Excel para importación masiva de convenios
     */
    public function exportTemplate(): BinaryFileResponse
    {
        try {
            Log::info('[CONVENIO API] Exportando plantilla Excel para importación masiva');

            $tempPath = $this->templateExportService->generateTemplate();

            return response()->download(
                $tempPath,
                'Plantilla_Convenios_Masivos.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
            )->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('[CONVENIO API] Error exportando plantilla Excel', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar la plantilla: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Importa convenios desde un archivo Excel y los genera masivamente
     * Opcionalmente envía correos electrónicos
     */
    public function importAndGenerateBulk(
        Request $request,
        ConvenioDuplicateDetectionService $duplicateDetection,
    ): JsonResponse {
        Log::info('[CONVENIO API] Iniciando importación masiva - Validando request', [
            'has_file' => $request->hasFile('file'),
            'send_email' => $request->input('send_email'),
        ]);

        // Normalizar send_email antes de validar (convertir "Si"/"No" o strings a booleanos)
        $requestData = $request->all();
        if (isset($requestData['send_email'])) {
            $sendEmailValue = $requestData['send_email'];
            if (is_string($sendEmailValue)) {
                $normalized = mb_strtolower(trim($sendEmailValue), 'UTF-8');
                if ($normalized === 'si' || $normalized === 'sí' || $normalized === 'yes' || $normalized === '1' || $normalized === 'true') {
                    $requestData['send_email'] = true;
                } elseif ($normalized === 'no' || $normalized === 'false' || $normalized === '0') {
                    $requestData['send_email'] = false;
                }
                // Si no coincide con ninguno, dejar el valor original para que la validación lo maneje
            }
        }

        if (isset($requestData['confirm_duplicates']) && is_string($requestData['confirm_duplicates'])) {
            $normalizedConfirm = mb_strtolower(trim($requestData['confirm_duplicates']), 'UTF-8');
            if (in_array($normalizedConfirm, ['si', 'sí', 'yes', '1', 'true'], true)) {
                $requestData['confirm_duplicates'] = true;
            } elseif (in_array($normalizedConfirm, ['no', 'false', '0'], true)) {
                $requestData['confirm_duplicates'] = false;
            }
        }

        if (isset($requestData['duplicate_actions']) && is_string($requestData['duplicate_actions'])) {
            $decodedActions = json_decode($requestData['duplicate_actions'], true);
            if (is_array($decodedActions)) {
                $requestData['duplicate_actions'] = $decodedActions;
            }
        }

        $validator = Validator::make($requestData, [
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // Max 10MB
            'send_email' => 'nullable|boolean',
            'confirm_duplicates' => 'nullable|boolean',
            'duplicate_actions' => 'nullable|array',
            'duplicate_actions.*.documento' => 'required_with:duplicate_actions|string|max:50',
            'duplicate_actions.*.sede' => 'required_with:duplicate_actions|string|max:255',
            'duplicate_actions.*.action' => 'required_with:duplicate_actions|in:invalidate_and_proceed,skip,proceed_anyway',
            'duplicate_actions.*.invalidation_reason' => 'nullable|string|min:8|max:500',
            'invalidation_reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            Log::warning('[CONVENIO API] Validación fallida en importación masiva', [
                'errors' => $validator->errors()->toArray(),
                'send_email_original' => $request->input('send_email'),
                'send_email_normalizado' => $requestData['send_email'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Usar los datos normalizados
        $request->merge($requestData);
        $sendEmail = $request->boolean('send_email', true);

        Log::debug('[CONVENIO API] send_email normalizado', [
            'original' => $request->input('send_email'),
            'normalizado' => $sendEmail,
        ]);
        $file = $request->file('file');

        Log::info('[CONVENIO API] Archivo validado correctamente', [
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'extension' => $file->getClientOriginalExtension(),
            'send_email' => $sendEmail,
        ]);

        try {
            Log::info('[CONVENIO API] Iniciando importación masiva desde Excel', [
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'send_email' => $sendEmail,
            ]);

            // Leer desde el upload de PHP o, si hace falta, copiar al disco local explícito (no al default, que en prod puede ser S3).
            Log::debug('[CONVENIO API] Resolviendo archivo temporal de importación', [
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
                'default_disk' => config('filesystems.default'),
            ]);

            try {
                ['path' => $fullTempPath, 'storage_path' => $tempPath] = $this->resolveBulkImportExcelPath($file);

                Log::info('[CONVENIO API] Archivo temporal listo para lectura', [
                    'temp_storage_path' => $tempPath,
                    'full_path' => $fullTempPath,
                    'exists' => file_exists($fullTempPath),
                    'readable' => is_readable($fullTempPath),
                    'size' => file_exists($fullTempPath) ? filesize($fullTempPath) : 0,
                ]);
            } catch (\RuntimeException $e) {
                Log::error('[CONVENIO API] Error al resolver archivo temporal de importación', [
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 500);
            } catch (\Exception $e) {
                Log::error('[CONVENIO API] Error al guardar archivo temporal', [
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al guardar el archivo: '.$e->getMessage(),
                ], 500);
            }

            // Leer Excel
            Log::debug('[CONVENIO API] Iniciando lectura del archivo Excel');
            try {
                $reader = IOFactory::createReader('Xlsx');
                if (method_exists($reader, 'setReadDataOnly')) {
                    $reader->setReadDataOnly(true);
                }

                Log::debug('[CONVENIO API] Cargando spreadsheet desde archivo', [
                    'path' => $fullTempPath,
                ]);
                $spreadsheet = $reader->load($fullTempPath);
                $sheet = $spreadsheet->getActiveSheet();

                Log::info('[CONVENIO API] Excel cargado exitosamente', [
                    'highest_row' => $sheet->getHighestRow(),
                    'highest_column' => $sheet->getHighestColumn(),
                ]);
            } catch (\Exception $e) {
                Log::error('[CONVENIO API] Error leyendo archivo Excel', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'file_path' => $fullTempPath,
                ]);
                $this->deleteBulkImportExcelTemp($tempPath);
                throw $e;
            }

            // Construir mapeo de columnas
            Log::debug('[CONVENIO API] Construyendo mapeo de columnas');
            $columnMapping = $this->buildColumnMapping($sheet);

            Log::info('[CONVENIO API] Mapeo de columnas construido', [
                'total_columnas' => count($columnMapping),
                'columnas' => array_keys($columnMapping),
            ]);

            if (empty($columnMapping)) {
                Log::error('[CONVENIO API] No se encontraron columnas válidas en el Excel');
                $this->deleteBulkImportExcelTemp($tempPath);

                return response()->json([
                    'success' => false,
                    'message' => 'No se encontraron columnas válidas en el Excel',
                ], 422);
            }

            // Validar columnas requeridas
            Log::debug('[CONVENIO API] Validando columnas requeridas');
            $requiredColumns = [
                'numero_documento',
                'apellidos',
                'nombres',
                'fecha_nacimiento',
                'lugar_nacimiento',
                'proceso',
                'ciudad',
                'sede',
                'fecha_inicio',
                'direccion',
                'celular',
            ];
            $missingColumns = [];
            foreach ($requiredColumns as $col) {
                if (! isset($columnMapping[$col])) {
                    $missingColumns[] = $col;
                }
            }

            if (! empty($missingColumns)) {
                Log::error('[CONVENIO API] Columnas requeridas no encontradas', [
                    'missing_columns' => $missingColumns,
                    'available_columns' => array_keys($columnMapping),
                ]);
                $this->deleteBulkImportExcelTemp($tempPath);

                return response()->json([
                    'success' => false,
                    'message' => 'Columnas requeridas no encontradas: '.implode(', ', $missingColumns),
                    'missing_columns' => $missingColumns,
                ], 422);
            }

            Log::info('[CONVENIO API] Todas las columnas requeridas están presentes');

            // Procesar filas
            $highestRow = $sheet->getHighestRow();

            if ($highestRow <= 1) {
                Log::warning('[CONVENIO API] El Excel no contiene filas de datos', [
                    'highest_row' => $highestRow,
                ]);
                $this->deleteBulkImportExcelTemp($tempPath);
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);

                return response()->json([
                    'success' => false,
                    'message' => 'El archivo Excel no contiene filas de datos. Complete al menos una fila debajo de los encabezados (a partir de la fila 2) e intente nuevamente.',
                ], 422);
            }

            Log::info('[CONVENIO API] Iniciando procesamiento de filas', [
                'total_filas' => $highestRow,
                'filas_a_procesar' => $highestRow - 1, // Excluyendo encabezado
            ]);

            $procesados = 0;
            $exitosos = 0;
            $errores = 0;
            $filasVacias = 0;
            $errors = [];
            $pendingRows = [];

            for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
                $procesados++;

                if ($procesados % 10 === 0) {
                    Log::debug('[CONVENIO API] Procesando filas', [
                        'procesadas' => $procesados,
                        'exitosas' => $exitosos,
                        'errores' => $errores,
                        'fila_actual' => $rowIndex,
                    ]);
                }

                try {
                    Log::debug('[CONVENIO API] Leyendo datos de fila', ['fila' => $rowIndex]);
                    $rowData = $this->readRowDataFromExcel($sheet, $rowIndex, $columnMapping);
                    Log::debug('[CONVENIO API] Datos de fila leídos', [
                        'fila' => $rowIndex,
                        'numero_documento' => $rowData['numero_documento'] ?? 'N/A',
                        'tiene_datos' => ! empty($rowData['numero_documento']) || ! empty($rowData['apellidos']),
                    ]);

                    // Saltar filas vacías
                    if (empty($rowData['numero_documento']) && empty($rowData['apellidos']) && empty($rowData['nombres'])) {
                        $filasVacias++;

                        continue;
                    }

                    // Validar datos requeridos básicos
                    $camposRequeridos = [
                        'numero_documento' => 'Número de documento',
                        'apellidos' => 'Apellidos',
                        'nombres' => 'Nombres',
                        'fecha_nacimiento' => 'Fecha de nacimiento',
                        'lugar_nacimiento' => 'Lugar de nacimiento',
                        'proceso' => 'Proceso',
                        'ciudad' => 'Ciudad',
                        'sede' => 'Sede',
                        'fecha_inicio' => 'Fecha de inicio',
                        'direccion' => 'Dirección',
                        'celular' => 'Celular',
                    ];

                    $camposFaltantes = [];
                    foreach ($camposRequeridos as $campo => $nombre) {
                        if (empty(trim($rowData[$campo] ?? ''))) {
                            $camposFaltantes[] = $nombre;
                        }
                    }

                    if (! empty($camposFaltantes)) {
                        $errores++;
                        $errors[] = "Fila {$rowIndex}: Faltan campos requeridos: ".implode(', ', $camposFaltantes);

                        continue;
                    }

                    // Validar compensación: debe haber compensacion_basica_redactada O valores individuales, pero no ambos
                    $tieneCompensacionRedactada = ! empty(trim($rowData['compensacion_basica_redactada'] ?? ''));
                    $tieneValoresIndividuales = $this->tieneValoresCompensacionIndividualesEnArray($rowData);

                    if ($tieneCompensacionRedactada && $tieneValoresIndividuales) {
                        $errores++;
                        $errors[] = "Fila {$rowIndex}: No se puede proporcionar 'Compensacion Basica Redactada' junto con valores individuales de compensación. Debe usar uno u otro.";

                        continue;
                    }

                    if (! $tieneCompensacionRedactada && ! $tieneValoresIndividuales) {
                        $errores++;
                        $errors[] = "Fila {$rowIndex}: Debe proporcionar 'Compensacion Basica Redactada' o al menos un valor individual de compensación (basico, auxilios, valor_hora_diurna, etc.)";

                        continue;
                    }

                    // Preparar datos para generación (eliminar campos que no van a la plantilla Word)
                    $convenioData = $rowData;
                    unset($convenioData['hospital'], $convenioData['nombre_archivo'], $convenioData['email'], $convenioData['send_email']);

                    $email = $rowData['email'] ?? null;

                    Log::debug('[CONVENIO API] Preparando job para fila', [
                        'fila' => $rowIndex,
                        'documento' => $convenioData['numero_documento'] ?? 'N/A',
                        'email' => $email,
                        'send_email' => $sendEmail,
                    ]);

                    $pendingRows[] = [
                        'row_index' => $rowIndex,
                        'documento' => (string) ($convenioData['numero_documento'] ?? ''),
                        'sede' => (string) ($convenioData['sede'] ?? ''),
                        'nombre' => trim(((string) ($convenioData['nombres'] ?? '')).' '.((string) ($convenioData['apellidos'] ?? ''))),
                        'convenio_data' => $convenioData,
                        'email' => $email,
                    ];

                } catch (\Exception $e) {
                    $errores++;
                    $errors[] = "Fila {$rowIndex}: ".$e->getMessage();
                    Log::error('[CONVENIO API] Error procesando fila en importación masiva', [
                        'fila' => $rowIndex,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $incoming = [];
            foreach ($pendingRows as $pendingRow) {
                $incoming[] = [
                    'documento' => $pendingRow['documento'],
                    'sede' => $pendingRow['sede'],
                    'incoming_label' => 'Fila '.$pendingRow['row_index'],
                    'incoming_nombre' => $pendingRow['nombre'],
                ];
            }

            $omitidos = 0;

            // TODO: reactivar cuando retomen confirmación de duplicados en importación Excel.
            if (config('convenios.duplicate_import_check_enabled', false)) {
                try {
                    $decision = $duplicateDetection->resolveImportDecision(
                        $duplicateDetection->findConflicts($incoming),
                        $request->boolean('confirm_duplicates'),
                        $this->duplicateActionsFromRequest($request),
                        $request->input('invalidation_reason'),
                    );
                } catch (\InvalidArgumentException $exception) {
                    $this->deleteBulkImportExcelTemp($tempPath);
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return response()->json([
                        'success' => false,
                        'message' => $exception->getMessage(),
                    ], 422);
                }

                if ($decision['needs_confirmation']) {
                    $this->deleteBulkImportExcelTemp($tempPath);
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return $this->duplicateConveniosResponse($decision['duplicates']);
                }

                try {
                    $duplicateDetection->applyInvalidations(
                        $decision['invalidate_entries'],
                        $request->user()?->id,
                        $request->input('invalidation_reason'),
                    );
                } catch (\InvalidArgumentException $exception) {
                    $this->deleteBulkImportExcelTemp($tempPath);
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    return response()->json([
                        'success' => false,
                        'message' => $exception->getMessage(),
                    ], 422);
                }

                $skipKeys = array_flip($decision['skip_keys']);
                foreach ($pendingRows as $pendingRow) {
                    $key = ConvenioDuplicateDetectionService::conflictKey(
                        $pendingRow['documento'],
                        $pendingRow['sede'],
                    );
                    if (isset($skipKeys[$key])) {
                        $omitidos++;

                        continue;
                    }

                    GenerateConvenioJob::dispatch(
                        $pendingRow['convenio_data'],
                        $pendingRow['email'],
                        $sendEmail,
                        $request->user()?->id,
                    );
                    $exitosos++;
                }
            } else {
                foreach ($pendingRows as $pendingRow) {
                    GenerateConvenioJob::dispatch(
                        $pendingRow['convenio_data'],
                        $pendingRow['email'],
                        $sendEmail,
                        $request->user()?->id,
                    );
                    $exitosos++;
                }
            }

            // Limpiar archivo temporal
            $this->deleteBulkImportExcelTemp($tempPath);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            Log::info('[CONVENIO API] Importación masiva completada', [
                'procesados' => $procesados,
                'exitosos' => $exitosos,
                'omitidos' => $omitidos,
                'errores' => $errores,
                'filas_vacias' => $filasVacias,
                'send_email' => $sendEmail,
            ]);

            return response()->json([
                'success' => $exitosos > 0,
                'message' => $this->buildImportBulkMessage($sendEmail, $exitosos, $errores, $filasVacias, $procesados),
                'delivery_mode' => config('convenios.delivery_mode'),
                'next_step' => 'email-history',
                'ui' => ConvenioHistoryUi::metadata(
                    (bool) config('convenio_signing.enabled', true),
                    ConvenioAutoSign::enabled(),
                ),
                'data' => [
                    'procesados' => $procesados,
                    'exitosos' => $exitosos,
                    'omitidos' => $omitidos,
                    'errores' => $errores,
                    'filas_vacias' => $filasVacias,
                    'send_email' => $sendEmail,
                    'errors' => $errors,
                ],
            ], $exitosos > 0 ? 202 : 422);

        } catch (\Exception $e) {
            Log::error('[CONVENIO API] Error en importación masiva', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'previous' => $e->getPrevious() ? [
                    'message' => $e->getPrevious()->getMessage(),
                    'class' => get_class($e->getPrevious()),
                ] : null,
            ]);

            // Limpiar archivo temporal si existe
            if (isset($tempPath)) {
                try {
                    $this->deleteBulkImportExcelTemp($tempPath);
                } catch (\Exception $cleanupException) {
                    Log::warning('[CONVENIO API] Error limpiando archivo temporal', [
                        'error' => $cleanupException->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo Excel: '.$e->getMessage(),
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Construye el mapeo de columnas del Excel
     */
    private function buildColumnMapping($sheet): array
    {
        Log::debug('[CONVENIO API] Iniciando construcción de mapeo de columnas');
        $mapping = [];
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        Log::debug('[CONVENIO API] Analizando columnas del Excel', [
            'highest_column' => $highestColumn,
            'highest_column_index' => $highestColumnIndex,
        ]);

        // Mapeo de nombres de columnas (case-insensitive)
        $columnMappings = [
            'numero_documento' => ['numero documento', 'numero_documento', 'num cedula', 'cedula', 'documento'],
            'apellidos' => ['apellidos'],
            'nombres' => ['nombres'],
            'fecha_nacimiento' => ['fecha nacimiento', 'fecha_nacimiento'],
            'lugar_nacimiento' => ['lugar nacimiento', 'lugar_nacimiento'],
            'proceso' => ['proceso', 'cargo'],
            'ciudad' => ['ciudad'],
            'sede' => ['sede'],
            'fecha_inicio' => ['fecha inicio', 'fecha_inicio', 'f.inicio'],
            'fecha_finalizacion' => ['fecha finalizacion', 'fecha_finalizacion', 'fecha finalización'],
            'direccion' => ['direccion', 'dirección'],
            'celular' => ['celular'],
            'compensacion_basica_redactada' => ['compensacion basica redactada', 'compensacion_basica_redactada'],
            'basico' => ['basico', 'básico'],
            'auxilios' => ['auxilios'],
            'manutencion' => ['manutencion', 'manutención'],
            'provisiones' => ['provisiones'],
            'horas' => ['horas'],
            'valor_hora_diurna' => ['valor hora diurna', 'valor_hora_diurna'],
            'valor_hora_nocturna' => ['valor hora nocturna', 'valor_hora_nocturna'],
            'valor_hora_diurna_festiva' => ['valor hora diurna festiva', 'valor_hora_diurna_festiva'],
            'valor_hora_nocturna_festiva' => ['valor hora nocturna festiva', 'valor_hora_nocturna_festiva'],
            'auxilio_de_transporte' => ['auxilio de transporte', 'auxilio_de_transporte'],
            'auxilio_de_manutencion' => ['auxilio de manutencion', 'auxilio_de_manutencion'],
            'auxilio_de_encierro' => ['auxilio de encierro', 'auxilio_de_encierro'],
            'auxilio_de_rodamiento' => ['auxilio de rodamiento', 'auxilio_de_rodamiento'],
            'auxilio_especial' => ['auxilio especial', 'auxilio_especial'],
            'auxilio_prosalud' => ['auxilio prosalud', 'auxilio_prosalud'],
            'valor_auxilio_diurno' => ['valor auxilio diurno', 'valor_auxilio_diurno'],
            'valor_auxilio_recargo_nocturno' => ['valor auxilio recargo nocturno', 'valor_auxilio_recargo_nocturno'],
            'valor_auxilio_recargo_festivo' => ['valor auxilio recargo festivo', 'valor_auxilio_recargo_festivo'],
            'valor_auxilio_recargo_festivo_nocturno' => ['valor auxilio recargo festivo nocturno', 'valor_auxilio_recargo_festivo_nocturno'],
            'email' => ['email'],
        ];

        // Leer fila de encabezados (fila 1)
        for ($colIndex = 1; $colIndex <= $highestColumnIndex; $colIndex++) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            $cell = $sheet->getCell($colLetter.'1');
            $headerValue = $this->normalizeColumnName(trim($cell->getValue() ?? ''));

            if (empty($headerValue)) {
                continue;
            }

            // Buscar en los mapeos
            foreach ($columnMappings as $internalName => $possibleNames) {
                foreach ($possibleNames as $possibleName) {
                    $normalizedPossible = $this->normalizeColumnName($possibleName);
                    if ($headerValue === $normalizedPossible) {
                        $mapping[$internalName] = $colIndex - 1; // 0-based index
                        break 2;
                    }
                }
            }
        }

        Log::info('[CONVENIO API] Mapeo de columnas completado', [
            'total_columnas_mapeadas' => count($mapping),
            'columnas' => array_keys($mapping),
        ]);

        return $mapping;
    }

    /**
     * Lee los datos de una fila del Excel
     */
    private function readRowDataFromExcel($sheet, int $rowIndex, array $columnMapping): array
    {
        try {
            $data = [];

            foreach ($columnMapping as $internalName => $colIndex) {
                $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                $cell = $sheet->getCell($colLetter.$rowIndex);
                $value = trim($cell->getValue() ?? '');

                // Procesar fechas si es necesario
                if (in_array($internalName, ['fecha_inicio', 'fecha_finalizacion', 'fecha_nacimiento'])) {
                    $value = $this->normalizeDate($value);
                }

                $data[$internalName] = $value;
            }

            return $data;
        } catch (\Exception $e) {
            Log::error('[CONVENIO API] Error leyendo datos de fila del Excel', [
                'fila' => $rowIndex,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Normaliza el nombre de una columna para comparación
     */
    private function normalizeColumnName(string $name): string
    {
        $name = mb_strtolower($name, 'UTF-8');
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim($name);

        return $name;
    }

    /**
     * Normaliza una fecha desde Excel
     */
    private function normalizeDate($value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            // Si es numérico, puede ser fecha Excel
            if (is_numeric($value)) {
                $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);

                return $date->format('Y-m-d');
            }

            $valueStr = trim((string) $value);

            // Formato DD/MM/YYYY
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $valueStr, $matches)) {
                $day = (int) $matches[1];
                $month = (int) $matches[2];
                $year = (int) $matches[3];
                if ($year < 100) {
                    $year += 2000;
                }

                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            // Intentar parsear con Carbon
            $carbon = \Carbon\Carbon::parse($valueStr);

            return $carbon->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning('[CONVENIO API] Error normalizando fecha', [
                'valor' => $value,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Verifica si hay valores individuales de compensación en el request
     */
    private function tieneValoresCompensacionIndividuales(Request $request): bool
    {
        $camposCompensacion = [
            'basico',
            'auxilios',
            'manutencion',
            'provisiones',
            'horas',
            'valor_hora_diurna',
            'valor_hora_nocturna',
            'valor_hora_diurna_festiva',
            'valor_hora_nocturna_festiva',
            'auxilio_de_transporte',
            'auxilio_de_manutencion',
            'auxilio_de_encierro',
            'auxilio_de_rodamiento',
            'auxilio_especial',
            'auxilio_prosalud',
            'valor_auxilio_diurno',
            'valor_auxilio_recargo_nocturno',
            'valor_auxilio_recargo_festivo',
            'valor_auxilio_recargo_festivo_nocturno',
        ];

        foreach ($camposCompensacion as $campo) {
            $valor = $request->input($campo);
            if (! empty($valor) && $valor !== '0' && $valor !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica si hay valores individuales de compensación en un array de datos
     */
    private function tieneValoresCompensacionIndividualesEnArray(array $data): bool
    {
        $camposCompensacion = [
            'basico',
            'auxilios',
            'manutencion',
            'provisiones',
            'horas',
            'valor_hora_diurna',
            'valor_hora_nocturna',
            'valor_hora_diurna_festiva',
            'valor_hora_nocturna_festiva',
            'auxilio_de_transporte',
            'auxilio_de_manutencion',
            'auxilio_de_encierro',
            'auxilio_de_rodamiento',
            'auxilio_especial',
            'auxilio_prosalud',
            'valor_auxilio_diurno',
            'valor_auxilio_recargo_nocturno',
            'valor_auxilio_recargo_festivo',
            'valor_auxilio_recargo_festivo_nocturno',
        ];

        foreach ($camposCompensacion as $campo) {
            $valor = $data[$campo] ?? null;
            if (! empty($valor) && $valor !== '0' && $valor !== 0 && trim($valor) !== '') {
                return true;
            }
        }

        return false;
    }

    private function buildImportBulkMessage(
        bool $sendEmail,
        int $exitosos,
        int $errores = 0,
        int $filasVacias = 0,
        int $procesados = 0,
    ): string {
        if ($exitosos === 0) {
            if ($errores > 0) {
                return 'No se encolaron convenios. Revise los errores de validación por fila en la respuesta.';
            }

            if ($filasVacias > 0 && $filasVacias === $procesados) {
                return 'No se encolaron convenios porque todas las filas del archivo están vacías.';
            }

            return 'No se encolaron convenios. Verifique que el archivo tenga filas con datos debajo de los encabezados.';
        }

        if ($sendEmail && ConvenioDelivery::isTestMode()) {
            return "Se encolaron {$exitosos} convenios. En modo TEST los correos (con enlace de firma o PDF adjunto) llegarán a tu usuario y los registros quedarán marcados como TEST en el historial.";
        }

        if ($sendEmail) {
            return "Se encolaron {$exitosos} convenios. Se generarán PDFs y se enviarán correos de forma asíncrona. Consulte el historial.";
        }

        return "Se encolaron {$exitosos} convenios para generación en PDF. Consulte el historial o descargue cuando estén listos.";
    }

    /**
     * @return array{path: string, storage_path: string|null}
     */
    private function resolveBulkImportExcelPath(UploadedFile $file): array
    {
        $realPath = $file->getRealPath();
        if (is_string($realPath) && $realPath !== '' && is_readable($realPath)) {
            return [
                'path' => $realPath,
                'storage_path' => null,
            ];
        }

        $localDisk = Storage::disk('local');
        $localDisk->makeDirectory('temp');

        $filename = 'convenio_import_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
        $storagePath = $file->storeAs('temp', $filename, 'local');

        if ($storagePath === false || ! $localDisk->exists($storagePath)) {
            throw new \RuntimeException(
                'Error al guardar el archivo temporalmente. Verifique los permisos del directorio '.$localDisk->path('temp')
            );
        }

        return [
            'path' => $localDisk->path($storagePath),
            'storage_path' => $storagePath,
        ];
    }

    private function deleteBulkImportExcelTemp(?string $storagePath): void
    {
        if ($storagePath === null || $storagePath === '') {
            return;
        }

        Storage::disk('local')->delete($storagePath);
    }

    /**
     * @return list<array{documento?: mixed, sede?: mixed, action?: mixed}>
     */
    private function duplicateActionsFromRequest(Request $request): array
    {
        $actions = $request->input('duplicate_actions', []);
        if (is_string($actions) && $actions !== '') {
            $decoded = json_decode($actions, true);
            $actions = is_array($decoded) ? $decoded : [];
        }

        return is_array($actions) ? array_values($actions) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $duplicates
     */
    private function duplicateConveniosResponse(array $duplicates): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'duplicate_convenios',
            'message' => 'Hay convenios existentes para algunos afiliados de esta misma sede. Confirme si desea invalidar el anterior, omitirlo o continuar.',
            'data' => [
                'duplicates' => $duplicates,
            ],
        ], 409);
    }
}
