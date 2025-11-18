<?php

namespace App\Http\Controllers;

use App\Constants\Providers;
use App\Http\Requests\{ChangeWellnessEventVisibilityRequest, StoreWellnessEventRequest, UpdateWellnessEventRequest};
use App\Models\{WellnessEvent, WellnessEventImage};
use Illuminate\Http\{Request, Response};
use Illuminate\Support\Facades\{Log, Storage};

class WellnessEventController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = WellnessEvent::query()->with('images');

        // Optional filters
        if ($request->filled('is_visible')) {
            $query->where('is_visible', $request->boolean('is_visible'));
        }
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

        $events = $query->orderByDesc('date')->paginate((int) $request->integer('per_page', 15));

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

            // Remove images from data before creating event
            $images = $data['images'] ?? [];
            unset($data['images']);

            Log::info('Datos validados para evento de bienestar', [
                'validated_data' => $data,
                'images_count' => count($images),
                'timestamp' => now()->toISOString(),
            ]);

            $event = WellnessEvent::create($data);

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

            return response()->json($event, Response::HTTP_CREATED);
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
     * Display the specified resource.
     */
    public function show(WellnessEvent $wellnessEvent)
    {
        $wellnessEvent->load('images');

        return response()->json($wellnessEvent);
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

            Log::info('Datos validados para actualización de evento de bienestar', [
                'event_id' => $wellnessEvent->id,
                'validated_data' => $data,
                'has_images' => isset($data['images']),
                'timestamp' => now()->toISOString(),
            ]);

            // Handle image uploads if provided
            if (isset($data['images'])) {
                $images = $data['images'];
                unset($data['images']);

                Log::info('Procesando imágenes para actualización de evento de bienestar', [
                    'event_id' => $wellnessEvent->id,
                    'images_count' => count($images),
                    'timestamp' => now()->toISOString(),
                ]);

                // Delete existing images
                $this->deleteEventImages($wellnessEvent);

                // Upload new images
                if (!empty($images)) {
                    $this->handleImageUploads($wellnessEvent, $images);
                }
            }

            $wellnessEvent->update($data);
            $wellnessEvent->load('images');

            Log::info('Evento de bienestar actualizado exitosamente', [
                'event_id' => $wellnessEvent->id,
                'title' => $wellnessEvent->title,
                'category' => $wellnessEvent->category,
                'date' => $wellnessEvent->date,
                'is_visible' => $wellnessEvent->is_visible,
                'images_count' => $wellnessEvent->images->count(),
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

            return response()->json($wellnessEvent);
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
}
