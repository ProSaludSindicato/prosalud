<?php

namespace App\Http\Controllers;

use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ConvenioEmailTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ConvenioManualController extends Controller
{
    /**
     * Send bulk emails with PDF attachments to multiple affiliates.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendBulkEmails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document_numbers' => 'required|array',
            'document_numbers.*' => 'required|string|max:50',
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

        $documentNumbers = $request->input('document_numbers');
        $emails = $request->input('emails', []);
        $conveniosPath = resource_path('convenios');

        if (!is_dir($conveniosPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Directorio de convenios no encontrado',
            ], 404);
        }

        // Get all PDF files
        $pdfFiles = glob($conveniosPath . '/*.pdf');
        $filesToProcess = [];

        // Find PDFs for the requested document numbers
        foreach ($documentNumbers as $documento) {
            foreach ($pdfFiles as $file) {
                $filename = basename($file);
                $filenameWithoutExt = basename($file, '.pdf');
                
                // Extract document number from filename
                $parts = explode(' - ', $filenameWithoutExt);
                if (count($parts) >= 2) {
                    $fileDocumento = preg_replace('/[^0-9]/', '', end($parts));
                    
                    if ($fileDocumento === $documento) {
                        $nombreConvenio = trim($parts[0]);
                        $filesToProcess[] = [
                            'documento' => $documento,
                            'filename' => $filename,
                            'ruta_archivo_pdf' => $file,
                            'nombre_convenio' => $nombreConvenio,
                        ];
                        break; // Found PDF for this document number
                    }
                }
            }
        }

        if (empty($filesToProcess)) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron archivos PDF para los números de documento proporcionados',
            ], 404);
        }

        // Rate limit: 6 emails per second = 1 email every 166.67 milliseconds
        $emailsPerSecond = 6;
        $delayMs = 1000 / $emailsPerSecond;

        // Build email map: documento => email (optional)
        // If emails array is provided, map it to document_numbers
        // It can be an associative array (document_number => email) or indexed array
        $emailMap = [];
        if (!empty($emails)) {
            // Check if emails is associative (keys are document numbers) or indexed
            $keys = array_keys($emails);
            $isAssociative = array_keys($keys) !== $keys;
            
            if ($isAssociative) {
                // Associative array: document_number => email
                $emailMap = array_filter($emails, function($email) {
                    return !empty($email);
                });
            } else {
                // Indexed array: map by position
                foreach ($documentNumbers as $index => $documentNumber) {
                    if (isset($emails[$index]) && !empty($emails[$index])) {
                        $emailMap[$documentNumber] = $emails[$index];
                    }
                }
            }
        }

        // Dispatch jobs with rate limiting
        $enqueued = 0;
        $errors = 0;

        foreach ($filesToProcess as $index => $item) {
            try {
                if (!file_exists($item['ruta_archivo_pdf'])) {
                    $errors++;
                    Log::warning('Archivo PDF no encontrado al encolar job', [
                        'archivo' => $item['filename'],
                        'ruta' => $item['ruta_archivo_pdf'],
                    ]);
                    continue;
                }

                // Calculate delay: each job should be delayed by (index * delay_ms) milliseconds
                $delaySeconds = ($index * $delayMs) / 1000;

                // Get optional email for this document if provided
                $optionalEmail = $emailMap[$item['documento']] ?? null;

                // Dispatch job with delay
                SendConvenioManualEmailJob::dispatch(
                    $item['documento'],
                    $item['filename'],
                    $item['ruta_archivo_pdf'],
                    $item['nombre_convenio'],
                    null, // parent_tracking_id
                    $optionalEmail // optional email
                )->delay(now()->addSeconds($delaySeconds));

                $enqueued++;
            } catch (\Exception $e) {
                $errors++;
                Log::error('Error al encolar job de correo de convenio manual', [
                    'archivo' => $item['filename'],
                    'documento' => $item['documento'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Bulk convenio manual emails queued', [
            'total_requested' => count($documentNumbers),
            'files_found' => count($filesToProcess),
            'enqueued' => $enqueued,
            'errors' => $errors,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'El proceso de envío masivo ha sido iniciado. Los correos se enviarán de forma asíncrona.',
            'data' => [
                'total_requested' => count($documentNumbers),
                'files_found' => count($filesToProcess),
                'enqueued' => $enqueued,
                'errors' => $errors,
                'status' => 'queued',
                'note' => 'Puedes consultar el estado de los envíos en el historial de correos.',
            ],
        ]);
    }

    /**
     * List email tracking history with filters.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function listEmailHistory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'documento' => 'nullable|string|max:50',
            'estado' => 'nullable|string|in:pendiente,enviado,fallido',
            'nombre_convenio' => 'nullable|string|max:255',
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date',
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

        $query = ConvenioEmailTracking::query();

        // Apply filters
        if ($request->has('documento')) {
            $query->byDocumento($request->input('documento'));
        }

        if ($request->has('estado')) {
            $query->byEstado($request->input('estado'));
        }

        if ($request->has('nombre_convenio')) {
            $query->byNombreConvenio($request->input('nombre_convenio'));
        }

        if ($request->has('fecha_desde') || $request->has('fecha_hasta')) {
            $fechaInicio = $request->input('fecha_desde') ?: '1970-01-01';
            $fechaFin = $request->input('fecha_hasta') ?: now()->format('Y-m-d');
            $query->byFechaRango($fechaInicio, $fechaFin);
        }

        // Order by most recent first
        $query->orderBy('created_at', 'desc');

        // Paginate
        $perPage = $request->input('per_page', 15);
        $trackings = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $trackings,
        ]);
    }

    /**
     * Resend emails to one or multiple affiliates.
     *
     * @param Request $request
     * @return JsonResponse
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
        
        // Build email map: tracking_id => email (optional)
        // If emails array is provided, map it to tracking_ids
        // It can be an associative array (tracking_id => email) or indexed array
        $emailMap = [];
        if (!empty($emails)) {
            // Check if emails is associative (keys are tracking IDs) or indexed
            $keys = array_keys($emails);
            $isAssociative = array_keys($keys) !== $keys;
            
            if ($isAssociative) {
                // Associative array: tracking_id => email
                $emailMap = array_filter($emails, function($email) {
                    return !empty($email);
                });
            } else {
                // Indexed array: map by position
                foreach ($trackingIds as $index => $trackingId) {
                    if (isset($emails[$index]) && !empty($emails[$index])) {
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

                // Verify PDF file still exists
                if (!file_exists($tracking->ruta_archivo_pdf)) {
                    $results['failed'][] = [
                        'tracking_id' => $trackingId,
                        'error' => 'Archivo PDF no encontrado: ' . $tracking->ruta_archivo_pdf,
                    ];
                    continue;
                }

                // Incrementar intentos en el tracking original
                $tracking->incrementarIntentos();

                // Get optional email for this tracking if provided
                $optionalEmail = $emailMap[$trackingId] ?? null;

                // Dispatch job to resend email with parent tracking ID
                SendConvenioManualEmailJob::dispatch(
                    $tracking->documento,
                    $tracking->nombre_archivo,
                    $tracking->ruta_archivo_pdf,
                    $tracking->nombre_convenio,
                    $trackingId, // parent_tracking_id
                    $optionalEmail // optional email
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
                    'email_provided' => !empty($optionalEmail),
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
            'data' => [
                'total' => count($trackingIds),
                'success_count' => count($results['success']),
                'failed_count' => count($results['failed']),
                'results' => $results,
            ],
        ]);
    }

    /**
     * Get email tracking statistics.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStatistics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = ConvenioEmailTracking::query();

        if ($request->has('fecha_desde') || $request->has('fecha_hasta')) {
            $fechaInicio = $request->input('fecha_desde') ?: '1970-01-01';
            $fechaFin = $request->input('fecha_hasta') ?: now()->format('Y-m-d');
            $query->byFechaRango($fechaInicio, $fechaFin);
        }

        $stats = [
            'total' => $query->count(),
            'by_status' => $query->selectRaw('estado, COUNT(*) as count')
                ->groupBy('estado')
                ->pluck('count', 'estado')
                ->toArray(),
            'sent_today' => (clone $query)->whereDate('enviado_at', today())->count(),
            'pending' => (clone $query)->where('estado', 'pendiente')->count(),
            'sent' => (clone $query)->where('estado', 'enviado')->count(),
            'failed' => (clone $query)->where('estado', 'fallido')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
