<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateConvenioJob;
use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioExcelTemplateExportService;
use App\Services\ConvenioGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\{IOFactory, Cell\Coordinate};

class ConvenioManualController extends Controller
{
    public function __construct(
        private readonly ConvenioGenerationService $convenioGenerationService,
        private readonly ConvenioExcelTemplateExportService $templateExportService
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
        $tieneCompensacionRedactada = !empty(trim($request->input('compensacion_basica_redactada', '')));
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
        
        if (!$tieneCompensacionRedactada && !$tieneValoresIndividuales) {
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

                // Generar convenio Word (se guarda en resources/templates/convenios)
                $resultado = $this->convenioGenerationService->generarConvenio($convenioData);
                
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
                )->deleteFileAfterSend(false);
            }

            // Si NO se solicita descarga, procesar de forma asíncrona (job)
            Log::info('[CONVENIO API] Enviando generación a cola de trabajos (asíncrono)', [
                'documento' => $request->input('numero_documento'),
            ]);

            GenerateConvenioJob::dispatch(
                $convenioData,
                $request->input('email'),
                $sendEmail
            );

            return response()->json([
                'success' => true,
                'message' => 'La generación del convenio ha sido encolada y se procesará de forma asíncrona. El archivo estará disponible en breve.',
                'data' => [
                    'documento' => $request->input('numero_documento'),
                    'procesando' => true,
                    'modo' => 'asíncrono',
                ],
                'warnings' => $sendEmail ? ['El envío por correo está temporalmente deshabilitado durante la fase de desarrollo y pruebas.'] : [],
            ], 202); // 202 Accepted - request accepted for processing

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

        // Directorio donde se guardan los convenios generados
        $outputDir = base_path('resources/templates/convenios');

        if (!is_dir($outputDir)) {
            Log::warning('[CONVENIO API] Directorio de convenios no existe al intentar descargar', [
                'output_dir' => $outputDir,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'El directorio de convenios no existe en el servidor.',
            ], 500);
        }

        // Buscar el archivo más reciente para ese documento
        $pattern = sprintf('%s/Convenio_%s_*.docx', $outputDir, $numeroDocumentoNormalizado);
        $files = glob($pattern);

        if (empty($files)) {
            Log::info('[CONVENIO API] Convenio no encontrado aún para descarga', [
                'numero_documento_normalizado' => $numeroDocumentoNormalizado,
                'pattern' => $pattern,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se encontró un convenio generado para el documento especificado. Es posible que aún esté en proceso de generación.',
            ], 404);
        }

        // Ordenar por fecha de modificación (más reciente primero)
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $filePath = $files[0];
        $fileName = basename($filePath);

        if (!file_exists($filePath)) {
            Log::error('[CONVENIO API] Archivo de convenio no encontrado al intentar descargar', [
                'file_path' => $filePath,
                'numero_documento_normalizado' => $numeroDocumentoNormalizado,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'El archivo del convenio no se encontró en el servidor.',
            ], 500);
        }

        Log::info('[CONVENIO API] Descargando convenio generado', [
            'numero_documento_normalizado' => $numeroDocumentoNormalizado,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'tamaño_bytes' => filesize($filePath),
        ]);

