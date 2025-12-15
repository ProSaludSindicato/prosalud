<?php

namespace App\Http\Controllers;

use App\Http\Requests\{StoreComfenalcoEventRequest, UpdateComfenalcoEventRequest};
use App\Models\ComfenalcoEvent;
use Illuminate\Http\{Request, Response};
use Illuminate\Support\Facades\{Cache, Log, Storage};
use Illuminate\Support\Str;

class ComfenalcoEventController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): \Illuminate\Http\JsonResponse
    {
        $cacheKey = 'comfenalco_events:list';
        
        // Verificar si existe en caché
        $cachedData = Cache::get($cacheKey);
        if ($cachedData !== null) {
            Log::info('[CACHE HIT] Lista de eventos Comfenalco obtenida desde caché', [
                'cache_key' => $cacheKey,
                'events_count' => count($cachedData),
            ]);
            return response()->json($cachedData);
        }

        Log::info('[CACHE MISS] Consultando eventos Comfenalco desde BD', [
            'cache_key' => $cacheKey,
        ]);
        
        // Cache por 24 horas (1 día)
        $events = Cache::remember($cacheKey, now()->addDay(), function () {
            return ComfenalcoEvent::orderBy('created_at', 'desc')->get();
        });
        
        Log::info('[CACHE STORED] Lista de eventos Comfenalco guardada en caché', [
            'cache_key' => $cacheKey,
            'events_count' => count($events),
            'expires_at' => now()->addDay()->toISOString(),
        ]);

        return response()->json($events);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreComfenalcoEventRequest $request): \Illuminate\Http\JsonResponse
    {
        try {
            $data = $request->validated();

            // Try prosalud-public first, fallback to public disk if S3 is not available
            $disk = 'prosalud-public';
            $fallbackDisk = 'public';

            // Handle banner image upload
            if ($request->hasFile('banner_image')) {
                $bannerImage = $request->file('banner_image');
                $filename = 'comfenalco_events/' . Str::uuid() . '.' . $bannerImage->getClientOriginalExtension();

                Log::info('Intentando guardar imagen en disco', [
                    'filename' => $filename,
                    'disk' => $disk,
                    'file_size' => $bannerImage->getSize(),
                ]);

                try {
                    // Store the file using putFileAs for S3 storage
                    $storedPath = Storage::disk($disk)->putFileAs('comfenalco_events', $bannerImage, basename($filename));

                    // If S3 fails (returns false), try local disk
                    if (false === $storedPath) {
                        Log::warning('S3 upload failed, trying local disk', [
                            's3_disk' => $disk,
                            'fallback_disk' => $fallbackDisk,
                        ]);

                        $storedPath = Storage::disk($fallbackDisk)->putFileAs('comfenalco_events', $bannerImage, basename($filename));
                        $disk = $fallbackDisk;
                    }

                    $data['banner_image'] = $storedPath;

                    Log::info('Resultado del upload', [
                        'stored_path' => $storedPath,
                        'final_disk' => $disk,
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
                        $storedPath = Storage::disk($fallbackDisk)->putFileAs('comfenalco_events', $bannerImage, basename($filename));
                        $data['banner_image'] = $storedPath;
                        Log::info('Imagen guardada en disco de respaldo', ['stored_path' => $storedPath]);
                    } catch (\Exception $fallbackError) {
                        Log::error('Error también en disco de respaldo', [
                            'fallback_error' => $fallbackError->getMessage(),
                        ]);
                        throw new \Exception('No se pudo guardar la imagen en ningún disco disponible');
                    }
                }
            }

            $event = ComfenalcoEvent::create($data);

            // Invalidar cache de lista de eventos
            Cache::forget('comfenalco_events:list');
            Log::info('[CACHE INVALIDATED] Cache de eventos Comfenalco invalidado después de crear evento', [
                'event_id' => $event->id,
            ]);

            Log::info('Evento Comfenalco creado', [
                'event_id' => $event->id,
                'title' => $event->title,
                'category' => $event->category,
                'event_date' => $event->event_date,
                'is_visible' => $event->is_visible,
                'banner_image' => $event->banner_image,
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json($event, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Error creando evento Comfenalco', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
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
    public function show(ComfenalcoEvent $comfenalcoEvent): \Illuminate\Http\JsonResponse
    {
        try {
            return response()->json($comfenalcoEvent);
        } catch (\Exception $e) {
            Log::error('Error obteniendo evento Comfenalco', [
                'id' => $comfenalcoEvent->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al obtener el evento',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateComfenalcoEventRequest $request, ComfenalcoEvent $comfenalcoEvent): \Illuminate\Http\JsonResponse
    {
        try {
            Log::info('Iniciando actualización de evento Comfenalco', [
                'event_id' => $comfenalcoEvent->id,
                'request_data' => $request->except(['banner_image']), // Exclude image from log for security
                'has_banner_image' => $request->hasFile('banner_image'),
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]);

            $data = $request->validated();

            Log::info('Datos validados para actualización de evento Comfenalco', [
                'event_id' => $comfenalcoEvent->id,
                'validated_data' => $data,
                'has_banner_image' => $request->hasFile('banner_image'),
                'timestamp' => now()->toISOString(),
            ]);

            // Try prosalud-public first, fallback to public disk if S3 is not available
            $disk = 'prosalud-public';
            $fallbackDisk = 'public';

            // Handle banner image upload
            if ($request->hasFile('banner_image')) {
                // Delete old banner image if exists
                if ($comfenalcoEvent->banner_image) {
                    try {
                        // Try to determine which disk the old image is on
                        $oldDisk = $disk;
                        if (!Storage::disk($disk)->exists($comfenalcoEvent->banner_image)) {
                            $oldDisk = $fallbackDisk;
                        }

                        if (Storage::disk($oldDisk)->exists($comfenalcoEvent->banner_image)) {
                            Storage::disk($oldDisk)->delete($comfenalcoEvent->banner_image);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Error al eliminar imagen antigua', [
                            'old_image' => $comfenalcoEvent->banner_image,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $bannerImage = $request->file('banner_image');
                $filename = 'comfenalco_events/' . Str::uuid() . '.' . $bannerImage->getClientOriginalExtension();

                try {
                    // Store the file using putFileAs for S3 storage
                    $storedPath = Storage::disk($disk)->putFileAs('comfenalco_events', $bannerImage, basename($filename));

                    // If S3 fails (returns false), try local disk
                    if (false === $storedPath) {
                        Log::warning('S3 upload failed, trying local disk', [
                            's3_disk' => $disk,
                            'fallback_disk' => $fallbackDisk,
                        ]);

                        $storedPath = Storage::disk($fallbackDisk)->putFileAs('comfenalco_events', $bannerImage, basename($filename));
                        $disk = $fallbackDisk;
                    }

                    $data['banner_image'] = $storedPath;
                } catch (\Exception $e) {
                    Log::error('Error al guardar imagen en disco S3', [
                        'filename' => $filename,
                        'disk' => $disk,
                        'error' => $e->getMessage(),
                    ]);

                    // Try fallback disk
                    try {
                        $storedPath = Storage::disk($fallbackDisk)->putFileAs('comfenalco_events', $bannerImage, basename($filename));
                        $data['banner_image'] = $storedPath;
                    } catch (\Exception $fallbackError) {
                        Log::error('Error también en disco de respaldo', [
                            'fallback_error' => $fallbackError->getMessage(),
                        ]);
                        throw new \Exception('No se pudo guardar la imagen en ningún disco disponible');
                    }
                }
            }

            $comfenalcoEvent->update($data);

            // Invalidar cache de lista de eventos
            Cache::forget('comfenalco_events:list');
            Log::info('[CACHE INVALIDATED] Cache de eventos Comfenalco invalidado después de actualizar evento', [
                'event_id' => $comfenalcoEvent->id,
            ]);

            Log::info('Evento Comfenalco actualizado exitosamente', [
                'event_id' => $comfenalcoEvent->id,
                'title' => $comfenalcoEvent->title,
                'category' => $comfenalcoEvent->category,
                'event_date' => $comfenalcoEvent->event_date,
                'is_visible' => $comfenalcoEvent->is_visible,
                'banner_image' => $comfenalcoEvent->banner_image,
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            // Log response data
            Log::info('Enviando respuesta de evento Comfenalco actualizado', [
                'event_id' => $comfenalcoEvent->id,
                'response_data_keys' => array_keys($comfenalcoEvent->toArray()),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json($comfenalcoEvent);
        } catch (\Exception $e) {
            Log::error('Error actualizando evento Comfenalco', [
                'id' => $comfenalcoEvent->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
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
    public function destroy(Request $request, ComfenalcoEvent $comfenalcoEvent): \Illuminate\Http\JsonResponse
    {
        try {
            // Log incoming request data
            Log::info('Iniciando eliminación de evento Comfenalco', [
                'event_id' => $comfenalcoEvent->id,
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]);

            Log::info('Evento Comfenalco encontrado para eliminación', [
                'event_id' => $comfenalcoEvent->id,
                'title' => $comfenalcoEvent->title,
                'category' => $comfenalcoEvent->category,
                'event_date' => $comfenalcoEvent->event_date,
                'banner_image' => $comfenalcoEvent->banner_image,
                'user_id' => $request->user()?->id,
                'timestamp' => now()->toISOString(),
            ]);

            $comfenalcoEvent->delete();

            // Invalidar cache de lista de eventos
            Cache::forget('comfenalco_events:list');
            Log::info('[CACHE INVALIDATED] Cache de eventos Comfenalco invalidado después de eliminar evento', [
                'event_id' => $comfenalcoEvent->id,
            ]);

            Log::info('Evento Comfenalco eliminado exitosamente', [
                'event_id' => $comfenalcoEvent->id,
                'title' => $comfenalcoEvent->title,
                'user_id' => $request->user()?->id,
                'timestamp' => now()->toISOString(),
            ]);

            // Log response data
            Log::info('Enviando respuesta de evento Comfenalco eliminado', [
                'event_id' => $comfenalcoEvent->id,
                'response_message' => 'Evento eliminado exitosamente',
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json(['message' => 'Evento eliminado exitosamente']);
        } catch (\Exception $e) {
            Log::error('Error eliminando evento Comfenalco', [
                'id' => $comfenalcoEvent->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Error al eliminar el evento',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Change the visibility of the specified resource.
     */
    public function changeVisibility(Request $request, ComfenalcoEvent $comfenalcoEvent): \Illuminate\Http\JsonResponse
    {
        try {

            $request->validate([
                'is_visible' => 'required|boolean',
            ]);

            $comfenalcoEvent->update(['is_visible' => $request->is_visible]);

            // Invalidar cache de lista de eventos
            Cache::forget('comfenalco_events:list');
            Log::info('[CACHE INVALIDATED] Cache de eventos Comfenalco invalidado después de cambiar visibilidad', [
                'event_id' => $comfenalcoEvent->id,
            ]);

            Log::info('Visibilidad de evento Comfenalco cambiada', [
                'event_id' => $comfenalcoEvent->id,
                'title' => $comfenalcoEvent->title,
                'is_visible' => $comfenalcoEvent->is_visible,
                'user_id' => $request->user()?->id,
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json($comfenalcoEvent);
        } catch (\Exception $e) {
            Log::error('Error cambiando visibilidad de evento Comfenalco', [
                'id' => $comfenalcoEvent->id ?? null,
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Error al cambiar la visibilidad del evento',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }
}
