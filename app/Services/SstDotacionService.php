<?php

namespace App\Services;

use App\Exceptions\AffiliateServiceUnavailableException;
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

        $fullCollection = $this->buildAffiliatesCollection();
        $rawTotal = $fullCollection->count();
        $activeFromFile = $fullCollection->filter(fn (array $affiliate) => $affiliate['active'] ?? false)->count();

        $affiliates = $fullCollection
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

        if ($total === 0 && $page === 1) {
            $this->logEmptyAffiliatesListContext(
                $filters,
                $rawTotal,
                $activeFromFile,
                $affiliates->count(),
                $fullCollection,
            );
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Resolve a single affiliate from a source that actually carries convenios — and therefore
     * the hospital. Returns null when unknown or when no valid source is reachable.
     *
     * Critical: when the ProSanet API is enabled, the per-document "detail" endpoint is the ONLY
     * valid hospital source. The summary catalog behind getAllAfiliadosBasic() maps every item with
     * 'convenios' => [] (see ProSaNetAfiliadoMapper::mapSummaryItemToBasicAfiliado), so resolving a
     * hospital from it always degrades to 'SIN ASIGNAR'. Never use the catalog for hospitals here.
     *
     * @return array<string, mixed>|null
     */
    public function findAffiliateWithConvenios(string $documentType, string $documentNumber): ?array
    {
        try {
            return $this->findAffiliate($documentType, $documentNumber);
        } catch (AffiliateServiceUnavailableException) {
            // Bulk callers skip what they cannot resolve instead of aborting the whole run.
            return null;
        }
    }

    /**
     * Find affiliate by document type and number.
     *
     * When the ProSanet API is enabled, the per-document "detail" endpoint is the only acceptable
     * source: it is the sole one carrying convenios, and therefore hospital and proceso. The bulk
     * summary catalog maps every item with 'convenios' => [] (ProSaNetAfiliadoMapper::
     * mapSummaryItemToBasicAfiliado), so falling back to it silently produces an affiliate with
     * hospital 'SIN ASIGNAR' and role null — which is how deliveries ended up stored without
     * hospital even though the panel had just shown the real one. Resyncing that catalog also costs
     * 50+ paginated requests, hanging interactive requests until the browser times out.
     *
     * So: fail loudly instead of persisting data we know is wrong.
     *
     * @throws AffiliateServiceUnavailableException When the detail lookup is unavailable and no
     *                                              convenio-bearing source can be used.
     */
    public function findAffiliate(string $documentType, string $documentNumber): ?array
    {
        if ($this->afiliadoService->isProsanetApiEnabled()) {
            $fromApi = $this->afiliadoService->getAfiliadoBasicWithConvenios($documentType, $documentNumber);

            if ($fromApi === null) {
                return null;
            }

            if ($fromApi === false) {
                throw new AffiliateServiceUnavailableException;
            }

            return $this->mapAfiliadoBasicToDotacionRecord(
                $fromApi,
                $this->resolveLastDeliveryAtIso($documentType, $documentNumber),
            );
        }

        // API disabled: the Excel-backed catalog does carry real convenios, so it is a valid source.
        return $this->matchAffiliateInCollection(
            $this->buildAffiliatesCollection(),
            $documentType,
            $documentNumber,
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $collection
     */
    private function matchAffiliateInCollection(Collection $collection, string $documentType, string $documentNumber): ?array
    {
        $documentTypeNormalized = strtoupper(trim($documentType));
        $documentNumberNormalized = trim($documentNumber);

        return $collection->first(function (array $affiliate) use ($documentTypeNormalized, $documentNumberNormalized) {
            return strtoupper($affiliate['documentType']) === $documentTypeNormalized
                && $affiliate['documentNumber'] === $documentNumberNormalized;
        });
    }

    /**
     * Create a delivery record with items and signature storage.
     */
    public function createDelivery(array $data): array
    {
        $documentType = strtoupper(trim((string) $data['affiliateDocumentType']));
        $documentNumber = trim((string) $data['affiliateDocumentNumber']);

        $affiliate = $this->findAffiliate($documentType, $documentNumber);

        if (! $affiliate) {
            $this->logAffiliateMissDiagnostics($documentType, $documentNumber);

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
        $itemsPayload = $data['items'] ?? [];

        $record = DB::transaction(function () use ($data, $affiliate, $signatureMeta, $user, $recordId, $itemsPayload, $documentType, $documentNumber) {
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

            foreach ($itemsPayload as $itemPayload) {
                $itemId = $itemPayload['itemId'];

                // Carnet es un ítem especial que no requiere validación de inventario
                $isCarnet = in_array(strtolower($itemId), ['__carnet__', 'carnet'], true);

                if ($isCarnet) {
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
                    $inventoryItem = $this->findInventoryItem($itemId);
                    if (! $inventoryItem) {
                        $this->logDeliveryInventoryItemMiss($documentType, $documentNumber, (string) $itemId);

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

            return $record;
        });

        $this->logDeliveryCreated($record, $itemsPayload);

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
        $documentType = strtoupper(trim((string) $data['affiliateDocumentType']));
        $documentNumber = trim((string) $data['affiliateDocumentNumber']);

        $affiliate = $this->findAffiliate($documentType, $documentNumber);

        if (! $affiliate) {
            $this->logAffiliateMissDiagnostics($documentType, $documentNumber);

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
        $itemsPayload = $data['items'] ?? [];

        $record = DB::transaction(function () use ($data, $affiliate, $signatureMeta, $user, $recordId, $itemsPayload, $documentType, $documentNumber) {
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

            foreach ($itemsPayload as $itemPayload) {
                $itemId = $itemPayload['itemId'];

                // Carnet es un ítem especial que no requiere validación de inventario
                $isCarnet = in_array(strtolower($itemId), ['__carnet__', 'carnet'], true);

                if ($isCarnet) {
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
                    $inventoryItem = $this->findInventoryItem($itemId);
                    if (! $inventoryItem) {
                        $this->logReturnInventoryItemMiss($documentType, $documentNumber, (string) $itemId);

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

            return $record;
        });

        $this->logReturnCreated($record, $itemsPayload);

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
     * Log cuando el listado paginado de dotación devuelve 0 filas (primera página), con causas típicas.
     *
     * @param  array<string, mixed>  $filters
     */
    private function logEmptyAffiliatesListContext(
        array $filters,
        int $rawTotal,
        int $activeFromFileEstadoColumn,
        int $activeAfterDedupPipeline,
        Collection $fullCollection,
    ): void {
        $baseContext = [
            'dotacion_epp' => true,
            'lookup' => 'affiliates_list',
            'filters' => [
                'status' => $filters['status'] ?? 'active',
                'documentType' => $filters['documentType'] ?? null,
                'documentNumber' => $filters['documentNumber'] ?? null,
                'hospital' => $filters['hospital'] ?? null,
                'searchTerm' => $filters['searchTerm'] ?? null,
                'pageSize' => $filters['pageSize'] ?? null,
            ],
            'counts' => [
                'loaded_from_basic_list' => $rawTotal,
                'active_estado_column_activo' => $activeFromFileEstadoColumn,
                'active_after_pipeline' => $activeAfterDedupPipeline,
            ],
        ];

        if ($rawTotal === 0) {
            Log::warning('Dotación/EPP: listado de afiliados vacío — no hay registros después de leer el Excel/cache (revisar archivo y pestaña INFORMACIÓN GENERAL)', $baseContext + [
                'cause' => 'empty_basic_list_or_cache_miss',
                'hint' => 'Si acaba de subir PROSANET_INFORMACION_AFILIADOS.xlsx, espere hasta 30 min o invalide cache `afiliado_service.all_basic` (tag afiliados). Revise también logs AfiliadoService (hoja ausente / error de lectura).',
            ]);

            return;
        }

        if ($activeFromFileEstadoColumn === 0) {
            $histogram = $this->estadoStatusHistogram($fullCollection);

            Log::warning('Dotación/EPP: listado vacío para entregas — hay filas cargadas pero ninguna con Estado=ACTIVO tras normalización', $baseContext + [
                'cause' => 'no_afiliados_activos_por_columna_estado',
                'estado_values_top' => $histogram,
                'hint' => 'Revise valores en columna Estado del Excel; solo se muestran filas donde strtoupper(estado)==="ACTIVO".',
            ]);

            return;
        }

        if ($filteredOut = $this->guessListEmptyDueToFilters($filters)) {
            Log::info('Dotación/EPP: listado vacío — filtros de la solicitud no coinciden con afiliados activos', $baseContext + [
                'cause' => $filteredOut['cause'],
                'detail' => $filteredOut['detail'],
            ]);

            return;
        }

        if ($activeFromFileEstadoColumn > 0 && $activeAfterDedupPipeline > 0) {
            Log::warning('Dotación/EPP: listado vacío — hay afiliados activos cargados pero el pipeline devolvió 0 filas (revisar lógica de filtros)', $baseContext + [
                'cause' => 'unexpected_empty_pipeline',
                'hint' => 'Reportar a desarrollo junto con el JSON de filtros.',
            ]);
        }
    }

    /**
     * Registra causa probable cuando GET /affiliados/{tipo}/{número} responde 404 (uso explícito del controlador).
     */
    public function logAffiliateMissDiagnostics(string $documentType, string $documentNumber): void
    {
        $documentType = strtoupper(trim($documentType));
        $documentNumber = trim($documentNumber);

        // Diagnostics are a nice-to-have, not worth a full ProSanet catalog resync (dozens of
        // paginated requests) just to enrich a log line. Only inspect the catalog when it is the
        // actual lookup source, i.e. when the API is disabled.
        if ($this->afiliadoService->isProsanetApiEnabled()) {
            Log::info('Dotación/EPP: búsqueda de afiliado sin resultado — diagnóstico detallado omitido (requeriría resincronizar el catálogo completo)', [
                'dotacion_epp' => true,
                'lookup' => 'find_affiliate',
                'document_type_requested' => $documentType,
                'document_number_requested' => $documentNumber,
                'cause' => 'diagnostics_skipped_cold_cache',
            ]);

            return;
        }

        $collection = $this->buildAffiliatesCollection();

        $this->logFindAffiliateMiss($collection, $documentType, $documentNumber);
    }

    /**
     * @return array<string, int>
     */
    private function estadoStatusHistogram(Collection $affiliates): array
    {
        return $affiliates
            ->groupBy(function (array $a) {
                $s = $a['status'] ?? '';

                return $s === '' || $s === null ? '(vacío)' : (string) $s;
            })
            ->map(fn (Collection $g) => $g->count())
            ->sortDesc()
            ->take(12)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{cause: string, detail?: string}|null
     */
    private function guessListEmptyDueToFilters(array $filters): ?array
    {
        $hospital = isset($filters['hospital']) ? trim((string) $filters['hospital']) : '';
        $search = isset($filters['searchTerm']) ? trim((string) $filters['searchTerm']) : '';
        $docType = isset($filters['documentType']) ? strtoupper(trim((string) $filters['documentType'])) : '';
        $docNum = isset($filters['documentNumber']) ? trim((string) $filters['documentNumber']) : '';

        if ($docType !== '' && $docNum !== '') {
            return [
                'cause' => 'filter_document_type_and_number',
                'detail' => "Ningún activo coincide con tipo {$docType} y documento proporcionados.",
            ];
        }

        if ($hospital !== '' && strtolower($hospital) !== 'all') {
            return [
                'cause' => 'filter_hospital',
                'detail' => 'Ningún activo coincide con hospital (comparación exacta tras mayúsculas).',
            ];
        }

        if ($search !== '') {
            return [
                'cause' => 'filter_search_term',
                'detail' => 'Ningún activo coincide con searchTerm en nombre, documento u hospital.',
            ];
        }

        if (($filters['status'] ?? '') === 'inactive') {
            return [
                'cause' => 'filter_status_inactive',
                'detail' => 'El backend solo incluye ACTIVOS en la colección base; status=inactive puede dejar lista vacía (limitación conocida del pipeline).',
            ];
        }

        return null;
    }

    /**
     * Diagnóstico cuando GET afiliados por tipo+número no encuentra coincidencia.
     */
    private function logFindAffiliateMiss(Collection $all, string $documentTypeRequested, string $documentNumberRequested): void
    {
        $total = $all->count();

        if ($total === 0) {
            Log::warning('Dotación/EPP: búsqueda de afiliado sin resultados — colección vacía (Excel/cache sin filas válidas)', [
                'dotacion_epp' => true,
                'lookup' => 'find_affiliate',
                'document_type_requested' => $documentTypeRequested,
                'document_number_requested' => $documentNumberRequested,
                'cause' => 'empty_affiliate_collection',
                'hint' => 'Mismo origen que listado vacío: ver PROSANET_INFORMACION_AFILIADOS.xlsx, caché 30min, errores AfiliadoService.',
            ]);

            return;
        }

        $sameNumber = $all->filter(
            fn (array $affiliate) => ($affiliate['documentNumber'] ?? '') === $documentNumberRequested
        )->values();

        if ($sameNumber->isEmpty()) {
            Log::warning('Dotación/EPP: búsqueda de afiliado sin resultados — documento no existe en datos cargados', [
                'dotacion_epp' => true,
                'lookup' => 'find_affiliate',
                'document_type_requested' => $documentTypeRequested,
                'document_number_requested' => $documentNumberRequested,
                'cause' => 'document_number_not_in_loaded_data',
                'affiliates_loaded' => $total,
                'hint' => 'Compruebe número en archivo, formato Excel (sin notación científica), y espacios. El front solo intenta algunos tipos de documento en cadena.',
            ]);

            return;
        }

        $typesFound = $sameNumber->pluck('documentType')->map(fn ($t) => strtoupper((string) $t))->unique()->sort()->values()->all();

        Log::info('Dotación/EPP: búsqueda de afiliado sin resultado — documento existe pero el tipo solicitado no coincide (p.ej. prueba CE/CC/PT en la UI)', [
            'dotacion_epp' => true,
            'lookup' => 'find_affiliate',
            'document_type_requested' => $documentTypeRequested,
            'document_number_requested' => $documentNumberRequested,
            'document_types_found_for_number' => $typesFound,
            'cause' => 'document_type_mismatch',
            'hint' => 'Use el tipo de documento registrado en la columna correspondiente del Excel o amplíe los tipos en la búsqueda del SPA.',
        ]);
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
            $lastDeliveryAt = $lastDeliveries[$id] ?? null;
            $lastDeliveryIso = $lastDeliveryAt
                ? Carbon::parse($lastDeliveryAt)->setTimezone('America/Bogota')->toISOString()
                : null;

            return $this->mapAfiliadoBasicToDotacionRecord($afiliado, $lastDeliveryIso);
        });
    }

    /**
     * @param  array<string, mixed>  $afiliado
     * @return array<string, mixed>
     */
    private function mapAfiliadoBasicToDotacionRecord(array $afiliado, ?string $lastDeliveryIso = null): array
    {
        $documentType = strtoupper($afiliado['tipo_documento'] ?? '');
        $documentNumber = $afiliado['documento'] ?? '';
        $id = sprintf('%s-%s', $documentType, $documentNumber);

        $convenios = $afiliado['convenios'] ?? [];
        $convenio = $this->selectMostRecentConvenio($convenios);

        $hospital = $convenio['cliente'] ?? 'SIN ASIGNAR';
        $role = $convenio['proceso'] ?? null;
        $status = strtoupper($afiliado['estado'] ?? '');

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
    }

    private function resolveLastDeliveryAtIso(string $documentType, string $documentNumber): ?string
    {
        $id = sprintf('%s-%s', strtoupper(trim($documentType)), trim($documentNumber));
        $lastDeliveryAt = SstDeliveryRecord::query()
            ->where('affiliate_id', $id)
            ->max('delivered_at');

        if ($lastDeliveryAt === null) {
            return null;
        }

        return Carbon::parse($lastDeliveryAt)->setTimezone('America/Bogota')->toISOString();
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
            Log::warning('Dotación/EPP: formato de firma inválido al almacenar', [
                'dotacion_epp' => true,
                'action' => 'signature_store_failed',
                'cause' => 'invalid_format',
            ]);

            throw new \InvalidArgumentException('Formato de firma inválido.');
        }

        $mimeType = $matches[1];
        $extension = $matches[2] === 'jpeg' ? 'jpg' : $matches[2];
        $base64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
        $binary = base64_decode($base64, true);

        if ($binary === false) {
            Log::warning('Dotación/EPP: firma no se pudo decodificar', [
                'dotacion_epp' => true,
                'action' => 'signature_store_failed',
                'cause' => 'decode_failed',
            ]);

            throw new \InvalidArgumentException('La firma no se pudo decodificar correctamente.');
        }

        $size = strlen($binary);
        if ($size > 1024 * 1024) {
            Log::warning('Dotación/EPP: firma excede tamaño máximo permitido', [
                'dotacion_epp' => true,
                'action' => 'signature_store_failed',
                'cause' => 'size_exceeded',
                'size_bytes' => $size,
            ]);

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

    /**
     * @param  array<int, array<string, mixed>>  $itemsPayload
     */
    private function logDeliveryCreated(SstDeliveryRecord $record, array $itemsPayload): void
    {
        Log::info('Dotación/EPP: entrega registrada exitosamente', [
            'dotacion_epp' => true,
            'action' => 'delivery_created',
            'record_id' => $record->id,
            'affiliate_id' => $record->affiliate_id,
            'affiliate_document_type' => $record->affiliate_document_type,
            'affiliate_document_number' => $record->affiliate_document_number,
            'affiliate_hospital' => $record->affiliate_hospital,
            'delivery_type' => $record->delivery_type,
            'delivered_by_user_id' => $record->delivered_by_user_id,
            'delivered_by_name' => $record->delivered_by_name,
            'items_count' => count($itemsPayload),
            'items' => $this->summarizeItemsForLog($itemsPayload),
            'signature_path' => $record->signature_path,
            'has_notes' => filled($record->notes),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemsPayload
     */
    private function logReturnCreated(SstReturnRecord $record, array $itemsPayload): void
    {
        Log::info('Dotación/EPP: devolución registrada exitosamente', [
            'dotacion_epp' => true,
            'action' => 'return_created',
            'record_id' => $record->id,
            'affiliate_id' => $record->affiliate_id,
            'affiliate_document_type' => $record->affiliate_document_type,
            'affiliate_document_number' => $record->affiliate_document_number,
            'affiliate_hospital' => $record->affiliate_hospital,
            'reason' => $record->reason,
            'received_by_user_id' => $record->received_by_user_id,
            'received_by_name' => $record->received_by_name,
            'items_count' => count($itemsPayload),
            'items' => $this->summarizeItemsForLog($itemsPayload),
            'signature_path' => $record->signature_path,
            'has_notes' => filled($record->notes),
        ]);
    }

    private function logDeliveryInventoryItemMiss(string $documentType, string $documentNumber, string $itemId): void
    {
        Log::warning('Dotación/EPP: ítem de inventario no reconocido al registrar entrega', [
            'dotacion_epp' => true,
            'action' => 'delivery_failed',
            'cause' => 'inventory_item_not_found',
            'affiliate_document_type' => $documentType,
            'affiliate_document_number' => $documentNumber,
            'item_id' => $itemId,
        ]);
    }

    private function logReturnInventoryItemMiss(string $documentType, string $documentNumber, string $itemId): void
    {
        Log::warning('Dotación/EPP: ítem de inventario no reconocido al registrar devolución', [
            'dotacion_epp' => true,
            'action' => 'return_failed',
            'cause' => 'inventory_item_not_found',
            'affiliate_document_type' => $documentType,
            'affiliate_document_number' => $documentNumber,
            'item_id' => $itemId,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemsPayload
     * @return array<int, array{item_id: string|null, quantity: int}>
     */
    private function summarizeItemsForLog(array $itemsPayload): array
    {
        return array_map(static function (array $item): array {
            return [
                'item_id' => isset($item['itemId']) ? (string) $item['itemId'] : null,
                'quantity' => (int) ($item['quantity'] ?? 1),
            ];
        }, $itemsPayload);
    }
}
