<?php

namespace App\Http\Controllers;

use App\Constants\Providers;
use App\Http\Requests\{ApproveWellnessEventRequest, ChangeWellnessEventVisibilityRequest, RejectWellnessEventRequest, ReviewWellnessEventRequest, StoreWellnessEventRequest, UpdateWellnessEventRequest};
use App\Models\{WellnessEvent, WellnessEventImage};
use Illuminate\Http\{Request, Response};
use Illuminate\Support\Facades\{Log, Storage};

class WellnessEventController extends Controller
{
    /**
     * Display a listing of the resource (Public API - for public website).
     * Only returns visible events without attendance_list information.
     */
    public function publicIndex(Request $request)
    {
        $query = WellnessEvent::query()->with('images');

        // Always filter by visible events only
        $query->where('is_visible', true);

        // Optional filters
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->filled('from_date')) {
            $from = $request->date('from_date');
            $query->whereDate('date', '>=', $from->format('Y-m-d'));
        }
        if ($request->filled('to_date')) {
            $to = $request->date('to_date');
            $query->whereDate('date', '<=', $to->format('Y-m-d'));
        }

        // Pagination with default of 50 and max of 100
        $perPage = $request->integer('per_page', 50);
        $perPage = min($perPage, 100); // Cap at 100 to prevent abuse
        $events = $query->orderByDesc('date')->paginate($perPage);

        // Format events WITHOUT attendance_list URLs (public API)
        $events->getCollection()->transform(function ($event) {
            return $this->formatPublicEventResponse($event);
        });

