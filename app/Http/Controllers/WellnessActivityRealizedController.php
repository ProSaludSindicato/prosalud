<?php

namespace App\Http\Controllers;

use App\Http\Requests\{PublishToGalleryRequest, StoreWellnessActivityRealizedRequest, UpdateWellnessActivityRealizedRequest};
use App\Models\{WellnessActivityEvidence, WellnessActivityRealized, WellnessEvent, WellnessEventImage, WellnessRequest};
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{DB, Log, Storage};
use Illuminate\Support\Str;

class WellnessActivityRealizedController extends Controller
{
    /**
     * Create activity realized for a wellness request.
     */
    public function store(StoreWellnessActivityRealizedRequest $request, int $wellness_request_id): JsonResponse
    {
        try {
            // Verify wellness request exists and is resolved
            $wellnessRequest = WellnessRequest::findOrFail($wellness_request_id);

            if ('resolved' !== $wellnessRequest->status) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se puede registrar actividad realizada para solicitudes aprobadas',
                ], 400);
            }

            // Check if activity already exists
            if (WellnessActivityRealized::where('wellness_request_id', $wellness_request_id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe una actividad realizada para esta solicitud',
                ], 400);
            }

            DB::beginTransaction();

            // Transform Spanish keys to English
            $data = [
                'wellness_request_id' => $wellness_request_id,
                'realized_date' => $request->input('fecha_realizada'),
                'real_location' => $request->input('ubicacion_real'),
                'real_attendees_count' => $request->input('numero_asistentes_real'),
                'realized_description' => $request->input('descripcion_realizada'),
                'gift_delivered' => $request->input('obsequio_entregado'),
            ];

            // Handle listado_asistencia file (private bucket)
            $listadoFile = $request->file('listado_asistencia');
            if ($listadoFile) {
                $data['listado_asistencia_path'] = $this->storeListadoAsistencia($listadoFile, $wellness_request_id);
            }

            // Create activity realized
            $activityRealized = WellnessActivityRealized::create($data);

            // Handle evidence images (public bucket)
            $evidencias = $request->file('evidencias', []);
            if (!empty($evidencias)) {
                $this->storeEvidencias($activityRealized, $evidencias);
            }

            DB::commit();

            $activityRealized->load('evidences');

            Log::info('Actividad realizada creada exitosamente', [
                'activity_realized_id' => $activityRealized->id,
                'wellness_request_id' => $wellness_request_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Actividad realizada registrada exitosamente',
                'data' => $this->formatActivityRealizedResponse($activityRealized),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creando actividad realizada', [
                'wellness_request_id' => $wellness_request_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar la actividad realizada',
                'error' => config('app.debug') ? $e->getMessage() : 'Ocurrió un error al procesar la solicitud',
            ], 500);
        }
    }

    /**
     * Get activity realized for a wellness request.
     */
    public function show(int $wellness_request_id): JsonResponse
    {
        try {
            $activityRealized = WellnessActivityRealized::where('wellness_request_id', $wellness_request_id)
                ->with('evidences')
                ->first();

            if (!$activityRealized) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró información de actividad realizada para esta solicitud',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatActivityRealizedResponse($activityRealized),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error obteniendo actividad realizada', [
                'wellness_request_id' => $wellness_request_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la actividad realizada',
                'error' => config('app.debug') ? $e->getMessage() : 'Ocurrió un error al procesar la solicitud',
            ], 500);
        }
    }

    /**
     * Update activity realized.
     */
    public function update(UpdateWellnessActivityRealizedRequest $request, int $wellness_request_id): JsonResponse
    {
        try {
            $activityRealized = WellnessActivityRealized::where('wellness_request_id', $wellness_request_id)
                ->firstOrFail();

            DB::beginTransaction();

            // Update basic fields if provided
            $updateData = [];
            if ($request->has('fecha_realizada')) {
                $updateData['realized_date'] = $request->input('fecha_realizada');
            }
            if ($request->has('ubicacion_real')) {
                $updateData['real_location'] = $request->input('ubicacion_real');
            }
            if ($request->has('numero_asistentes_real')) {
                $updateData['real_attendees_count'] = $request->input('numero_asistentes_real');
            }
            if ($request->has('descripcion_realizada')) {
                $updateData['realized_description'] = $request->input('descripcion_realizada');
            }
            if ($request->has('obsequio_entregado')) {
                $updateData['gift_delivered'] = $request->input('obsequio_entregado');
            }

            // Handle published_to_gallery update (allow explicitly setting to false)
            if ($request->has('publicado_en_galeria')) {
                $updateData['published_to_gallery'] = $request->boolean('publicado_en_galeria');
                // If setting to false, also clear gallery_event_id
                if (false === $updateData['published_to_gallery']) {
                    $updateData['gallery_event_id'] = null;
                }
            }

            // Handle listado_asistencia update
            if ($request->hasFile('listado_asistencia')) {
                // Delete old file if exists
                if ($activityRealized->listado_asistencia_path) {
                    try {
                        Storage::disk('prosalud-private')->delete($activityRealized->listado_asistencia_path);
                    } catch (\Exception $e) {
                        Log::warning('Error eliminando listado anterior', [
                            'path' => $activityRealized->listado_asistencia_path,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $listadoFile = $request->file('listado_asistencia');
                $updateData['listado_asistencia_path'] = $this->storeListadoAsistencia($listadoFile, $wellness_request_id);
            }

            // Handle delete listado_asistencia
            if ('true' === $request->input('eliminar_listado_asistencia')) {
                if ($activityRealized->listado_asistencia_path) {
                    try {
                        Storage::disk('prosalud-private')->delete($activityRealized->listado_asistencia_path);
                    } catch (\Exception $e) {
                        Log::warning('Error eliminando listado', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                $updateData['listado_asistencia_path'] = null;
            }

            // Update activity
            if (!empty($updateData)) {
                $activityRealized->update($updateData);
            }

            // Handle new evidencias
            $evidencias = $request->file('evidencias', []);
            if (!empty($evidencias)) {
                $this->storeEvidencias($activityRealized, $evidencias);
            }

            // Handle evidencias_seleccionadas (mark for gallery)
            if ($request->has('evidencias_seleccionadas')) {
                $selectedIds = $this->parseJsonArray($request->input('evidencias_seleccionadas'));
                if (!empty($selectedIds)) {
                    WellnessActivityEvidence::where('activity_realized_id', $activityRealized->id)
                        ->whereIn('id', $selectedIds)
                        ->update(['is_selected_for_gallery' => true]);
                }
            }

            // Handle evidencias_orden (update order of evidences)
            if ($request->has('evidencias_orden')) {
                $evidenciasOrden = $request->input('evidencias_orden');

                // Normalize format (support both object and array of objects)
                $orderMap = [];
                if (isset($evidenciasOrden[0]) && is_array($evidenciasOrden[0])) {
                    // Array of objects format: [{"evidence_id": 1, "order": 1}, ...]
                    foreach ($evidenciasOrden as $item) {
                        $evidenceId = $item['evidence_id'] ?? $item['id'] ?? null;
                        $order = $item['order'] ?? null;
                        if ($evidenceId && null !== $order) {
                            $orderMap[$evidenceId] = $order;
                        }
                    }
                } else {
                    // Object format: {"1": 1, "2": 2, ...}
                    $orderMap = $evidenciasOrden;
                }

                foreach ($orderMap as $evidenceId => $order) {
                    WellnessActivityEvidence::where('activity_realized_id', $activityRealized->id)
                        ->where('id', (int) $evidenceId)
                        ->update(['order' => (int) $order]);
                }

                // If event is published, we need to reorder images in the gallery event
                // Note: wellness_event_images doesn't have an order field, so we rely on
                // the order stored in wellness_activity_evidences. The frontend should
                // query evidences ordered by 'order' field when displaying gallery event.
            }

            // Handle imagen_principal_id - update gallery event if published
            if ($request->has('imagen_principal_id') && $activityRealized->published_to_gallery && $activityRealized->gallery_event_id) {
                $imagenPrincipalId = $request->input('imagen_principal_id');
                $principalEvidence = WellnessActivityEvidence::where('activity_realized_id', $activityRealized->id)
                    ->where('id', $imagenPrincipalId)
                    ->first();

                if ($principalEvidence) {
                    // Update all images in the gallery event to set is_main correctly
                    WellnessEventImage::where('event_id', $activityRealized->gallery_event_id)
                        ->update(['is_main' => false]);

                    // Set the main image based on the evidence URL
                    WellnessEventImage::where('event_id', $activityRealized->gallery_event_id)
                        ->where('image_url', $principalEvidence->image_url)
                        ->update(['is_main' => true]);
                }
            }

            // Handle evidencias_eliminadas
            if ($request->has('evidencias_eliminadas')) {
                $deletedIds = $this->parseJsonArray($request->input('evidencias_eliminadas'));
                if (!empty($deletedIds)) {
                    $evidencesToDelete = WellnessActivityEvidence::where('activity_realized_id', $activityRealized->id)
                        ->whereIn('id', $deletedIds)
                        ->get();

                    foreach ($evidencesToDelete as $evidence) {
                        $this->deleteEvidenceImage($evidence);
                        $evidence->delete();
                    }
                }
            }

            DB::commit();

            $activityRealized->refresh();
            $activityRealized->load('evidences');

            Log::info('Actividad realizada actualizada exitosamente', [
                'activity_realized_id' => $activityRealized->id,
                'wellness_request_id' => $wellness_request_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Actividad realizada actualizada exitosamente',
                'data' => $this->formatActivityRealizedResponse($activityRealized),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error actualizando actividad realizada', [
                'wellness_request_id' => $wellness_request_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la actividad realizada',
                'error' => config('app.debug') ? $e->getMessage() : 'Ocurrió un error al procesar la solicitud',
            ], 500);
        }
    }

    /**
     * Publish activity to gallery.
     */
    public function publishToGallery(PublishToGalleryRequest $request, int $wellness_request_id): JsonResponse
    {
        try {
            // Verify wellness request from URL matches the one in body (if provided)
            if ($request->has('wellness_request_id') && $request->input('wellness_request_id') != $wellness_request_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'El ID de la solicitud en el body no coincide con el de la URL',
                ], 400);
            }

            $activityRealized = WellnessActivityRealized::where('wellness_request_id', $wellness_request_id)
                ->with('evidences')
                ->firstOrFail();

            // Verify selected evidences exist and belong to this activity
            $selectedIds = $request->input('evidencias_seleccionadas');
            $selectedEvidences = WellnessActivityEvidence::where('activity_realized_id', $activityRealized->id)
                ->whereIn('id', $selectedIds)
                ->get()
                ->keyBy('id'); // Key by ID for easy lookup

            if ($selectedEvidences->count() !== count($selectedIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Una o más evidencias seleccionadas no existen o no pertenecen a esta actividad',
                ], 400);
            }

            // Get order mapping if provided, otherwise use array order
            $evidenciasOrden = $request->input('evidencias_orden', []);
            $imagenPrincipalId = $request->input('imagen_principal_id');

            // Validate imagen_principal_id is in selected evidences
            if ($imagenPrincipalId && !in_array($imagenPrincipalId, $selectedIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La imagen principal debe estar entre las evidencias seleccionadas',
                ], 400);
            }

            // If no imagen_principal_id provided, use first evidence
            if (!$imagenPrincipalId && !empty($selectedIds)) {
                $imagenPrincipalId = $selectedIds[0];
            }

            DB::beginTransaction();

            // Create wellness event
            $wellnessRequest = $activityRealized->wellnessRequest;
            $eventData = [
                'title' => $request->input('title'),
                'date' => $activityRealized->realized_date,
                'category' => $request->input('category'),
                'description' => $request->input('description'),
                'location' => $activityRealized->real_location,
                'attendees' => $activityRealized->real_attendees_count,
                'gift' => $activityRealized->gift_delivered,
                'is_visible' => $request->input('is_visible', true),
                'wellness_request_id' => $wellnessRequest->id,
                'review_status' => 'pending', // Events from requests also need review
            ];

            $galleryEvent = WellnessEvent::create($eventData);

            // Sort evidences by order if provided, otherwise use array order
            $sortedEvidences = [];
            if (!empty($evidenciasOrden)) {
                // Normalize evidencias_orden format (support both object and array of objects)
                $orderMap = [];
                if (isset($evidenciasOrden[0]) && is_array($evidenciasOrden[0])) {
                    // Array of objects format: [{"evidence_id": 1, "order": 1}, ...]
                    foreach ($evidenciasOrden as $item) {
                        $evidenceId = $item['evidence_id'] ?? $item['id'] ?? null;
                        $order = $item['order'] ?? null;
                        if ($evidenceId && null !== $order) {
                            $orderMap[$evidenceId] = $order;
                        }
                    }
                } else {
                    // Object format: {"1": 1, "2": 2, ...}
                    $orderMap = $evidenciasOrden;
                }

                // Sort by provided order
                foreach ($orderMap as $evidenceId => $order) {
                    $evidenceId = (int) $evidenceId; // Ensure it's an integer
                    if (isset($selectedEvidences[$evidenceId])) {
                        $sortedEvidences[] = [
                            'evidence' => $selectedEvidences[$evidenceId],
                            'order' => (int) $order,
                        ];
                    }
                }
                // Sort by order value
                usort($sortedEvidences, function ($a, $b) {
                    return $a['order'] <=> $b['order'];
                });
            } else {
                // Use array order from selectedIds
                foreach ($selectedIds as $index => $evidenceId) {
                    if (isset($selectedEvidences[$evidenceId])) {
                        $sortedEvidences[] = [
                            'evidence' => $selectedEvidences[$evidenceId],
                            'order' => $index + 1,
                        ];
                    }
                }
            }

            // Create wellness event images from selected evidences in the correct order
            foreach ($sortedEvidences as $item) {
                $evidence = $item['evidence'];
                WellnessEventImage::create([
                    'event_id' => $galleryEvent->id,
                    'image_url' => $evidence->image_url,
                    'is_main' => $evidence->id == $imagenPrincipalId,
                ]);
            }

            // Update order in evidences table
            foreach ($sortedEvidences as $item) {
                $evidence = $item['evidence'];
                $evidence->update(['order' => $item['order']]);
            }

            // Mark evidences as selected for gallery
            WellnessActivityEvidence::whereIn('id', $selectedIds)
                ->update(['is_selected_for_gallery' => true]);

            // Update activity realized
            $activityRealized->update([
                'published_to_gallery' => true,
                'gallery_event_id' => $galleryEvent->id,
            ]);

            DB::commit();

            Log::info('Actividad publicada en galería exitosamente', [
                'activity_realized_id' => $activityRealized->id,
                'event_id' => $galleryEvent->id,
                'wellness_request_id' => $wellness_request_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Actividad publicada en galería exitosamente',
                'data' => [
                    'event_id' => $galleryEvent->id,
                    'wellness_request_id' => $wellness_request_id,
                    'publicado_en_galeria' => true,
                    'evento_galeria_id' => $galleryEvent->id,
                ],
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error publicando actividad en galería', [
                'wellness_request_id' => $wellness_request_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al publicar la actividad en galería',
                'error' => config('app.debug') ? $e->getMessage() : 'Ocurrió un error al procesar la solicitud',
            ], 500);
        }
    }

    /**
     * Store evidencias (images) in public bucket.
     */
    private function storeEvidencias(WellnessActivityRealized $activityRealized, array $evidencias): void
    {
        $disk = 'prosalud-public';
        $fallbackDisk = 'public';

        $currentMaxOrder = WellnessActivityEvidence::where('activity_realized_id', $activityRealized->id)
            ->max('order') ?? 0;

        foreach ($evidencias as $index => $image) {
            try {
                $extension = $image->getClientOriginalExtension() ?: $this->getExtensionFromMimeType($image->getMimeType());
                $filename = $this->generateDescriptiveFilenameForEvidence($image, $activityRealized->id, $index, $extension);
                $storagePath = 'wellness-activities/' . $activityRealized->id . '/' . $filename;

                // Store file
                $storedPath = Storage::disk($disk)->putFileAs(
                    'wellness-activities/' . $activityRealized->id,
                    $image,
                    $filename
                );

                $finalDisk = $disk;
                if (false === $storedPath) {
                    Log::warning('Failed to store evidence in public bucket, trying fallback', [
                        'activity_realized_id' => $activityRealized->id,
                    ]);
                    $storedPath = Storage::disk($fallbackDisk)->putFileAs(
                        'wellness-activities/' . $activityRealized->id,
                        $image,
                        $filename
                    );
                    if ($storedPath) {
                        $finalDisk = $fallbackDisk;
                    } else {
                        throw new \Exception('No se pudo guardar la evidencia en ningún disco disponible');
                    }
                }

                // Generate URL
                if ('prosalud-public' === $finalDisk) {
                    $baseUrl = config('filesystems.disks.prosalud-public.url');
                    $imageUrl = rtrim($baseUrl, '/') . '/' . ltrim($storedPath, '/');
                } else {
                    $baseUrl = config('filesystems.disks.public.url');
                    $imageUrl = rtrim($baseUrl, '/') . '/' . ltrim($storedPath, '/');
                }

                // Create evidence record
                WellnessActivityEvidence::create([
                    'activity_realized_id' => $activityRealized->id,
                    'image_url' => $imageUrl,
                    'is_selected_for_gallery' => false,
                    'order' => $currentMaxOrder + $index + 1,
                ]);
            } catch (\Exception $e) {
                Log::error('Error guardando evidencia', [
                    'activity_realized_id' => $activityRealized->id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }

    /**
     * Store listado_asistencia file in private bucket
     * Returns the stored path (always uses prosalud-private bucket).
     */
    private function storeListadoAsistencia($file, int $wellness_request_id): string
    {
        $disk = 'prosalud-private';
        $fallbackDisk = 'local';

        $extension = $file->getClientOriginalExtension() ?: $this->getExtensionFromMimeType($file->getMimeType());
        $filename = $this->generateDescriptiveFilenameForListado($file, $wellness_request_id, $extension);

        // Store file
        $storedPath = Storage::disk($disk)->putFileAs(
            'wellness-activities/listados/' . date('Y/m'),
            $file,
            $filename
        );

        if (false === $storedPath) {
            Log::warning('Failed to store listado in private bucket, trying fallback', [
                'wellness_request_id' => $wellness_request_id,
            ]);
            $storedPath = Storage::disk($fallbackDisk)->putFileAs(
                'wellness-activities/listados/' . date('Y/m'),
                $file,
                $filename
            );
            if (!$storedPath) {
                throw new \Exception('No se pudo guardar el listado de asistencia en ningún disco disponible');
            }
        }

        return $storedPath;
    }

    /**
     * Delete evidence image from storage.
     */
    private function deleteEvidenceImage(WellnessActivityEvidence $evidence): void
    {
        try {
            // Extract path from URL
            $url = $evidence->image_url;
            $path = null;

            // Try to extract path from prosalud-public URL
            $publicBaseUrl = config('filesystems.disks.prosalud-public.url');
            if ($publicBaseUrl && str_starts_with($url, $publicBaseUrl)) {
                $path = str_replace($publicBaseUrl . '/', '', $url);
                $disk = 'prosalud-public';
            } else {
                // Try public disk
                $baseUrl = config('filesystems.disks.public.url');
                if ($baseUrl && str_starts_with($url, $baseUrl)) {
                    $path = str_replace($baseUrl . '/', '', $url);
                    $disk = 'public';
                }
            }

            if ($path && isset($disk)) {
                Storage::disk($disk)->delete($path);
            }
        } catch (\Exception $e) {
            Log::warning('Error eliminando imagen de evidencia', [
                'evidence_id' => $evidence->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse JSON array string to array.
     */
    private function parseJsonArray(?string $jsonString): array
    {
        if (empty($jsonString)) {
            return [];
        }

        // Try to decode as JSON
        $decoded = json_decode($jsonString, true);
        if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
            return $decoded;
        }

        // If not JSON, try as comma-separated string
        return array_filter(array_map('trim', explode(',', $jsonString)));
    }

    /**
     * Format activity realized response with Spanish keys.
     */
    private function formatActivityRealizedResponse(WellnessActivityRealized $activityRealized): array
    {
        $response = [
            'id' => $activityRealized->id,
            'wellness_request_id' => $activityRealized->wellness_request_id,
            'fecha_realizada' => $activityRealized->realized_date->format('Y-m-d'),
            'ubicacion_real' => $activityRealized->real_location,
            'numero_asistentes_real' => $activityRealized->real_attendees_count,
            'descripcion_realizada' => $activityRealized->realized_description,
            'obsequio_entregado' => $activityRealized->gift_delivered,
            'evidencias' => $this->formatEvidencias($activityRealized),
            'publicado_en_galeria' => $activityRealized->published_to_gallery,
            'evento_galeria_id' => $activityRealized->gallery_event_id,
            'created_at' => $activityRealized->created_at->toIso8601String(),
            'updated_at' => $activityRealized->updated_at->toIso8601String(),
        ];

        // Add listado_asistencia if exists
        if ($activityRealized->listado_asistencia_path) {
            $fileUrl = null;
            $urlExpiresAt = null;

            try {
                // Generate temporary signed URL for private bucket file (valid for 1 hour)
                $storage = Storage::disk('prosalud-private');
                $fileUrl = $storage->temporaryUrl($activityRealized->listado_asistencia_path, now()->addHours(1));
                $urlExpiresAt = now()->addHours(1)->toIso8601String();
            } catch (\Exception $e) {
                Log::warning('Failed to generate temporary URL for listado_asistencia', [
                    'activity_realized_id' => $activityRealized->id,
                    'path' => $activityRealized->listado_asistencia_path,
                    'error' => $e->getMessage(),
                ]);
                // If temporary URL generation fails, try fallback disk
                try {
                    $storage = Storage::disk('local');
                    if (method_exists($storage, 'temporaryUrl')) {
                        $fileUrl = $storage->temporaryUrl($activityRealized->listado_asistencia_path, now()->addHours(1));
                        $urlExpiresAt = now()->addHours(1)->toIso8601String();
                    }
                } catch (\Exception $fallbackError) {
                    Log::error('Failed to generate temporary URL from fallback disk', [
                        'error' => $fallbackError->getMessage(),
                    ]);
                }
            }

            $response['listado_asistencia'] = [
                'id' => $activityRealized->id,
                'file_url' => $fileUrl,
                'url_expires_at' => $urlExpiresAt,
            ];
        }

        return $response;
    }

    /**
     * Format evidencias with main image information.
     */
    private function formatEvidencias(WellnessActivityRealized $activityRealized): array
    {
        // Get main image URL from gallery event if published
        $mainImageUrl = null;
        if ($activityRealized->published_to_gallery && $activityRealized->gallery_event_id) {
            $mainImage = WellnessEventImage::where('event_id', $activityRealized->gallery_event_id)
                ->where('is_main', true)
                ->first();
            if ($mainImage) {
                $mainImageUrl = $mainImage->image_url;
            }
        }

        return $activityRealized->evidences->map(function ($evidence) use ($mainImageUrl) {
            return [
                'id' => $evidence->id,
                'image_url' => $evidence->image_url,
                'is_selected_for_gallery' => $evidence->is_selected_for_gallery,
                'order' => $evidence->order,
                'is_main' => $mainImageUrl && $evidence->image_url === $mainImageUrl,
            ];
        })->sortBy('order')->values()->toArray();
    }

    /**
     * Generate a simple but descriptive filename for evidence images
     * Format: Evid-Act[ID]-[UniqueId]-[Index].[ext].
     */
    private function generateDescriptiveFilenameForEvidence(
        \Illuminate\Http\UploadedFile $image,
        int $activityRealizedId,
        int $index,
        string $extension,
    ): string {
        $uniqueId = substr(Str::uuid()->toString(), 0, 6);

        // Build simple filename: Evid-Act[ID]-[UniqueId]-[Index].[ext]
        return sprintf(
            'Evid-Act%d-%s-%d.%s',
            $activityRealizedId,
            $uniqueId,
            $index + 1,
            $extension
        );
    }

    /**
     * Generate a simple but descriptive filename for listado_asistencia
     * Format: Listado-Req[ID]-[UniqueId].[ext].
     */
    private function generateDescriptiveFilenameForListado(
        \Illuminate\Http\UploadedFile $file,
        int $wellnessRequestId,
        string $extension,
    ): string {
        $uniqueId = substr(Str::uuid()->toString(), 0, 6);

        // Build simple filename: Listado-Req[ID]-[UniqueId].[ext]
        return sprintf(
            'Listado-Req%d-%s.%s',
            $wellnessRequestId,
            $uniqueId,
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
