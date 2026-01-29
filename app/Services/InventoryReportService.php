<?php

namespace App\Services;

use App\Models\{
    HospitalRequest,
    InventoryCategory,
    InventoryLocation,
    InventoryProduct,
    InventoryVariant,
    InventoryVariantStock,
    SupplierDelivery,
};
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill, Font};
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Chart\{Chart, DataSeries, DataSeriesValues, Legend, PlotArea, Title};

class InventoryReportService
{
    /**
     * Generate Excel report based on report type and filters.
     */
    public function generateReport(string $reportType, ?array $dateRange = null): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // Build report data
        $data = $this->buildReportData($dateRange);

        // Filter data based on report type
        $filteredData = $this->getFilteredData($data, $reportType);

        // Generate Excel file
        $spreadsheet = $this->generateExcel($filteredData, $reportType, $dateRange);

        // Generate filename
        $filename = $this->generateFilename($reportType, $dateRange);

        // Return as streamed response
        return $this->streamExcelResponse($spreadsheet, $filename);
    }

    /**
     * Build base report data from database.
     */
    protected function buildReportData(?array $dateRange = null): array
    {
        $startDate = $dateRange ? Carbon::parse($dateRange['start']) : null;
        $endDate = $dateRange ? Carbon::parse($dateRange['end']) : null;

        // Get all categories with products and variants
        $categories = InventoryCategory::with([
            'products.variants.color',
            'products.variants.stocks.location',
        ])->get();

        // Get pending requests map
        $pendingRequestsMap = $this->buildPendingRequestsMap($startDate, $endDate);

        // Get all requests
        $requests = $this->buildRequests($startDate, $endDate);

        // Get all deliveries
        $deliveries = $this->buildDeliveries($startDate, $endDate);

        // Build categories with products
        $reportCategories = [];
        foreach ($categories as $category) {
            $products = [];
            $lowStockProducts = 0;
            $criticalProducts = 0;

            foreach ($category->products as $product) {
                foreach ($product->variants as $variant) {
                    $stock = $variant->stock ?? 0;
                    $minStock = $variant->min_stock ?? 0;
                    $maxStock = $variant->max_stock ?? ($minStock * 2);
                    $status = $this->getStockStatus($stock, $minStock);

                    // Get pending requests info
                    $variantKey = "{$product->id}-{$variant->id}";
                    $pendingInfo = $pendingRequestsMap[$variantKey] ?? [
                        'quantity' => 0,
                        'hospitals' => [],
                        'lastDate' => null,
                    ];

                    // Also check for product-level requests (when variant_id is null)
                    $productKey = "{$product->id}-default";
                    if (isset($pendingRequestsMap[$productKey])) {
                        $pendingInfo['quantity'] += $pendingRequestsMap[$productKey]['quantity'];
                        $pendingInfo['hospitals'] = array_merge($pendingInfo['hospitals'], $pendingRequestsMap[$productKey]['hospitals']);
                        if ($pendingRequestsMap[$productKey]['lastDate'] && (!$pendingInfo['lastDate'] || Carbon::parse($pendingRequestsMap[$productKey]['lastDate'])->gt($pendingInfo['lastDate']))) {
                            $pendingInfo['lastDate'] = $pendingRequestsMap[$productKey]['lastDate'];
                        }
                    }
                    $pendingInfo['hospitals'] = array_unique($pendingInfo['hospitals']);

                    // Build variant name
                    $variantName = $this->buildVariantName($product->name, $variant);

                    // Get stock by location
                    $stockByLocation = $this->getStockByLocation($variant);

                    $productData = [
                        'productId' => $product->id,
                        'variantId' => $variant->id,
                        'sku' => $variant->sku ?? '',
                        'name' => $variantName,
                        'categoryName' => $category->name,
                        'stock' => $stock,
                        'min' => $minStock,
                        'max' => $maxStock,
                        'status' => $status,
                        'pendingRequests' => $pendingInfo['quantity'],
                        'pendingHospitals' => $pendingInfo['hospitals'],
                        'lastRequestDate' => $pendingInfo['lastDate'],
                        'stockByLocation' => $stockByLocation,
                    ];

                    $products[] = $productData;

                    if ($status === 'low') {
                        $lowStockProducts++;
                    } elseif ($status === 'critical') {
                        $criticalProducts++;
                    }
                }
            }

            if (!empty($products)) {
                $reportCategories[] = [
                    'id' => $category->id,
                    'name' => $category->name,
                    'description' => $category->description,
                    'totalProducts' => count($products),
                    'lowStockProducts' => $lowStockProducts,
                    'criticalProducts' => $criticalProducts,
                    'products' => $products,
                ];
            }
        }

        // Calculate summary
        $summary = $this->calculateSummary($reportCategories, $requests, $deliveries);

        return [
            'metadata' => [
                'generatedAt' => now()->locale('es-ES')->isoFormat('dddd, D [de] MMMM [de] YYYY [a las] HH:mm:ss'),
                'reportId' => 'REP-' . now()->format('YmdHis'),
                'generatedBy' => 'Sistema ProSalud',
                'dateRange' => $dateRange ? [
                    'start' => $startDate->locale('es-ES')->isoFormat('dddd, D [de] MMMM [de] YYYY'),
                    'end' => $endDate->locale('es-ES')->isoFormat('dddd, D [de] MMMM [de] YYYY'),
                ] : null,
            ],
            'summary' => $summary,
            'categories' => $reportCategories,
            'requests' => $requests,
            'deliveries' => $deliveries,
        ];
    }

    /**
     * Build map of pending requests by variant.
     */
    protected function buildPendingRequestsMap(?Carbon $startDate, ?Carbon $endDate): array
    {
        $query = HospitalRequest::with(['items.variant', 'items.product'])
            ->whereNotIn('status', ['rejected', 'delivered']);

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('created_at', '<=', $endDate->endOfDay());
        }

        $requests = $query->get();
        $map = [];

        foreach ($requests as $request) {
            foreach ($request->items as $item) {
                // Handle cases where variant_id might be null
                $variantId = $item->variant_id ?? 'default';
                $variantKey = "{$item->product_id}-{$variantId}";
                if (!isset($map[$variantKey])) {
                    $map[$variantKey] = [
                        'quantity' => 0,
                        'hospitals' => [],
                        'lastDate' => null,
                    ];
                }

                $map[$variantKey]['quantity'] += $item->quantity;
                $map[$variantKey]['hospitals'][] = $request->hospital_name;
                $requestDate = Carbon::parse($request->created_at);
                if (!$map[$variantKey]['lastDate'] || $requestDate->gt($map[$variantKey]['lastDate'])) {
                    $map[$variantKey]['lastDate'] = $requestDate;
                }
            }
        }

        // Remove duplicates from hospitals array
        foreach ($map as $key => $value) {
            $map[$key]['hospitals'] = array_unique($value['hospitals']);
        }

        return $map;
    }

    /**
     * Build requests array.
     */
    protected function buildRequests(?Carbon $startDate, ?Carbon $endDate): array
    {
        $query = HospitalRequest::with(['items', 'timeline']);

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('created_at', '<=', $endDate->endOfDay());
        }

        $requests = $query->get();
        $result = [];

        foreach ($requests as $request) {
            $totalItems = $request->items->sum('quantity');
            $pendingItems = in_array($request->status, ['delivered', 'rejected']) ? 0 : $totalItems;

            $lastUpdate = $request->timeline->isNotEmpty()
                ? $request->timeline->last()->timestamp
                : $request->created_at;

            $result[] = [
                'id' => $request->id,
                'hospital' => $request->hospital_name,
                'coordinator' => $request->requested_by,
                'createdAt' => $request->created_at->toIso8601String(),
                'status' => $request->status,
                'totalItems' => $totalItems,
                'pendingItems' => $pendingItems,
                'lastUpdate' => Carbon::parse($lastUpdate)->toIso8601String(),
            ];
        }

        return $result;
    }

    /**
     * Build deliveries array.
     */
    protected function buildDeliveries(?Carbon $startDate, ?Carbon $endDate): array
    {
        $query = SupplierDelivery::with(['items.variant.color', 'items.product']);

        if ($startDate) {
            $query->where('delivery_date', '>=', $startDate->startOfDay());
        }
        if ($endDate) {
            $query->where('delivery_date', '<=', $endDate->endOfDay());
        }

        $deliveries = $query->get();
        $result = [];

        foreach ($deliveries as $delivery) {
            $products = [];
            foreach ($delivery->items as $item) {
                if ($item->product && $item->variant) {
                    $variantName = $this->buildVariantName(
                        $item->product->name,
                        $item->variant
                    );
                    $products[] = $variantName;
                }
            }

            $totalItems = $delivery->total_items ?? $delivery->items->sum('quantity');

            $result[] = [
                'id' => $delivery->id,
                'supplier' => $delivery->supplier_name,
                'date' => $delivery->delivery_date->toIso8601String(),
                'totalItems' => $totalItems,
                'status' => $delivery->status,
                'products' => array_unique($products),
            ];
        }

        return $result;
    }

    /**
     * Get stock status.
     */
    protected function getStockStatus(int $stock, int $min): string
    {
        if ($stock <= 0) {
            return 'critical';
        }
        if ($stock <= $min) {
            return 'low';
        }

        return 'ok';
    }

    /**
     * Build variant name.
     */
    protected function buildVariantName(string $productName, InventoryVariant $variant): string
    {
        $parts = [$productName];

        if ($variant->size) {
            $parts[] = "Talla {$variant->size}";
        }

        if ($variant->color) {
            $parts[] = $variant->color->label;
        }

        return implode(' · ', $parts);
    }

    /**
     * Get stock by location for a variant.
     */
    protected function getStockByLocation(InventoryVariant $variant): array
    {
        $stocks = InventoryVariantStock::with('location')
            ->where('variant_id', $variant->id)
            ->get();

        $result = [];
        foreach ($stocks as $stock) {
            $result[] = [
                'location' => $stock->location->name,
                'isPrimary' => $stock->location->is_primary,
                'stock' => $stock->stock,
                'reserved' => $stock->reserved,
                'available' => $stock->stock - $stock->reserved,
            ];
        }

        return $result;
    }

    /**
     * Calculate summary metrics.
     */
    protected function calculateSummary(array $categories, array $requests, array $deliveries): array
    {
        $totalCategories = count($categories);
        $totalProducts = 0;
        $totalVariants = 0;
        $totalStock = 0;
        $lowStockCount = 0;
        $criticalStockCount = 0;

        foreach ($categories as $category) {
            $totalProducts += count(array_unique(array_column($category['products'], 'productId')));
            $totalVariants += count($category['products']);

            foreach ($category['products'] as $product) {
                $totalStock += $product['stock'];
                if ($product['status'] === 'low') {
                    $lowStockCount++;
                } elseif ($product['status'] === 'critical') {
                    $criticalStockCount++;
                }
            }
        }

        $requestStatusCounts = array_count_values(array_column($requests, 'status'));
        $deliveryStatusCounts = array_count_values(array_column($deliveries, 'status'));

        return [
            'totalCategories' => $totalCategories,
            'totalProducts' => $totalProducts,
            'totalVariants' => $totalVariants,
            'totalStock' => $totalStock,
            'lowStockCount' => $lowStockCount,
            'criticalStockCount' => $criticalStockCount,
            'pendingHospitalRequests' => $requestStatusCounts['pending'] ?? 0,
            'preparingHospitalRequests' => $requestStatusCounts['preparing'] ?? 0,
            'deliveredHospitalRequests' => $requestStatusCounts['delivered'] ?? 0,
            'rejectedHospitalRequests' => $requestStatusCounts['rejected'] ?? 0,
            'pendingDeliveries' => $deliveryStatusCounts['pending'] ?? 0,
        ];
    }

    /**
     * Filter data based on report type.
     */
    protected function getFilteredData(array $data, string $reportType): array
    {
        if ($reportType === 'strategic') {
            return $data;
        }

        $filteredCategories = [];
        foreach ($data['categories'] as $category) {
            $filteredProducts = [];

            foreach ($category['products'] as $product) {
                $include = false;

                if ($reportType === 'operational') {
                    $include = $product['pendingRequests'] > 0 || $product['status'] !== 'ok';
                } elseif ($reportType === 'critical_stock') {
                    $include = $product['status'] !== 'ok';
                }

                if ($include) {
                    $filteredProducts[] = $product;
                }
            }

            if (!empty($filteredProducts)) {
                $filteredCategories[] = array_merge($category, ['products' => $filteredProducts]);
            }
        }

        // Filter requests for operational and critical_stock
        $filteredRequests = [];
        if ($reportType !== 'strategic') {
            foreach ($data['requests'] as $request) {
                if ($request['pendingItems'] > 0) {
                    $filteredRequests[] = $request;
                }
            }
        } else {
            $filteredRequests = $data['requests'];
        }

        return [
            'metadata' => $data['metadata'],
            'summary' => $this->calculateSummary($filteredCategories, $filteredRequests, $data['deliveries']),
            'categories' => $filteredCategories,
            'requests' => $filteredRequests,
            'deliveries' => $data['deliveries'],
        ];
    }

    /**
     * Generate Excel file.
     */
    protected function generateExcel(array $data, string $reportType, ?array $dateRange): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        // Create sheets
        $this->createSummarySheet($spreadsheet, $data, $reportType);
        $this->createInventorySheet($spreadsheet, $data);
        $this->createLowStockSheet($spreadsheet, $data);
        $this->createRequestsSheet($spreadsheet, $data);
        $this->createDeliveriesSheet($spreadsheet, $data);
        $this->createStockByLocationSheet($spreadsheet, $data);

        return $spreadsheet;
    }

    /**
     * Create summary sheet.
     */
    protected function createSummarySheet(Spreadsheet $spreadsheet, array $data, string $reportType): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Resumen');

        $row = 1;
        $sheet->setCellValue('A' . $row, 'REPORTE DE INVENTARIO PROSALUD');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);

        $row = 2;
        $sheet->setCellValue('A' . $row, 'Fecha de generación: ' . $data['metadata']['generatedAt']);

        $row = 3;
        $sheet->setCellValue('A' . $row, 'ID del Reporte: ' . $data['metadata']['reportId']);

        $row = 4;
        $reportTypeNames = [
            'strategic' => 'Estratégico',
            'operational' => 'Operacional',
            'critical_stock' => 'Stock Crítico',
        ];
        $sheet->setCellValue('A' . $row, 'Tipo de Reporte: ' . ($reportTypeNames[$reportType] ?? $reportType));

        if ($data['metadata']['dateRange']) {
            $row = 5;
            $sheet->setCellValue('A' . $row, 'Período: ' . $data['metadata']['dateRange']['start'] . ' - ' . $data['metadata']['dateRange']['end']);
        }

        $row = ($data['metadata']['dateRange'] ? 7 : 6);
        $sheet->setCellValue('A' . $row, 'RESUMEN GENERAL');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);

        $row++;
        $sheet->setCellValue('A' . $row, 'Métrica');
        $sheet->setCellValue('B' . $row, 'Valor');
        $sheet->getStyle('A' . $row . ':B' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':B' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E0E0E0');

        $metrics = [
            'Total de categorías activas' => $data['summary']['totalCategories'],
            'Total de productos (SKU únicos)' => $data['summary']['totalProducts'],
            'Total de variantes' => $data['summary']['totalVariants'],
            'Unidades en stock' => $data['summary']['totalStock'],
            'Variantes con stock bajo' => $data['summary']['lowStockCount'],
            'Variantes con stock crítico' => $data['summary']['criticalStockCount'],
            'Solicitudes pendientes' => $data['summary']['pendingHospitalRequests'],
            'Solicitudes en preparación' => $data['summary']['preparingHospitalRequests'],
            'Solicitudes entregadas' => $data['summary']['deliveredHospitalRequests'],
            'Solicitudes rechazadas' => $data['summary']['rejectedHospitalRequests'],
            'Entregas pendientes' => $data['summary']['pendingDeliveries'],
        ];

        foreach ($metrics as $label => $value) {
            $row++;
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, $value);
        }

        $sheet->getColumnDimension('A')->setWidth(35);
        $sheet->getColumnDimension('B')->setWidth(25);

        // Agregar gráficas estratégicas después de las métricas
        $this->addChartsToSummarySheet($sheet, $data, $row + 3);
    }

    /**
     * Create inventory sheet.
     */
    protected function createInventorySheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Inventario');

        $row = 1;
        $sheet->setCellValue('A' . $row, 'DETALLE DE INVENTARIO');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);

        $row = 3;
        $headers = [
            'A' => 'Categoría',
            'B' => 'SKU',
            'C' => 'Producto / Variante',
            'D' => 'Stock',
            'E' => 'Mínimo',
            'F' => 'Máximo',
            'G' => 'Estado',
            'H' => 'Solicitudes pendientes',
            'I' => 'Hospitales pendientes',
            'J' => 'Última solicitud',
        ];

        foreach ($headers as $col => $header) {
            $sheet->setCellValue($col . $row, $header);
        }

        $sheet->getStyle('A' . $row . ':J' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':J' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E0E0E0');

        $row++;
        foreach ($data['categories'] as $category) {
            foreach ($category['products'] as $product) {
                $sheet->setCellValue('A' . $row, $product['categoryName']);
                $sheet->setCellValue('B' . $row, $product['sku']);
                $sheet->setCellValue('C' . $row, $product['name']);
                $sheet->setCellValue('D' . $row, $product['stock']);
                $sheet->setCellValue('E' . $row, $product['min']);
                $sheet->setCellValue('F' . $row, $product['max']);

                $statusLabels = ['ok' => 'Óptimo', 'low' => 'Bajo', 'critical' => 'Crítico'];
                $sheet->setCellValue('G' . $row, $statusLabels[$product['status']] ?? $product['status']);

                $sheet->setCellValue('H' . $row, $product['pendingRequests']);
                $sheet->setCellValue('I' . $row, !empty($product['pendingHospitals']) ? implode(', ', $product['pendingHospitals']) : '—');
                $sheet->setCellValue('J' . $row, $product['lastRequestDate'] ? Carbon::parse($product['lastRequestDate'])->locale('es-CO')->isoFormat('DD/MM/YYYY, HH:mm:ss') : '—');

                // Color code status
                if ($product['status'] === 'critical') {
                    $sheet->getStyle('G' . $row)->getFont()->getColor()->setRGB('FF0000');
                } elseif ($product['status'] === 'low') {
                    $sheet->getStyle('G' . $row)->getFont()->getColor()->setRGB('FFA500');
                }

                $row++;
            }
        }

        // Set column widths
        $widths = ['A' => 28, 'B' => 18, 'C' => 45, 'D' => 12, 'E' => 12, 'F' => 12, 'G' => 16, 'H' => 20, 'I' => 32, 'J' => 22];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        // Auto filter
        $sheet->setAutoFilter('A3:J' . ($row - 1));
    }

    /**
     * Create low stock sheet.
     */
    protected function createLowStockSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $lowStockProducts = [];
        foreach ($data['categories'] as $category) {
            foreach ($category['products'] as $product) {
                if ($product['status'] !== 'ok') {
                    $lowStockProducts[] = $product;
                }
            }
        }

        if (empty($lowStockProducts)) {
            return;
        }

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Stock Bajo');

        $row = 1;
        $sheet->setCellValue('A' . $row, 'VARIANTES CON STOCK CRÍTICO O BAJO');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);

        $row = 3;
        $headers = [
            'A' => 'Categoría',
            'B' => 'SKU',
            'C' => 'Producto / Variante',
            'D' => 'Stock',
            'E' => 'Mínimo',
            'F' => 'Estado',
            'G' => 'Solicitudes pendientes',
            'H' => 'Hospitales',
        ];

        foreach ($headers as $col => $header) {
            $sheet->setCellValue($col . $row, $header);
        }

        $sheet->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':H' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E0E0E0');

        $row++;
        foreach ($lowStockProducts as $product) {
            $sheet->setCellValue('A' . $row, $product['categoryName']);
            $sheet->setCellValue('B' . $row, $product['sku']);
            $sheet->setCellValue('C' . $row, $product['name']);
            $sheet->setCellValue('D' . $row, $product['stock']);
            $sheet->setCellValue('E' . $row, $product['min']);

            $statusLabels = ['low' => 'Stock bajo', 'critical' => 'Stock crítico'];
            $sheet->setCellValue('F' . $row, $statusLabels[$product['status']] ?? $product['status']);

            $sheet->setCellValue('G' . $row, $product['pendingRequests']);
            $sheet->setCellValue('H' . $row, !empty($product['pendingHospitals']) ? implode(', ', $product['pendingHospitals']) : '—');

            // Color code
            if ($product['status'] === 'critical') {
                $sheet->getStyle('F' . $row)->getFont()->getColor()->setRGB('FF0000');
            } else {
                $sheet->getStyle('F' . $row)->getFont()->getColor()->setRGB('FFA500');
            }

            $row++;
        }

        $widths = ['A' => 28, 'B' => 18, 'C' => 45, 'D' => 12, 'E' => 12, 'F' => 18, 'G' => 18, 'H' => 32];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $sheet->setAutoFilter('A3:H' . ($row - 1));
    }

    /**
     * Create requests sheet.
     */
    protected function createRequestsSheet(Spreadsheet $spreadsheet, array $data): void
    {
        if (empty($data['requests'])) {
            return;
        }

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Solicitudes');

        $row = 1;
        $sheet->setCellValue('A' . $row, 'SOLICITUDES DE HOSPITALES');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);

        $row = 3;
        $headers = [
            'A' => 'ID',
            'B' => 'Hospital',
            'C' => 'Coordinador',
            'D' => 'Fecha creación',
            'E' => 'Estado',
            'F' => 'Total ítems',
            'G' => 'Ítems pendientes',
            'H' => 'Última actualización',
        ];

        foreach ($headers as $col => $header) {
            $sheet->setCellValue($col . $row, $header);
        }

        $sheet->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':H' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E0E0E0');

        $statusLabels = [
            'pending' => 'Pendiente',
            'approved' => 'Aprobada',
            'preparing' => 'Preparando',
            'delivered' => 'Entregada',
            'rejected' => 'Rechazada',
        ];

        // Colores de fondo para cada estado
        $statusColors = [
            'pending' => 'FFE0B2',      // Naranja claro
            'approved' => 'C8E6C9',      // Verde claro
            'preparing' => 'BBDEFB',     // Azul claro
            'delivered' => 'A5D6A7',     // Verde más oscuro
            'rejected' => 'EF9A9A',      // Rojo claro
        ];

        $row++;
        foreach ($data['requests'] as $request) {
            $sheet->setCellValue('A' . $row, $request['id']);
            $sheet->setCellValue('B' . $row, $request['hospital']);
            $sheet->setCellValue('C' . $row, $request['coordinator'] ?? '—');
            $sheet->setCellValue('D' . $row, Carbon::parse($request['createdAt'])->locale('es-CO')->isoFormat('DD/MM/YYYY, HH:mm:ss'));
            
            $statusLabel = $statusLabels[$request['status']] ?? $request['status'];
            $sheet->setCellValue('E' . $row, $statusLabel);
            
            // Aplicar color de fondo según el estado
            if (isset($statusColors[$request['status']])) {
                $sheet->getStyle('E' . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($statusColors[$request['status']]);
            }
            
            $sheet->setCellValue('F' . $row, $request['totalItems']);
            $sheet->setCellValue('G' . $row, $request['pendingItems']);
            $sheet->setCellValue('H' . $row, Carbon::parse($request['lastUpdate'])->locale('es-CO')->isoFormat('DD/MM/YYYY, HH:mm:ss'));

            $row++;
        }

        $widths = ['A' => 18, 'B' => 36, 'C' => 26, 'D' => 24, 'E' => 16, 'F' => 16, 'G' => 18, 'H' => 24];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $sheet->setAutoFilter('A3:H' . ($row - 1));
    }

    /**
     * Create deliveries sheet.
     */
    protected function createDeliveriesSheet(Spreadsheet $spreadsheet, array $data): void
    {
        if (empty($data['deliveries'])) {
            return;
        }

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Entregas');

        $row = 1;
        $sheet->setCellValue('A' . $row, 'ENTREGAS DE PROVEEDORES');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);

        $row = 3;
        $headers = [
            'A' => 'ID',
            'B' => 'Proveedor',
            'C' => 'Fecha',
            'D' => 'Total ítems',
            'E' => 'Estado',
            'F' => 'Productos',
        ];

        foreach ($headers as $col => $header) {
            $sheet->setCellValue($col . $row, $header);
        }

        $sheet->getStyle('A' . $row . ':F' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':F' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E0E0E0');

        $statusLabels = [
            'pending' => 'Pendiente',
            'received' => 'Recibida',
            'completed' => 'Completada',
        ];

        $row++;
        foreach ($data['deliveries'] as $delivery) {
            $sheet->setCellValue('A' . $row, $delivery['id']);
            $sheet->setCellValue('B' . $row, $delivery['supplier']);
            $sheet->setCellValue('C' . $row, Carbon::parse($delivery['date'])->locale('es-ES')->isoFormat('DD/MM/YYYY'));
            $sheet->setCellValue('D' . $row, number_format($delivery['totalItems'], 0, ',', '.'));
            $sheet->setCellValue('E' . $row, $statusLabels[$delivery['status']] ?? $delivery['status']);
            $sheet->setCellValue('F' . $row, !empty($delivery['products']) ? implode(', ', $delivery['products']) : '—');

            $row++;
        }

        $widths = ['A' => 18, 'B' => 32, 'C' => 18, 'D' => 14, 'E' => 16, 'F' => 60];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $sheet->setAutoFilter('A3:F' . ($row - 1));
    }

    /**
     * Create stock by location sheet (NEW - improved feature).
     */
    protected function createStockByLocationSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Stock por Ubicación');

        $row = 1;
        $sheet->setCellValue('A' . $row, 'STOCK POR UBICACIÓN (BODEGA PRINCIPAL Y SATÉLITES)');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);

        $row = 3;
        $headers = [
            'A' => 'Categoría',
            'B' => 'SKU',
            'C' => 'Producto / Variante',
            'D' => 'Ubicación',
            'E' => 'Tipo',
            'F' => 'Stock',
            'G' => 'Reservado',
            'H' => 'Disponible',
        ];

        foreach ($headers as $col => $header) {
            $sheet->setCellValue($col . $row, $header);
        }

        $sheet->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':H' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E0E0E0');

        $row++;
        foreach ($data['categories'] as $category) {
            foreach ($category['products'] as $product) {
                if (!empty($product['stockByLocation'])) {
                    foreach ($product['stockByLocation'] as $locationStock) {
                        $sheet->setCellValue('A' . $row, $product['categoryName']);
                        $sheet->setCellValue('B' . $row, $product['sku']);
                        $sheet->setCellValue('C' . $row, $product['name']);
                        $sheet->setCellValue('D' . $row, $locationStock['location']);
                        $sheet->setCellValue('E' . $row, $locationStock['isPrimary'] ? 'Principal' : 'Satélite');
                        $sheet->setCellValue('F' . $row, $locationStock['stock']);
                        $sheet->setCellValue('G' . $row, $locationStock['reserved']);
                        $sheet->setCellValue('H' . $row, $locationStock['available']);

                        if ($locationStock['available'] < 0) {
                            $sheet->getStyle('H' . $row)->getFont()->getColor()->setRGB('FF0000');
                        }

                        $row++;
                    }
                } else {
                    // If no location data, show total stock
                    $sheet->setCellValue('A' . $row, $product['categoryName']);
                    $sheet->setCellValue('B' . $row, $product['sku']);
                    $sheet->setCellValue('C' . $row, $product['name']);
                    $sheet->setCellValue('D' . $row, 'N/A');
                    $sheet->setCellValue('E' . $row, '—');
                    $sheet->setCellValue('F' . $row, $product['stock']);
                    $sheet->setCellValue('G' . $row, 0);
                    $sheet->setCellValue('H' . $row, $product['stock']);
                    $row++;
                }
            }
        }

        $widths = ['A' => 28, 'B' => 18, 'C' => 45, 'D' => 28, 'E' => 12, 'F' => 12, 'G' => 12, 'H' => 12];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $sheet->setAutoFilter('A3:H' . ($row - 1));
    }

    /**
     * Add strategic charts to summary sheet.
     */
    protected function addChartsToSummarySheet(Worksheet $sheet, array $data, int $startRow): void
    {
        // Preparar datos para gráficas
        $stockStatusData = $this->prepareStockStatusData($data);
        $requestStatusData = $this->prepareRequestStatusData($data);
        $stockByCategoryData = $this->prepareStockByCategoryData($data);

        $chartRow = $startRow;

        // Gráfica 1: Distribución de Estado de Stock (Pastel) - Columna D
        if (!empty($stockStatusData)) {
            $this->addStockStatusChart($sheet, $stockStatusData, 'D' . $chartRow, 'I' . $chartRow, 'D' . ($chartRow + 15), 'I' . ($chartRow + 28));
        }

        // Gráfica 2: Distribución de Estado de Solicitudes (Barras) - Columna K
        if (!empty($requestStatusData)) {
            $this->addRequestStatusChart($sheet, $requestStatusData, 'K' . $chartRow, 'P' . $chartRow, 'K' . ($chartRow + 15), 'P' . ($chartRow + 28));
        }

        // Gráfica 3: Stock por Categoría (Barras) - Columna D, más abajo
        if (!empty($stockByCategoryData)) {
            $chartRow2 = $chartRow + 30;
            $this->addStockByCategoryChart($sheet, $stockByCategoryData, 'D' . $chartRow2, 'I' . $chartRow2, 'D' . ($chartRow2 + 15), 'I' . ($chartRow2 + 28));
        }
    }

    /**
     * Prepare stock status data for chart.
     */
    protected function prepareStockStatusData(array $data): array
    {
        $optimalCount = $data['summary']['totalVariants'] - $data['summary']['lowStockCount'] - $data['summary']['criticalStockCount'];
        
        return array_filter([
            'Óptimo' => $optimalCount,
            'Stock Bajo' => $data['summary']['lowStockCount'],
            'Stock Crítico' => $data['summary']['criticalStockCount'],
        ], fn($count) => $count > 0);
    }

    /**
     * Prepare request status data for chart.
     */
    protected function prepareRequestStatusData(array $data): array
    {
        return array_filter([
            'Pendientes' => $data['summary']['pendingHospitalRequests'],
            'En Preparación' => $data['summary']['preparingHospitalRequests'],
            'Entregadas' => $data['summary']['deliveredHospitalRequests'],
            'Rechazadas' => $data['summary']['rejectedHospitalRequests'],
        ], fn($count) => $count > 0);
    }

    /**
     * Prepare stock by category data for chart.
     */
    protected function prepareStockByCategoryData(array $data): array
    {
        $result = [];
        foreach ($data['categories'] as $category) {
            $totalStock = array_sum(array_column($category['products'], 'stock'));
            if ($totalStock > 0) {
                $result[$category['name']] = $totalStock;
            }
        }
        
        // Limitar a top 10 categorías para mejor visualización
        arsort($result);
        return array_slice($result, 0, 10, true);
    }

    /**
     * Add stock status distribution chart (Pie Chart).
     */
    protected function addStockStatusChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
    {
        if (empty($data)) {
            return;
        }

        $labels = array_keys($data);
        $values = array_values($data);

        $dataSeriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1),
        ];

        $xAxisTickValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                null,
                null,
                count($labels),
                $labels
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
        $title = new Title('Distribución de Estado de Stock');

        $chart = new Chart('stock_status_chart', $title, $legend, $plotArea, true, 0, null, null);
        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        $sheet->addChart($chart);
    }

    /**
     * Add request status distribution chart (Bar Chart).
     */
    protected function addRequestStatusChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
    {
        if (empty($data)) {
            return;
        }

        $labels = array_keys($data);
        $values = array_values($data);

        $dataSeriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1),
        ];

        $xAxisTickValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                null,
                null,
                count($labels),
                $labels
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
            DataSeries::GROUPING_CLUSTERED,
            range(0, count($dataSeriesValues) - 1),
            $dataSeriesLabels,
            $xAxisTickValues,
            $dataSeriesValues
        );

        $series->setPlotDirection(DataSeries::DIRECTION_VERTICAL);

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $title = new Title('Distribución de Estado de Solicitudes');

        $chart = new Chart('request_status_chart', $title, $legend, $plotArea, true, 0, new Title('Estado'), new Title('Cantidad'));
        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        $sheet->addChart($chart);
    }

    /**
     * Add stock by category chart (Bar Chart).
     */
    protected function addStockByCategoryChart(Worksheet $sheet, array $data, string $topLeft, string $topRight, string $bottomLeft, string $bottomRight): void
    {
        if (empty($data)) {
            return;
        }

        $labels = array_keys($data);
        $values = array_values($data);

        $dataSeriesLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1),
        ];

        $xAxisTickValues = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                null,
                null,
                count($labels),
                $labels
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
            DataSeries::GROUPING_CLUSTERED,
            range(0, count($dataSeriesValues) - 1),
            $dataSeriesLabels,
            $xAxisTickValues,
            $dataSeriesValues
        );

        $series->setPlotDirection(DataSeries::DIRECTION_VERTICAL);

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $title = new Title('Stock por Categoría (Top 10)');

        $chart = new Chart('stock_by_category_chart', $title, $legend, $plotArea, true, 0, new Title('Categoría'), new Title('Stock'));
        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);

        $sheet->addChart($chart);
    }

    /**
     * Generate filename.
     */
    protected function generateFilename(string $reportType, ?array $dateRange): string
    {
        $typeMap = [
            'strategic' => 'STRATEGIC',
            'operational' => 'OPERATIONAL',
            'critical_stock' => 'CRITICAL_STOCK',
        ];

        $date = $dateRange ? Carbon::parse($dateRange['start'])->format('Y-m-d') : now()->format('Y-m-d');

        return "Reporte_{$typeMap[$reportType]}_{$date}.xlsx";
    }

    /**
     * Stream Excel response.
     */
    protected function streamExcelResponse(Spreadsheet $spreadsheet, string $filename): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}