        return response()->json($events);
    }

    /**
     * Display a listing of the resource (Private API - requires authentication and permissions).
     * Returns all events with attendance_list information.
     */
    public function index(Request $request)
    {
        $query = WellnessEvent::query()->with(['images', 'wellnessRequest', 'reviewer']);

        // Optional filters
        if ($request->filled('is_visible')) {
            $query->where('is_visible', $request->boolean('is_visible'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->filled('review_status')) {
            $query->where('review_status', $request->input('review_status'));
        }
        if ($request->filled('from_date')) {
            $from = $request->date('from_date');
            $query->whereDate('date', '>=', $from->format('Y-m-d'));
        }
        if ($request->filled('to_date')) {
            $to = $request->date('to_date');
            $query->whereDate('date', '<=', $to->format('Y-m-d'));
        }

        // Pagination with default of 50 and max of 100
        $perPage = $request->integer('per_page', 50);
        $perPage = min($perPage, 100); // Cap at 100 to prevent abuse
        $events = $query->orderByDesc('date')->paginate($perPage);

        // Format events to include attendance_list URLs
        $events->getCollection()->transform(function ($event) {
            return $this->formatEventResponse($event);
        });

        return response()->json($events);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreWellnessEventRequest $request)
    {
        try {
            // Log incoming request data
            Log::info('Iniciando creación de evento de bienestar', [
                'request_data' => $request->except(['images']), // Exclude images from log for security
                'images_count' => count($request->file('images', [])),
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]);

            $data = $request->validated();

            if (!isset($data['provider'])) {
                $data['provider'] = Providers::PROSALUD;
            }

            // Set review_status to 'pending' by default for directly created events
            // Events created from wellness requests will have their review_status set by the publishToGallery method
            if (!isset($data['review_status'])) {
                $data['review_status'] = 'pending';
            }

            // Remove images from data before creating event
            $images = $data['images'] ?? [];
            unset($data['images']);

            // Remove attendance_list from data (we'll handle it after creating the event)
            $attendanceListFile = $request->file('attendance_list');
            unset($data['attendance_list']);

            Log::info('Datos validados para evento de bienestar', [
                'validated_data' => $data,
                'images_count' => count($images),
                'has_attendance_list' => $attendanceListFile !== null,
                'timestamp' => now()->toISOString(),
            ]);

            $event = WellnessEvent::create($data);

            // Handle attendance_list file if provided (after event is created so we have the ID)
            if ($attendanceListFile) {
                // Increase timeout for file upload (S3 operations can take time)
                $originalTimeout = ini_get('max_execution_time');
                set_time_limit(120); // 2 minutes for file upload
                
                try {
                    $attendanceListPath = $this->storeAttendanceList($attendanceListFile, $event->id);
                    $event->update(['attendance_list_path' => $attendanceListPath]);
                } finally {
                    // Restore original timeout
                    if ($originalTimeout) {
                        set_time_limit((int) $originalTimeout);
                    }
                }
            }

            // Handle image uploads
            if (!empty($images)) {
                Log::info('Procesando imágenes para evento de bienestar', [
                    'event_id' => $event->id,
                    'images_count' => count($images),
                    'timestamp' => now()->toISOString(),
                ]);
                $this->handleImageUploads($event, $images);
            }

            $event->load('images');

            Log::info('Evento de bienestar creado exitosamente', [
                'event_id' => $event->id,
                'title' => $event->title,
                'category' => $event->category,
                'date' => $event->date,
                'is_visible' => $event->is_visible,
                'provider' => $event->provider,
                'images_count' => $event->images->count(),
                'has_attendance_list' => !empty($event->attendance_list_path),
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            // Log response data
            Log::info('Enviando respuesta de evento de bienestar creado', [
                'event_id' => $event->id,
                'response_status' => Response::HTTP_CREATED,
                'response_data_keys' => array_keys($event->toArray()),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json($this->formatEventResponse($event), Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Error creando evento de bienestar', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['images']),
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'message' => 'Error al crear el evento',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Display the specified resource (Public API - for public website).
     * Only returns visible events without attendance_list information.
     */
    public function publicShow(WellnessEvent $wellnessEvent)
    {
        // Only show visible events in public API
        if (!$wellnessEvent->is_visible) {
            return response()->json([
                'message' => 'Evento no encontrado o no disponible',
            ], 404);
        }

        $wellnessEvent->load('images');

        return response()->json($this->formatPublicEventResponse($wellnessEvent));
    }

    /**
     * Display the specified resource (Private API - requires authentication and permissions).
     * Returns event with attendance_list information.
     */
    public function show(WellnessEvent $wellnessEvent)
    {
        $wellnessEvent->load(['images', 'wellnessRequest', 'reviewer']);

        return response()->json($this->formatEventResponse($wellnessEvent));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateWellnessEventRequest $request, WellnessEvent $wellnessEvent)
    {
        try {
            // Log incoming request data
            Log::info('Iniciando actualización de evento de bienestar', [
                'event_id' => $wellnessEvent->id,
                'method' => $request->method(),
                'content_type' => $request->header('Content-Type'),
                'request_data' => $request->except(['images']),
                'json_data' => $request->json()->all(),
                'input_data' => $request->input(),
                'all_data' => $request->all(),
                'has_images' => $request->hasFile('images'),
                'images_count' => count($request->file('images', [])),
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]);

            $data = $request->validated();

            // If validated data is empty, try to get data from input
            // This can happen with multipart/form-data when Laravel doesn't parse it correctly
            if (empty($data)) {
                Log::warning('Validated data is empty, checking input data', [
                    'event_id' => $wellnessEvent->id,
                    'all_data' => $request->all(),
                    'input_data' => $request->input(),
                    'has_files' => $request->hasFile('images') || $request->hasFile('attendance_list'),
                    'content_type' => $request->header('Content-Type'),
                    'timestamp' => now()->toISOString(),
                ]);
                
                // Try to get data from input
                $inputData = $request->only([
                    'title', 'date', 'category', 'description', 'location',
                    'attendees', 'gift', 'provider', 'is_visible'
                ]);
                
                // Remove null/empty values to avoid overwriting with null
                $data = array_filter($inputData, function ($value) {
                    return $value !== null && $value !== '';
                });
                
                // If input is also empty, try to manually parse multipart/form-data
                if (empty($data) && str_contains($request->header('Content-Type', ''), 'multipart/form-data')) {
                    $parsedData = $this->parseMultipartFormData($request);
                    if (!empty($parsedData)) {
                        Log::info('Parsed multipart/form-data manually', [
                            'event_id' => $wellnessEvent->id,
                            'parsed_data' => $parsedData,
                            'timestamp' => now()->toISOString(),
                        ]);
                        $data = $parsedData;
                    }
                }
                
                // If we got data from input or parsing, validate it manually
                if (!empty($data)) {
                    Log::info('Using input data after validation failed', [
                        'event_id' => $wellnessEvent->id,
                        'data_from_input' => $data,
                        'timestamp' => now()->toISOString(),
                    ]);
                } else {
                    Log::warning('No data found in validated or input, update will be skipped', [
                        'event_id' => $wellnessEvent->id,
                        'timestamp' => now()->toISOString(),
                    ]);
                }
            }

            // Remove attendance_list from data (it's a file, not a database field)
            unset($data['attendance_list']);

            // Track if we have any actual data to update (excluding files)
            $hasFileUpdates = false;
            $updateData = $data;

            // Handle image uploads if provided (check both validated data and direct request)
            $images = null;
            if (isset($data['images'])) {
                $images = $data['images'];
                unset($updateData['images']);
            } elseif ($request->hasFile('images')) {
                // If images are in request but not in validated data, get them directly
                $images = $request->file('images');
            }

            if ($images !== null && !empty($images)) {
                $hasFileUpdates = true;

                Log::info('Procesando imágenes para actualización de evento de bienestar', [
                    'event_id' => $wellnessEvent->id,
                    'images_count' => count($images),
                    'timestamp' => now()->toISOString(),
                ]);

                // Delete existing images
                $this->deleteEventImages($wellnessEvent);

                // Upload new images
                $this->handleImageUploads($wellnessEvent, $images);
            }

            // Handle attendance_list file if provided
            if ($request->hasFile('attendance_list')) {
                $hasFileUpdates = true;
                // Delete old file if exists
                if ($wellnessEvent->attendance_list_path) {
                    try {
                        Storage::disk('prosalud-private')->delete($wellnessEvent->attendance_list_path);
                        // Try fallback disk if not found
                        if (Storage::disk('local')->exists($wellnessEvent->attendance_list_path)) {
                            Storage::disk('local')->delete($wellnessEvent->attendance_list_path);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Error eliminando listado de asistencia anterior', [
                            'path' => $wellnessEvent->attendance_list_path,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Increase timeout for file upload (S3 operations can take time)
                $originalTimeout = ini_get('max_execution_time');
                set_time_limit(120); // 2 minutes for file upload
                
                try {
                    $attendanceListFile = $request->file('attendance_list');
                    $updateData['attendance_list_path'] = $this->storeAttendanceList($attendanceListFile, $wellnessEvent->id);
                } finally {
                    // Restore original timeout
                    if ($originalTimeout) {
                        set_time_limit((int) $originalTimeout);
                    }
                }
            }

            // Handle delete attendance_list (if sent as a flag)
            if ('true' === $request->input('eliminar_attendance_list')) {
                $hasFileUpdates = true;
                if ($wellnessEvent->attendance_list_path) {
                    try {
                        Storage::disk('prosalud-private')->delete($wellnessEvent->attendance_list_path);
                        // Try fallback disk if not found
                        if (Storage::disk('local')->exists($wellnessEvent->attendance_list_path)) {
                            Storage::disk('local')->delete($wellnessEvent->attendance_list_path);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Error eliminando listado de asistencia', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                $updateData['attendance_list_path'] = null;
            }

            Log::info('Datos validados para actualización de evento de bienestar', [
                'event_id' => $wellnessEvent->id,
                'validated_data' => $updateData,
                'has_images' => isset($data['images']),
                'has_file_updates' => $hasFileUpdates,
                'data_count' => count($updateData),
                'timestamp' => now()->toISOString(),
            ]);

            // Only update if we have actual data to update or file changes
            if (!empty($updateData) || $hasFileUpdates) {
                if (!empty($updateData)) {
                    $wellnessEvent->update($updateData);
                }
            } else {
                Log::warning('No hay datos para actualizar, retornando error', [
                    'event_id' => $wellnessEvent->id,
                    'timestamp' => now()->toISOString(),
                ]);
                
                return response()->json([
                    'message' => 'No se proporcionaron datos para actualizar',
                    'error' => 'La solicitud no contiene datos válidos para actualizar el evento',
                ], 422);
            }
            $wellnessEvent->load('images');

            Log::info('Evento de bienestar actualizado exitosamente', [
                'event_id' => $wellnessEvent->id,
                'title' => $wellnessEvent->title,
                'category' => $wellnessEvent->category,
                'date' => $wellnessEvent->date,
                'is_visible' => $wellnessEvent->is_visible,
                'images_count' => $wellnessEvent->images->count(),
                'has_attendance_list' => !empty($wellnessEvent->attendance_list_path),
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            // Log response data
            Log::info('Enviando respuesta de evento de bienestar actualizado', [
                'event_id' => $wellnessEvent->id,
                'response_data_keys' => array_keys($wellnessEvent->toArray()),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json($this->formatEventResponse($wellnessEvent));
        } catch (\Exception $e) {
            Log::error('Error actualizando evento de bienestar', [
                'event_id' => $wellnessEvent->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['images']),
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'message' => 'Error al actualizar el evento',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, WellnessEvent $wellnessEvent)
    {
        try {
            // Log incoming request data
            Log::info('Iniciando eliminación de evento de bienestar', [
                'event_id' => $wellnessEvent->id,
                'title' => $wellnessEvent->title,
                'category' => $wellnessEvent->category,
                'date' => $wellnessEvent->date,
                'images_count' => $wellnessEvent->images->count(),
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]);

            // Delete all images first
            if ($wellnessEvent->images->count() > 0) {
                Log::info('Eliminando imágenes del evento de bienestar', [
                    'event_id' => $wellnessEvent->id,
                    'images_count' => $wellnessEvent->images->count(),
                    'timestamp' => now()->toISOString(),
                ]);
                $this->deleteEventImages($wellnessEvent);
            }

            // Delete attendance_list file if exists
            if ($wellnessEvent->attendance_list_path) {
                try {
                    Storage::disk('prosalud-private')->delete($wellnessEvent->attendance_list_path);
                    // Try fallback disk if not found
                    if (Storage::disk('local')->exists($wellnessEvent->attendance_list_path)) {
                        Storage::disk('local')->delete($wellnessEvent->attendance_list_path);
                    }
                } catch (\Exception $e) {
                    Log::warning('Error eliminando listado de asistencia al eliminar evento', [
                        'path' => $wellnessEvent->attendance_list_path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Delete the event
            $wellnessEvent->delete();

            Log::info('Evento de bienestar eliminado exitosamente', [
                'event_id' => $wellnessEvent->id,
                'title' => $wellnessEvent->title,
                'user_id' => $request->user()?->id,
                'timestamp' => now()->toISOString(),
            ]);

            // Log response data
            Log::info('Enviando respuesta de evento de bienestar eliminado', [
                'event_id' => $wellnessEvent->id,
                'response_message' => 'Evento eliminado exitosamente',
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json(['message' => 'Evento eliminado exitosamente']);
        } catch (\Exception $e) {
            Log::error('Error eliminando evento de bienestar', [
                'event_id' => $wellnessEvent->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'message' => 'Error al eliminar el evento',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Toggle or set the visibility of the event.
     */
    public function changeVisibility(ChangeWellnessEventVisibilityRequest $request, WellnessEvent $wellnessEvent)
    {
        $validated = $request->validated();
        $oldVisibility = $wellnessEvent->is_visible;

        if (array_key_exists('is_visible', $validated)) {
            $wellnessEvent->is_visible = (bool) $validated['is_visible'];
        } else {
            $wellnessEvent->is_visible = !$wellnessEvent->is_visible;
        }

        $wellnessEvent->save();

        Log::info('Visibilidad de evento de bienestar cambiada', [
            'event_id' => $wellnessEvent->id,
            'title' => $wellnessEvent->title,
            'old_visibility' => $oldVisibility,
            'new_visibility' => $wellnessEvent->is_visible,
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
            'timestamp' => now()->toISOString(),
        ]);

        return response()->json([
            'id' => $wellnessEvent->id,
            'is_visible' => $wellnessEvent->is_visible,
        ]);
    }

    /**
     * Add images to an existing event.
     */
    public function addImages(Request $request, WellnessEvent $wellnessEvent)
    {
        try {
            $request->validate([
                'images' => ['required', 'array', 'min:1'],
                'images.*' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            ]);

            $this->handleImageUploads($wellnessEvent, $request->file('images'));
            $wellnessEvent->load('images');

            return response()->json([
                'message' => 'Imágenes agregadas exitosamente',
                'event' => $wellnessEvent,
            ]);
        } catch (\Exception $e) {
            Log::error('Error agregando imágenes al evento', [
                'event_id' => $wellnessEvent->id,
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Error al agregar imágenes',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Mark event as in review.
     */
    public function review(ReviewWellnessEventRequest $request, WellnessEvent $wellnessEvent)
    {
        try {
            if ($wellnessEvent->review_status === 'approved') {
                return response()->json([
                    'message' => 'No se puede poner en revisión un evento que ya fue aprobado',
                ], 400);
            }

            $wellnessEvent->review_status = 'in_review';
            $wellnessEvent->save();

            Log::info('Evento de bienestar puesto en revisión', [
                'event_id' => $wellnessEvent->id,
                'title' => $wellnessEvent->title,
                'user_id' => $request->user()?->id,
                'timestamp' => now()->toISOString(),
            ]);

            $wellnessEvent->load(['images', 'wellnessRequest', 'reviewer']);

            return response()->json($this->formatEventResponse($wellnessEvent));
        } catch (\Exception $e) {
            Log::error('Error poniendo evento en revisión', [
                'event_id' => $wellnessEvent->id,
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Error al poner el evento en revisión',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Approve event for publication.
     */
    public function approve(ApproveWellnessEventRequest $request, WellnessEvent $wellnessEvent)
    {
        try {
            if ($wellnessEvent->review_status === 'approved') {
                return response()->json([
                    'message' => 'El evento ya está aprobado',
                ], 400);
            }

            $user = $request->user();
            $validated = $request->validated();

            $wellnessEvent->review_status = 'approved';
            $wellnessEvent->reviewed_at = now();
            $wellnessEvent->reviewed_by = $user->id;

            // If is_visible is provided, update it
            if (isset($validated['is_visible'])) {
                $wellnessEvent->is_visible = (bool) $validated['is_visible'];
            } else {
                // Default to visible when approved
                $wellnessEvent->is_visible = true;
            }

            $wellnessEvent->save();

            Log::info('Evento de bienestar aprobado', [
                'event_id' => $wellnessEvent->id,
                'title' => $wellnessEvent->title,
                'is_visible' => $wellnessEvent->is_visible,
                'reviewed_by' => $user->id,
                'timestamp' => now()->toISOString(),
            ]);

            $wellnessEvent->load(['images', 'wellnessRequest', 'reviewer']);

            return response()->json($this->formatEventResponse($wellnessEvent));
        } catch (\Exception $e) {
            Log::error('Error aprobando evento', [
                'event_id' => $wellnessEvent->id,
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Error al aprobar el evento',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Reject event.
     */
    public function reject(RejectWellnessEventRequest $request, WellnessEvent $wellnessEvent)
    {
        try {
            if ($wellnessEvent->review_status === 'approved') {
                return response()->json([
                    'message' => 'No se puede rechazar un evento que ya fue aprobado',
                ], 400);
            }

            $user = $request->user();
            $validated = $request->validated();

            $wellnessEvent->review_status = 'rejected';
            $wellnessEvent->reviewed_at = now();
            $wellnessEvent->reviewed_by = $user->id;
            // Set is_visible to false when rejected
            $wellnessEvent->is_visible = false;
            $wellnessEvent->save();

            Log::info('Evento de bienestar rechazado', [
                'event_id' => $wellnessEvent->id,
                'title' => $wellnessEvent->title,
                'rejection_reason' => $validated['rejection_reason'] ?? null,
                'reviewed_by' => $user->id,
                'timestamp' => now()->toISOString(),
            ]);

            $wellnessEvent->load(['images', 'wellnessRequest', 'reviewer']);

            $response = $this->formatEventResponse($wellnessEvent);
            if (isset($validated['rejection_reason'])) {
                $response['rejection_reason'] = $validated['rejection_reason'];
            }

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error rechazando evento', [
                'event_id' => $wellnessEvent->id,
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Error al rechazar el evento',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Remove a specific image from an event.
     */
    public function removeImage(WellnessEvent $wellnessEvent, WellnessEventImage $image)
    {
        try {
            // Verify the image belongs to the event
            if ($image->event_id !== $wellnessEvent->id) {
                return response()->json(['message' => 'La imagen no pertenece a este evento'], 404);
            }

            $disk = 'prosalud-public';
            $fallbackDisk = 'public';

            // Extract path from URL
            $path = $this->extractPathFromUrl($image->image_url);

            // Try to determine which disk the image is on
            $currentDisk = $disk;
            if ($path && !Storage::disk($disk)->exists($path)) {
                $currentDisk = $fallbackDisk;
            }

            // Delete file from storage
            if ($path && Storage::disk($currentDisk)->exists($path)) {
                Storage::disk($currentDisk)->delete($path);
                Log::info('Imagen eliminada del almacenamiento', [
                    'event_id' => $wellnessEvent->id,
                    'image_id' => $image->id,
                    'path' => $path,
                    'disk' => $currentDisk,
                ]);
            }

            // Delete database record
            $image->delete();

            return response()->json(['message' => 'Imagen eliminada exitosamente']);
        } catch (\Exception $e) {
            Log::error('Error eliminando imagen del evento', [
                'event_id' => $wellnessEvent->id,
                'image_id' => $image->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al eliminar imagen',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Handle image uploads for an event.
     */
    private function handleImageUploads(WellnessEvent $event, array $images)
    {
        $disk = 'prosalud-public';
        $fallbackDisk = 'public';

        foreach ($images as $index => $image) {
            // Generate unique filename with descriptive name
            $extension = $image->getClientOriginalExtension() ?: $this->getExtensionFromMimeType($image->getMimeType());
            $filename = $this->generateDescriptiveFilenameForEventImage($image, $event->id, $index, $extension);
            $fullPath = 'wellness-events/' . $event->id . '/' . $filename;

            Log::info('Subiendo imagen para evento de bienestar', [
                'event_id' => $event->id,
                'filename' => $filename,
                'full_path' => $fullPath,
                'original_name' => $image->getClientOriginalName(),
                'size' => $image->getSize(),
                'disk' => $disk,
                'timestamp' => now()->toISOString(),
            ]);

            try {
                // Store the file using putFileAs for S3 storage (same as Comfenalco)
                $storedPath = Storage::disk($disk)->putFileAs(
                    'wellness-events/' . $event->id,
                    $image,
                    $filename
                );

                // If S3 fails (returns false), try local disk
                if (false === $storedPath) {
                    Log::warning('S3 upload failed, trying local disk', [
                        's3_disk' => $disk,
                        'fallback_disk' => $fallbackDisk,
                    ]);

                    $storedPath = Storage::disk($fallbackDisk)->putFileAs(
                        'wellness-events/' . $event->id,
                        $image,
                        $filename
                    );
                    $disk = $fallbackDisk;
                }

                Log::info('Imagen subida exitosamente', [
                    'event_id' => $event->id,
                    'filename' => $filename,
                    'stored_path' => $storedPath,
                    'expected_path' => $fullPath,
                    'final_disk' => $disk,
                    'timestamp' => now()->toISOString(),
                ]);
            } catch (\Exception $e) {
                Log::error('Error al guardar imagen en disco S3', [
                    'filename' => $filename,
                    'disk' => $disk,
                    'error' => $e->getMessage(),
                ]);

                // Try fallback disk
                try {
                    Log::info('Intentando disco de respaldo', ['fallback_disk' => $fallbackDisk]);
                    $storedPath = Storage::disk($fallbackDisk)->putFileAs(
                        'wellness-events/' . $event->id,
                        $image,
                        $filename
                    );
                    $disk = $fallbackDisk;
                    Log::info('Imagen guardada en disco de respaldo', ['stored_path' => $storedPath]);
                } catch (\Exception $fallbackError) {
                    Log::error('Error también en disco de respaldo', [
                        'fallback_error' => $fallbackError->getMessage(),
                    ]);
                    throw new \Exception('No se pudo guardar la imagen en ningún disco disponible');
                }
            }

            // Ensure we have a valid stored path
            if (!$storedPath) {
                Log::error('No se obtuvo path válido para la imagen', [
                    'event_id' => $event->id,
                    'filename' => $filename,
                    'disk' => $disk,
                ]);
                throw new \Exception('No se pudo obtener el path de la imagen almacenada');
            }

            // Generate URL manually based on disk configuration
            // Use the actual stored path, not the expected path
            if ('prosalud-public' === $disk) {
                $baseUrl = config('filesystems.disks.prosalud-public.url');
                $imageUrl = rtrim($baseUrl, '/') . '/' . ltrim($storedPath, '/');
            } else {
                $baseUrl = config('filesystems.disks.public.url');
                $imageUrl = rtrim($baseUrl, '/') . '/' . ltrim($storedPath, '/');
            }

            Log::info('URL generada para imagen', [
                'event_id' => $event->id,
                'filename' => $filename,
                'stored_path' => $storedPath,
                'image_url' => $imageUrl,
                'disk' => $disk,
                'base_url' => $baseUrl,
                'timestamp' => now()->toISOString(),
            ]);

            // Verify URL uniqueness before saving to database
            $existingImage = WellnessEventImage::where('image_url', $imageUrl)->first();
            if ($existingImage) {
                Log::error('URL duplicada detectada', [
                    'event_id' => $event->id,
                    'duplicate_url' => $imageUrl,
                    'existing_image_id' => $existingImage->id,
                ]);
                throw new \Exception('URL de imagen duplicada detectada: ' . $imageUrl);
            }

            WellnessEventImage::create([
                'event_id' => $event->id,
                'image_url' => $imageUrl,
                'is_main' => 0 === $index, // First image is main
            ]);

            Log::info('Imagen guardada en base de datos', [
                'event_id' => $event->id,
                'image_url' => $imageUrl,
                'is_main' => 0 === $index,
                'timestamp' => now()->toISOString(),
            ]);
        }
    }

    /**
     * Delete all images for an event.
     */
    private function deleteEventImages(WellnessEvent $event)
    {
        $disk = 'prosalud-public';
        $fallbackDisk = 'public';

        foreach ($event->images as $image) {
            try {
                // Extract path from URL
                $path = $this->extractPathFromUrl($image->image_url);

                // Try to determine which disk the image is on
                $currentDisk = $disk;
                if ($path && !Storage::disk($disk)->exists($path)) {
                    $currentDisk = $fallbackDisk;
                }

                // Delete file from storage
                if ($path && Storage::disk($currentDisk)->exists($path)) {
                    Storage::disk($currentDisk)->delete($path);
                    Log::info('Imagen eliminada del almacenamiento', [
                        'event_id' => $event->id,
                        'image_id' => $image->id,
                        'path' => $path,
                        'disk' => $currentDisk,
                    ]);
                }

                // Delete database record
                $image->delete();
            } catch (\Exception $e) {
                Log::error('Error eliminando imagen en deleteEventImages', [
                    'event_id' => $event->id,
                    'image_id' => $image->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Extract path from CloudFront or S3 URL.
     */
    private function extractPathFromUrl(string $url): ?string
    {
        // Remove domain and get path
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['path'])) {
            return null;
        }

        // Remove leading /storage/ or just /
        $path = ltrim($parsed['path'], '/');

        return preg_replace('#^storage/#', '', $path);
    }

    /**
     * Generate a simple but descriptive filename for event images
     * Format: Evento[ID]-[UniqueId]-[Index].[ext].
     */
    private function generateDescriptiveFilenameForEventImage(
        \Illuminate\Http\UploadedFile $image,
        int $eventId,
        int $index,
        string $extension,
    ): string {
        $uniqueId = substr(\Illuminate\Support\Str::uuid()->toString(), 0, 6);

        // Build simple filename: Evento[ID]-[UniqueId]-[Index].[ext]
        return sprintf(
            'Evento%d-%s-%d.%s',
            $eventId,
            $uniqueId,
            $index + 1,
            $extension
        );
    }

    /**
     * Get file extension from MIME type.
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
     * Store attendance_list file in private bucket
     * Returns the stored path (always uses prosalud-private bucket).
     */
    private function storeAttendanceList($file, ?int $eventId): string
    {
        $disk = 'prosalud-private';
        $fallbackDisk = 'local';

        $extension = $file->getClientOriginalExtension() ?: $this->getExtensionFromMimeType($file->getMimeType());
        $filename = $this->generateDescriptiveFilenameForAttendanceList($file, $eventId, $extension);
        $directory = 'wellness-events/listados/' . date('Y/m');

        Log::info('Iniciando almacenamiento de listado de asistencia', [
            'event_id' => $eventId,
            'filename' => $filename,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'disk' => $disk,
            'timestamp' => now()->toISOString(),
        ]);

        // Try to store in S3 first, with error handling
        try {
            $storedPath = Storage::disk($disk)->putFileAs(
                $directory,
                $file,
                $filename
            );

            if (false === $storedPath) {
                throw new \Exception('putFileAs returned false');
            }

            Log::info('Listado de asistencia guardado exitosamente en S3', [
                'event_id' => $eventId,
                'filename' => $filename,
                'stored_path' => $storedPath,
                'disk' => $disk,
                'timestamp' => now()->toISOString(),
            ]);

            return $storedPath;
        } catch (\Exception $e) {
            Log::warning('Error al guardar listado de asistencia en S3, usando disco local', [
                'event_id' => $eventId,
                'filename' => $filename,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'fallback_disk' => $fallbackDisk,
                'timestamp' => now()->toISOString(),
            ]);

            // Try fallback disk
            try {
                $storedPath = Storage::disk($fallbackDisk)->putFileAs(
                    $directory,
                    $file,
                    $filename
                );

                if (!$storedPath) {
                    throw new \Exception('No se pudo guardar el listado de asistencia en el disco local');
                }

                Log::info('Listado de asistencia guardado exitosamente en disco local', [
                    'event_id' => $eventId,
                    'filename' => $filename,
                    'stored_path' => $storedPath,
                    'disk' => $fallbackDisk,
                    'timestamp' => now()->toISOString(),
                ]);

                return $storedPath;
            } catch (\Exception $fallbackError) {
                Log::error('Error también en disco local', [
                    'event_id' => $eventId,
                    'filename' => $filename,
                    'fallback_error' => $fallbackError->getMessage(),
                    'timestamp' => now()->toISOString(),
                ]);
                throw new \Exception('No se pudo guardar el listado de asistencia en ningún disco disponible: ' . $fallbackError->getMessage());
            }
        }
    }

    /**
     * Generate a descriptive filename for attendance list
     * Format: Listado-Evento[ID]-[UniqueId].[ext] or Listado-Evento-[UniqueId].[ext] if no ID yet
     */
    private function generateDescriptiveFilenameForAttendanceList(
        \Illuminate\Http\UploadedFile $file,
        ?int $eventId,
        string $extension,
    ): string {
        $uniqueId = substr(\Illuminate\Support\Str::uuid()->toString(), 0, 6);

        if ($eventId) {
            // Build filename: Listado-Evento[ID]-[UniqueId].[ext]
            return sprintf(
                'Listado-Evento%d-%s.%s',
                $eventId,
                $uniqueId,
                $extension
            );
        } else {
            // Build filename: Listado-Evento-[UniqueId].[ext] (for new events)
            return sprintf(
                'Listado-Evento-%s.%s',
                $uniqueId,
                $extension
            );
        }
    }

    /**
     * Format event response with attendance_list URL if exists
     */
    private function formatEventResponse(WellnessEvent $event): array
    {
        $eventArray = $event->toArray();

        // Add wellness request information if related
        if ($event->wellnessRequest) {
            $eventArray['wellness_request'] = [
                'id' => $event->wellnessRequest->id,
                'activity_name' => $event->wellnessRequest->activity_name,
                'status' => $event->wellnessRequest->status,
            ];
        }

        // Add reviewer information if reviewed
        if ($event->reviewer) {
            $eventArray['reviewer'] = [
                'id' => $event->reviewer->id,
                'name' => $event->reviewer->name,
                'email' => $event->reviewer->email,
            ];
        }

        // Add review_status_text
        $eventArray['review_status_text'] = $event->review_status_text;

        // Add attendance_list URL if exists
        if ($event->attendance_list_path) {
            $fileUrl = null;
            $urlExpiresAt = null;

            try {
                // Generate temporary signed URL for private bucket file (valid for 1 hour)
                $storage = Storage::disk('prosalud-private');
                $fileUrl = $storage->temporaryUrl($event->attendance_list_path, now()->addHours(1));
                $urlExpiresAt = now()->addHours(1)->toIso8601String();
            } catch (\Exception $e) {
                Log::warning('Failed to generate temporary URL for attendance_list', [
                    'event_id' => $event->id,
                    'path' => $event->attendance_list_path,
                    'error' => $e->getMessage(),
                ]);
                // If temporary URL generation fails, try fallback disk
                try {
                    $storage = Storage::disk('local');
                    if (method_exists($storage, 'temporaryUrl')) {
                        $fileUrl = $storage->temporaryUrl($event->attendance_list_path, now()->addHours(1));
                        $urlExpiresAt = now()->addHours(1)->toIso8601String();
                    }
                } catch (\Exception $fallbackError) {
                    Log::error('Failed to generate temporary URL from fallback disk', [
                        'error' => $fallbackError->getMessage(),
                    ]);
                }
            }

            $eventArray['attendance_list'] = [
                'file_url' => $fileUrl,
                'url_expires_at' => $urlExpiresAt,
            ];
        }

        return $eventArray;
    }

    /**
     * Format event response for public API (without attendance_list information)
     */
    private function formatPublicEventResponse(WellnessEvent $event): array
    {
        $eventArray = $event->toArray();

        // Remove attendance_list_path from public response
        unset($eventArray['attendance_list_path']);

        // Do NOT include attendance_list information in public API
        // This ensures sensitive data is not exposed

        return $eventArray;
    }

    /**
     * Manually parse multipart/form-data when Laravel doesn't parse it correctly
     * This is a fallback for cases where the request body isn't being parsed automatically
     */
    private function parseMultipartFormData(Request $request): array
    {
        $contentType = $request->header('Content-Type', '');
        if (!str_contains($contentType, 'multipart/form-data')) {
            return [];
        }

        // Extract boundary from Content-Type header
        if (!preg_match('/boundary=(.+)$/i', $contentType, $matches)) {
            return [];
        }

        $boundary = '--' . trim($matches[1]);
        $body = $request->getContent();
        
        if (empty($body)) {
            return [];
        }

        $parts = explode($boundary, $body);
        $data = [];

        foreach ($parts as $part) {
            $part = trim($part);
            
            // Skip empty parts and the closing boundary
            if (empty($part) || $part === '--') {
                continue;
            }

            // Split headers and content
            if (strpos($part, "\r\n\r\n") === false && strpos($part, "\n\n") === false) {
                continue;
            }

            $delimiter = strpos($part, "\r\n\r\n") !== false ? "\r\n\r\n" : "\n\n";
            list($headers, $content) = explode($delimiter, $part, 2);
            $content = rtrim($content, "\r\n--");

            // Parse headers to find field name
            if (preg_match('/Content-Disposition:.*name="([^"]+)"/i', $headers, $nameMatch)) {
                $fieldName = $nameMatch[1];
                
                // Skip file fields (they should be handled by Laravel's file handling)
                if (preg_match('/filename="([^"]*)"/i', $headers)) {
                    continue;
                }

                // Only process known fields
                $allowedFields = ['title', 'date', 'category', 'description', 'location', 
                                 'attendees', 'gift', 'provider', 'is_visible', 'eliminar_attendance_list'];
                
                if (in_array($fieldName, $allowedFields)) {
                    $value = trim($content);
                    
                    // Convert boolean strings
                    if ($fieldName === 'is_visible') {
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    }
                    
                    // Convert integer strings
                    if ($fieldName === 'attendees' && is_numeric($value)) {
                        $value = (int) $value;
                    }
                    
                    // Only add non-empty values
                    if ($value !== null && $value !== '') {
                        $data[$fieldName] = $value;
                    }
                }
            }
        }

        return $data;
    }
}
