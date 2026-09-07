<?php

namespace App\Services;

use App\Contracts\DocxToPdfConverter;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DocxToPdfCloudConvertService implements DocxToPdfConverter
{
    private string $apiKey;

    private string $baseUrl;

    private int $timeout;

    private int $maxFileSize;

    public function __construct()
    {
        $apiKey = config('cloudconvert.api_key');

        if (empty($apiKey)) {
            Log::warning('CLOUDCONVERT_API_KEY no configurada — servicio CloudConvert no disponible (fallback deshabilitado)', [
                'config_key' => 'cloudconvert.api_key',
            ]);
            $this->apiKey = '';
            $this->baseUrl = '';
            $this->timeout = 60;
            $this->maxFileSize = 25 * 1024 * 1024;

            return;
        }

        // Limpiar espacios en blanco
        $this->apiKey = trim($apiKey);

        // Obtener URL base desde configuración (con fallback a la URL por defecto)
        $this->baseUrl = rtrim(config('cloudconvert.base_url', 'https://api.cloudconvert.com/v2'), '/');

        $this->timeout = config('cloudconvert.timeout', 60);
        $this->maxFileSize = config('cloudconvert.max_file_size', 25 * 1024 * 1024);

        Log::info('CloudConvert inicializado correctamente', LogSanitizationService::sanitize([
            'api_key_length' => strlen($this->apiKey),
            'api_key' => $this->apiKey, // Will be sanitized by the service
            'base_url' => $this->baseUrl,
        ]));
    }

    /**
     * Convierte un archivo .docx a .pdf usando CloudConvert API
     *
     * @param  string  $docxPath  Ruta local del archivo .docx
     * @param  bool  $saveToStorage  Si es true, guarda el PDF en storage y retorna la ruta. Si es false, retorna el contenido binario.
     * @return array ['path' => string, 'content' => string|null, 'size' => int] o ['content' => string, 'size' => int]
     *
     * @throws Exception
     */
    public function convert(string $docxPath, bool $saveToStorage = true, ?bool $protectPdf = null): array
    {
        // Validar que el archivo existe
        if (! file_exists($docxPath)) {
            throw new Exception("El archivo .docx no existe en: {$docxPath}");
        }

        // Validar tamaño del archivo
        $fileSize = filesize($docxPath);
        if ($fileSize === false) {
            throw new Exception("No se pudo obtener el tamaño del archivo: {$docxPath}");
        }

        if ($fileSize > $this->maxFileSize) {
            throw new Exception(
                'El archivo excede el tamaño máximo permitido. '.
                'Tamaño: '.$this->formatBytes($fileSize).', '.
                'Máximo: '.$this->formatBytes($this->maxFileSize)
            );
        }

        // Validar extensión
        $extension = strtolower(pathinfo($docxPath, PATHINFO_EXTENSION));
        if ($extension !== 'docx') {
            throw new Exception("El archivo debe ser .docx, se recibió: .{$extension}");
        }

        try {
            Log::info('Iniciando conversión DOCX a PDF con CloudConvert', [
                'archivo' => $docxPath,
                'tamaño' => $this->formatBytes($fileSize),
            ]);

            // 1. Crear Job con tareas
            $job = $this->createJob($protectPdf);

            // 2. Subir archivo
            $this->uploadFile($job['id'], $docxPath);

            // 3. Esperar a que el Job se complete
            $this->waitForJobCompletion($job['id']);

            // 4. Obtener el PDF convertido
            $pdfContent = $this->downloadConvertedPdf($job['id']);

            if ($saveToStorage) {
                // Guardar en storage
                $pdfPath = $this->savePdfToStorage($pdfContent, $docxPath);

                Log::info('PDF guardado exitosamente en storage', [
                    'ruta' => $pdfPath,
                    'tamaño' => strlen($pdfContent),
                ]);

                return [
                    'path' => $pdfPath,
                    'content' => null,
                    'size' => strlen($pdfContent),
                ];
            } else {
                // Retornar contenido binario
                return [
                    'content' => $pdfContent,
                    'size' => strlen($pdfContent),
                ];
            }
        } catch (Exception $e) {
            Log::error('Error en conversión DOCX a PDF con CloudConvert', [
                'archivo' => $docxPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception('Error al convertir DOCX a PDF: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Crea un Job en CloudConvert con las tareas necesarias
     *
     * @return array Datos del Job creado
     *
     * @throws Exception
     */
    private function createJob(?bool $protectPdf = null): array
    {
        try {
            // Obtener configuración de seguridad PDF
            $pdfSecurity = config('cloudconvert.pdf_security', []);
            $securityEnabled = $protectPdf ?? (bool) ($pdfSecurity['enabled'] ?? false);

            // Configurar tarea de conversión
            $convertTask = [
                'operation' => 'convert',
                'input' => 'upload-file',
                'input_format' => 'docx',
                'output_format' => 'pdf',
            ];

            // Agregar opciones de seguridad si están habilitadas
            if ($securityEnabled) {
                $ownerPassword = $pdfSecurity['owner_password'] ?? null;

                // Requiere encryption: "encrypt" para activar la encriptación
                // Nota: Según la documentación de CloudConvert, set_owner_password es opcional
                // pero se recomienda para establecer permisos de forma segura
                $convertTask['encryption'] = 'encrypt';

                if ($ownerPassword) {
                    // Usar set_owner_password según la documentación
                    $convertTask['set_owner_password'] = $ownerPassword;
                } else {
                    Log::warning('PDF Security habilitado pero CLOUDCONVERT_PDF_OWNER_PASSWORD no está configurado. Los permisos pueden no aplicarse correctamente.');
                }

                // Mapear permisos según la documentación de CloudConvert
                $permissions = $pdfSecurity['permissions'] ?? [];

                // allow_print: enum - "full", "low", "none"
                if (isset($permissions['allow_print'])) {
                    $convertTask['allow_print'] = $permissions['allow_print'];
                }

                // allow_extract: boolean
                if (isset($permissions['allow_extract'])) {
                    $convertTask['allow_extract'] = (bool) $permissions['allow_extract'];
                }

                // allow_modify: enum - "all", "annotate", "form", "assembly", "none"
                if (isset($permissions['allow_modify'])) {
                    $convertTask['allow_modify'] = $permissions['allow_modify'];
                }

                // allow_accessibility: boolean
                if (isset($permissions['allow_accessibility'])) {
                    $convertTask['allow_accessibility'] = (bool) $permissions['allow_accessibility'];
                }

                Log::info('Opciones de seguridad PDF aplicadas', [
                    'encryption' => 'encrypt',
                    'set_owner_password_set' => ! empty($ownerPassword),
                    'allow_print' => $permissions['allow_print'] ?? null,
                    'allow_extract' => $permissions['allow_extract'] ?? null,
                    'allow_modify' => $permissions['allow_modify'] ?? null,
                    'allow_accessibility' => $permissions['allow_accessibility'] ?? null,
                ]);
            }

            // Formato correcto: objeto con nombres de tareas como claves
            $payload = [
                'tasks' => [
                    'upload-file' => [
                        'operation' => 'import/upload',
                    ],
                    'convert-docx-to-pdf' => $convertTask,
                    'export-pdf' => [
                        'operation' => 'export/url',
                        'input' => 'convert-docx-to-pdf',
                    ],
                ],
            ];

            Log::info('Creando Job en CloudConvert', [
                'base_url' => $this->baseUrl,
                'pdf_security_enabled' => $securityEnabled,
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->post($this->baseUrl.'/jobs', $payload);

            if (! $response->successful()) {
                $errorBody = $response->json();
                $errorMessage = $errorBody['message'] ?? 'Error desconocido';
                $errorCode = $errorBody['code'] ?? null;

                Log::error('Error al crear Job en CloudConvert', [
                    'status' => $response->status(),
                    'error_message' => $errorMessage,
                    'error_code' => $errorCode,
                    'response_body' => $errorBody,
                ]);

                if ($response->status() === 401) {
                    throw new Exception(
                        'Error de autenticación con CloudConvert. '.
                        'Verifica que tu API key sea correcta y esté activa. '.
                        'Obtén tu API key desde: https://cloudconvert.com/dashboard/api-keys',
                        401
                    );
                }

                throw new Exception("Error al crear Job en CloudConvert: {$errorMessage}");
            }

            $jobData = $response->json('data');

            Log::info('Job creado exitosamente en CloudConvert', [
                'job_id' => $jobData['id'] ?? null,
            ]);

            return $jobData;
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                throw $e;
            }
            throw new Exception('Error al crear Job en CloudConvert: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Sube el archivo DOCX a CloudConvert
     *
     * @param  string  $jobId  ID del Job
     * @param  string  $docxPath  Ruta del archivo
     *
     * @throws Exception
     */
    private function uploadFile(string $jobId, string $docxPath): void
    {
        try {
            // Primero obtener el Job para encontrar la tarea de upload
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])
                ->timeout($this->timeout)
                ->get($this->baseUrl.'/jobs/'.$jobId);

            if (! $response->successful()) {
                throw new Exception('Error al obtener Job: '.$response->body());
            }

            $jobData = $response->json('data');
            $tasks = $jobData['tasks'] ?? [];

            // Las tareas pueden venir como objeto (clave=nombre) o como array
            // Buscar la tarea de upload
            $uploadTask = null;

            // Si es un objeto (clave=nombre de tarea)
            if (isset($tasks['upload-file'])) {
                $uploadTask = $tasks['upload-file'];
            } else {
                // Si es un array, buscar por nombre
                foreach ($tasks as $task) {
                    $taskName = is_array($task) ? ($task['name'] ?? null) : null;
                    if ($taskName === 'upload-file' && ($task['operation'] ?? null) === 'import/upload') {
                        $uploadTask = $task;
                        break;
                    }
                }
            }

            if (! $uploadTask) {
                throw new Exception('No se encontró la tarea de upload en el Job');
            }

            $formData = $uploadTask['result']['form'] ?? null;
            if (! $formData || ! isset($formData['url'])) {
                throw new Exception('No se encontró la información de upload en la tarea');
            }

            $uploadUrl = $formData['url'];
            $parameters = $formData['parameters'] ?? [];

            Log::info('Subiendo archivo a CloudConvert', [
                'filename' => basename($docxPath),
                'upload_url' => $uploadUrl,
            ]);

            // Construir el multipart form data con los parámetros requeridos
            $httpClient = Http::asMultipart();

            // Agregar los parámetros del formulario
            foreach ($parameters as $key => $value) {
                $httpClient = $httpClient->attach($key, $value);
            }

            // Agregar el archivo
            $httpClient = $httpClient->attach('file', file_get_contents($docxPath), basename($docxPath));

            // Subir el archivo usando multipart/form-data
            $uploadResponse = $httpClient->post($uploadUrl);

            if (! $uploadResponse->successful()) {
                throw new Exception('Error al subir archivo: '.$uploadResponse->body());
            }

            Log::info('Archivo subido exitosamente a CloudConvert');
        } catch (Exception $e) {
            throw new Exception('Error al subir archivo a CloudConvert: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Espera a que el Job se complete
     *
     * @throws Exception
     */
    private function waitForJobCompletion(string $jobId): void
    {
        $startTime = time();
        $maxWaitTime = $this->timeout;

        while (true) {
            // Verificar timeout
            if (time() - $startTime > $maxWaitTime) {
                throw new Exception("Timeout esperando la conversión. Tiempo máximo: {$maxWaitTime}s");
            }

            // Obtener estado actual del Job
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])
                ->timeout(10)
                ->get($this->baseUrl.'/jobs/'.$jobId);

            if (! $response->successful()) {
                throw new Exception('Error al obtener estado del Job: '.$response->body());
            }

            $jobData = $response->json('data');
            $status = $jobData['status'] ?? 'unknown';

            Log::debug('Estado del Job en CloudConvert', [
                'job_id' => $jobId,
                'status' => $status,
                'message' => $jobData['message'] ?? null,
            ]);

            if ($status === 'finished') {
                Log::info('Job de conversión completado exitosamente', [
                    'job_id' => $jobId,
                ]);

                return;
            }

            if ($status === 'error') {
                $errorMessage = $jobData['message'] ?? 'Error desconocido en CloudConvert';
                $errorCode = $jobData['code'] ?? null;

                // Obtener información de errores de las tareas
                $tasks = $jobData['tasks'] ?? [];
                $taskErrors = [];
                $sandboxError = false;

                // Las tareas pueden venir como objeto o array
                if (is_array($tasks) && ! empty($tasks)) {
                    // Si es un objeto asociativo (clave = nombre de tarea)
                    if (isset($tasks['upload-file']) || isset($tasks['convert-docx-to-pdf']) || isset($tasks['export-pdf'])) {
                        foreach ($tasks as $taskName => $task) {
                            if (is_array($task) && isset($task['status']) && $task['status'] === 'error') {
                                $taskErrors[$taskName] = [
                                    'message' => $task['message'] ?? 'Error desconocido',
                                    'code' => $task['code'] ?? null,
                                    'operation' => $task['operation'] ?? null,
                                ];

                                // Detectar error de Sandbox
                                if ($task['code'] === 'SANDBOX_FILE_NOT_ALLOWED') {
                                    $sandboxError = true;
                                }
                            }
                        }
                    } else {
                        // Si es un array indexado
                        foreach ($tasks as $index => $task) {
                            if (is_array($task) && isset($task['status']) && $task['status'] === 'error') {
                                $taskName = $task['name'] ?? "task-{$index}";
                                $taskErrors[$taskName] = [
                                    'message' => $task['message'] ?? 'Error desconocido',
                                    'code' => $task['code'] ?? null,
                                    'operation' => $task['operation'] ?? null,
                                ];

                                // Detectar error de Sandbox
                                if ($task['code'] === 'SANDBOX_FILE_NOT_ALLOWED') {
                                    $sandboxError = true;
                                }
                            }
                        }
                    }
                }

                Log::error('Error en Job de CloudConvert', [
                    'job_id' => $jobId,
                    'error_message' => $errorMessage,
                    'error_code' => $errorCode,
                    'task_errors' => $taskErrors,
                    'job_data' => $jobData,
                ]);

                // Mensaje especial para errores de Sandbox
                if ($sandboxError) {
                    $message = 'El archivo no está permitido en la API de Sandbox. ';
                    $message .= 'Para usar archivos reales, cambia CLOUDCONVERT_BASE_URL a la API de producción: ';
                    $message .= 'https://api.cloudconvert.com/v2';
                    throw new Exception($message);
                }

                $detailedError = $errorMessage;
                if (! empty($taskErrors)) {
                    $detailedError .= ' | Errores en tareas: '.json_encode($taskErrors);
                }

                throw new Exception("Error en CloudConvert: {$detailedError}");
            }

            // Esperar antes de verificar nuevamente
            sleep(2);
        }
    }

    /**
     * Descarga el PDF convertido desde CloudConvert
     *
     * @return string Contenido binario del PDF
     *
     * @throws Exception
     */
    private function downloadConvertedPdf(string $jobId): string
    {
        try {
            // Obtener el Job para encontrar la tarea de export
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])
                ->timeout(10)
                ->get($this->baseUrl.'/jobs/'.$jobId);

            if (! $response->successful()) {
                throw new Exception('Error al obtener Job: '.$response->body());
            }

            $jobData = $response->json('data');
            $tasks = $jobData['tasks'] ?? [];

            // Las tareas pueden venir como objeto (clave=nombre) o como array
            // Buscar la tarea de export
            $exportTask = null;

            // Si es un objeto (clave=nombre de tarea)
            if (isset($tasks['export-pdf'])) {
                $exportTask = $tasks['export-pdf'];
            } else {
                // Si es un array, buscar por nombre
                foreach ($tasks as $task) {
                    $taskName = is_array($task) ? ($task['name'] ?? null) : null;
                    if ($taskName === 'export-pdf' && ($task['operation'] ?? null) === 'export/url') {
                        $exportTask = $task;
                        break;
                    }
                }
            }

            if (! $exportTask) {
                throw new Exception('No se encontró la tarea de exportación en el Job');
            }

            $result = $exportTask['result'] ?? null;
            if (! $result || ! isset($result['files']) || empty($result['files'])) {
                throw new Exception('No se encontraron archivos en el resultado de exportación');
            }

            $pdfUrl = $result['files'][0]['url'] ?? null;
            if (! $pdfUrl) {
                throw new Exception('No se encontró la URL del PDF en el resultado');
            }

            Log::info('Descargando PDF desde CloudConvert', [
                'url' => $pdfUrl,
            ]);

            // Descargar el PDF
            $pdfResponse = Http::timeout(30)->get($pdfUrl);

            if (! $pdfResponse->successful()) {
                throw new Exception('No se pudo descargar el PDF desde CloudConvert: '.$pdfResponse->body());
            }

            $pdfContent = $pdfResponse->body();

            // Validar que sea un PDF válido
            if (substr($pdfContent, 0, 4) !== '%PDF') {
                throw new Exception('El archivo descargado no es un PDF válido');
            }

            return $pdfContent;
        } catch (Exception $e) {
            throw new Exception('Error al descargar PDF desde CloudConvert: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Guarda el PDF en storage
     *
     * @return string Ruta relativa del PDF guardado
     *
     * @throws Exception
     */
    private function savePdfToStorage(string $pdfContent, string $originalDocxPath): string
    {
        try {
            // Crear directorio temporal si no existe
            $tempDir = config('cloudconvert.temp_storage_path', storage_path('app/tmp'));
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // Usar el nombre del archivo original (sin extensión) y cambiar a .pdf
            // Esto mantiene la misma fecha que se usó en el nombre del Word
            $originalName = pathinfo($originalDocxPath, PATHINFO_FILENAME);
            $pdfFileName = $originalName.'.pdf';
            $pdfPath = $tempDir.'/'.$pdfFileName;

            // Guardar PDF
            file_put_contents($pdfPath, $pdfContent);

            // Retornar ruta relativa desde storage/app
            return 'tmp/'.$pdfFileName;
        } catch (Exception $e) {
            throw new Exception('Error al guardar PDF en storage: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Formatea bytes a formato legible
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2).' '.$units[$pow];
    }

    public function isAvailable(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Obtiene la instancia de CloudConvert (para pruebas - ahora retorna null ya que no usamos SDK)
     *
     * @return null
     */
    public function getCloudConvertInstance()
    {
        return null;
    }
}
