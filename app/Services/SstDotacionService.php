<?php

namespace App\Services;

use App\Models\SstDeliveryItem;
use App\Models\SstDeliveryRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SstDotacionService
{
    private const SIGNATURE_DISK = 'prosalud-private';
    private const SIGNATURE_TEMP_URL_MINUTES = 10;

    private static ?array $inventoryCache = null;

    public function __construct(private readonly AfiliadoService $afiliadoService)
    {
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
                $fullName = trim(($affiliate['firstName'] ?? '') . ' ' . ($affiliate['lastName'] ?? ''));
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
            if ($statusFilter === 'active' && !$affiliate['active']) {
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

        if (!$affiliate) {
            throw new \RuntimeException('No se encontró el afiliado solicitado.');
        }

        $signatureMeta = $this->storeSignature($data['signatureData']);

        $userId = Auth::id();

        if (!$userId && !empty($data['deliveredBy'])) {
            $userId = is_numeric($data['deliveredBy']) ? (int) $data['deliveredBy'] : null;
        }

        if (!$userId) {
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
            $inventoryItem = $this->findInventoryItem($itemPayload['itemId']);
            if (!$inventoryItem) {
                throw new \RuntimeException('Ítem de inventario no reconocido: ' . $itemPayload['itemId']);
            }

            SstDeliveryItem::create([
                'id' => (string) Str::uuid(),
                'delivery_id' => $record->id,
                'item_id' => $inventoryItem['id'],
                'item_name' => $inventoryItem['name'],
                'item_category' => $inventoryItem['category'],
                'unit' => $inventoryItem['unit'] ?? 'unidad',
                'variant_color' => $itemPayload['variant']['color'] ?? null,
                'variant_size' => $itemPayload['variant']['size'] ?? null,
                'variant_payload' => $itemPayload['variant'] ?? null,
                'quantity' => (int) $itemPayload['quantity'],
            ]);
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
                    ->orWhere('delivered_by_name', 'like', '%' . $deliveredBy . '%');
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
            $convenio = $afiliado['convenios'][0] ?? null;
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
            'items' => $record->items->map(fn (SstDeliveryItem $item) => [
                'itemId' => $item->item_id,
                'variant' => array_filter([
                    'color' => $item->variant_color,
                    'size' => $item->variant_size,
                ], fn ($value) => $value !== null),
                'quantity' => $item->quantity,
            ])->all(),
            'signedDocumentUrl' => $signatureUrl,
            'signedDocumentType' => $record->signed_document_type,
            'signedDocumentNumber' => $record->signed_document_number,
            'notes' => $record->notes,
        ];
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

    private static function sizesWithColor(string $color): array
    {
        $sizes = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'];

        return array_map(fn ($size) => ['color' => $color, 'size' => $size], $sizes);
    }

    private static function inventoryItems(): array
    {
        if (self::$inventoryCache !== null) {
            return self::$inventoryCache;
        }

        self::$inventoryCache = [
            [
                'id' => 'epp-gorros-quirurgicos',
                'name' => 'Gorros Quirúrgicos',
                'category' => 'EPP',
            ],
            [
                'id' => 'epp-tapabocas-n95',
                'name' => 'Tapabocas N95',
                'category' => 'EPP',
            ],
            [
                'id' => 'epp-tapabocas-quirurgico',
                'name' => 'Tapabocas Quirúrgico',
                'category' => 'EPP',
            ],
            [
                'id' => 'dotacion-bata-aguamarina',
                'name' => 'Bata (Aguamarina)',
                'category' => 'Dotación',
                'defaultColor' => 'AGUAMA',
                'variants' => [
                    ['color' => 'AGUAMA', 'size' => 'XS'],
                    ['color' => 'AGUAMA', 'size' => 'S'],
                    ['color' => 'AGUAMA', 'size' => 'M'],
                    ['color' => 'AGUAMA', 'size' => 'L'],
                    ['color' => 'AGUAMA', 'size' => 'XL'],
                    ['color' => 'AGUAMA', 'size' => '2XL'],
                    ['color' => 'AGUAMA', 'size' => '3XL'],
                    ['color' => 'AGUAMA', 'size' => '4XL'],
                    ['color' => 'AGUAMA', 'size' => '5XL'],
                ],
            ],
            [
                'id' => 'dotacion-bata-blanca',
                'name' => 'Bata (Blanca)',
                'category' => 'Dotación',
                'defaultColor' => 'BLANCO',
                'variants' => self::sizesWithColor('BLANCO'),
            ],
            [
                'id' => 'dotacion-bata-larga-boton',
                'name' => 'Bata Larga Botón (Blanca)',
                'category' => 'Dotación',
                'defaultColor' => 'BLANCO',
                'variants' => self::sizesWithColor('BLANCO'),
            ],
            [
                'id' => 'dotacion-bata-larga-cierre',
                'name' => 'Bata Larga Cierre (Blanca)',
                'category' => 'Dotación',
                'defaultColor' => 'BLANCO',
                'variants' => self::sizesWithColor('BLANCO'),
            ],
            [
                'id' => 'dotacion-buzo-azul',
                'name' => 'Buzo (Azul)',
                'category' => 'Dotación',
                'defaultColor' => 'AZUL',
                'variants' => self::sizesWithColor('AZUL'),
            ],
            [
                'id' => 'dotacion-camiseta-polo-hombre-gris',
                'name' => 'Camiseta Polo Hombre (Gris)',
                'category' => 'Dotación',
                'defaultColor' => 'GRIS',
                'variants' => self::sizesWithColor('GRIS'),
            ],
            [
                'id' => 'dotacion-camiseta-polo-mujer-blanca',
                'name' => 'Camiseta Polo Mujer (Blanca)',
                'category' => 'Dotación',
                'defaultColor' => 'BLANCO',
                'variants' => self::sizesWithColor('BLANCO'),
            ],
            [
                'id' => 'dotacion-camiseta-polo-mujer-gris',
                'name' => 'Camiseta Polo Mujer (Gris)',
                'category' => 'Dotación',
                'defaultColor' => 'GRIS',
                'variants' => self::sizesWithColor('GRIS'),
            ],
            [
                'id' => 'dotacion-conjunto-cierre-azul',
                'name' => 'Conjunto Cierre (Azul)',
                'category' => 'Dotación',
                'defaultColor' => 'AZUL',
                'variants' => [
                    ['color' => 'AZUL', 'size' => 'XS'],
                    ['color' => 'AZUL', 'size' => 'S'],
                    ['color' => 'AZUL', 'size' => 'XL'],
                ],
            ],
            [
                'id' => 'dotacion-pijama-azul-claro',
                'name' => 'Pijama (Azul Claro)',
                'category' => 'Dotación',
                'defaultColor' => 'AZUL CLARO',
                'variants' => self::sizesWithColor('AZUL CLARO'),
            ],
            [
                'id' => 'dotacion-pijama-azul-oscuro',
                'name' => 'Pijama (Azul Oscuro)',
                'category' => 'Dotación',
                'defaultColor' => 'AZUL OSCURO',
                'variants' => self::sizesWithColor('AZUL OSCURO'),
            ],
            [
                'id' => 'dotacion-pijama-azul-rey',
                'name' => 'Pijama (Azul Rey)',
                'category' => 'Dotación',
                'defaultColor' => 'AZUL REY',
                'variants' => self::sizesWithColor('AZUL REY'),
            ],
            [
                'id' => 'dotacion-pijama-gris-raton',
                'name' => 'Pijama (Gris Ratón)',
                'category' => 'Dotación',
                'defaultColor' => 'GRIS RATÓN',
                'variants' => self::sizesWithColor('GRIS RATÓN'),
            ],
            [
                'id' => 'dotacion-pijama-gris-reflectivo',
                'name' => 'Pijama (Gris Reflectivo)',
                'category' => 'Dotación',
                'defaultColor' => 'GRIS REFLECTIVO',
                'variants' => self::sizesWithColor('GRIS REFLECTIVO'),
            ],
            [
                'id' => 'dotacion-pijama-negra',
                'name' => 'Pijama (Negra)',
                'category' => 'Dotación',
                'defaultColor' => 'NEGRA',
                'variants' => self::sizesWithColor('NEGRA'),
            ],
            [
                'id' => 'dotacion-pijama-petroleo',
                'name' => 'Pijama (Petróleo)',
                'category' => 'Dotación',
                'defaultColor' => 'PETRÓLEO',
                'variants' => self::sizesWithColor('PETRÓLEO'),
            ],
            [
                'id' => 'dotacion-pijama-verde',
                'name' => 'Pijama (Verde)',
                'category' => 'Dotación',
                'defaultColor' => 'VERDE',
                'variants' => self::sizesWithColor('VERDE'),
            ],
            [
                'id' => 'dotacion-pijama-auxiliar-blanca',
                'name' => 'Pijama Auxiliar (Blanca)',
                'category' => 'Dotación',
                'defaultColor' => 'BLANCO',
                'variants' => self::sizesWithColor('BLANCO'),
            ],
            [
                'id' => 'dotacion-pijama-jefe-blanca',
                'name' => 'Pijama Jefe (Blanca)',
                'category' => 'Dotación',
                'defaultColor' => 'BLANCO',
                'variants' => self::sizesWithColor('BLANCO'),
            ],
        ];

        return self::$inventoryCache;
    }

    private function storeSignature(string $dataUrl): array
    {
        if (!preg_match('/^data:(image\/(png|jpe?g));base64,/', $dataUrl, $matches)) {
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
        $path = 'dotacion-signatures/' . Str::uuid() . '.' . $extension;

        Storage::disk($disk)->put($path, $binary, ['visibility' => 'private']);

        return [
            'path' => $path,
            'mime_type' => $mimeType,
        ];
    }
}

