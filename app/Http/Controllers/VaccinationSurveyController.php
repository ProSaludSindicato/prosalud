<?php

namespace App\Http\Controllers;

use App\Http\Requests\{ExportVaccinationSurveysExcelRequest, StoreVaccinationSurveyRequest};
use App\Models\VaccinationSurvey;
use App\Services\VaccinationSurveyExcelExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
     * Export vaccination surveys to Excel (registros + resumen y estadísticas).
     * Requires authentication and permission vaccination_surveys.view.
     */
    public function exportExcel(ExportVaccinationSurveysExcelRequest $request): BinaryFileResponse|JsonResponse
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

            Log::info('[Encuesta vacunación] Inicio exportación Excel', [
                'user_id' => $user?->id,
                'user_email' => $user?->email,
                'filters' => $filters,
            ]);

            $filePath = $this->excelExportService->generateReport($filters);

            if (!file_exists($filePath)) {
                Log::error('[Encuesta vacunación] Export Excel: archivo no creado', [
                    'user_id' => $user?->id,
                    'filters' => $filters,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar el reporte',
                ], 500);
            }

            $fileName = 'Reporte_Encuesta_Vacunacion_ProSalud_' . now()->setTimezone('America/Bogota')->format('Y-m-d_His') . '.xlsx';

            Log::info('[Encuesta vacunación] Export Excel completado', [
                'user_id' => $user?->id,
                'file_name' => $fileName,
                'filters' => $filters,
            ]);

            return response()->download($filePath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
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
                'message' => 'Error al generar el reporte. Por favor, intente nuevamente.',
            ], 500);
        }
    }
}

