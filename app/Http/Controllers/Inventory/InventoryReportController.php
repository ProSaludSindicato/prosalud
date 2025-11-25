<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\GenerateInventoryReportRequest;
use App\Services\InventoryReportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryReportController extends Controller
{
    public function __construct(
        protected InventoryReportService $reportService
    ) {
    }

    /**
     * Generate Excel report for inventory.
     */
    public function generateExcel(GenerateInventoryReportRequest $request): StreamedResponse|JsonResponse
    {
        // Validate user has required permissions
        $user = $request->user();
        $requiredPermissions = [
            'inventory.view_dashboard',
            'inventory.products.view',
            'inventory.categories.view',
        ];

        foreach ($requiredPermissions as $permission) {
            if (!$user->can($permission)) {
                return response()->json([
                    'message' => 'Acceso denegado. Permisos insuficientes para generar reportes.',
                    'required_permissions' => $requiredPermissions,
                ], 403);
            }
        }

        try {
            $reportType = $request->input('reportType');
            $dateRange = $request->input('dateRange');

            return $this->reportService->generateReport($reportType, $dateRange);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al generar el reporte: ' . $e->getMessage(),
            ], 500);
        }
    }
}

