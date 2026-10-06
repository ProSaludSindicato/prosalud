<?php

namespace App\Http\Controllers;

use App\Models\SstDeliveryRecord;
use App\Models\SstReturnRecord;
use App\Services\SstDeliveryReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SstDeliveryReportController extends Controller
{
    public function __construct(
        private readonly SstDeliveryReportService $reportService
    ) {}

    /**
     * Distinct hospitals and delivery responsibles for export filters.
     */
    public function filterOptions(): JsonResponse
    {
        $hospitals = SstDeliveryRecord::query()
            ->whereNotNull('affiliate_hospital')
            ->where('affiliate_hospital', '!=', '')
            ->distinct()
            ->orderBy('affiliate_hospital')
            ->pluck('affiliate_hospital')
            ->merge(
                SstReturnRecord::query()
                    ->whereNotNull('affiliate_hospital')
                    ->where('affiliate_hospital', '!=', '')
                    ->distinct()
                    ->pluck('affiliate_hospital')
            )
            ->unique()
            ->sort()
            ->values();

        $deliveredBy = SstDeliveryRecord::query()
            ->select(['delivered_by_user_id', 'delivered_by_name'])
            ->whereNotNull('delivered_by_user_id')
            ->distinct()
            ->orderBy('delivered_by_name')
            ->get()
            ->map(fn (SstDeliveryRecord $record) => [
                'id' => (string) $record->delivered_by_user_id,
                'name' => $record->delivered_by_name ?: (string) $record->delivered_by_user_id,
            ])
            ->unique('id')
            ->values();

        return response()->json([
            'hospitals' => $hospitals,
            'deliveredBy' => $deliveredBy,
        ]);
    }

    /**
     * Enqueue Excel report generation (always async to avoid gateway timeouts).
     */
    public function download(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'hospital',
                'hospitals',
                'startDate',
                'endDate',
                'documentNumber',
                'deliveredBy',
            ]);

            // Collapse `hospital` / `hospitals` into a single normalized list
            $filters['hospitals'] = $this->reportService->normalizeHospitalFilter($filters);
            unset($filters['hospital']);

            $this->reportService->validateReportFilters($filters);

            $includeSignatures = $request->boolean('includeSignatures', false);
            $options = [
                'includeSignatures' => $includeSignatures,
                'signatureSize' => [
                    'width' => $request->integer('signatureWidth', 100),
                    'height' => $request->integer('signatureHeight', 50),
                ],
            ];

            $jobId = $this->enqueueReportGeneration($filters, $options);

            return response()->json([
                'success' => true,
                'message' => 'El reporte se está generando. Use el job_id para verificar el estado.',
                'job_id' => $jobId,
                'status' => 'processing',
                'check_status_url' => url("/api/dotacion-epp/reports/deliveries/status/{$jobId}"),
            ], 202);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error encolando reporte de entregas SST', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check the status of an async report generation job.
     */
    public function checkStatus(string $jobId): JsonResponse
    {
        $cacheKey = "sst_report:{$jobId}";
        $status = cache()->get($cacheKey);

        if (! $status) {
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
            $response['download_url'] = url("/api/dotacion-epp/reports/deliveries/download/{$jobId}");
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
        $cacheKey = "sst_report:{$jobId}";
        $status = cache()->get($cacheKey);

        if (! $status) {
            return response()->json([
                'success' => false,
                'message' => 'Job no encontrado o expirado',
            ], 404);
        }

        if ($status['status'] !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'El reporte aún no está listo. Estado: '.($status['status'] ?? 'unknown'),
                'status' => $status['status'],
            ], 400);
        }

        $filePath = $status['file_path'] ?? null;
        $fileName = $status['file_name'] ?? 'reporte-dotacion-epp.xlsx';

        if (! $filePath) {
            return response()->json([
                'success' => false,
                'message' => 'Ruta del archivo no encontrada',
            ], 404);
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo no existe en el almacenamiento',
            ], 404);
        }

        try {
            $fileContent = $disk->get($filePath);

            Log::info('Reporte de entregas SST descargado', [
                'job_id' => $jobId,
                'file_name' => $fileName,
            ]);

            return response()->streamDownload(function () use ($fileContent) {
                echo $fileContent;
            }, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (\Exception $e) {
            Log::error('Error descargando reporte de entregas SST', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al descargar el archivo: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    private function enqueueReportGeneration(array $filters, array $options): string
    {
        $jobId = Str::uuid()->toString();

        cache()->put(
            "sst_report:{$jobId}",
            [
                'status' => 'processing',
                'created_at' => now()->toIso8601String(),
            ],
            now()->addHours(24)
        );

        $reportService = $this->reportService;

        dispatch(function () use ($reportService, $filters, $options, $jobId) {
            try {
                Log::info('Iniciando generación asíncrona de reporte de entregas SST', [
                    'job_id' => $jobId,
                    'include_signatures' => $options['includeSignatures'] ?? false,
                ]);

                $filePath = $reportService->generateReport($filters, $options);

                if (! file_exists($filePath)) {
                    throw new \Exception('El archivo del reporte no fue creado');
                }

                $fileName = 'reporte-dotacion-epp-'.now()->setTimezone('America/Bogota')->format('Ymd-His').'.xlsx';
                $storagePath = 'reports/sst-delivery/'.$jobId.'/'.$fileName;
                $disk = Storage::disk('local');
                $disk->put($storagePath, file_get_contents($filePath));

                @unlink($filePath);

                cache()->put(
                    "sst_report:{$jobId}",
                    [
                        'status' => 'completed',
                        'file_path' => $storagePath,
                        'file_name' => $fileName,
                        'created_at' => now()->toIso8601String(),
                    ],
                    now()->addHours(24)
                );

                Log::info('Reporte de entregas SST generado exitosamente', [
                    'job_id' => $jobId,
                    'file_path' => $storagePath,
                    'file_name' => $fileName,
                ]);
            } catch (\Throwable $e) {
                Log::error('Error generando reporte de entregas SST (background)', [
                    'job_id' => $jobId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                cache()->put(
                    "sst_report:{$jobId}",
                    [
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                        'created_at' => now()->toIso8601String(),
                    ],
                    now()->addHours(24)
                );
            }
        })->afterResponse();

        Log::info('Reporte de entregas SST encolado para generación asíncrona', [
            'job_id' => $jobId,
            'include_signatures' => $options['includeSignatures'] ?? false,
        ]);

        return $jobId;
    }
}
