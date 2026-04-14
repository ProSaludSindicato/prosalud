<?php

namespace App\Services;

use App\Models\InventoryCategory;
use App\Models\InventoryColor;
use App\Models\SstDeliveryItem;
use App\Models\SstDeliveryRecord;
use App\Models\SstReturnItem;
use App\Models\SstReturnRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SstDotacionService
{
    /**
     * @var array<string, string>|null
     */
    private ?array $inventoryColorLabelsById = null;

    private const SIGNATURE_DISK = 'prosalud-private';

    private const SIGNATURE_TEMP_URL_MINUTES = 10;

    /**
     * Shared cache key for dotación/EPP inventory payload (must invalidate across all PHP workers).
     *
     * @internal Exposed for tests and operational tooling (e.g. cache:clear scope).
     */
    public const INVENTORY_ITEMS_CACHE_KEY = 'sst_dotacion.inventory_items';

    /**
     * TTL as a safety net if a code path forgets to call {@see clearInventoryCache()}.
     */
    private const INVENTORY_ITEMS_CACHE_TTL_MINUTES = 10;

    public function __construct(private readonly AfiliadoService $afiliadoService) {}

    /**
     * Invalidate cached dotación/EPP inventory for all application workers (Redis/array/etc.).
     * Call when inventory products or categories used in entregas change.
     */
    public static function clearInventoryCache(): void
    {
        Cache::forget(self::INVENTORY_ITEMS_CACHE_KEY);
    }

    /**
     * Retrieve inventory items in frontend-friendly structure.
     */
    public function getInventoryItems(): array
    {
        return array_map(function (array $item) {
            $variants = $item['variants'] ?? null;

            return array_filter([
                'id' => $item['id'],
                'name' => $item['name'],
                'category' => $item['category'],
                'gender' => $item['gender'] ?? null,
                'variants' => $variants,
                'defaultColor' => $item['defaultColor'] ?? null,
                'description' => $item['description'] ?? null,
                'unit' => $item['unit'] ?? 'unidad',
            ], fn ($value) => $value !== null);
        }, self::inventoryItems());
    }

    /**
     * Get paginated affiliates list matching filters.
     */
    public function getAffiliates(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = max(1, min(200, (int) ($filters['pageSize'] ?? 50)));
        $statusFilter = $filters['status'] ?? 'active';
        $documentTypeFilter = isset($filters['documentType']) ? strtoupper($filters['documentType']) : null;
        $documentNumberFilter = $filters['documentNumber'] ?? null;
        $hospitalFilter = isset($filters['hospital']) ? strtoupper(trim($filters['hospital'])) : null;
        $searchTerm = isset($filters['searchTerm']) ? trim($filters['searchTerm']) : null;

        $affiliates = $this->buildAffiliatesCollection()
            ->filter(fn (array $affiliate) => $affiliate['active'] ?? false)
            ->sortBy(function (array $affiliate) {
                $fullName = trim(($affiliate['firstName'] ?? '').' '.($affiliate['lastName'] ?? ''));

                return mb_strtolower($fullName, 'UTF-8');
            })
            ->values();

        $filtered = $affiliates->filter(function (array $affiliate) use (
            $statusFilter,
            $documentTypeFilter,
            $documentNumberFilter,
            $hospitalFilter,
            $searchTerm
        ) {
            if ($statusFilter === 'active' && ! $affiliate['active']) {
                return false;
            }

            if ($statusFilter === 'inactive' && $affiliate['active']) {
                return false;
            }

            if ($documentTypeFilter && strtoupper($affiliate['documentType']) !== $documentTypeFilter) {
                return false;
            }

            if ($documentNumberFilter && $affiliate['documentNumber'] !== $documentNumberFilter) {
                return false;
            }

            if ($hospitalFilter && strtoupper($affiliate['hospital']) !== $hospitalFilter) {
                return false;
            }

            if ($searchTerm) {
                $needle = mb_strtolower($searchTerm);
                $haystack = mb_strtolower(implode(' ', [
                    $affiliate['firstName'] ?? '',
                    $affiliate['lastName'] ?? '',
                    $affiliate['documentNumber'] ?? '',
                    $affiliate['hospital'] ?? '',
                ]));

                if (mb_strpos($haystack, $needle) === false) {
                    return false;
                }
            }

            return true;
        })->values();

        $total = $filtered->count();
        $items = $filtered->slice(($page - 1) * $pageSize, $pageSize)->values()->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Find affiliate by document type and number.
     */
    public function findAffiliate(string $documentType, string $documentNumber): ?array
    {
        $documentType = strtoupper(trim($documentType));
        $documentNumber = trim($documentNumber);

        return $this->buildAffiliatesCollection()
            ->first(function (array $affiliate) use ($documentType, $documentNumber) {
                return strtoupper($affiliate['documentType']) === $documentType
                    && $affiliate['documentNumber'] === $documentNumber;
            });
    }

    /**
     * Create a delivery record with items and signature storage.
     */
    public function createDelivery(array $data): array
    {
        $affiliate = $this->findAffiliate($data['affiliateDocumentType'], $data['affiliateDocumentNumber']);

        if (! $affiliate) {
            throw new \RuntimeException('No se encontró el afiliado solicitado.');
        }

        $signatureMeta = $this->storeSignature($data['signatureData']);

        $userId = Auth::id();

        if (! $userId && ! empty($data['deliveredBy'])) {
            $userId = is_numeric($data['deliveredBy']) ? (int) $data['deliveredBy'] : null;
        }

        if (! $userId) {
            $userId = 1; // fallback while auth is not enabled
        }

        $user = User::find($userId);

        $recordId = (string) Str::uuid();

        $record = SstDeliveryRecord::create([
            'id' => $recordId,
            'affiliate_id' => $affiliate['id'],
            'affiliate_document_type' => $affiliate['documentType'],
            'affiliate_document_number' => $affiliate['documentNumber'],
            'affiliate_first_name' => $affiliate['firstName'],
            'affiliate_last_name' => $affiliate['lastName'],
            'affiliate_hospital' => $affiliate['hospital'],
            'affiliate_role' => $affiliate['role'],
            'affiliate_status' => $affiliate['status'],
            'delivered_by_user_id' => $user?->id,
            'delivered_by_name' => $user?->name ?? ($data['deliveredByName'] ?? ($user?->email ?? 'Usuario Sistema')),
            'delivered_at' => Carbon::now('America/Bogota'),
            'signature_path' => $signatureMeta['path'] ?? null,
            'signature_mime_type' => $signatureMeta['mime_type'] ?? null,
            'signed_document_type' => strtoupper($data['signedDocumentType']),
            'signed_document_number' => $data['signedDocumentNumber'],
            'notes' => $data['notes'] ?? null,
            'delivery_type' => $data['deliveryType'],
        ]);

        $itemsPayload = $data['items'] ?? [];

        foreach ($itemsPayload as $itemPayload) {
            $itemId = $itemPayload['itemId'];

            // Carnet es un ítem especial que no requiere validación de inventario
            $isCarnet = in_array(strtolower($itemId), ['__carnet__', 'carnet'], true);

            if ($isCarnet) {
                // Crear registro para Carnet sin validación de inventario
                SstDeliveryItem::create([
                    'id' => (string) Str::uuid(),
                    'delivery_id' => $record->id,
                    'item_id' => '__carnet__',
                    'item_name' => 'Carnet',
                    'item_category' => 'Documentación',
                    'item_gender' => null,
                    'unit' => 'unidad',
                    'variant_color' => null,
                    'variant_size' => null,
                    'variant_payload' => null,
                    'quantity' => (int) ($itemPayload['quantity'] ?? 1),
                ]);
            } else {
                // Validar y crear registro para ítems de inventario normales
                $inventoryItem = $this->findInventoryItem($itemId);
                if (! $inventoryItem) {
                    throw new \RuntimeException('Ítem de inventario no reconocido: '.$itemId);
                }

                SstDeliveryItem::create([
                    'id' => (string) Str::uuid(),
                    'delivery_id' => $record->id,
                    'item_id' => $inventoryItem['id'],
                    'item_name' => $inventoryItem['name'],
                    'item_category' => $inventoryItem['category'],
                    'item_gender' => $inventoryItem['gender'] ?? null,
                    'unit' => $inventoryItem['unit'] ?? 'unidad',
                    'variant_color' => $itemPayload['variant']['color'] ?? null,
                    'variant_size' => $itemPayload['variant']['size'] ?? null,
                    'variant_payload' => $itemPayload['variant'] ?? null,
                    'quantity' => (int) $itemPayload['quantity'],
                ]);
            }
        }

        return $this->transformDeliveryRecord($record->load('items', 'deliveredBy'));
    }

    /**
     * Retrieve delivery records with optional filters.
     */
    public function getDeliveries(array $filters): array
    {
        $affiliateId = $filters['affiliateId'] ?? null;
        $deliveredBy = $filters['deliveredBy'] ?? null;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = max(1, min(200, (int) ($filters['pageSize'] ?? 25)));

        $query = SstDeliveryRecord::query()
            ->with('items')
            ->orderByDesc('delivered_at');

        if ($affiliateId) {
            $query->where('affiliate_id', $affiliateId);
        }

        if ($deliveredBy) {
            $query->where(function (Builder $builder) use ($deliveredBy) {
                $builder->where('delivered_by_user_id', $deliveredBy)
                    ->orWhere('delivered_by_name', 'like', '%'.$deliveredBy.'%');
            });
        }

        $total = (clone $query)->count();
        $records = $query->skip(($page - 1) * $pageSize)
            ->take($pageSize)
            ->get();

        $items = $records->map(fn (SstDeliveryRecord $record) => $this->transformDeliveryRecord($record))->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Create a return record with items and signature storage.
     */
    public function createReturn(array $data): array
    {
        $affiliate = $this->findAffiliate($data['affiliateDocumentType'], $data['affiliateDocumentNumber']);

        if (! $affiliate) {
            throw new \RuntimeException('No se encontró el afiliado solicitado.');
        }

        $signatureMeta = $this->storeSignature($data['signatureData']);

        $userId = Auth::id();

        if (! $userId && ! empty($data['receivedBy'])) {
            $userId = is_numeric($data['receivedBy']) ? (int) $data['receivedBy'] : null;
        }

        if (! $userId) {
            $userId = 1; // fallback while auth is not enabled
        }

        $user = User::find($userId);

        $recordId = (string) Str::uuid();

        $record = SstReturnRecord::create([
            'id' => $recordId,
            'affiliate_id' => $affiliate['id'],
            'affiliate_document_type' => $affiliate['documentType'],
            'affiliate_document_number' => $affiliate['documentNumber'],
            'affiliate_first_name' => $affiliate['firstName'],
            'affiliate_last_name' => $affiliate['lastName'],
            'affiliate_hospital' => $affiliate['hospital'],
            'affiliate_role' => $affiliate['role'],
            'received_by_user_id' => $user?->id,
            'received_by_name' => $data['receivedByName'] ?? ($user?->name ?? ($user?->email ?? 'Usuario Sistema')),
            'returned_at' => Carbon::now('America/Bogota'),
            'signature_path' => $signatureMeta['path'] ?? null,
            'signature_mime_type' => $signatureMeta['mime_type'] ?? null,
            'signed_document_type' => strtoupper($data['signedDocumentType']),
            'signed_document_number' => $data['signedDocumentNumber'],
            'reason' => $data['reason'] ?? 'replacement',
            'notes' => $data['notes'] ?? null,
        ]);

        $itemsPayload = $data['items'] ?? [];

        foreach ($itemsPayload as $itemPayload) {
            $itemId = $itemPayload['itemId'];

            // Carnet es un ítem especial que no requiere validación de inventario
            $isCarnet = in_array(strtolower($itemId), ['__carnet__', 'carnet'], true);

            if ($isCarnet) {
                // Crear registro para Carnet sin validación de inventario
                SstReturnItem::create([
                    'id' => (string) Str::uuid(),
                    'return_id' => $record->id,
                    'item_id' => '__carnet__',
                    'item_name' => 'Carnet',
                    'item_category' => 'Documentación',
                    'item_gender' => null,
                    'unit' => 'unidad',
                    'variant_color' => null,
                    'variant_size' => null,
                    'variant_payload' => null,
                    'quantity' => (int) ($itemPayload['quantity'] ?? 1),
                ]);
            } else {
                // Validar y crear registro para ítems de inventario normales
                $inventoryItem = $this->findInventoryItem($itemId);
                if (! $inventoryItem) {
                    throw new \RuntimeException('Ítem de inventario no reconocido: '.$itemId);
                }

                SstReturnItem::create([
                    'id' => (string) Str::uuid(),
                    'return_id' => $record->id,
                    'item_id' => $inventoryItem['id'],
                    'item_name' => $inventoryItem['name'],
                    'item_category' => $inventoryItem['category'],
                    'item_gender' => $inventoryItem['gender'] ?? null,
                    'unit' => $inventoryItem['unit'] ?? 'unidad',
                    'variant_color' => $itemPayload['variant']['color'] ?? null,
                    'variant_size' => $itemPayload['variant']['size'] ?? null,
                    'variant_payload' => $itemPayload['variant'] ?? null,
                    'quantity' => (int) $itemPayload['quantity'],
                ]);
            }
        }

        return $this->transformReturnRecord($record->load('items', 'receivedBy'));
    }

    /**
     * Retrieve return records with optional filters.
     */
    public function getReturns(array $filters): array
    {
        $affiliateId = $filters['affiliateId'] ?? null;
        $receivedBy = $filters['receivedBy'] ?? null;
        $hospital = $filters['hospital'] ?? null;
        $startDate = $filters['startDate'] ?? null;
        $endDate = $filters['endDate'] ?? null;
        $documentNumber = $filters['documentNumber'] ?? null;
        $searchTerm = $filters['searchTerm'] ?? null;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = max(1, min(200, (int) ($filters['pageSize'] ?? 25)));

        $query = SstReturnRecord::query()
            ->with('items')
            ->orderByDesc('returned_at');

        if ($affiliateId) {
            $query->where('affiliate_id', $affiliateId);
        }

        if ($receivedBy) {
            $query->where(function (Builder $builder) use ($receivedBy) {
                $builder->where('received_by_user_id', $receivedBy)
                    ->orWhere('received_by_name', 'like', '%'.$receivedBy.'%');
            });
        }

        if ($hospital) {
            $query->where('affiliate_hospital', 'like', '%'.$hospital.'%');
        }

        if ($startDate) {
            try {
                $start = Carbon::parse($startDate)->startOfDay();
                $query->where('returned_at', '>=', $start);
            } catch (\Exception $e) {
                // Ignore invalid date
            }
        }

        if ($endDate) {
            try {
                $end = Carbon::parse($endDate)->endOfDay();
                $query->where('returned_at', '<=', $end);
            } catch (\Exception $e) {
                // Ignore invalid date
            }
        }

        if ($documentNumber) {
            $query->where('affiliate_document_number', 'like', '%'.$documentNumber.'%');
        }

        if ($searchTerm) {
            $search = trim($searchTerm);
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('affiliate_first_name', 'like', '%'.$search.'%')
                    ->orWhere('affiliate_last_name', 'like', '%'.$search.'%')
                    ->orWhere('affiliate_document_number', 'like', '%'.$search.'%')
                    ->orWhere('affiliate_hospital', 'like', '%'.$search.'%')
                    ->orWhere('received_by_name', 'like', '%'.$search.'%');
            });
        }

        $total = (clone $query)->count();
        $records = $query->skip(($page - 1) * $pageSize)
            ->take($pageSize)
            ->get();

        $items = $records->map(fn (SstReturnRecord $record) => $this->transformReturnRecord($record))->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Build affiliates collection enriched with last delivery information.
     */
    private function buildAffiliatesCollection(): Collection
    {
        $affiliatesRaw = collect($this->afiliadoService->getAllAfiliadosBasic());

        $lastDeliveries = SstDeliveryRecord::query()
            ->select('affiliate_id', DB::raw('MAX(delivered_at) as last_delivery_at'))
            ->groupBy('affiliate_id')
            ->pluck('last_delivery_at', 'affiliate_id');

        return $affiliatesRaw->map(function (array $afiliado) use ($lastDeliveries) {
            $documentType = strtoupper($afiliado['tipo_documento'] ?? '');
            $documentNumber = $afiliado['documento'] ?? '';
            $id = sprintf('%s-%s', $documentType, $documentNumber);

            // Seleccionar el convenio más reciente o activo usando la misma lógica que otros servicios
            $convenios = $afiliado['convenios'] ?? [];
            $convenio = $this->selectMostRecentConvenio($convenios);

            $hospital = $convenio['cliente'] ?? 'SIN ASIGNAR';
            $role = $convenio['proceso'] ?? null;
            $status = strtoupper($afiliado['estado'] ?? '');

            $lastDeliveryAt = $lastDeliveries[$id] ?? null;
            $lastDeliveryIso = $lastDeliveryAt
                ? Carbon::parse($lastDeliveryAt)->setTimezone('America/Bogota')->toISOString()
                : null;

            return [
                'id' => $id,
                'firstName' => trim($afiliado['nombres'] ?? ''),
                'lastName' => trim($afiliado['apellidos'] ?? ''),
                'documentType' => $documentType,
                'documentNumber' => $documentNumber,
                'hospital' => $hospital,
                'role' => $role,
                'active' => $status === 'ACTIVO',
                'status' => $status,
                'convenioStatus' => $convenio['estado'] ?? null,
                'lastDeliveryAt' => $lastDeliveryIso,
                'notes' => null,
            ];
        });
    }

    /**
     * Select the most recent convenio from an array of convenios.
     * Priority: Active convenios first, then by fecha_fin (most recent), then by fecha_ingreso.
     * Uses the same logic as AfiliadoService::selectMostRecentConvenio().
     *
     * @param  array  $convenios  Array of convenio arrays
     * @return array|null The most recent convenio or null if no convenios
     */
    private function selectMostRecentConvenio(array $convenios): ?array
    {
        if (empty($convenios)) {
            return null;
        }

        // Si solo hay un convenio, retornarlo directamente
        if (count($convenios) === 1) {
            return $convenios[0];
        }

        // Filtrar convenios activos
        $conveniosActivos = array_filter($convenios, function ($conv) {
            $estado = is_string($conv['estado'] ?? null) ? trim($conv['estado']) : '';

            return strcasecmp($estado, 'Activo') === 0;
        });

        $selectedConvenio = null;

        if (! empty($conveniosActivos)) {
            // Si hay convenios activos, seleccionar el más reciente/actual
            // Prioridad: fecha_fin vacía/null > fecha_fin más reciente > fecha_ingreso más reciente
            usort($conveniosActivos, function ($a, $b) {
                // Normalizar valores de fecha_fin (pueden ser null, '', o string con fecha)
                $aFechaFin = $a['fecha_fin'] ?? null;
                $bFechaFin = $b['fecha_fin'] ?? null;

                // Considerar vacío tanto null como string vacío
                $aFechaFinVacia = empty($aFechaFin) || $aFechaFin === null;
                $bFechaFinVacia = empty($bFechaFin) || $bFechaFin === null;

                // Si uno tiene fecha_fin vacía y el otro no, el vacío tiene prioridad (más reciente)
                if ($aFechaFinVacia && ! $bFechaFinVacia) {
                    return -1; // $a tiene prioridad (viene primero)
                }
                if (! $aFechaFinVacia && $bFechaFinVacia) {
                    return 1; // $b tiene prioridad (viene primero)
                }

                // Si ambos tienen fecha_fin, comparar por fecha_fin (más reciente primero)
                if (! $aFechaFinVacia && ! $bFechaFinVacia) {
                    $comparison = strcmp((string) $bFechaFin, (string) $aFechaFin);
                    if ($comparison !== 0) {
                        return $comparison; // Más reciente primero
                    }
                }

                // Si las fechas_fin son iguales o ambas vacías, usar fecha_ingreso como criterio secundario
                $aFechaIngreso = $a['fecha_ingreso'] ?? '';
                $bFechaIngreso = $b['fecha_ingreso'] ?? '';

                return strcmp((string) $bFechaIngreso, (string) $aFechaIngreso); // Más reciente primero
            });

            $selectedConvenio = reset($conveniosActivos);
        } else {
            // Si no hay activos, seleccionar el más reciente por fecha_fin
            usort($convenios, function ($a, $b) {
                // Normalizar valores de fecha_fin
                $aFechaFin = $a['fecha_fin'] ?? null;
                $bFechaFin = $b['fecha_fin'] ?? null;

                $aFechaFinVacia = empty($aFechaFin) || $aFechaFin === null;
                $bFechaFinVacia = empty($bFechaFin) || $bFechaFin === null;

                // Fecha_fin vacía tiene menor prioridad cuando no hay activos
                if ($aFechaFinVacia && ! $bFechaFinVacia) {
                    return 1; // $b tiene prioridad
                }
                if (! $aFechaFinVacia && $bFechaFinVacia) {
                    return -1; // $a tiene prioridad
                }

                // Comparar por fecha_fin (más reciente primero)
                if (! $aFechaFinVacia && ! $bFechaFinVacia) {
                    $comparison = strcmp((string) $bFechaFin, (string) $aFechaFin);
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                // Si las fechas_fin son iguales, usar fecha_ingreso
                $aFechaIngreso = $a['fecha_ingreso'] ?? '';
                $bFechaIngreso = $b['fecha_ingreso'] ?? '';

                return strcmp((string) $bFechaIngreso, (string) $aFechaIngreso);
            });

            $selectedConvenio = $convenios[0];
        }

        return $selectedConvenio;
    }

    private function transformDeliveryRecord(SstDeliveryRecord $record): array
    {
        $signatureUrl = null;
        if ($record->signature_path) {
            try {
                $disk = self::SIGNATURE_DISK;

                $diskInstance = Storage::disk($disk);

                if (method_exists($diskInstance, 'temporaryUrl')) {
                    $signatureUrl = $diskInstance->temporaryUrl(
                        $record->signature_path,
                        now()->addMinutes(self::SIGNATURE_TEMP_URL_MINUTES)
                    );
                } else {
                    $signatureUrl = $diskInstance->url($record->signature_path);
                }
            } catch (\Throwable $e) {
                Log::warning('No se pudo generar URL para la firma de dotación', [
                    'record_id' => $record->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'id' => $record->id,
            'affiliateId' => $record->affiliate_id,
            'deliveredAt' => $record->delivered_at?->setTimezone('America/Bogota')->toISOString(),
            'deliveredBy' => $record->delivered_by_name,
            'deliveryType' => $record->delivery_type,
            'items' => $record->items->map(function (SstDeliveryItem $item) {
                // Si es carnet, no buscar en inventario
                $isCarnet = in_array(strtolower($item->item_id), ['__carnet__', 'carnet'], true);

                if ($isCarnet) {
                    return [
                        'itemId' => $item->item_id,
                        'name' => $item->item_name,
                        'gender' => null,
                        'variant' => [],
                        'quantity' => $item->quantity,
                    ];
                }

                $inventoryItem = $this->findInventoryItem($item->item_id);
                $inventoryGender = is_array($inventoryItem) ? ($inventoryItem['gender'] ?? null) : null;

                $variant = array_filter([
                    'color' => $item->variant_color,
                    'size' => $item->variant_size,
                ], fn ($value) => $value !== null);
                $colorLabel = $this->labelForInventoryColorId($item->variant_color);
                if ($colorLabel !== null && $colorLabel !== '') {
                    $variant['colorLabel'] = $colorLabel;
                }

                return [
                    'itemId' => $item->item_id,
                    'name' => $item->item_name,
                    'gender' => $item->item_gender ?? $inventoryGender,
                    'variant' => $variant,
                    'quantity' => $item->quantity,
                ];
            })->all(),
            'signedDocumentUrl' => $signatureUrl,
            'signedDocumentType' => $record->signed_document_type,
            'signedDocumentNumber' => $record->signed_document_number,
            'notes' => $record->notes,
        ];
    }

    private function labelForInventoryColorId(?string $colorId): ?string
    {
        if ($colorId === null || $colorId === '') {
            return null;
        }

        if ($this->inventoryColorLabelsById === null) {
            $this->inventoryColorLabelsById = InventoryColor::query()->pluck('label', 'id')->all();
        }

        return $this->inventoryColorLabelsById[$colorId] ?? null;
    }

    private function findInventoryItem(string $itemId): ?array
    {
        foreach (self::inventoryItems() as $item) {
            if ($item['id'] === $itemId) {
                return $item;
            }
        }

        return null;
    }

    private static function inventoryItems(): array
    {
        return Cache::remember(
            self::INVENTORY_ITEMS_CACHE_KEY,
            now()->addMinutes(self::INVENTORY_ITEMS_CACHE_TTL_MINUTES),
            function (): array {
                $categories = InventoryCategory::query()
                    ->with(['products.variants.color'])
                    ->get()
                    ->filter(function (InventoryCategory $category) {
                        $normalized = Str::slug($category->name);

                        return in_array($normalized, ['dotacion', 'dotación', 'epp'], true);
                    });

                $items = [];

                foreach ($categories as $category) {
                    $categoryLabel = $category->name;

                    foreach ($category->products as $product) {
                        $variants = $product->variants->map(function ($variant) {
                            $payload = array_filter([
                                'color' => $variant->color_id,
                                'size' => $variant->size,
                            ], fn ($value) => $value !== null && $value !== '');

                            return $payload ?: null;
                        })->filter()->values()->all();

                        $defaultColor = $product->variants->firstWhere('color_id')?->color_id;

                        $items[] = array_filter([
                            'id' => $product->id,
                            'name' => $product->name,
                            'category' => $categoryLabel,
                            'gender' => $product->gender,
                            'variants' => ! empty($variants) ? $variants : null,
                            'defaultColor' => $defaultColor,
                            'description' => $product->description,
                            'unit' => 'unidad',
                        ], fn ($value) => $value !== null);
                    }
                }

                return $items;
            }
        );
    }

    private function transformReturnRecord(SstReturnRecord $record): array
    {
        $signatureUrl = null;
        if ($record->signature_path) {
            try {
                $disk = self::SIGNATURE_DISK;

                $diskInstance = Storage::disk($disk);

                if (method_exists($diskInstance, 'temporaryUrl')) {
                    $signatureUrl = $diskInstance->temporaryUrl(
                        $record->signature_path,
                        now()->addMinutes(self::SIGNATURE_TEMP_URL_MINUTES)
                    );
                } else {
                    $signatureUrl = $diskInstance->url($record->signature_path);
                }
            } catch (\Throwable $e) {
                Log::warning('No se pudo generar URL para la firma de devolución', [
                    'record_id' => $record->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'id' => $record->id,
            'affiliateId' => $record->affiliate_id,
            'returnedAt' => $record->returned_at?->setTimezone('America/Bogota')->toISOString(),
            'receivedBy' => $record->received_by_name,
            'receivedByName' => $record->received_by_name,
            'affiliateDocumentType' => $record->affiliate_document_type,
            'affiliateDocumentNumber' => $record->affiliate_document_number,
            'affiliateFirstName' => $record->affiliate_first_name,
            'affiliateLastName' => $record->affiliate_last_name,
            'affiliateFullName' => $record->affiliate_full_name,
            'affiliateHospital' => $record->affiliate_hospital,
            'affiliateRole' => $record->affiliate_role,
            'items' => $record->items->map(function (SstReturnItem $item) {
                // Si es carnet, no buscar en inventario
                $isCarnet = in_array(strtolower($item->item_id), ['__carnet__', 'carnet'], true);

                if ($isCarnet) {
                    return [
                        'itemId' => $item->item_id,
                        'variant' => null,
                        'quantity' => $item->quantity,
                    ];
                }

                $inventoryItem = $this->findInventoryItem($item->item_id);
                $inventoryGender = is_array($inventoryItem) ? ($inventoryItem['gender'] ?? null) : null;

                $variant = array_filter([
                    'color' => $item->variant_color,
                    'size' => $item->variant_size,
                ], fn ($value) => $value !== null);
                $colorLabel = $this->labelForInventoryColorId($item->variant_color);
                if ($colorLabel !== null && $colorLabel !== '') {
                    $variant['colorLabel'] = $colorLabel;
                }

                return [
                    'itemId' => $item->item_id,
                    'variant' => $variant === [] ? null : $variant,
                    'quantity' => $item->quantity,
                ];
            })->all(),
            'signedDocumentUrl' => $signatureUrl,
            'signedDocumentType' => $record->signed_document_type,
            'signedDocumentNumber' => $record->signed_document_number,
            'reason' => $record->reason,
            'notes' => $record->notes,
        ];
    }

    private function storeSignature(string $dataUrl): array
    {
        if (! preg_match('/^data:(image\/(png|jpe?g));base64,/', $dataUrl, $matches)) {
            throw new \InvalidArgumentException('Formato de firma inválido.');
        }

        $mimeType = $matches[1];
        $extension = $matches[2] === 'jpeg' ? 'jpg' : $matches[2];
        $base64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
        $binary = base64_decode($base64, true);

        if ($binary === false) {
            throw new \InvalidArgumentException('La firma no se pudo decodificar correctamente.');
        }

        $size = strlen($binary);
        if ($size > 1024 * 1024) {
            throw new \InvalidArgumentException('La firma excede el tamaño máximo permitido de 1MB.');
        }

        $disk = self::SIGNATURE_DISK;
        $path = 'dotacion-signatures/'.Str::uuid().'.'.$extension;

        Storage::disk($disk)->put($path, $binary, ['visibility' => 'private']);

        return [
            'path' => $path,
            'mime_type' => $mimeType,
        ];
    }
}
