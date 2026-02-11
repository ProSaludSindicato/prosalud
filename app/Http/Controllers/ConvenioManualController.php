<?php

namespace App\Http\Controllers;

use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ConvenioManualController extends Controller
{
    public function __construct(
        private readonly ConvenioGenerationService $convenioGenerationService
    ) {
    }
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

    /**
     * Generate and optionally send convenio from frontend data.
     * 
     * This endpoint allows the frontend to send convenio data via API
     * instead of relying only on Excel files.
     *
     * @param Request $request
     * @return JsonResponse|BinaryFileResponse
     */
    public function generateAndSendConvenio(Request $request): JsonResponse|BinaryFileResponse
    {
        $validator = Validator::make($request->all(), [
            // Campos requeridos básicos
            'numero_documento' => 'required|string|max:50',
            'apellidos' => 'required|string|max:255',
            'nombres' => 'required|string|max:255',
            'fecha_nacimiento' => 'required|date',
            'lugar_nacimiento' => 'required|string|max:255',
            
            // Campos opcionales del afiliado y convenio (todos los que se usan en la plantilla Word)
            'proceso' => 'nullable|string|max:255',
            'ciudad' => 'nullable|string|max:255',
            'sede' => 'nullable|string|max:255',
            'hospital' => 'nullable|string|max:255', // Campo adicional para hospital/entidad
            'fecha_inicio' => 'nullable|date',
            'fecha_finalizacion' => 'nullable|date',
            'direccion' => 'nullable|string|max:500',
            'telefono' => 'nullable|string|max:50',
            'celular' => 'nullable|string|max:50',
            'nombre_archivo' => 'nullable|string|max:255',
            
            // Compensación básica redactada (puede venir del frontend o generarse automáticamente)
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

        try {
            Log::info('Generando convenio desde API', [
                'documento' => $request->input('numero_documento'),
                'send_email' => $request->input('send_email', false),
            ]);

            // Preparar datos para el servicio de generación (todos los campos que se usan en la plantilla Word)
            $convenioData = [
                'numero_documento' => $request->input('numero_documento'),
                'apellidos' => $request->input('apellidos'),
                'nombres' => $request->input('nombres'),
                'proceso' => $request->input('proceso'),
                'ciudad' => $request->input('ciudad'),
                'sede' => $request->input('sede'),
                'hospital' => $request->input('hospital'), // Campo adicional para hospital/entidad
                'fecha_nacimiento' => $request->input('fecha_nacimiento'),
                'lugar_nacimiento' => $request->input('lugar_nacimiento'),
                'fecha_inicio' => $request->input('fecha_inicio'),
                'fecha_finalizacion' => $request->input('fecha_finalizacion'),
                'direccion' => $request->input('direccion'),
                'telefono' => $request->input('telefono'),
                'celular' => $request->input('celular'),
                'nombre_archivo' => $request->input('nombre_archivo'),
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

            // Generar convenio Word (se guarda en resources/convenios)
            $resultado = $this->convenioGenerationService->generarConvenio($convenioData);
            
            $responseData = [
                'success' => true,
                'message' => 'Convenio generado exitosamente',
                'data' => [
                    'nombre_archivo' => $resultado['nombre'],
                    'ruta' => $resultado['ruta'],
                    'tipo' => $resultado['tipo'],
                ],
            ];

            // NOTA TEMPORAL (DESARROLLO):
            // - No se convierte a PDF (solo Word)
            // - El envío por correo queda deshabilitado hasta que se active la generación de PDF
            if ($request->boolean('send_email')) {
                $responseData['warnings'][] = 'El envío por correo está temporalmente deshabilitado mientras se completa la implementación de PDF.';
            }

            // Si se solicita descarga directa del Word, devolver el archivo
            if ($request->boolean('download')) {
                if (!file_exists($resultado['ruta'])) {
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

                return response()->download(
                    $resultado['ruta'],
                    $resultado['nombre'],
                    ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']
                );
            }

            // Caso normal: responder con JSON (metadata del archivo generado)
            return response()->json($responseData, 200);
        } catch (\Exception $e) {
            Log::error('Error generando convenio desde API', [
                'documento' => $request->input('numero_documento'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el convenio: ' . $e->getMessage(),
            ], 500);
        }
    }
}