        return response()->download(
            $filePath,
            $fileName,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']
        )->deleteFileAfterSend(false);
    }

    /**
     * Exporta una plantilla Excel para importación masiva de convenios
     *
     * @return BinaryFileResponse
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
                'message' => 'Error al generar la plantilla: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Importa convenios desde un archivo Excel y los genera masivamente
     * Opcionalmente envía correos electrónicos
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function importAndGenerateBulk(Request $request): JsonResponse
    {
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

        $validator = Validator::make($requestData, [
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // Max 10MB
            'send_email' => 'nullable|boolean',
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
        $sendEmail = $request->boolean('send_email', false);
        
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

            // Guardar archivo temporalmente
            Log::debug('[CONVENIO API] Guardando archivo temporalmente', [
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ]);
            
            // Asegurar que el directorio temp existe
            $tempDir = storage_path('app/temp');
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
                Log::info('[CONVENIO API] Directorio temp creado', ['path' => $tempDir]);
            }
            
            // Usar storeAs de Laravel que es más confiable
            try {
                $tempPath = $file->storeAs('temp', 'convenio_import_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension());
                $fullTempPath = storage_path('app/' . $tempPath);
                
                Log::info('[CONVENIO API] Archivo guardado temporalmente', [
                    'temp_path' => $tempPath,
                    'full_path' => $fullTempPath,
                    'exists' => file_exists($fullTempPath),
                    'readable' => is_readable($fullTempPath),
                    'size' => file_exists($fullTempPath) ? filesize($fullTempPath) : 0,
                ]);
                
                if (!file_exists($fullTempPath)) {
                    Log::error('[CONVENIO API] El archivo no se guardó correctamente', [
                        'temp_path' => $tempPath,
                        'full_path' => $fullTempPath,
                        'temp_dir_exists' => file_exists($tempDir),
                        'temp_dir_writable' => is_writable($tempDir),
                        'storage_app_exists' => file_exists(storage_path('app')),
                        'storage_app_writable' => is_writable(storage_path('app')),
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Error al guardar el archivo temporalmente. Verifique los permisos del directorio storage/app/temp',
                    ], 500);
                }
            } catch (\Exception $e) {
                Log::error('[CONVENIO API] Error al guardar archivo temporal', [
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Error al guardar el archivo: ' . $e->getMessage(),
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
                \Storage::delete($tempPath);
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
                \Storage::delete($tempPath);
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
                if (!isset($columnMapping[$col])) {
                    $missingColumns[] = $col;
                }
            }

            if (!empty($missingColumns)) {
                Log::error('[CONVENIO API] Columnas requeridas no encontradas', [
                    'missing_columns' => $missingColumns,
                    'available_columns' => array_keys($columnMapping),
                ]);
                \Storage::delete($tempPath);
                return response()->json([
                    'success' => false,
                    'message' => 'Columnas requeridas no encontradas: ' . implode(', ', $missingColumns),
                    'missing_columns' => $missingColumns,
                ], 422);
            }

            Log::info('[CONVENIO API] Todas las columnas requeridas están presentes');

            // Procesar filas
            $highestRow = $sheet->getHighestRow();
            Log::info('[CONVENIO API] Iniciando procesamiento de filas', [
                'total_filas' => $highestRow,
                'filas_a_procesar' => $highestRow - 1, // Excluyendo encabezado
            ]);
            
            $procesados = 0;
            $exitosos = 0;
            $errores = 0;
            $filasVacias = 0;
            $errors = [];

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
                        'tiene_datos' => !empty($rowData['numero_documento']) || !empty($rowData['apellidos']),
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
                    
                    if (!empty($camposFaltantes)) {
                        $errores++;
                        $errors[] = "Fila {$rowIndex}: Faltan campos requeridos: " . implode(', ', $camposFaltantes);
                        continue;
                    }

                    // Validar compensación: debe haber compensacion_basica_redactada O valores individuales, pero no ambos
                    $tieneCompensacionRedactada = !empty(trim($rowData['compensacion_basica_redactada'] ?? ''));
                    $tieneValoresIndividuales = $this->tieneValoresCompensacionIndividualesEnArray($rowData);
                    
                    if ($tieneCompensacionRedactada && $tieneValoresIndividuales) {
                        $errores++;
                        $errors[] = "Fila {$rowIndex}: No se puede proporcionar 'Compensacion Basica Redactada' junto con valores individuales de compensación. Debe usar uno u otro.";
                        continue;
                    }
                    
                    if (!$tieneCompensacionRedactada && !$tieneValoresIndividuales) {
                        $errores++;
                        $errors[] = "Fila {$rowIndex}: Debe proporcionar 'Compensacion Basica Redactada' o al menos un valor individual de compensación (basico, auxilios, valor_hora_diurna, etc.)";
                        continue;
                    }

                    // Preparar datos para generación (eliminar hospital y nombre_archivo si existen)
                    $convenioData = $rowData;
                    unset($convenioData['hospital'], $convenioData['nombre_archivo']);
                    
                    $email = $rowData['email'] ?? null;
                    // send_email ya es booleano después de readRowDataFromExcel, pero asegurarnos
                    $rowSendEmail = isset($rowData['send_email']) 
                        ? (is_bool($rowData['send_email']) ? $rowData['send_email'] : (bool) $rowData['send_email'])
                        : $sendEmail;

                    Log::debug('[CONVENIO API] Preparando job para fila', [
                        'fila' => $rowIndex,
                        'documento' => $convenioData['numero_documento'] ?? 'N/A',
                        'email' => $email,
                        'send_email' => $rowSendEmail,
                    ]);

                    // Encolar job para generar convenio
                    GenerateConvenioJob::dispatch($convenioData, $email, $rowSendEmail);
                    $exitosos++;
                    
                    Log::debug('[CONVENIO API] Job encolado exitosamente', [
                        'fila' => $rowIndex,
                        'documento' => $convenioData['numero_documento'] ?? 'N/A',
                    ]);

                } catch (\Exception $e) {
                    $errores++;
                    $errors[] = "Fila {$rowIndex}: " . $e->getMessage();
                    Log::error('[CONVENIO API] Error procesando fila en importación masiva', [
                        'fila' => $rowIndex,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Limpiar archivo temporal
            \Storage::delete($tempPath);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            Log::info('[CONVENIO API] Importación masiva completada', [
                'procesados' => $procesados,
                'exitosos' => $exitosos,
                'errores' => $errores,
                'filas_vacias' => $filasVacias,
                'send_email' => $sendEmail,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'La importación masiva ha sido procesada. Los convenios se generarán de forma asíncrona.',
                'data' => [
                    'procesados' => $procesados,
                    'exitosos' => $exitosos,
                    'errores' => $errores,
                    'filas_vacias' => $filasVacias,
                    'send_email' => $sendEmail,
                    'errors' => $errors,
                ],
            ], 202);

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
                    \Storage::delete($tempPath);
                } catch (\Exception $cleanupException) {
                    Log::warning('[CONVENIO API] Error limpiando archivo temporal', [
                        'error' => $cleanupException->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo Excel: ' . $e->getMessage(),
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
            'send_email' => ['enviar email', 'send_email', 'enviar_email'],
        ];

        // Leer fila de encabezados (fila 1)
        for ($colIndex = 1; $colIndex <= $highestColumnIndex; $colIndex++) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            $cell = $sheet->getCell($colLetter . '1');
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
            $cell = $sheet->getCell($colLetter . $rowIndex);
            $value = trim($cell->getValue() ?? '');

            // Procesar fechas si es necesario
            if (in_array($internalName, ['fecha_inicio', 'fecha_finalizacion', 'fecha_nacimiento'])) {
                $value = $this->normalizeDate($value);
            }

            // Procesar booleanos (acepta "Si"/"No" en español o "true"/"false" en inglés)
            // Por defecto es "Si" (true) si está vacío o no se puede determinar
            // IMPORTANTE: El valor final siempre debe ser booleano (true/false), no string
            if ($internalName === 'send_email') {
                // Si ya es booleano, mantenerlo
                if (is_bool($value)) {
                    // Ya es booleano, no hacer nada
                } else {
                    // Convertir string a booleano
                    $valueStr = is_string($value) ? trim($value) : (string) $value;
                    $valueNormalizado = mb_strtolower($valueStr, 'UTF-8');
                    
                    if ($valueNormalizado === 'no' || $valueNormalizado === 'false' || $valueNormalizado === '0') {
                        $value = false; // Booleano false
                    } elseif ($valueNormalizado === 'si' || $valueNormalizado === 'sí' || $valueNormalizado === 'true' || $valueNormalizado === '1' || $valueNormalizado === 'yes') {
                        $value = true; // Booleano true
                    } else {
                        // Por defecto "Si" (true) si está vacío o no se puede determinar
                        $value = true; // Booleano true
                    }
                }
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
            if (!empty($valor) && $valor !== '0' && $valor !== 0) {
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
            if (!empty($valor) && $valor !== '0' && $valor !== 0 && trim($valor) !== '') {
                return true;
            }
        }

        return false;
    }
}
