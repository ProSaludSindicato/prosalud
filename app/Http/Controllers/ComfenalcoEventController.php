<?php

namespace App\Http\Controllers;

use App\Models\ComfenalcoEvent;
use App\Http\Requests\StoreComfenalcoEventRequest;
use App\Http\Requests\UpdateComfenalcoEventRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ComfenalcoEventController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): \Illuminate\Http\JsonResponse
    {
        $events = ComfenalcoEvent::orderBy('created_at', 'desc')->get();
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
                    if ($storedPath === false) {
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
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ComfenalcoEvent $comfenalcoEvent): \Illuminate\Http\JsonResponse
    {
        return response()->json($comfenalcoEvent);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateComfenalcoEventRequest $request, ComfenalcoEvent $comfenalcoEvent): \Illuminate\Http\JsonResponse
    {
        try {
            $data = $request->validated();
            
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
                    if ($storedPath === false) {
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

            Log::info('Evento Comfenalco actualizado', [
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

            return response()->json($comfenalcoEvent);
        } catch (\Exception $e) {
            Log::error('Error actualizando evento Comfenalco', [
                'event_id' => $comfenalcoEvent->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Error al actualizar el evento',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ComfenalcoEvent $comfenalcoEvent): \Illuminate\Http\JsonResponse
    {
        Log::info('Evento Comfenalco eliminado', [
            'event_id' => $comfenalcoEvent->id,
            'title' => $comfenalcoEvent->title,
            'user_id' => auth()->user()?->id,
            'timestamp' => now()->toISOString(),
        ]);

        $comfenalcoEvent->delete();

        return response()->json(['message' => 'Evento eliminado exitosamente']);
    }

    /**
     * Change the visibility of the specified resource.
     */
    public function changeVisibility(Request $request, ComfenalcoEvent $comfenalcoEvent): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'is_visible' => 'required|boolean'
        ]);

        $comfenalcoEvent->update(['is_visible' => $request->is_visible]);

        Log::info('Visibilidad de evento Comfenalco cambiada', [
            'event_id' => $comfenalcoEvent->id,
            'title' => $comfenalcoEvent->title,
            'is_visible' => $comfenalcoEvent->is_visible,
            'user_id' => $request->user()?->id,
            'timestamp' => now()->toISOString(),
        ]);

        return response()->json($comfenalcoEvent);
    }
}
