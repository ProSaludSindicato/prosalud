<?php

namespace App\Http\Controllers;

use App\Http\Requests\{ExportVaccinationSurveysExcelRequest, StoreVaccinationSurveyRequest};
use App\Models\VaccinationSurvey;
use App\Services\VaccinationSurveyExcelExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VaccinationSurveyController extends Controller
{
    public function __construct(
        private VaccinationSurveyExcelExportService $excelExportService
    ) {
    }

    /**
     * Store a new vaccination survey submitted by an affiliate.
     */
    public function store(StoreVaccinationSurveyRequest $request): JsonResponse
    {
        $processId = Str::uuid()->toString();
        $logContext = [
            'process_id' => $processId,
            'tipo_documento' => $request->input('tipo_documento'),
            'numero_documento' => $request->input('numero_documento'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        Log::info('[Encuesta vacunación] Inicio del proceso de creación', $logContext);

        try {
            $validated = $request->validated();
            Log::info('[Encuesta vacunación] Validación exitosa', array_merge($logContext, ['step' => 'validation_ok']));

            // Procesar y almacenar la firma digital (obligatoria)
            Log::info('[Encuesta vacunación] Almacenando firma en disco privado', array_merge($logContext, ['step' => 'signature_storage_start']));
            $firmaPath = $this->storeSignature(
                $validated['firma'],
                $validated['tipo_documento'],
                $validated['numero_documento']
            );

            if (null === $firmaPath) {
                Log::error('[Encuesta vacunación] Error al procesar la firma digital', array_merge($logContext, [
                    'step' => 'signature_storage_failed',
                    'tipo_documento' => $validated['tipo_documento'],
                    'numero_documento' => $validated['numero_documento'],
                ]));

                return response()->json([
                    'success' => false,
                    'message' => 'Error al procesar la firma digital. Por favor, intente nuevamente.',
                ], 500);
            }

            Log::info('[Encuesta vacunación] Firma almacenada correctamente', array_merge($logContext, [
                'step' => 'signature_storage_ok',
                'firma_path' => $firmaPath,
            ]));

            // Crear registro de la encuesta
            Log::info('[Encuesta vacunación] Creando registro en base de datos', array_merge($logContext, ['step' => 'db_create_start']));
            $survey = VaccinationSurvey::create([
                'tipo_documento' => $validated['tipo_documento'],
                'numero_documento' => $validated['numero_documento'],
                'hospital' => $validated['hospital'] ?? null,
                'fecha_nacimiento' => $validated['fecha_nacimiento'],
                'primer_nombre' => $validated['primer_nombre'] ?? '',
                'segundo_nombre' => $validated['segundo_nombre'] ?? null,
                'primer_apellido' => $validated['primer_apellido'] ?? '',
                'segundo_apellido' => $validated['segundo_apellido'] ?? null,
                'fecha_aplicacion_srp' => $validated['fecha_aplicacion_srp'] ?? null,
                'fecha_aplicacion_sr' => $validated['fecha_aplicacion_sr'] ?? null,
                'fecha_aplicacion_fiebre_amarilla' => $validated['fecha_aplicacion_fiebre_amarilla'] ?? null,
                'firma_path' => $firmaPath,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            Log::info('[Encuesta vacunación] Proceso completado correctamente', array_merge($logContext, [
                'step' => 'created',
                'survey_id' => $survey->id,
                'created_at' => $survey->created_at?->toIso8601String(),
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Encuesta de vacunación registrada correctamente.',
                'data' => [
                    'id' => $survey->id,
                    'created_at' => $survey->created_at?->toIso8601String(),
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('[Encuesta vacunación] Error inesperado', array_merge($logContext, [
                'step' => 'exception',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['firma']),
            ]));

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la encuesta de vacunación. Por favor, intente nuevamente.',
            ], 500);
        }
    }

    /**
     * Store base64 PNG signature in private storage.
     */
    private function storeSignature(string $firmaBase64, string $tipoDocumento, string $numeroDocumento): ?string
    {
        $disk = 'prosalud-private';
        $fallbackDisk = 'local';

        if (!preg_match('/^data:image\/png;base64,/', $firmaBase64)) {
            return null;
        }

        $base64Data = substr($firmaBase64, strpos($firmaBase64, ',') + 1);
        $fileContent = base64_decode($base64Data, true);

        if (false === $fileContent) {
            return null;
        }

        $filename = sprintf(
            'firma-%s-%s-%s.png',
            strtoupper(trim($tipoDocumento)),
            trim($numeroDocumento),
            Str::uuid()
        );

        $storagePath = 'vaccination-surveys/signatures/' . date('Y/m') . '/' . $filename;

        $stored = Storage::disk($disk)->put($storagePath, $fileContent);

        if (false === $stored) {
            $stored = Storage::disk($fallbackDisk)->put($storagePath, $fileContent);
        }

        return $stored ? $storagePath : null;
    }

    /**
     * Export vaccination surveys to Excel (registros + resumen, con firmas embebidas).
     * Se genera de forma asíncrona para evitar timeouts con muchos registros.
     * Requires authentication and permission vaccination_surveys.view.
     */
    public function exportExcel(ExportVaccinationSurveysExcelRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $dateRange = $request->input('date_range', []);
            $filters = [
                'date_range' => [
                    'include_all' => $dateRange['include_all'] ?? true,
                    'start_date' => $dateRange['start_date'] ?? null,
                    'end_date' => $dateRange['end_date'] ?? null,
                ],
            ];

            $jobId = Str::uuid()->toString();

            cache()->put(
                "vaccination_survey_report:{$jobId}",
                [
                    'status' => 'processing',
                    'created_at' => now()->toIso8601String(),
                ],
                now()->addHours(24)
            );

            $excelExportService = $this->excelExportService;
            dispatch(function () use ($excelExportService, $filters, $jobId, $user) {
                try {
                    Log::info('[Encuesta vacunación] Iniciando generación asíncrona de reporte Excel', [
                        'job_id' => $jobId,
                        'user_id' => $user?->id,
                    ]);

                    $filePath = $excelExportService->generateReport($filters);

                    if (!file_exists($filePath)) {
                        throw new \Exception('El archivo del reporte no fue creado');
                    }

                    $fileName = 'Reporte_Encuesta_Vacunacion_ProSalud_' . now()->setTimezone('America/Bogota')->format('Y-m-d_His') . '.xlsx';
                    $storagePath = 'reports/vaccination-surveys/' . $jobId . '/' . $fileName;
                    $disk = Storage::disk('local');
                    $disk->put($storagePath, file_get_contents($filePath));

                    @unlink($filePath);

                    cache()->put(
                        "vaccination_survey_report:{$jobId}",
                        [
                            'status' => 'completed',
                            'file_path' => $storagePath,
                            'file_name' => $fileName,
                            'created_at' => now()->toIso8601String(),
                        ],
                        now()->addHours(24)
                    );

                    Log::info('[Encuesta vacunación] Reporte Excel generado exitosamente', [
                        'job_id' => $jobId,
                        'file_path' => $storagePath,
                        'user_id' => $user?->id,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('[Encuesta vacunación] Error generando reporte Excel (background)', [
                        'job_id' => $jobId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'user_id' => $user?->id,
                    ]);

                    cache()->put(
                        "vaccination_survey_report:{$jobId}",
                        [
                            'status' => 'failed',
                            'error' => $e->getMessage(),
                            'created_at' => now()->toIso8601String(),
                        ],
                        now()->addHours(24)
                    );
                }
            })->afterResponse();

            Log::info('[Encuesta vacunación] Export Excel encolado para generación asíncrona', [
                'job_id' => $jobId,
                'user_id' => $user?->id,
                'filters' => $filters,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'El reporte se está generando. Use el job_id para verificar el estado.',
                'job_id' => $jobId,
                'status' => 'processing',
                'check_status_url' => url("/api/encuesta-vacunacion/export/status/{$jobId}"),
            ], 202);
        } catch (\InvalidArgumentException $e) {
            Log::warning('[Encuesta vacunación] Validación export Excel', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('[Encuesta vacunación] Error export Excel', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al encolar el reporte. Por favor, intente nuevamente.',
            ], 500);
        }
    }

    /**
     * Check the status of an async vaccination survey Excel report job.
     */
    public function checkStatus(string $jobId): JsonResponse
    {
        $cacheKey = "vaccination_survey_report:{$jobId}";
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
            $response['download_url'] = url("/api/encuesta-vacunacion/export/download/{$jobId}");
            $response['file_name'] = $status['file_name'] ?? null;
            $response['created_at'] = $status['created_at'] ?? null;
        } elseif ($status['status'] === 'failed') {
            $response['error'] = $status['error'] ?? 'Error desconocido';
        }

        return response()->json($response);
    }

    /**
     * Download a completed vaccination survey Excel report.
     */
    public function downloadReport(string $jobId): StreamedResponse|JsonResponse
    {
        $cacheKey = "vaccination_survey_report:{$jobId}";
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
        $fileName = $status['file_name'] ?? 'Reporte_Encuesta_Vacunacion_ProSalud.xlsx';

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

            Log::info('[Encuesta vacunación] Reporte Excel descargado', [
                'job_id' => $jobId,
                'file_name' => $fileName,
            ]);

            return response()->streamDownload(function () use ($fileContent) {
                echo $fileContent;
            }, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (\Exception $e) {
            Log::error('[Encuesta vacunación] Error descargando reporte Excel', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al descargar el archivo: ' . $e->getMessage(),
            ], 500);
        }
    }
}

