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

        \Illuminate\Support\Facades\Log::info('Evento de bienestar creado', [
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
    }

    /**
     * Remove a specific image from an event
     */
    public function removeImage(WellnessEvent $wellnessEvent, WellnessEventImage $image)
    {
        // Verify the image belongs to the event
        if ($image->event_id !== $wellnessEvent->id) {
            return response()->json(['message' => 'La imagen no pertenece a este evento'], 404);
        }

        // Delete file from storage
        $path = str_replace('/storage/', '', $image->image_url);
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        // Delete database record
        $image->delete();

        return response()->json(['message' => 'Imagen eliminada exitosamente']);
    }

    /**
     * Handle image uploads for an event
     */
    private function handleImageUploads(WellnessEvent $event, array $images)
    {
        foreach ($images as $index => $image) {
            $filename = 'wellness-events/' . $event->id . '/' . time() . '_' . $index . '.' . $image->getClientOriginalExtension();

            // Store the file
            $path = Storage::disk('public')->putFileAs('wellness-events/' . $event->id, $image, basename($filename));

            // Create database record
            WellnessEventImage::create([
                'event_id' => $event->id,
                'image_url' => Storage::url($path),
                'is_main' => $index === 0, // First image is main
            ]);
        }
    }

    /**
     * Delete all images for an event
     */
    private function deleteEventImages(WellnessEvent $event)
    {
        foreach ($event->images as $image) {
            // Delete file from storage
            $path = str_replace('/storage/', '', $image->image_url);
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

            // Delete database record
            $image->delete();
        }
    }
}
