<?php

namespace App\Http\Controllers;

use App\Constants\Providers;
use App\Http\Requests\ChangeWellnessEventVisibilityRequest;
use App\Http\Requests\StoreWellnessEventRequest;
use App\Http\Requests\UpdateWellnessEventRequest;
use App\Models\WellnessEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

        $event = WellnessEvent::create($data);
        $event->load('images');

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

        if (array_key_exists('is_visible', $validated)) {
            $wellnessEvent->is_visible = (bool) $validated['is_visible'];
        } else {
            $wellnessEvent->is_visible = !$wellnessEvent->is_visible;
        }

        $wellnessEvent->save();

        return response()->json([
            'id' => $wellnessEvent->id,
            'is_visible' => $wellnessEvent->is_visible,
        ]);
    }
}
