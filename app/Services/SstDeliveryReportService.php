<?php

namespace App\Services;

use App\Models\{InventoryColor, InventoryCategory, SstDeliveryItem, SstDeliveryRecord, SstReturnItem, SstReturnRecord};
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\{DB, Log, Storage};
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill};
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Chart\{Chart, DataSeries, DataSeriesValues, Legend, PlotArea, Title};
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class SstDeliveryReportService
{
    private const SIGNATURE_DISK = 'prosalud-private';
    private const MAX_IMAGE_SIZE = 2 * 1024 * 1024; // 2MB
    private const IMAGE_TIMEOUT = 5; // 5 seconds

    /**
     * Temporary image files to clean up after report generation.
     */
    private array $tempImageFiles = [];

    /**
     * Cache for colors lookup
     */
    private ?array $colorCache = null;

    /**
     * Cache for inventory items
     */
    private ?array $inventoryCache = null;

    public function __construct(
        private readonly SstDotacionService $dotacionService,
        private readonly AfiliadoService $afiliadoService
    ) {
    }

    /**
     * Generate Excel report with delivery and return records.
     */
    public function generateReport(array $filters, array $options = []): string
    {
        try {
            // Validate filters
            $this->validateFilters($filters);

            // Get all deliveries matching filters
            $deliveries = $this->getAllDeliveries($filters);

            // Get all returns matching filters
            $returns = $this->getAllReturns($filters);

            // Combine unique affiliate IDs from both deliveries and returns
            $allAffiliateIds = $deliveries->pluck('affiliate_id')
                ->merge($returns->pluck('affiliate_id'))
                ->filter()
                ->unique()
                ->values()
                ->all();

            // Get affiliates map
            $affiliatesMap = $this->getAffiliatesMap($allAffiliateIds, $filters);

            // Get inventory map
            $inventoryMap = $this->getInventoryMap();

            // Get colors map
            $colorsMap = $this->getColorsMap();

            // Filter deliveries (additional client-side filtering)
            $filteredDeliveries = $this->filterDeliveries($deliveries, $filters, $affiliatesMap);

            // Filter returns (additional client-side filtering)
            $filteredReturns = $this->filterReturns($returns, $filters, $affiliatesMap);

            // Create spreadsheet
            $spreadsheet = new Spreadsheet();
            $spreadsheet->removeSheetByIndex(0);

            // Create deliveries sheet
            $deliveriesSheet = $spreadsheet->createSheet();
            $deliveriesSheet->setTitle('Entregas');
            $this->buildDeliveriesSheet($deliveriesSheet, $filteredDeliveries, $affiliatesMap, $inventoryMap, $colorsMap, $options);

            // Create returns sheet
            $returnsSheet = $spreadsheet->createSheet();
            $returnsSheet->setTitle('Devoluciones');
            $this->buildReturnsSheet($returnsSheet, $filteredReturns, $affiliatesMap, $inventoryMap, $colorsMap, $options);

            // Create summary sheet
            $summarySheet = $spreadsheet->createSheet();
            $summarySheet->setTitle('Resumen');
            $this->buildSummarySheet($summarySheet, $filteredDeliveries, $filteredReturns, $filters, $affiliatesMap, $inventoryMap, $colorsMap);

            // Create totals by article sheet
            $totalsSheet = $spreadsheet->createSheet();
            $totalsSheet->setTitle('Totales por Artículo');
            $this->buildTotalsSheet($totalsSheet, $filteredDeliveries, $filteredReturns, $inventoryMap, $colorsMap);

            // Create charts sheet
            $chartsSheet = $spreadsheet->createSheet();
            $chartsSheet->setTitle('Gráficas');
            $this->buildChartsSheet($chartsSheet, $filteredDeliveries, $filteredReturns, $affiliatesMap, $inventoryMap, $colorsMap);

            // Set first sheet as active
            $spreadsheet->setActiveSheetIndex(0);

            // Save to temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'sst_delivery_report_') . '.xlsx';
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            $writer->save($tempFile);

            // Clean up temporary image files
            $this->cleanupTempFiles();

            return $tempFile;
        } catch (\Exception $e) {
            // Clean up temporary image files on error
            $this->cleanupTempFiles();
            Log::error('Error generando reporte de entregas y devoluciones SST', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Validate filters.
     */
    private function validateFilters(array $filters): void
    {
        if (isset($filters['startDate']) && isset($filters['endDate'])) {
            $startDate = Carbon::parse($filters['startDate']);
            $endDate = Carbon::parse($filters['endDate']);

            if ($startDate->gt($endDate)) {
                throw new \InvalidArgumentException('La fecha inicial debe ser anterior o igual a la fecha final.');
            }
        }
    }

    /**
     * Get all deliveries matching filters (with pagination).
     */
    private function getAllDeliveries(array $filters): Collection
    {
        $query = SstDeliveryRecord::query()
            ->with('items')
            ->orderByDesc('delivered_at');

        // Apply filters
        if (isset($filters['hospital']) && $filters['hospital'] !== 'all') {
            $query->where('affiliate_hospital', 'like', '%' . $filters['hospital'] . '%');
        }

        if (isset($filters['startDate'])) {
            try {
                $start = Carbon::parse($filters['startDate'])->startOfDay();
                $query->where('delivered_at', '>=', $start);
            } catch (\Exception $e) {
                // Ignore invalid date
            }
        }

        if (isset($filters['endDate'])) {
            try {
                $end = Carbon::parse($filters['endDate'])->endOfDay();
                $query->where('delivered_at', '<=', $end);
            } catch (\Exception $e) {
                // Ignore invalid date
            }
        }

        if (isset($filters['documentNumber'])) {
            $query->where('affiliate_document_number', 'like', '%' . $filters['documentNumber'] . '%');
        }

        if (isset($filters['deliveredBy'])) {
            $query->where(function (Builder $builder) use ($filters) {
                $builder->where('delivered_by_user_id', $filters['deliveredBy'])
                    ->orWhere('delivered_by_name', 'like', '%' . $filters['deliveredBy'] . '%');
            });
        }

        // Get all records (no pagination for report)
        return $query->get();
    }

    /**
     * Get all returns matching filters.
     */
    private function getAllReturns(array $filters): Collection
    {
        $query = SstReturnRecord::query()
            ->with('items')
            ->orderByDesc('returned_at');

        // Apply filters
        if (isset($filters['hospital']) && $filters['hospital'] !== 'all') {
            $query->where('affiliate_hospital', 'like', '%' . $filters['hospital'] . '%');
        }

        if (isset($filters['startDate'])) {
            try {
                $start = Carbon::parse($filters['startDate'])->startOfDay();
                $query->where('returned_at', '>=', $start);
            } catch (\Exception $e) {
                // Ignore invalid date
            }
        }

        if (isset($filters['endDate'])) {
            try {
                $end = Carbon::parse($filters['endDate'])->endOfDay();
                $query->where('returned_at', '<=', $end);
            } catch (\Exception $e) {
                // Ignore invalid date
            }
        }

        if (isset($filters['documentNumber'])) {
            $query->where('affiliate_document_number', 'like', '%' . $filters['documentNumber'] . '%');
        }

        if (isset($filters['deliveredBy'])) {
            // For returns, this would be receivedBy
            $query->where(function (Builder $builder) use ($filters) {
                $builder->where('received_by_user_id', $filters['deliveredBy'])
                    ->orWhere('received_by_name', 'like', '%' . $filters['deliveredBy'] . '%');
            });
        }

        // Get all records (no pagination for report)
        return $query->get();
    }

    /**
     * Get affiliates map for all unique affiliate IDs.
     */
    private function getAffiliatesMap(array $affiliateIds, array $filters): array
    {
        $uniqueAffiliateIds = array_unique(array_filter($affiliateIds));

        $affiliatesMap = [];

        // Get all affiliates (in batches if needed)
        $allAffiliates = [];
        $page = 1;
        $pageSize = 200; // Max allowed by service
        do {
            $result = $this->dotacionService->getAffiliates([
                'page' => $page,
                'pageSize' => $pageSize,
                'hospital' => $filters['hospital'] ?? null,
                'status' => 'all',
            ]);
            $allAffiliates = array_merge($allAffiliates, $result['items'] ?? []);
            $page++;
        } while (count($result['items'] ?? []) === $pageSize && $page <= 500); // Safety limit

        foreach ($allAffiliates as $affiliate) {
            $affiliatesMap[$affiliate['id']] = $affiliate;
        }

        // For affiliates not found in the map, try to get by document
        foreach ($uniqueAffiliateIds as $affiliateId) {
            if (!isset($affiliatesMap[$affiliateId])) {
                // Try to parse affiliate ID (format: "TIPO-NUMERO")
                $parts = explode('-', $affiliateId, 2);
                if (count($parts) === 2) {
                    $docType = $parts[0];
                    $docNumber = $parts[1];
                    $affiliate = $this->dotacionService->findAffiliate($docType, $docNumber);
                    if ($affiliate) {
                        $affiliatesMap[$affiliateId] = $affiliate;
                    }
                }
            }
        }

        return $affiliatesMap;
    }

    /**
     * Get inventory map.
     */
    private function getInventoryMap(): array
    {
        if (null !== $this->inventoryCache) {
            return $this->inventoryCache;
        }

        $inventory = $this->dotacionService->getInventoryItems();
        $this->inventoryCache = [];

        foreach ($inventory as $item) {
            $this->inventoryCache[$item['id']] = $item;
        }

        return $this->inventoryCache;
    }

    /**
     * Get colors map.
     */
    private function getColorsMap(): array
    {
        if (null !== $this->colorCache) {
            return $this->colorCache;
        }

        $colors = InventoryColor::all();
        $this->colorCache = [];

        foreach ($colors as $color) {
            $this->colorCache[$color->id] = $color->label;
        }

        return $this->colorCache;
    }

    /**
     * Resolve color label from color ID.
     */
    private function resolveColorLabel(?string $colorId, array $colorsMap): string
    {
        if (!$colorId) {
            return '';
        }

        return $colorsMap[$colorId] ?? $colorId;
    }

    /**
     * Filter deliveries with additional client-side filtering.
     */
    private function filterDeliveries(Collection $deliveries, array $filters, array $affiliatesMap): Collection
    {
        return $deliveries->filter(function (SstDeliveryRecord $record) use ($filters, $affiliatesMap) {
            // Additional filtering can be done here if needed
            // Most filtering is already done in the query, but we can add normalization here
            
            // Normalize hospital filter if needed
            if (isset($filters['hospital']) && $filters['hospital'] !== 'all') {
                $affiliate = $affiliatesMap[$record->affiliate_id] ?? null;
                $recordHospital = $record->affiliate_hospital
                    ?: ($affiliate['hospital'] ?? '');
                
                // Normalize for comparison (case-insensitive)
                $filterHospital = mb_strtoupper(trim($filters['hospital']));
                $recordHospitalNormalized = mb_strtoupper(trim($recordHospital));
                
                if ($filterHospital && !str_contains($recordHospitalNormalized, $filterHospital)) {
                    return false;
                }
            }
            
            return true;
        });
    }

    /**
     * Filter returns with additional client-side filtering.
     */
    private function filterReturns(Collection $returns, array $filters, array $affiliatesMap): Collection
    {
        return $returns->filter(function (SstReturnRecord $record) use ($filters, $affiliatesMap) {
            // Additional filtering can be done here if needed
            // Most filtering is already done in the query, but we can add normalization here
            
            // Normalize hospital filter if needed
            if (isset($filters['hospital']) && $filters['hospital'] !== 'all') {
                $affiliate = $affiliatesMap[$record->affiliate_id] ?? null;
                $recordHospital = $record->affiliate_hospital
                    ?: ($affiliate['hospital'] ?? '');
                
                // Normalize for comparison (case-insensitive)
                $filterHospital = mb_strtoupper(trim($filters['hospital']));
                $recordHospitalNormalized = mb_strtoupper(trim($recordHospital));
                
                if ($filterHospital && !str_contains($recordHospitalNormalized, $filterHospital)) {
                    return false;
                }
            }
            
            return true;
        });
    }

    /**
     * Build deliveries sheet.
     */
    private function buildDeliveriesSheet(
        Worksheet $sheet,
        Collection $deliveries,
        array $affiliatesMap,
        array $inventoryMap,
        array $colorsMap,
        array $options
    ): void {
        // Headers
        $headers = [
            'ID de entrega',
            'Fecha de entrega',
            'Hospital',
            'Afiliado tipo documento',
            'Afiliado número documento',
            'Afiliado nombre completo',
            'Proceso del afiliado',
            'Responsable de entrega',
            'Observaciones',
            'Tipo de entrega',
            'Artículo',
            'Categoría artículo',
            'Color',
            'Talla',
            'Cantidad',
            'Firma imagen',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        // Style headers
        $headerRange = 'A1:P1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Set column widths
        $columnWidths = [
            'A' => 18, // ID de entrega
            'B' => 20, // Fecha de entrega
            'C' => 26, // Hospital
            'D' => 12, // Afiliado tipo documento
            'E' => 18, // Afiliado número documento
            'F' => 30, // Afiliado nombre completo
            'G' => 24, // Proceso del afiliado
            'H' => 24, // Responsable de entrega
            'I' => 36, // Observaciones
            'J' => 18, // Tipo de entrega
            'K' => 28, // Artículo
            'L' => 20, // Categoría artículo
            'M' => 18, // Color
            'N' => 12, // Talla
            'O' => 14, // Cantidad
            'P' => 50, // Firma imagen (sin filtro, más ancha para que no se salga la imagen)
        ];

        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $row = 2;
        $includeSignatures = $options['includeSignatures'] ?? false;
        $signatureWidth = $options['signatureSize']['width'] ?? 100;
        $signatureHeight = $options['signatureSize']['height'] ?? 50;

        foreach ($deliveries as $record) {
            // Resolve affiliate data
            $affiliate = $affiliatesMap[$record->affiliate_id] ?? null;

            $docType = $record->affiliate_document_type
                ?: ($affiliate['documentType'] ?? $this->parseDocumentTypeFromId($record->affiliate_id));

            $docNumber = $record->affiliate_document_number
                ?: ($affiliate['documentNumber'] ?? $this->parseDocumentNumberFromId($record->affiliate_id));

            $affiliateName = $record->affiliate_full_name
                ?: ($affiliate ? trim(($affiliate['firstName'] ?? '') . ' ' . ($affiliate['lastName'] ?? '')) : '')
                ?: ($record->affiliate_first_name && $record->affiliate_last_name
                    ? trim($record->affiliate_first_name . ' ' . $record->affiliate_last_name)
                    : '')
                ?: 'Sin información';

            $hospital = $record->affiliate_hospital
                ?: ($affiliate['hospital'] ?? '')
                ?: 'No especificado';

            $role = $record->affiliate_role
                ?: ($affiliate['role'] ?? '')
                ?: 'No especificado';

            $deliveryTypeLabel = $record->delivery_type === 'first_time' ? 'Primera vez' : 'Periódica';

            $deliveredByName = $record->delivered_by_name ?: $record->delivered_by_user_id ?: '';

            // Base row data (common to all items in this delivery)
            $baseRowData = [
                $record->id,
                $record->delivered_at?->setTimezone('America/Bogota')->format('Y-m-d H:i'),
                $hospital,
                $docType,
                $docNumber,
                $affiliateName,
                $role,
                $deliveredByName,
                $record->notes ?? '',
                $deliveryTypeLabel,
            ];

            // If no items, create one row with "Sin artículos registrados"
            if ($record->items->isEmpty()) {
                $rowData = array_merge($baseRowData, [
                    'Sin artículos registrados',
                    '',
                    '',
                    '',
                    0,
                    '',
                ]);

                $sheet->fromArray([$rowData], null, "A{$row}");

                // Embed signature if available
                if ($includeSignatures && $record->signature_path) {
                    try {
                        $this->embedSignature($sheet, $record, "P{$row}", $signatureWidth, $signatureHeight);
                    } catch (\Exception $e) {
                        Log::warning('No se pudo embebir firma en reporte', [
                            'record_id' => $record->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Set row height for signature
                $sheet->getRowDimension($row)->setRowHeight(max(60, $signatureHeight + 10));

                $row++;
                continue;
            }

            // Create one row per item
            foreach ($record->items as $item) {
                $inventoryItem = $inventoryMap[$item->item_id] ?? null;

                $category = $inventoryItem['category'] ?? $item->item_category ?? 'Sin categoría';

                $baseArticleName = $inventoryItem['name'] ?? $item->item_name ?? $item->item_id;
                $articleName = ($inventoryItem['gender'] ?? $item->item_gender)
                    ? "{$baseArticleName} ({$inventoryItem['gender']})"
                    : $baseArticleName;

                $colorLabel = $this->resolveColorLabel(
                    $item->variant_color ?? $inventoryItem['defaultColor'] ?? null,
                    $colorsMap
                );

                $rowData = array_merge($baseRowData, [
                    $articleName,
                    $category,
                    $colorLabel,
                    $item->variant_size ?? '',
                    $item->quantity ?? 0,
                    '',
                ]);

                $sheet->fromArray([$rowData], null, "A{$row}");

                // Embed signature if available (on all item rows for the same delivery)
                if ($includeSignatures && $record->signature_path) {
                    try {
                        $this->embedSignature($sheet, $record, "P{$row}", $signatureWidth, $signatureHeight);
                    } catch (\Exception $e) {
                        Log::warning('No se pudo embebir firma en reporte', [
                            'record_id' => $record->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Set row height for signature (on all item rows to display signature properly)
                if ($includeSignatures && $record->signature_path) {
                    $sheet->getRowDimension($row)->setRowHeight(max(60, $signatureHeight + 10));
                }

                $row++;
            }
        }

        // Apply borders to all data rows
        if ($row > 2) {
            $dataRange = "A1:P" . ($row - 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            ]);
        }

        // Add autofilter (exclude column P - Firma imagen)
        if ($row > 2) {
            $sheet->setAutoFilter("A1:O" . ($row - 1));
        }

        // Freeze first row
        $sheet->freezePane('A2');
    }

    /**
     * Build returns sheet.
     */
    private function buildReturnsSheet(
        Worksheet $sheet,
        Collection $returns,
        array $affiliatesMap,
        array $inventoryMap,
        array $colorsMap,
        array $options
    ): void {
        // Headers
        $headers = [
            'ID de devolución',
            'Fecha de devolución',
            'Hospital',
            'Afiliado tipo documento',
            'Afiliado número documento',
            'Afiliado nombre completo',
            'Proceso del afiliado',
            'Recibido por',
            'Motivo',
            'Observaciones',
            'Artículo',
            'Categoría artículo',
            'Color',
            'Talla',
            'Cantidad',
            'Firma imagen',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        // Style headers
        $headerRange = 'A1:P1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E74C3C'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Set column widths (same as deliveries)
        $columnWidths = [
            'A' => 18, // ID de devolución
            'B' => 20, // Fecha de devolución
            'C' => 26, // Hospital
            'D' => 12, // Afiliado tipo documento
            'E' => 18, // Afiliado número documento
            'F' => 30, // Afiliado nombre completo
            'G' => 24, // Proceso del afiliado
            'H' => 24, // Recibido por
            'I' => 18, // Motivo
            'J' => 36, // Observaciones
            'K' => 28, // Artículo
            'L' => 20, // Categoría artículo
            'M' => 18, // Color
            'N' => 12, // Talla
            'O' => 14, // Cantidad
            'P' => 50, // Firma imagen (sin filtro, más ancha para que no se salga la imagen)
        ];

        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $row = 2;
        $includeSignatures = $options['includeSignatures'] ?? false;
        $signatureWidth = $options['signatureSize']['width'] ?? 100;
        $signatureHeight = $options['signatureSize']['height'] ?? 50;

        foreach ($returns as $record) {
            // Resolve affiliate data
            $affiliate = $affiliatesMap[$record->affiliate_id] ?? null;

            $docType = $record->affiliate_document_type
                ?: ($affiliate['documentType'] ?? $this->parseDocumentTypeFromId($record->affiliate_id));

            $docNumber = $record->affiliate_document_number
                ?: ($affiliate['documentNumber'] ?? $this->parseDocumentNumberFromId($record->affiliate_id));

            $affiliateName = $record->affiliate_full_name
                ?: ($affiliate ? trim(($affiliate['firstName'] ?? '') . ' ' . ($affiliate['lastName'] ?? '')) : '')
                ?: ($record->affiliate_first_name && $record->affiliate_last_name
                    ? trim($record->affiliate_first_name . ' ' . $record->affiliate_last_name)
                    : '')
                ?: 'Sin información';

            $hospital = $record->affiliate_hospital
                ?: ($affiliate['hospital'] ?? '')
                ?: 'No especificado';

            $role = $record->affiliate_role
                ?: ($affiliate['role'] ?? '')
                ?: 'No especificado';

            $receivedByName = $record->received_by_name ?: $record->received_by_user_id ?: '';

            $reasonLabel = $this->getReasonLabel($record->reason);

            // Base row data (common to all items in this return)
            $baseRowData = [
                $record->id,
                $record->returned_at?->setTimezone('America/Bogota')->format('Y-m-d H:i'),
                $hospital,
                $docType,
                $docNumber,
                $affiliateName,
                $role,
                $receivedByName,
                $reasonLabel,
                $record->notes ?? '',
            ];

            // If no items, create one row with "Sin artículos registrados"
            if ($record->items->isEmpty()) {
                $rowData = array_merge($baseRowData, [
                    'Sin artículos registrados',
                    '',
                    '',
                    '',
                    0,
                    '',
                ]);

                $sheet->fromArray([$rowData], null, "A{$row}");

                // Embed signature if available
                if ($includeSignatures && $record->signature_path) {
                    try {
                        $this->embedReturnSignature($sheet, $record, "P{$row}", $signatureWidth, $signatureHeight);
                    } catch (\Exception $e) {
                        Log::warning('No se pudo embebir firma en reporte de devolución', [
                            'record_id' => $record->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Set row height for signature
                $sheet->getRowDimension($row)->setRowHeight(max(60, $signatureHeight + 10));

                $row++;
                continue;
            }

            // Create one row per item
            foreach ($record->items as $item) {
                $inventoryItem = $inventoryMap[$item->item_id] ?? null;

                $category = $inventoryItem['category'] ?? $item->item_category ?? 'Sin categoría';

                $baseArticleName = $inventoryItem['name'] ?? $item->item_name ?? $item->item_id;
                $articleName = ($inventoryItem['gender'] ?? $item->item_gender)
                    ? "{$baseArticleName} ({$inventoryItem['gender']})"
                    : $baseArticleName;

                $colorLabel = $this->resolveColorLabel(
                    $item->variant_color ?? $inventoryItem['defaultColor'] ?? null,
                    $colorsMap
                );

                $rowData = array_merge($baseRowData, [
                    $articleName,
                    $category,
                    $colorLabel,
                    $item->variant_size ?? '',
                    $item->quantity ?? 0,
                    '',
                ]);

                $sheet->fromArray([$rowData], null, "A{$row}");

                // Embed signature if available (on all item rows for the same return)
                if ($includeSignatures && $record->signature_path) {
                    try {
                        $this->embedReturnSignature($sheet, $record, "P{$row}", $signatureWidth, $signatureHeight);
                    } catch (\Exception $e) {
                        Log::warning('No se pudo embebir firma en reporte de devolución', [
                            'record_id' => $record->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Set row height for signature (on all item rows to display signature properly)
                if ($includeSignatures && $record->signature_path) {
                    $sheet->getRowDimension($row)->setRowHeight(max(60, $signatureHeight + 10));
                }

                $row++;
            }
        }

        // Apply borders to all data rows
        if ($row > 2) {
            $dataRange = "A1:P" . ($row - 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            ]);
        }

        // Add autofilter (exclude column P - Firma imagen)
        if ($row > 2) {
            $sheet->setAutoFilter("A1:O" . ($row - 1));
        }

        // Freeze first row
        $sheet->freezePane('A2');
    }

    /**
     * Get reason label from reason code.
     */
    private function getReasonLabel(?string $reason): string
    {
        $reasonMap = [
            'replacement' => 'Reemplazo',
            'damaged' => 'Dañado',
            'lost' => 'Perdido',
            'other' => 'Otro',
        ];

        return $reasonMap[$reason] ?? ($reason ?? 'No especificado');
    }

    /**
     * Build summary sheet.
     */
    private function buildSummarySheet(
        Worksheet $sheet,
        Collection $deliveries,
        Collection $returns,
        array $filters,
        array $affiliatesMap,
        array $inventoryMap,
        array $colorsMap
    ): void {
        $row = 1;

        // Section 1: Applied Filters
        $sheet->setCellValue("A{$row}", 'Filtros aplicados');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row++;

        $sheet->setCellValue("A{$row}", 'Hospital');
        $sheet->setCellValue("B{$row}", $filters['hospital'] ?? 'Todos');
        $row++;

        $sheet->setCellValue("A{$row}", 'Fecha desde');
        $sheet->setCellValue("B{$row}", $filters['startDate'] ?? 'Sin definir');
        $row++;

        $sheet->setCellValue("A{$row}", 'Fecha hasta');
        $sheet->setCellValue("B{$row}", $filters['endDate'] ?? 'Sin definir');
        $row++;

        $sheet->setCellValue("A{$row}", 'Número de documento');
        $sheet->setCellValue("B{$row}", $filters['documentNumber'] ?? 'Sin definir');
        $row++;

        $sheet->setCellValue("A{$row}", 'Responsable (registrado por)');
        $sheet->setCellValue("B{$row}", $filters['deliveredBy'] ?? 'Sin definir');
        $row += 2; // Blank row

        // Section 2: General Indicators
        $stats = $this->calculateStatistics($deliveries, $returns, $affiliatesMap, $inventoryMap, $colorsMap);

        $sheet->setCellValue("A{$row}", 'Indicadores generales');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row++;

        $sheet->setCellValue("A{$row}", 'Total entregas registradas');
        $sheet->setCellValue("B{$row}", $stats['totalDeliveries']);
        $row++;

        $sheet->setCellValue("A{$row}", 'Total unidades entregadas');
        $sheet->setCellValue("B{$row}", $stats['totalUnitsDelivered']);
        $row++;

        $sheet->setCellValue("A{$row}", 'Entregas con observaciones');
        $sheet->setCellValue("B{$row}", $stats['deliveriesWithNotes']);
        $row++;

        $sheet->setCellValue("A{$row}", 'Entregas primera vez');
        $sheet->setCellValue("B{$row}", $stats['firstTimeDeliveries']);
        $row++;

        $sheet->setCellValue("A{$row}", 'Entregas periódicas');
        $sheet->setCellValue("B{$row}", $stats['periodicDeliveries']);
        $row++;

        $sheet->setCellValue("A{$row}", 'Total devoluciones registradas');
        $sheet->setCellValue("B{$row}", $stats['totalReturns']);
        $row++;

        $sheet->setCellValue("A{$row}", 'Total unidades devueltas');
        $sheet->setCellValue("B{$row}", $stats['totalUnitsReturned']);
        $row++;

        $sheet->setCellValue("A{$row}", 'Devoluciones con observaciones');
        $sheet->setCellValue("B{$row}", $stats['returnsWithNotes']);
        $row++;

        $sheet->setCellValue("A{$row}", 'Afiliados únicos incluidos');
        $sheet->setCellValue("B{$row}", $stats['uniqueAffiliates']);
        $row++;

        $sheet->setCellValue("A{$row}", 'Artículos diferentes entregados');
        $sheet->setCellValue("B{$row}", $stats['uniqueArticlesDelivered']);
        $row++;

        $sheet->setCellValue("A{$row}", 'Artículos diferentes devueltos');
        $sheet->setCellValue("B{$row}", $stats['uniqueArticlesReturned']);
        $row += 2; // Blank row

        // Section 3: Deliveries by Hospital
        $sheet->setCellValue("A{$row}", 'Entregas por hospital');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $sheet->setCellValue("B{$row}", 'Entregas');
        $sheet->setCellValue("C{$row}", 'Unidades entregadas');
        $row++;

        $hospitalStats = $stats['deliveriesByHospital'];
        arsort($hospitalStats);
        foreach ($hospitalStats as $hospital => $data) {
            $sheet->setCellValue("A{$row}", $hospital);
            $sheet->setCellValue("B{$row}", $data['deliveries']);
            $sheet->setCellValue("C{$row}", $data['units']);
            $row++;
        }

        $row += 2; // Blank row

        // Section 3b: Returns by Hospital
        $sheet->setCellValue("A{$row}", 'Devoluciones por hospital');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $sheet->setCellValue("B{$row}", 'Devoluciones');
        $sheet->setCellValue("C{$row}", 'Unidades devueltas');
        $row++;

        $hospitalReturnStats = $stats['returnsByHospital'];
        arsort($hospitalReturnStats);
        foreach ($hospitalReturnStats as $hospital => $data) {
            $sheet->setCellValue("A{$row}", $hospital);
            $sheet->setCellValue("B{$row}", $data['returns']);
            $sheet->setCellValue("C{$row}", $data['units']);
            $row++;
        }

        $row += 2; // Blank row

        // Section 4: Top Articles
        $sheet->setCellValue("A{$row}", 'Top artículos entregados');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $sheet->setCellValue("B{$row}", 'Unidades');
        $row++;

        $topArticles = $stats['topArticles'];
        foreach ($topArticles as $article => $units) {
            $sheet->setCellValue("A{$row}", $article);
            $sheet->setCellValue("B{$row}", $units);
            $row++;
        }

        $row += 2; // Blank row

        // Section 5: Articles by Category
        $sheet->setCellValue("A{$row}", 'Artículos por categoría');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $sheet->setCellValue("B{$row}", 'Unidades');
        $row++;

        $categoryStats = $stats['articlesByCategory'];
        arsort($categoryStats);
        foreach ($categoryStats as $category => $units) {
            $sheet->setCellValue("A{$row}", $category);
            $sheet->setCellValue("B{$row}", $units);
            $row++;
        }

        $row += 2; // Blank row

        // Section 6: Metadata
        $sheet->setCellValue("A{$row}", 'Generado el');
        $sheet->setCellValue("B{$row}", now()->setTimezone('America/Bogota')->format('Y-m-d H:i'));

        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(36);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(24);
    }

    /**
     * Build totals by article sheet.
     */
    private function buildTotalsSheet(
        Worksheet $sheet,
        Collection $deliveries,
        Collection $returns,
        array $inventoryMap,
        array $colorsMap
    ): void {
        // Headers
        $headers = [
            'Artículo',
            'Categoría',
            'Unidades entregadas',
            'Unidades devueltas',
            'Saldo neto',
            'Entregas registradas',
            'Devoluciones registradas',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        // Style headers
        $headerRange = 'A1:G1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '70AD47'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        // Calculate totals for deliveries
        $articleDeliveredTotals = [];
        $articleCategories = [];
        $articleDeliveries = [];

        foreach ($deliveries as $record) {
            foreach ($record->items as $item) {
                $inventoryItem = $inventoryMap[$item->item_id] ?? null;
                $baseArticleName = $inventoryItem['name'] ?? $item->item_name ?? $item->item_id;
                $articleName = ($inventoryItem['gender'] ?? $item->item_gender)
                    ? "{$baseArticleName} ({$inventoryItem['gender']})"
                    : $baseArticleName;

                $colorLabel = $this->resolveColorLabel(
                    $item->variant_color ?? $inventoryItem['defaultColor'] ?? null,
                    $colorsMap
                );

                $articleKey = $colorLabel
                    ? "{$articleName} - {$colorLabel}"
                    : $articleName;

                $category = $inventoryItem['category'] ?? $item->item_category ?? 'Sin categoría';

                if (!isset($articleDeliveredTotals[$articleKey])) {
                    $articleDeliveredTotals[$articleKey] = 0;
                    $articleCategories[$articleKey] = $category;
                    $articleDeliveries[$articleKey] = [];
                }

                $articleDeliveredTotals[$articleKey] += $item->quantity ?? 0;

                if (!in_array($record->id, $articleDeliveries[$articleKey])) {
                    $articleDeliveries[$articleKey][] = $record->id;
                }
            }
        }

        // Calculate totals for returns
        $articleReturnedTotals = [];
        $articleReturns = [];

        foreach ($returns as $record) {
            foreach ($record->items as $item) {
                $inventoryItem = $inventoryMap[$item->item_id] ?? null;
                $baseArticleName = $inventoryItem['name'] ?? $item->item_name ?? $item->item_id;
                $articleName = ($inventoryItem['gender'] ?? $item->item_gender)
                    ? "{$baseArticleName} ({$inventoryItem['gender']})"
                    : $baseArticleName;

                $colorLabel = $this->resolveColorLabel(
                    $item->variant_color ?? $inventoryItem['defaultColor'] ?? null,
                    $colorsMap
                );

                $articleKey = $colorLabel
                    ? "{$articleName} - {$colorLabel}"
                    : $articleName;

                $category = $inventoryItem['category'] ?? $item->item_category ?? 'Sin categoría';

                if (!isset($articleReturnedTotals[$articleKey])) {
                    $articleReturnedTotals[$articleKey] = 0;
                    $articleReturns[$articleKey] = [];
                    // Ensure category is set if not already
                    if (!isset($articleCategories[$articleKey])) {
                        $articleCategories[$articleKey] = $category;
                    }
                }

                $articleReturnedTotals[$articleKey] += $item->quantity ?? 0;

                if (!in_array($record->id, $articleReturns[$articleKey])) {
                    $articleReturns[$articleKey][] = $record->id;
                }
            }
        }

        // Merge all article keys
        $allArticleKeys = array_unique(array_merge(
            array_keys($articleDeliveredTotals),
            array_keys($articleReturnedTotals)
        ));

        // Calculate net balance and sort by net balance (descending)
        $articleNetBalance = [];
        foreach ($allArticleKeys as $articleKey) {
            $delivered = $articleDeliveredTotals[$articleKey] ?? 0;
            $returned = $articleReturnedTotals[$articleKey] ?? 0;
            $articleNetBalance[$articleKey] = $delivered - $returned;
        }
        arsort($articleNetBalance);

        // Write data
        $row = 2;
        foreach ($articleNetBalance as $articleKey => $netBalance) {
            $delivered = $articleDeliveredTotals[$articleKey] ?? 0;
            $returned = $articleReturnedTotals[$articleKey] ?? 0;
            $deliveriesCount = count($articleDeliveries[$articleKey] ?? []);
            $returnsCount = count($articleReturns[$articleKey] ?? []);

            $sheet->setCellValue("A{$row}", $articleKey);
            $sheet->setCellValue("B{$row}", $articleCategories[$articleKey] ?? 'Sin categoría');
            $sheet->setCellValue("C{$row}", $delivered);
            $sheet->setCellValue("D{$row}", $returned);
            $sheet->setCellValue("E{$row}", $netBalance);
            $sheet->setCellValue("F{$row}", $deliveriesCount);
            $sheet->setCellValue("G{$row}", $returnsCount);

            // Style net balance cell (red if negative, green if positive)
            if ($netBalance < 0) {
                $sheet->getStyle("E{$row}")->getFont()->getColor()->setRGB('E74C3C');
            } elseif ($netBalance > 0) {
                $sheet->getStyle("E{$row}")->getFont()->getColor()->setRGB('27AE60');
            }

            $row++;
        }

        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getColumnDimension('E')->setWidth(18);
        $sheet->getColumnDimension('F')->setWidth(20);
        $sheet->getColumnDimension('G')->setWidth(20);

        // Apply borders
        if ($row > 2) {
            $dataRange = "A1:G" . ($row - 1);
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
            ]);
        }

        // Add autofilter
        if ($row > 2) {
            $sheet->setAutoFilter("A1:G" . ($row - 1));
        }

        // Freeze first row
        $sheet->freezePane('A2');
    }

    /**
     * Calculate statistics from deliveries and returns.
     */
    private function calculateStatistics(
        Collection $deliveries,
        Collection $returns,
        array $affiliatesMap,
        array $inventoryMap,
        array $colorsMap
    ): array {
        $stats = [
            'totalDeliveries' => $deliveries->count(),
            'totalUnitsDelivered' => 0,
            'deliveriesWithNotes' => 0,
            'firstTimeDeliveries' => 0,
            'periodicDeliveries' => 0,
            'totalReturns' => $returns->count(),
            'totalUnitsReturned' => 0,
            'returnsWithNotes' => 0,
            'uniqueAffiliates' => 0,
            'uniqueArticlesDelivered' => 0,
            'uniqueArticlesReturned' => 0,
            'deliveriesByHospital' => [],
            'returnsByHospital' => [],
            'topArticles' => [],
            'articlesByCategory' => [],
        ];

        $uniqueAffiliateIds = [];
        $uniqueArticlesDelivered = [];
        $uniqueArticlesReturned = [];

        // Process deliveries
        foreach ($deliveries as $record) {
            $uniqueAffiliateIds[$record->affiliate_id] = true;

            $affiliate = $affiliatesMap[$record->affiliate_id] ?? null;
            $hospital = $record->affiliate_hospital
                ?: ($affiliate['hospital'] ?? '')
                ?: 'No especificado';

            if (!isset($stats['deliveriesByHospital'][$hospital])) {
                $stats['deliveriesByHospital'][$hospital] = [
                    'deliveries' => 0,
                    'units' => 0,
                ];
            }

            $stats['deliveriesByHospital'][$hospital]['deliveries']++;

            $recordUnits = 0;

            foreach ($record->items as $item) {
                $quantity = $item->quantity ?? 0;
                $recordUnits += $quantity;
                $stats['totalUnitsDelivered'] += $quantity;

                $inventoryItem = $inventoryMap[$item->item_id] ?? null;
                $baseArticleName = $inventoryItem['name'] ?? $item->item_name ?? $item->item_id;
                $articleName = ($inventoryItem['gender'] ?? $item->item_gender)
                    ? "{$baseArticleName} ({$inventoryItem['gender']})"
                    : $baseArticleName;

                $colorLabel = $this->resolveColorLabel(
                    $item->variant_color ?? $inventoryItem['defaultColor'] ?? null,
                    $colorsMap
                );

                $articleKey = $colorLabel
                    ? "{$articleName} - {$colorLabel}"
                    : $articleName;

                $uniqueArticlesDelivered[$articleKey] = true;

                $category = $inventoryItem['category'] ?? $item->item_category ?? 'Sin categoría';

                if (!isset($stats['topArticles'][$articleKey])) {
                    $stats['topArticles'][$articleKey] = 0;
                }
                $stats['topArticles'][$articleKey] += $quantity;

                if (!isset($stats['articlesByCategory'][$category])) {
                    $stats['articlesByCategory'][$category] = 0;
                }
                $stats['articlesByCategory'][$category] += $quantity;
            }

            $stats['deliveriesByHospital'][$hospital]['units'] += $recordUnits;

            if ($record->notes && trim($record->notes) !== '') {
                $stats['deliveriesWithNotes']++;
            }

            if ($record->delivery_type === 'first_time') {
                $stats['firstTimeDeliveries']++;
            } elseif ($record->delivery_type === 'periodic') {
                $stats['periodicDeliveries']++;
            }
        }

        // Process returns
        foreach ($returns as $record) {
            $uniqueAffiliateIds[$record->affiliate_id] = true;

            $affiliate = $affiliatesMap[$record->affiliate_id] ?? null;
            $hospital = $record->affiliate_hospital
                ?: ($affiliate['hospital'] ?? '')
                ?: 'No especificado';

            if (!isset($stats['returnsByHospital'][$hospital])) {
                $stats['returnsByHospital'][$hospital] = [
                    'returns' => 0,
                    'units' => 0,
                ];
            }

            $stats['returnsByHospital'][$hospital]['returns']++;

            $recordUnits = 0;

            foreach ($record->items as $item) {
                $quantity = $item->quantity ?? 0;
                $recordUnits += $quantity;
                $stats['totalUnitsReturned'] += $quantity;

                $inventoryItem = $inventoryMap[$item->item_id] ?? null;
                $baseArticleName = $inventoryItem['name'] ?? $item->item_name ?? $item->item_id;
                $articleName = ($inventoryItem['gender'] ?? $item->item_gender)
                    ? "{$baseArticleName} ({$inventoryItem['gender']})"
                    : $baseArticleName;

                $colorLabel = $this->resolveColorLabel(
                    $item->variant_color ?? $inventoryItem['defaultColor'] ?? null,
                    $colorsMap
                );

                $articleKey = $colorLabel
                    ? "{$articleName} - {$colorLabel}"
                    : $articleName;

                $uniqueArticlesReturned[$articleKey] = true;
            }

            $stats['returnsByHospital'][$hospital]['units'] += $recordUnits;

            if ($record->notes && trim($record->notes) !== '') {
                $stats['returnsWithNotes']++;
            }
        }

        $stats['uniqueAffiliates'] = count($uniqueAffiliateIds);
        $stats['uniqueArticlesDelivered'] = count($uniqueArticlesDelivered);
        $stats['uniqueArticlesReturned'] = count($uniqueArticlesReturned);

        // Get top 5 articles
        arsort($stats['topArticles']);
        $stats['topArticles'] = array_slice($stats['topArticles'], 0, 5, true);

        return $stats;
    }

    /**
     * Parse document type from affiliate ID.
     */
    private function parseDocumentTypeFromId(?string $affiliateId): string
    {
        if (!$affiliateId) {
            return '';
        }

        $parts = explode('-', $affiliateId, 2);
        return count($parts) > 1 ? $parts[0] : '';
    }

    /**
     * Parse document number from affiliate ID.
     */
    private function parseDocumentNumberFromId(?string $affiliateId): string
    {
        if (!$affiliateId) {
            return '';
        }

        $parts = explode('-', $affiliateId, 2);
        return count($parts) > 1 ? $parts[1] : $affiliateId;
    }

    /**
     * Get signature URL from delivery record.
     */
    private function getSignatureUrl(SstDeliveryRecord $record): string
    {
        if (!$record->signature_path) {
            return '';
        }

        try {
            $disk = Storage::disk(self::SIGNATURE_DISK);
            if (method_exists($disk, 'temporaryUrl')) {
                return $disk->temporaryUrl($record->signature_path, now()->addMinutes(10));
            }
            return $disk->url($record->signature_path);
        } catch (\Throwable $e) {
            Log::warning('No se pudo generar URL para la firma', [
                'record_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Get signature URL from return record.
     */
    private function getReturnSignatureUrl(SstReturnRecord $record): string
    {
        if (!$record->signature_path) {
            return '';
        }

        try {
            $disk = Storage::disk(self::SIGNATURE_DISK);
            if (method_exists($disk, 'temporaryUrl')) {
                return $disk->temporaryUrl($record->signature_path, now()->addMinutes(10));
            }
            return $disk->url($record->signature_path);
        } catch (\Throwable $e) {
            Log::warning('No se pudo generar URL para la firma de devolución', [
                'record_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Embed signature image in Excel cell.
     */
    private function embedSignature(
        Worksheet $sheet,
        SstDeliveryRecord $record,
        string $cell,
        int $width,
        int $height
    ): void {
        if (!$record->signature_path) {
            return;
        }

        $disk = Storage::disk(self::SIGNATURE_DISK);

        if (!$disk->exists($record->signature_path)) {
            throw new \Exception('Firma no encontrada en almacenamiento');
        }

        // Get file content
        $imageContent = $disk->get($record->signature_path);

        // Check size
        if (strlen($imageContent) > self::MAX_IMAGE_SIZE) {
            throw new \Exception('Imagen excede el tamaño máximo permitido');
        }

        // Detect image type
        $extension = strtolower(pathinfo($record->signature_path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg'])) {
            $imageInfo = @getimagesizefromstring($imageContent);
            if ($imageInfo && isset($imageInfo['mime'])) {
                $mime = $imageInfo['mime'];
                if ($mime === 'image/png') {
                    $extension = 'png';
                } elseif (in_array($mime, ['image/jpeg', 'image/jpg'])) {
                    $extension = 'jpg';
                } else {
                    $extension = 'png'; // Default
                }
            } else {
                $extension = 'png'; // Default
            }
        }

        // Create temporary file for image
        $tempImageFile = tempnam(sys_get_temp_dir(), 'signature_') . '.' . $extension;
        file_put_contents($tempImageFile, $imageContent);

        // Track temp file for cleanup
        $this->tempImageFiles[] = $tempImageFile;

        // Create drawing object
        $drawing = new Drawing();
        $drawing->setPath($tempImageFile);
        $drawing->setCoordinates($cell);
        $drawing->setWidth($width);
        $drawing->setHeight($height);
        $drawing->setOffsetX(5);
        $drawing->setOffsetY(5);
        $drawing->setWorksheet($sheet);
    }

    /**
     * Embed return signature image in Excel cell.
     */
    private function embedReturnSignature(
        Worksheet $sheet,
        SstReturnRecord $record,
        string $cell,
        int $width,
        int $height
    ): void {
        if (!$record->signature_path) {
            return;
        }

        $disk = Storage::disk(self::SIGNATURE_DISK);

        if (!$disk->exists($record->signature_path)) {
            throw new \Exception('Firma no encontrada en almacenamiento');
        }

        // Get file content
        $imageContent = $disk->get($record->signature_path);

        // Check size
        if (strlen($imageContent) > self::MAX_IMAGE_SIZE) {
            throw new \Exception('Imagen excede el tamaño máximo permitido');
        }

        // Detect image type
        $extension = strtolower(pathinfo($record->signature_path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg'])) {
            $imageInfo = @getimagesizefromstring($imageContent);
            if ($imageInfo && isset($imageInfo['mime'])) {
                $mime = $imageInfo['mime'];
                if ($mime === 'image/png') {
                    $extension = 'png';
                } elseif (in_array($mime, ['image/jpeg', 'image/jpg'])) {
                    $extension = 'jpg';
                } else {
                    $extension = 'png'; // Default
                }
            } else {
                $extension = 'png'; // Default
            }
        }

        // Create temporary file for image
        $tempImageFile = tempnam(sys_get_temp_dir(), 'signature_return_') . '.' . $extension;
        file_put_contents($tempImageFile, $imageContent);

        // Track temp file for cleanup
        $this->tempImageFiles[] = $tempImageFile;

        // Create drawing object
        $drawing = new Drawing();
        $drawing->setPath($tempImageFile);
        $drawing->setCoordinates($cell);
        $drawing->setWidth($width);
        $drawing->setHeight($height);
        $drawing->setOffsetX(5);
        $drawing->setOffsetY(5);
        $drawing->setWorksheet($sheet);
    }

    /**
     * Build charts sheet with visualizations.
     */
    private function buildChartsSheet(
        Worksheet $sheet,
        Collection $deliveries,
        Collection $returns,
        array $affiliatesMap,
        array $inventoryMap,
        array $colorsMap
    ): void {
        // Prepare data for charts
        $monthlyData = $this->prepareMonthlyData($deliveries, $returns);
        $categoryData = $this->prepareCategoryData($deliveries, $inventoryMap, $colorsMap);
        $topArticlesData = $this->prepareTopArticlesData($deliveries, $inventoryMap, $colorsMap, 10);
        $hospitalData = $this->prepareHospitalData($deliveries, $affiliatesMap);

        // Chart 1: Entregas vs Devoluciones por Mes (Bar Chart)
        $this->addMonthlyComparisonChart($sheet, $monthlyData, 'A1', 'H1', 'A15', 'H27');

        // Chart 2: Distribución por Categoría (Pie Chart)
        $this->addCategoryDistributionChart($sheet, $categoryData, 'J1', 'Q1', 'J15', 'Q27');

        // Chart 3: Top Artículos Entregados (Bar Chart)
        $this->addTopArticlesChart($sheet, $topArticlesData, 'A29', 'H29', 'A43', 'H55');

        // Chart 4: Entregas por Hospital (Bar Chart)
        $this->addHospitalChart($sheet, $hospitalData, 'J29', 'Q29', 'J43', 'Q55');
    }

    /**
     * Prepare monthly data for charts.
     */
    private function prepareMonthlyData(Collection $deliveries, Collection $returns): array
    {
        $monthlyDeliveries = [];
        $monthlyReturns = [];

        foreach ($deliveries as $delivery) {
            $month = $delivery->delivered_at?->format('Y-m') ?? 'Sin fecha';
            $monthlyDeliveries[$month] = ($monthlyDeliveries[$month] ?? 0) + 1;
        }

        foreach ($returns as $return) {
            $month = $return->returned_at?->format('Y-m') ?? 'Sin fecha';
            $monthlyReturns[$month] = ($monthlyReturns[$month] ?? 0) + 1;
        }

        // Combine all months
        $allMonths = array_unique(array_merge(array_keys($monthlyDeliveries), array_keys($monthlyReturns)));
        sort($allMonths);

        $data = [];
        foreach ($allMonths as $month) {
            $data[] = [
                'month' => $month,
                'deliveries' => $monthlyDeliveries[$month] ?? 0,
                'returns' => $monthlyReturns[$month] ?? 0,
            ];
        }

        return $data;
    }

    /**
     * Prepare category data for charts.
     */
    private function prepareCategoryData(Collection $deliveries, array $inventoryMap, array $colorsMap): array
    {
        $categoryTotals = [];

        foreach ($deliveries as $delivery) {
            foreach ($delivery->items as $item) {
                $inventoryItem = $inventoryMap[$item->item_id] ?? null;
                $category = $inventoryItem['category'] ?? $item->item_category ?? 'Sin categoría';
                $quantity = $item->quantity ?? 0;

                $categoryTotals[$category] = ($categoryTotals[$category] ?? 0) + $quantity;
            }
        }

        arsort($categoryTotals);
        return $categoryTotals;
    }

    /**
     * Prepare top articles data for charts.
     */
    private function prepareTopArticlesData(Collection $deliveries, array $inventoryMap, array $colorsMap, int $limit = 10): array
    {
        $articleTotals = [];

        foreach ($deliveries as $delivery) {
            foreach ($delivery->items as $item) {
                $inventoryItem = $inventoryMap[$item->item_id] ?? null;
                $baseArticleName = $inventoryItem['name'] ?? $item->item_name ?? $item->item_id;
                $articleName = ($inventoryItem['gender'] ?? $item->item_gender)
                    ? "{$baseArticleName} ({$inventoryItem['gender']})"
                    : $baseArticleName;

                $colorLabel = $this->resolveColorLabel(
                    $item->variant_color ?? $inventoryItem['defaultColor'] ?? null,
                    $colorsMap
                );

                $articleKey = $colorLabel
                    ? "{$articleName} - {$colorLabel}"
                    : $articleName;

                $quantity = $item->quantity ?? 0;
                $articleTotals[$articleKey] = ($articleTotals[$articleKey] ?? 0) + $quantity;
            }
        }

        arsort($articleTotals);
        return array_slice($articleTotals, 0, $limit, true);
    }

    /**
     * Prepare hospital data for charts.
     */
    private function prepareHospitalData(Collection $deliveries, array $affiliatesMap): array
    {
        $hospitalTotals = [];

        foreach ($deliveries as $delivery) {
            $affiliate = $affiliatesMap[$delivery->affiliate_id] ?? null;
            $hospital = $delivery->affiliate_hospital
                ?: ($affiliate['hospital'] ?? '')
                ?: 'No especificado';

            $hospitalTotals[$hospital] = ($hospitalTotals[$hospital] ?? 0) + 1;
        }

        arsort($hospitalTotals);
        return array_slice($hospitalTotals, 0, 10, true); // Top 10 hospitals
    }

    /**
     * Add monthly comparison chart (Entregas vs Devoluciones).
     */
    private function addMonthlyComparisonChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
    {
        if (empty($data)) {
            return;
        }

        // Prepare data arrays
        $months = [];
        $deliveriesValues = [];
        $returnsValues = [];

        foreach ($data as $item) {
            $months[] = Carbon::parse($item['month'] . '-01')->format('M Y');
            $deliveriesValues[] = $item['deliveries'];
            $returnsValues[] = $item['returns'];
        }

        // Create data series
        $dataSeriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['Entregas']),
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, ['Devoluciones']),
        ];

        $xAxisTickValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                null,
                null,
                count($months),
                $months
            ),
        ];

        $dataSeriesValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                null,
                null,
                count($deliveriesValues),
                $deliveriesValues
            ),
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                null,
                null,
                count($returnsValues),
                $returnsValues
            ),
        ];

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            range(0, count($dataSeriesValues) - 1),
            $dataSeriesLabels,
            $xAxisTickValues,
            $dataSeriesValues
        );

        $series->setPlotDirection(DataSeries::DIRECTION_VERTICAL);

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $title = new Title('Entregas vs Devoluciones por Mes');

        $chart = new Chart(
            'chart_monthly_comparison',
            $title,
            $legend,
            $plotArea,
            true,
            0,
            new Title('Mes'),
            new Title('Cantidad')
        );

        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        $sheet->addChart($chart);
    }

    /**
     * Add category distribution chart (Pie Chart).
     */
    private function addCategoryDistributionChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
    {
        if (empty($data)) {
            return;
        }

        $categories = array_keys($data);
        $values = array_values($data);

        $dataSeriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1),
        ];

        $xAxisTickValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                null,
                null,
                count($categories),
                $categories
            ),
        ];

        $dataSeriesValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                null,
                null,
                count($values),
                $values
            ),
        ];

        $series = new DataSeries(
            DataSeries::TYPE_PIECHART,
            DataSeries::GROUPING_STANDARD,
            range(0, count($dataSeriesValues) - 1),
            $dataSeriesLabels,
            $xAxisTickValues,
            $dataSeriesValues
        );

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $title = new Title('Distribución de Artículos por Categoría');

        $chart = new Chart(
            'chart_category_distribution',
            $title,
            $legend,
            $plotArea,
            true,
            0
        );

        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        $sheet->addChart($chart);
    }

    /**
     * Add top articles chart (Bar Chart).
     */
    private function addTopArticlesChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
    {
        if (empty($data)) {
            return;
        }

        $articles = array_keys($data);
        // Truncate long article names for better display
        $articles = array_map(function ($name) {
            return mb_strlen($name) > 30 ? mb_substr($name, 0, 27) . '...' : $name;
        }, $articles);
        $values = array_values($data);

        $dataSeriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1),
        ];

        $xAxisTickValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                null,
                null,
                count($articles),
                $articles
            ),
        ];

        $dataSeriesValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                null,
                null,
                count($values),
                $values
            ),
        ];

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_STANDARD,
            range(0, count($dataSeriesValues) - 1),
            $dataSeriesLabels,
            $xAxisTickValues,
            $dataSeriesValues
        );

        $series->setPlotDirection(DataSeries::DIRECTION_HORIZONTAL);

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $title = new Title('Top Artículos Entregados');

        $chart = new Chart(
            'chart_top_articles',
            $title,
            $legend,
            $plotArea,
            true,
            0,
            new Title('Artículo'),
            new Title('Unidades')
        );

        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        $sheet->addChart($chart);
    }

    /**
     * Add hospital chart (Bar Chart).
     */
    private function addHospitalChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
    {
        if (empty($data)) {
            return;
        }

        $hospitals = array_keys($data);
        // Truncate long hospital names for better display
        $hospitals = array_map(function ($name) {
            return mb_strlen($name) > 25 ? mb_substr($name, 0, 22) . '...' : $name;
        }, $hospitals);
        $values = array_values($data);

        $dataSeriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1),
        ];

        $xAxisTickValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                null,
                null,
                count($hospitals),
                $hospitals
            ),
        ];

        $dataSeriesValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                null,
                null,
                count($values),
                $values
            ),
        ];

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_STANDARD,
            range(0, count($dataSeriesValues) - 1),
            $dataSeriesLabels,
            $xAxisTickValues,
            $dataSeriesValues
        );

        $series->setPlotDirection(DataSeries::DIRECTION_VERTICAL);

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $title = new Title('Entregas por Hospital (Top 10)');

        $chart = new Chart(
            'chart_hospitals',
            $title,
            $legend,
            $plotArea,
            true,
            0,
            new Title('Hospital'),
            new Title('Cantidad de Entregas')
        );

        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        $sheet->addChart($chart);
    }

    /**
     * Clean up temporary image files.
     */
    private function cleanupTempFiles(): void
    {
        foreach ($this->tempImageFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->tempImageFiles = [];
    }
}

