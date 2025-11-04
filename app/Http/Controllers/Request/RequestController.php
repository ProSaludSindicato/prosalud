<?php

namespace App\Http\Controllers\Request;

use App\Constants\RequestStatuses;
use App\Domain\RequestForm\RequestFormDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\RespondToRequestRequest;
use App\Models\RequestForm;
use App\Models\RequestResponse;
use App\Mail\RequestFormReceived;
use App\Mail\RequestFormResponse;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RequestController extends Controller
{
    public function __construct(
        private AuditLogService $auditLogService
    ) {}
    public function store(StoreRequestFormRequest $request): JsonResponse
    {
        $dto = RequestFormDTO::fromArray($request->validated());
        $requestData = $dto->toArray();

        $requestData['status'] = RequestStatuses::PENDING;

        $originalFilesForEmail = $this->extractOriginalFiles($request);

        $filesMetadata = $this->processAndStoreFiles($request);

        if (!empty($filesMetadata)) {
            $requestData['files'] = $filesMetadata;
        }

        $requestForm = new RequestForm($requestData);
        $requestForm->created_at = now();
        $requestForm->save();

        try {
            Mail::to($requestForm->email)
                ->send(new RequestFormReceived($requestForm, $originalFilesForEmail));
        } catch (\Throwable $e) {
            Log::error('Error enviando correo de confirmación de solicitud', [
                'request_id' => $requestForm->id,
                'email' => $requestForm->email,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Nueva solicitud procesada', [
            'request_id' => $requestForm->id,
            'request_type' => $requestForm->request_type,
            'affiliate_info' => [
                'document_type' => $requestForm->document_type,
                'document_number' => $requestForm->document_number,
                'full_name' => $requestForm->full_name,
                'email' => $requestForm->email,
                'phone_number' => $requestForm->phone_number,
            ],
            'timestamp' => $requestForm->formatted_created_at,
            'status' => $requestForm->status,
            'payload' => $this->getPayloadSummary($requestForm->payload),
            'files_count' => is_array($requestForm->files) ? count($requestForm->files) : 0,
            'files_keys' => is_array($requestForm->files) ? array_keys($requestForm->files) : [],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $this->auditLogService->logBusinessProcess('request_form', 'created', $this->auditLogService->addRequestContext($request, [
            'request_id' => $requestForm->id,
            'request_type' => $requestForm->request_type,
            'affiliate_document' => $requestForm->document_number,
            'affiliate_email' => $requestForm->email,
        ]));

        $response = [
            'success' => true,
            'message' => 'Solicitud recibida exitosamente',
            'data' => [
                'id' => $requestForm->id,
                'request_type' => $requestForm->request_type,
                'status' => $requestForm->status,
                'created_at' => $requestForm->formatted_created_at,
                'files' => $this->formatFilesMetadata($requestForm->files, $requestForm->id),
                'files_count' => is_array($requestForm->files) ? count($requestForm->files) : 0,
            ]
        ];

        // For 'actualizar-datos-personales' requests, also include 'request' key for backward compatibility
        if ($requestForm->request_type === 'actualizar-datos-personales') {
            $response['request'] = [
                'id' => $requestForm->id,
                'request_type' => $requestForm->request_type,
                'status' => $requestForm->status,
                'created_at' => $requestForm->created_at->toIso8601String(),
            ];
            $response['message'] = 'Solicitud de actualización de datos personales recibida correctamente';
        }

        return response()->json($response, 201);
    }

    /**
     * Extract original files from request for email attachment
     * Only extracts multipart files, not base64 (which can't be attached)
     * Supports both files[certificacionBancaria] (FormData) and files.certificacionBancaria notation
     */
    private function extractOriginalFiles(Request $request): array
    {
        $originalFiles = [];
        $allFiles = $request->allFiles();

        foreach ($allFiles as $key => $file) {
            // Handle nested files array (files[certificacionBancaria] from FormData)
            if (is_array($file)) {
                foreach ($file as $singleFile) {
                    if ($singleFile instanceof \Illuminate\Http\UploadedFile && $singleFile->isValid()) {
                        $originalFiles[] = $singleFile;
                    }
                }
            }
            // Handle files with dot notation (files.certificacionBancaria)
            elseif (strpos($key, 'files.') === 0) {
                if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                    $originalFiles[] = $file;
                }
            }
            // Handle single file upload
            elseif ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                $originalFiles[] = $file;
            }
        }

        return $originalFiles;
    }

    /**
     * Process and store files to private bucket
     * Handles files from JSON array or multipart form-data
     * Supports both files[certificacionBancaria] (FormData) and files.certificacionBancaria notation
     */
    private function processAndStoreFiles(Request $request): array
    {
        $disk = 'prosalud-private';
        $fallbackDisk = 'local';
        $filesMetadata = [];

        $allFiles = $request->allFiles();

        foreach ($allFiles as $key => $file) {
            if (!is_array($file) && !($file instanceof \Illuminate\Http\UploadedFile)) {
                continue;
            }

            // Handle nested files array (files[certificacionBancaria] from FormData)
            if (is_array($file)) {
                foreach ($file as $fileKey => $singleFile) {
                    if ($singleFile instanceof \Illuminate\Http\UploadedFile && $singleFile->isValid()) {
                        $metadata = $this->storeUploadedFile($singleFile, $fileKey, $disk, $fallbackDisk);
                        if ($metadata) {
                            $filesMetadata[$fileKey] = $metadata;
                        }
                    }
                }
            }
            // Handle files with dot notation (files.certificacionBancaria)
            elseif ($key === 'files' && $file instanceof \Illuminate\Http\UploadedFile) {
                // This shouldn't happen, but handle it just in case
                $metadata = $this->storeUploadedFile($file, $key, $disk, $fallbackDisk);
                if ($metadata) {
                    $filesMetadata[$key] = $metadata;
                }
            }
            // Handle direct file keys (certificacionBancaria directly)
            elseif ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                $metadata = $this->storeUploadedFile($file, $key, $disk, $fallbackDisk);
                if ($metadata) {
                    $filesMetadata[$key] = $metadata;
                }
            }
        }

        // Also check for files with dot notation (files.certificacionBancaria)
        // Laravel converts files[certificacionBancaria] to files.certificacionBancaria
        $dotNotationFiles = [];
        foreach ($allFiles as $key => $value) {
            if (strpos($key, 'files.') === 0) {
                $fileKey = substr($key, 6); // Remove 'files.' prefix
                if ($value instanceof \Illuminate\Http\UploadedFile && $value->isValid()) {
                    $dotNotationFiles[$fileKey] = $value;
                }
            }
        }
        
        // Process dot notation files
        foreach ($dotNotationFiles as $fileKey => $file) {
            if (!isset($filesMetadata[$fileKey])) {
                $metadata = $this->storeUploadedFile($file, $fileKey, $disk, $fallbackDisk);
                if ($metadata) {
                    $filesMetadata[$fileKey] = $metadata;
                }
            }
        }

        // Then, handle files from JSON array (base64 encoded)
        $jsonFiles = $request->input('files', []);
        if (is_array($jsonFiles) && !empty($jsonFiles)) {
            foreach ($jsonFiles as $key => $fileData) {
                // Skip if we already processed this file from multipart
                if (isset($filesMetadata[$key])) {
                    continue;
                }

                try {
                // Handle base64 encoded files from API
                if (is_string($fileData) && preg_match('/^data:([a-zA-Z0-9\/]+);base64,/', $fileData, $matches)) {
                    $mimeType = $matches[1];
                    $base64Data = substr($fileData, strpos($fileData, ',') + 1);
                    $fileContent = base64_decode($base64Data, true);

                    if ($fileContent === false) {
                        Log::warning('Failed to decode base64 file', [
                            'key' => $key,
                            'mime_type' => $mimeType,
                        ]);
                        continue;
                    }

                    // Determine file extension from mime type
                    $extension = $this->getExtensionFromMimeType($mimeType);
                    $filename = Str::uuid() . '.' . $extension;
                    $storagePath = 'request-forms/' . date('Y/m') . '/' . $filename;

                    // Store file
                    $stored = Storage::disk($disk)->put($storagePath, $fileContent);

                    if ($stored === false) {
                        Log::warning('Failed to store file in private bucket, trying fallback', [
                            'key' => $key,
                            'path' => $storagePath,
                        ]);
                        $stored = Storage::disk($fallbackDisk)->put($storagePath, $fileContent);
                        if ($stored) {
                            $disk = $fallbackDisk;
                        }
                    }

                    if ($stored) {
                        $filesMetadata[$key] = [
                            'path' => $storagePath,
                            'disk' => $disk,
                            'mime_type' => $mimeType,
                            'size' => strlen($fileContent),
                            'original_key' => $key,
                        ];
                    }
                }
                // Handle file upload objects
                elseif (is_array($fileData) && isset($fileData['name']) && isset($fileData['content'])) {
                    // File data structure: {name: string, content: base64 string, mime_type?: string}
                    $fileName = $fileData['name'];
                    $content = $fileData['content'];
                    $mimeType = $fileData['mime_type'] ?? 'application/octet-stream';

                    // If content is base64, decode it
                    if (preg_match('/^data:([a-zA-Z0-9\/]+);base64,/', $content, $matches)) {
                        $mimeType = $matches[1];
                        $base64Data = substr($content, strpos($content, ',') + 1);
                        $fileContent = base64_decode($base64Data, true);
                    } else {
                        // Assume it's already base64 without prefix
                        $fileContent = base64_decode($content, true);
                    }

                    if ($fileContent === false) {
                        Log::warning('Failed to decode file content', ['key' => $key]);
                        continue;
                    }

                    $extension = pathinfo($fileName, PATHINFO_EXTENSION) ?: $this->getExtensionFromMimeType($mimeType);
                    $filename = Str::uuid() . ($extension ? '.' . $extension : '');
                    $storagePath = 'request-forms/' . date('Y/m') . '/' . $filename;

                    // Store file
                    $stored = Storage::disk($disk)->put($storagePath, $fileContent);

                    if ($stored === false) {
                        Log::warning('Failed to store file in private bucket, trying fallback', [
                            'key' => $key,
                            'path' => $storagePath,
                        ]);
                        $stored = Storage::disk($fallbackDisk)->put($storagePath, $fileContent);
                        if ($stored) {
                            $disk = $fallbackDisk;
                        }
                    }

                    if ($stored) {
                        $filesMetadata[$key] = [
                            'path' => $storagePath,
                            'disk' => $disk,
                            'original_name' => $fileName,
                            'mime_type' => $mimeType,
                            'size' => strlen($fileContent),
                            'original_key' => $key,
                        ];
                    }
                }
                } catch (\Exception $e) {
                    Log::error('Error processing file for request form', [
                        'key' => $key,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Continue with next file instead of failing completely
                }
            }
        }

        return $filesMetadata;
    }

    /**
     * Store an uploaded file to private bucket
     */
    private function storeUploadedFile(
        \Illuminate\Http\UploadedFile $file,
        string $key,
        string &$disk,
        string $fallbackDisk
    ): ?array {
        try {
            $extension = $file->getClientOriginalExtension();
            $filename = Str::uuid() . ($extension ? '.' . $extension : '');
            $storagePath = 'request-forms/' . date('Y/m') . '/' . $filename;

            // Store file
            $storedPath = Storage::disk($disk)->putFileAs(
                'request-forms/' . date('Y/m'),
                $file,
                $filename
            );

            $finalDisk = $disk;
            if ($storedPath === false) {
                Log::warning('Failed to store file in private bucket, trying fallback', [
                    'key' => $key,
                    'path' => $storagePath,
                ]);
                $storedPath = Storage::disk($fallbackDisk)->putFileAs(
                    'request-forms/' . date('Y/m'),
                    $file,
                    $filename
                );
                if ($storedPath) {
                    $finalDisk = $fallbackDisk;
                } else {
                    return null;
                }
            }

            return [
                'path' => $storedPath,
                'disk' => $finalDisk,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'original_key' => $key,
            ];
        } catch (\Exception $e) {
            Log::error('Error storing uploaded file', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get file extension from MIME type
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
        ];

        return $mimeToExt[$mimeType] ?? 'bin';
    }

    /**
     * Get a summary of payload data for logging
     */
    private function getPayloadSummary(array $payload): array
    {
        $summary = [];

        $commonFields = [
            'proceso', 'dondeRealizaProceso', 'motivoSolicitud',
            'dirigidoAQuien', 'tipoVehiculo', 'placaVehiculo',
            'infoCertificado', 'otrosDescripcion'
        ];

        foreach ($commonFields as $field) {
            if (isset($payload[$field])) {
                $summary[$field] = $payload[$field];
            }
        }

        return $summary;
    }

    /**
     * Display a listing of requests
     */
    public function index(Request $request): JsonResponse
    {
        $query = RequestForm::query();

        // Search by name, email, or document number
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('document_number', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        // Filter by request type
        if ($request->has('request_type')) {
            $query->where('request_type', $request->get('request_type'));
        }

        // Order by created_at desc by default
        $query->orderBy('created_at', 'desc');

        // Eager load responses for better performance
        $requests = $query->with('responses')->get();

        Log::info('Lista de solicitudes consultada', [
            'total_requests' => $requests->count(),
            'filters' => $request->only(['search', 'status', 'request_type'])
        ]);

        return response()->json([
            'success' => true,
            'data' => $requests->map(function ($request) {
                return [
                    'id' => $request->id,
                    'request_type' => $request->request_type,
                    'document_type' => $request->document_type,
                    'document_number' => $request->document_number,
                    'name' => $request->name,
                    'last_name' => $request->last_name,
                    'full_name' => $request->full_name,
                    'email' => $request->email,
                    'phone_number' => $request->phone_number,
                    'status' => $request->status,
                    'payload' => $request->payload,
                    'created_at' => $request->created_at,
                    'formatted_created_at' => $request->formatted_created_at,
                    'processed_at' => $request->processed_at,
                    'formatted_processed_at' => $request->formatted_processed_at,
                    'responses' => $request->responses->map(function ($response) {
                        return [
                            'id' => $response->id,
                            'status' => $response->status,
                            'email_subject' => $response->email_subject,
                            'email_body' => $response->email_body,
                            'created_at' => $response->created_at,
                        ];
                    }),
                    'responses_count' => $request->responses->count(),
                    'files' => $this->formatFilesMetadata($request->files, $request->id),
                    'files_count' => is_array($request->files) ? count($request->files) : 0,
                ];
            })
        ]);
    }

    /**
     * Display the specified request
     */
    public function show(RequestForm $request): JsonResponse
    {
        // Load responses relationship
        $request->load('responses');

        Log::info('Solicitud consultada', [
            'request_id' => $request->id,
            'request_type' => $request->request_type,
            'status' => $request->status,
            'responses_count' => $request->responses->count(),
            'affiliate_info' => [
                'document_type' => $request->document_type,
                'document_number' => $request->document_number,
                'full_name' => $request->full_name,
                'email' => $request->email,
                'phone_number' => $request->phone_number,
            ]
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $request->id,
                'request_type' => $request->request_type,
                'document_type' => $request->document_type,
                'document_number' => $request->document_number,
                'name' => $request->name,
                'last_name' => $request->last_name,
                'full_name' => $request->full_name,
                'email' => $request->email,
                'phone_number' => $request->phone_number,
                'payload' => json_encode($request->payload ?? (object) [], JSON_UNESCAPED_UNICODE),
                'status' => $request->status,
                'created_at' => $request->created_at,
                'formatted_created_at' => $request->formatted_created_at,
                'processed_at' => $request->processed_at,
                'formatted_processed_at' => $request->formatted_processed_at,
                'responses' => $request->responses->map(function ($response) {
                    return [
                        'id' => $response->id,
                        'status' => $response->status,
                        'email_subject' => $response->email_subject,
                        'email_body' => $response->email_body,
                        'created_at' => $response->created_at,
                    ];
                }),
                'responses_count' => $request->responses->count(),
                'files' => $this->formatFilesMetadata($request->files, $request->id),
                'files_count' => is_array($request->files) ? count($request->files) : 0,
            ]
        ]);
    }

    /**
     * Download a file from a request form
     */
    public function downloadFile(RequestForm $request, string $fileKey): \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $files = $request->files ?? [];

        if (!isset($files[$fileKey])) {
            return response()->json([
                'success' => false,
                'message' => 'Archivo no encontrado',
            ], 404);
        }

        $fileMetadata = $files[$fileKey];
        $disk = $fileMetadata['disk'] ?? 'prosalud-private';
        $path = $fileMetadata['path'] ?? null;

        if (!$path || !Storage::disk($disk)->exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Archivo no existe en el almacenamiento',
            ], 404);
        }

        try {
            $fileContent = Storage::disk($disk)->get($path);
            $originalName = $fileMetadata['original_name'] ?? $fileMetadata['original_key'] ?? 'file';
            $mimeType = $fileMetadata['mime_type'] ?? 'application/octet-stream';

            Log::info('Archivo descargado de solicitud', [
                'request_id' => $request->id,
                'file_key' => $fileKey,
                'path' => $path,
                'disk' => $disk,
            ]);

            return response()->streamDownload(function () use ($fileContent) {
                echo $fileContent;
            }, $originalName, [
                'Content-Type' => $mimeType,
            ]);

        } catch (\Exception $e) {
            Log::error('Error al descargar archivo de solicitud', [
                'request_id' => $request->id,
                'file_key' => $fileKey,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al descargar el archivo',
            ], 500);
        }
    }

    /**
     * Format files metadata for API response (without exposing sensitive data)
     * Generates temporary URLs for private bucket files
     */
    private function formatFilesMetadata(?array $files, ?string $requestId = null): array
    {
        if (!is_array($files) || empty($files)) {
            return [];
        }

        $formatted = [];
        foreach ($files as $key => $fileMetadata) {
            $fileKey = $fileMetadata['original_key'] ?? $key;
            $disk = $fileMetadata['disk'] ?? 'prosalud-private';
            $path = $fileMetadata['path'] ?? null;

            $downloadUrl = null;
            $urlExpiresAt = null;

            if ($path && $disk === 'prosalud-private') {
                try {
                    $storage = Storage::disk($disk);
                    $downloadUrl = $storage->temporaryUrl($path, now()->addHours(1));
                    $urlExpiresAt = now()->addHours(1)->toIso8601String();
                } catch (\Exception $e) {
                    // If temporary URL generation fails (e.g., local disk doesn't support it),
                    // fallback to the download endpoint
                    Log::warning('Failed to generate temporary URL, using download endpoint', [
                        'disk' => $disk,
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                    $downloadUrl = $requestId
                        ? url("/api/requests/{$requestId}/files/{$fileKey}")
                        : null;
                }
            } else {
                // For non-private disks or if path is missing, use download endpoint
                $downloadUrl = $requestId
                    ? url("/api/requests/{$requestId}/files/{$fileKey}")
                    : null;
            }

            $formatted[$key] = [
                'original_name' => $fileMetadata['original_name'] ?? $fileMetadata['original_key'] ?? $key,
                'mime_type' => $fileMetadata['mime_type'] ?? 'application/octet-stream',
                'size' => $fileMetadata['size'] ?? 0,
                'original_key' => $fileKey,
                'download_url' => $downloadUrl,
                'url_expires_at' => $urlExpiresAt,
            ];
        }

        return $formatted;
    }

    /**
     * Change request status
     */
    public function changeStatus(ChangeRequestStatusRequest $statusRequest, RequestForm $request): JsonResponse
    {
        $status = $statusRequest->validated()['status'];

        $updateData = ['status' => $status];

        if ($status === RequestStatuses::COMPLETED || $status === RequestStatuses::REJECTED) {
            $updateData['processed_at'] = now();
        } else {
            // For other statuses, clear processed_at
            $updateData['processed_at'] = null;
        }

        $request->update($updateData);

        $statusText = $this->getStatusText($status);

        Log::info("Solicitud marcada como {$statusText}", [
            'request_id' => $request->id,
            'request_type' => $request->request_type,
            'affiliate_info' => [
                'document_type' => $request->document_type,
                'document_number' => $request->document_number,
                'full_name' => $request->full_name,
                'email' => $request->email,
            ],
            'new_status' => $status,
            'processed_at' => $request->processed_at,
        ]);

        $this->auditLogService->logBusinessProcess('request_form', 'status_changed', $this->auditLogService->addRequestContext($statusRequest, [
            'request_id' => $request->id,
            'request_type' => $request->request_type,
            'old_status' => $request->getOriginal('status'),
            'new_status' => $status,
            'affiliate_document' => $request->document_number,
            'affiliate_email' => $request->email,
        ]));

        return response()->json([
            'success' => true,
            'message' => "Solicitud marcada como {$statusText} exitosamente",
            'data' => [
                'id' => $request->id,
                'request_type' => $request->request_type,
                'full_name' => $request->full_name,
                'email' => $request->email,
                'status' => $request->status,
                'processed_at' => $request->processed_at,
                'formatted_processed_at' => $request->formatted_processed_at,
            ]
        ]);
    }

    /**
     * Get human-readable status text
     */
    private function getStatusText(string $status): string
    {
        return match ($status) {
            RequestStatuses::PENDING => 'pendiente',
            RequestStatuses::IN_REVIEW => 'en revisión',
            RequestStatuses::REJECTED => 'rechazada',
            RequestStatuses::COMPLETED => 'completada',
            default => 'desconocido',
        };
    }

    /**
     * Respond to a request with email and status update
     */
    public function respond(RespondToRequestRequest $request, $requestId = null): JsonResponse
    {
        // Get the ID from the route parameter (route model binding may not work with string IDs with leading zeros)
        if (!$requestId) {
            $requestId = $request->route('request');
        }

        // Ensure requestId is a string
        $requestId = (string) $requestId;

        Log::info('Respond to request - buscando RequestForm', [
            'route_id' => $requestId,
            'route_id_length' => strlen($requestId),
        ]);

        // Find the request form manually to ensure it works with string IDs with leading zeros
        $requestForm = RequestForm::where('id', $requestId)->first();

        if (!$requestForm) {
            Log::error('RequestForm no encontrado en respond', [
                'route_id' => $requestId,
                'searched_id' => $requestId,
                'searched_id_type' => gettype($requestId),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Solicitud no encontrada',
            ], 404);
        }

        Log::info('Respond to request - RequestForm encontrado', [
            'request_form_id' => $requestForm->id,
            'request_form_exists' => $requestForm->exists,
        ]);

        $validated = $request->validated();
        $status = $validated['status'];
        $emailSubject = $validated['email_subject'];
        $emailBody = $validated['email_body'];

        // Get attachments if provided
        // Laravel automatically handles attachments as array when sent as attachments[0], attachments[1], etc.
        $attachments = [];
        if ($request->hasFile('attachments')) {
            $files = $request->file('attachments');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if ($file && $file->isValid()) {
                        $attachments[] = $file;
                    }
                }
            } else {
                // Single file
                if ($files && $files->isValid()) {
                    $attachments[] = $files;
                }
            }
        }

        // Prepare data for logging
        $oldStatus = $requestForm->status;
        $requestFormId = (string) $requestForm->id;

        Log::info('Iniciando proceso de respuesta a solicitud', [
            'request_id' => $requestFormId,
            'request_type' => $requestForm->request_type,
            'old_status' => $oldStatus,
            'new_status' => $status,
            'email' => $requestForm->email,
            'has_attachments' => !empty($attachments),
            'attachments_count' => count($attachments),
        ]);

        // IMPORTANT: Send email FIRST, before updating status or creating response record
        // This ensures that if email fails, we don't update the request status
        try {
            Log::info('Intentando enviar correo de respuesta', [
                'request_id' => $requestFormId,
                'email_to' => $requestForm->email,
                'email_cc' => 'juanpapabon@gmail.com',
                'email_subject' => $emailSubject,
                'email_body_length' => strlen($emailBody),
                'attachments_count' => count($attachments),
            ]);

            Mail::to($requestForm->email)
                ->cc('juanpapabon@gmail.com') // Hardcoded as per requirements
                ->send(new RequestFormResponse(
                    $requestForm,
                    $emailSubject,
                    $emailBody,
                    $status,
                    $attachments // This parameter is renamed to $uploadedFiles in RequestFormResponse constructor
                ));

            Log::info('Correo de respuesta enviado exitosamente', [
                'request_id' => $requestFormId,
                'email' => $requestForm->email,
                'status' => $status,
                'has_attachments' => !empty($attachments),
                'attachments_count' => count($attachments),
            ]);

        } catch (\Throwable $e) {
            // Log detailed error information
            Log::error('FALLO AL ENVIAR CORREO DE RESPUESTA - NO SE ACTUALIZARÁ EL ESTADO', [
                'request_id' => $requestFormId,
                'request_type' => $requestForm->request_type,
                'email' => $requestForm->email,
                'email_subject' => $emailSubject,
                'old_status' => $oldStatus,
                'intended_new_status' => $status,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
                'has_attachments' => !empty($attachments),
                'attachments_count' => count($attachments),
            ]);

            // Return error - do NOT update status or create response record
            return response()->json([
                'success' => false,
                'message' => 'Error al enviar el correo de respuesta. La solicitud no fue actualizada.',
                'error' => config('app.debug') ? $e->getMessage() : 'Error al enviar el correo electrónico',
            ], 500);
        }

        // Email was sent successfully, now update the request status
        $updateData = ['status' => $status];

        if ($status === RequestStatuses::COMPLETED || $status === RequestStatuses::REJECTED) {
            $updateData['processed_at'] = now();
        } else {
            // For other statuses, clear processed_at
            $updateData['processed_at'] = null;
        }

        $requestForm->update($updateData);
        $requestForm->refresh(); // Refresh to ensure we have the latest data

        // Store the response for traceability (only after email is sent successfully)
        $requestResponse = RequestResponse::create([
            'request_form_id' => $requestFormId,
            'status' => $status,
            'email_subject' => $emailSubject,
            'email_body' => $emailBody,
            'created_at' => now(),
        ]);

        Log::info('Respuesta de solicitud procesada exitosamente', [
            'request_id' => $requestFormId,
            'response_id' => $requestResponse->id,
            'request_type' => $requestForm->request_type,
            'affiliate_info' => [
                'document_type' => $requestForm->document_type,
                'document_number' => $requestForm->document_number,
                'full_name' => $requestForm->full_name,
                'email' => $requestForm->email,
            ],
            'old_status' => $oldStatus,
            'new_status' => $status,
            'has_attachments' => !empty($attachments),
            'attachments_count' => count($attachments),
        ]);

        $this->auditLogService->logBusinessProcess('request_form', 'responded', $this->auditLogService->addRequestContext($request, [
            'request_id' => $requestForm->id,
            'response_id' => $requestResponse->id,
            'request_type' => $requestForm->request_type,
            'old_status' => $oldStatus,
            'new_status' => $status,
            'affiliate_document' => $requestForm->document_number,
            'affiliate_email' => $requestForm->email,
            'has_attachments' => !empty($attachments),
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Respuesta enviada exitosamente',
            'data' => [
                'id' => $requestForm->id,
                'request_type' => $requestForm->request_type,
                'document_type' => $requestForm->document_type,
                'document_number' => $requestForm->document_number,
                'name' => $requestForm->name,
                'last_name' => $requestForm->last_name,
                'full_name' => $requestForm->full_name,
                'email' => $requestForm->email,
                'status' => $requestForm->status,
                'created_at' => $requestForm->created_at,
                'processed_at' => $requestForm->processed_at,
                'formatted_processed_at' => $requestForm->formatted_processed_at,
            ]
        ]);
    }
}
