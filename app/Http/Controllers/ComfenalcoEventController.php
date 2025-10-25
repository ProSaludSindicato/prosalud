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
        $events = ComfenalcoEvent::query()->orderBy('created_at', 'desc')->get();
        return response()->json($events);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreComfenalcoEventRequest $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validated();

        // Handle banner image upload
        if ($request->hasFile('banner_image')) {
            $bannerImage = $request->file('banner_image');
            $filename = 'comfenalco_events/' . Str::uuid() . '.' . $bannerImage->getClientOriginalExtension();
            $bannerImage->storeAs('public', $filename);
            $data['banner_image'] = $filename;
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
        $data = $request->validated();

        // Handle banner image upload
        if ($request->hasFile('banner_image')) {
            // Delete old banner image if exists
            if ($comfenalcoEvent->banner_image && Storage::exists($comfenalcoEvent->banner_image)) {
                Storage::delete($comfenalcoEvent->banner_image);
            }

            $bannerImage = $request->file('banner_image');
            $filename = 'comfenalco_events/' . Str::uuid() . '.' . $bannerImage->getClientOriginalExtension();
            $bannerImage->storeAs('public', $filename);
            $data['banner_image'] = $filename;
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
