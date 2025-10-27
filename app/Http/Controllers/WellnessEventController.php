<?php

namespace App\Http\Controllers;

use App\Constants\Providers;
use App\Http\Requests\ChangeWellnessEventVisibilityRequest;
use App\Http\Requests\StoreWellnessEventRequest;
use App\Http\Requests\UpdateWellnessEventRequest;
use App\Models\WellnessEvent;
use App\Models\WellnessEventImage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

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
            $data = $request->validated();

            if (!isset($data['provider'])) {
                $data['provider'] = Providers::PROSALUD;
            }

            // Remove images from data before creating event
            $images = $data['images'] ?? [];
            unset($data['images']);

            $event = WellnessEvent::create($data);

            // Handle image uploads
            if (!empty($images)) {
                $this->handleImageUploads($event, $images);
            }

            $event->load('images');

            Log::info('Evento de bienestar creado', [
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

            return response()->json($event, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Error creando evento de bienestar', [
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
        $data = $request->validated();

        // Handle image uploads if provided
        if (isset($data['images'])) {
            $images = $data['images'];
            unset($data['images']);

            // Delete existing images
            $this->deleteEventImages($wellnessEvent);

            // Upload new images
            if (!empty($images)) {
                $this->handleImageUploads($wellnessEvent, $images);
            }
        }

        $wellnessEvent->update($data);
        $wellnessEvent->load('images');

        return response()->json($wellnessEvent);
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

        \Illuminate\Support\Facades\Log::info('Visibilidad de evento de bienestar cambiada', [
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
     * Add images to an existing event
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
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Remove a specific image from an event
     */
    public function removeImage(WellnessEvent $wellnessEvent, WellnessEventImage $image)
    {
        try {
            // Verify the image belongs to the event
            if ($image->event_id !== $wellnessEvent->id) {
                return response()->json(['message' => 'La imagen no pertenece a este evento'], 404);
            }

            $disk = config('filesystems.default');
            
            // Extract path from URL
            $path = $this->extractPathFromUrl($image->image_url);
            
            // Delete file from storage
            if ($path && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
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
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Handle image uploads for an event
     */
    private function handleImageUploads(WellnessEvent $event, array $images)
    {
        $disk = config('filesystems.default');
        
        foreach ($images as $index => $image) {
            $filename = 'wellness-events/' . $event->id . '/' . time() . '_' . $index . '.' . $image->getClientOriginalExtension();

            // Store the file with public visibility
            $path = Storage::disk($disk)->put(
                $filename,
                file_get_contents($image->getRealPath()),
                [
                    'visibility' => 'public',
                    'CacheControl' => 'max-age=31536000, public'
                ]
            );

            // Create database record with full URL
            WellnessEventImage::create([
                'event_id' => $event->id,
                'image_url' => Storage::disk($disk)->url($filename),
                'is_main' => $index === 0, // First image is main
            ]);
        }
    }

    /**
     * Delete all images for an event
     */
    private function deleteEventImages(WellnessEvent $event)
    {
        $disk = config('filesystems.default');
        
        foreach ($event->images as $image) {
            try {
                // Extract path from URL
                $path = $this->extractPathFromUrl($image->image_url);
                
                // Delete file from storage
                if ($path && Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
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
     * Extract path from CloudFront or S3 URL
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
        $path = preg_replace('#^storage/#', '', $path);
        
        return $path;
    }
}
