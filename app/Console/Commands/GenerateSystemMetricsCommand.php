<?php

namespace App\Console\Commands;

use App\Constants\{RequestStatuses, RequestTypes};
use App\Models\{
    CertificadoConvenioRecord,
    ChatbotConversation,
    ConvenioEmailTracking,
    RequestForm,
    RequestStatusLog,
    SocioDemographicSurvey,
    SstDeliveryItem,
    SstDeliveryRecord,
    SstReturnItem,
    SstReturnRecord,
    WellnessDeliveryRequest,
    WellnessRequest,
};
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateSystemMetricsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system:metrics
                            {--from= : Fecha de inicio (formato: Y-m-d)}
                            {--to= : Fecha de fin (formato: Y-m-d)}
                            {--format=table : Formato de salida (table, json, both)}
                            {--output= : Ruta del archivo para guardar el reporte}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera un reporte completo de métricas y estadísticas del uso de la plataforma';

    /**
     * Fecha de inicio para el análisis
     */
    private ?Carbon $fromDate = null;

    /**
     * Fecha de fin para el análisis
     */
    private ?Carbon $toDate = null;

    /**
     * Datos recopilados de todas las métricas
     */
    private array $metrics = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('📊 Generador de Métricas del Sistema');
        $this->line('=====================================');
        $this->line('');

        // Parsear fechas
        $this->parseDates();

        // Mostrar rango de fechas
        $this->displayDateRange();

        // Recopilar todas las métricas
        $this->info('Recopilando métricas...');
        $this->collectAllMetrics();

        // Generar salida según formato
        $format = $this->option('format');
        $output = $this->option('output');

        if ($format === 'json' || $format === 'both') {
            $this->outputJson($output);
        }

        if ($format === 'table' || $format === 'both') {
            $this->outputTable();
        }

        $this->info('');
        $this->info('✅ Reporte generado exitosamente');

        return self::SUCCESS;
    }

    /**
     * Parsear fechas de entrada
     */
    private function parseDates(): void
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if ($from) {
            try {
                $this->fromDate = Carbon::parse($from)->startOfDay();
            } catch (\Exception $e) {
                $this->error("Fecha de inicio inválida: {$from}");
                exit(1);
            }
        }

        if ($to) {
            try {
                $this->toDate = Carbon::parse($to)->endOfDay();
            } catch (\Exception $e) {
                $this->error("Fecha de fin inválida: {$to}");
                exit(1);
            }
        } else {
            $this->toDate = Carbon::now()->endOfDay();
        }
    }

    /**
     * Mostrar rango de fechas
     */
    private function displayDateRange(): void
    {
        $fromStr = $this->fromDate ? $this->fromDate->format('Y-m-d') : 'Inicio de registros';
        $toStr = $this->toDate->format('Y-m-d H:i:s');

        $this->line("📅 Período de análisis:");
        $this->line("   Desde: {$fromStr}");
        $this->line("   Hasta: {$toStr}");
        $this->line('');
    }

    /**
     * Aplicar filtro de fechas a una query
     */
    private function applyDateFilter($query, string $dateColumn = 'created_at')
    {
        if ($this->fromDate) {
            $query->where($dateColumn, '>=', $this->fromDate);
        }
        $query->where($dateColumn, '<=', $this->toDate);

        return $query;
    }

    /**
     * Recopilar todas las métricas
     */
    private function collectAllMetrics(): void
    {
        // Calcular días del período
        $daysInPeriod = $this->fromDate 
            ? $this->fromDate->diffInDays($this->toDate) + 1 
            : null;

        $this->metrics = [
            'metadata' => [
                'generated_at' => now()->toIso8601String(),
                'period' => [
                    'from' => $this->fromDate?->toIso8601String(),
                    'to' => $this->toDate->toIso8601String(),
                    'from_formatted' => $this->fromDate?->format('Y-m-d'),
                    'to_formatted' => $this->toDate->format('Y-m-d'),
                    'days' => $daysInPeriod,
                    'description' => $this->fromDate 
                        ? "Del {$this->fromDate->format('d/m/Y')} al {$this->toDate->format('d/m/Y')}"
                        : "Desde el inicio hasta el {$this->toDate->format('d/m/Y')}",
                ],
            ],
            'executive_summary' => $this->getExecutiveSummary(),
            'requests' => $this->getRequestMetrics(),
            'certificates' => $this->getCertificateMetrics(),
            'sst_deliveries' => $this->getSstDeliveryMetrics(),
            'wellness_deliveries' => $this->getWellnessDeliveryMetrics(),
            'chatbot' => $this->getChatbotMetrics(),
            'agreements' => $this->getAgreementMetrics(),
            'surveys' => $this->getSurveyMetrics(),
            'additional' => $this->getAdditionalMetrics(),
        ];
    }

    /**
     * Obtener resumen ejecutivo
     */
    private function getExecutiveSummary(): array
    {
        $requestsQuery = $this->applyDateFilter(RequestForm::query());
        $totalRequests = $requestsQuery->count();
        $completedRequests = $requestsQuery->clone()->where('status', RequestStatuses::COMPLETED)->count();
        $pendingRequests = RequestForm::where('status', RequestStatuses::PENDING)->count();

        $certificatesQuery = $this->applyDateFilter(CertificadoConvenioRecord::query(), 'generated_at');
        $totalCertificates = $certificatesQuery->count();

        $sstDeliveriesQuery = $this->applyDateFilter(SstDeliveryRecord::query(), 'delivered_at');
        $totalSstDeliveries = $sstDeliveriesQuery->count();

        $wellnessDeliveriesQuery = $this->applyDateFilter(WellnessDeliveryRequest::query());
        $totalWellnessDeliveries = $wellnessDeliveriesQuery->count();

        $chatbotQuery = $this->applyDateFilter(ChatbotConversation::query());
        $totalChatbotConversations = $chatbotQuery->count();

        $uniqueAffiliates = $requestsQuery->clone()->distinct('document_number')->count('document_number');

        return [
            'total_requests' => $totalRequests,
            'completed_requests' => $completedRequests,
            'pending_requests' => $pendingRequests,
            'total_certificates' => $totalCertificates,
            'total_sst_deliveries' => $totalSstDeliveries,
            'total_wellness_deliveries' => $totalWellnessDeliveries,
            'total_chatbot_conversations' => $totalChatbotConversations,
            'unique_affiliates' => $uniqueAffiliates,
        ];
    }

    /**
     * Obtener métricas de solicitudes
     */
    private function getRequestMetrics(): array
    {
        $query = $this->applyDateFilter(RequestForm::query());

        // Totales
        $total = $query->count();
        $byStatusRaw = $query->clone()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $byTypeRaw = $query->clone()
            ->select('request_type', DB::raw('count(*) as count'))
            ->groupBy('request_type')
            ->pluck('count', 'request_type')
            ->toArray();

        // Contar completadas y rechazadas para porcentajes
        $completedCount = $byStatusRaw[RequestStatuses::COMPLETED] ?? 0;
        $rejectedCount = $byStatusRaw[RequestStatuses::REJECTED] ?? 0;

        // Agregar porcentajes y nombres traducidos para estados
        $byStatusWithDetails = [];
        foreach ($byStatusRaw as $status => $count) {
            $requestForm = new RequestForm(['status' => $status]);
            $byStatusWithDetails[] = [
                'status' => $status,
                'status_label' => $requestForm->translated_status,
                'count' => (int) $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0,
            ];
        }

        // Agregar porcentajes y nombres traducidos para tipos
        $byTypeWithDetails = [];
        foreach ($byTypeRaw as $type => $count) {
            $requestForm = new RequestForm(['request_type' => $type]);
            $byTypeWithDetails[] = [
                'type' => $type,
                'type_label' => $requestForm->translated_request_type,
                'count' => (int) $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0,
            ];
        }

        // Tiempos promedio de procesamiento con valores numéricos
        $avgProcessingTimeByType = [];
        foreach (RequestTypes::all() as $type) {
            $typeQuery = $query->clone()
                ->where('request_type', $type)
                ->whereNotNull('processed_at');

            $avgSeconds = $typeQuery
                ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) as avg_seconds')
                ->value('avg_seconds');

            if ($avgSeconds) {
                $requestForm = new RequestForm(['request_type' => $type]);
                $avgProcessingTimeByType[] = [
                    'type' => $type,
                    'type_label' => $requestForm->translated_request_type,
                    'seconds' => (float) $avgSeconds,
                    'minutes' => round($avgSeconds / 60, 2),
                    'hours' => round($avgSeconds / 3600, 2),
                    'days' => round($avgSeconds / 86400, 2),
                    'formatted' => $this->formatDuration($avgSeconds),
                ];
            }
        }

        // Tiempo promedio para completadas
        $completedQuery = $query->clone()
            ->where('status', RequestStatuses::COMPLETED)
            ->whereNotNull('processed_at');

        $avgCompletedSeconds = $completedQuery
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) as avg_seconds')
            ->value('avg_seconds');

        $avgCompletedTime = null;
        if ($avgCompletedSeconds) {
            $avgCompletedTime = [
                'seconds' => (float) $avgCompletedSeconds,
                'minutes' => round($avgCompletedSeconds / 60, 2),
                'hours' => round($avgCompletedSeconds / 3600, 2),
                'days' => round($avgCompletedSeconds / 86400, 2),
                'formatted' => $this->formatDuration($avgCompletedSeconds),
            ];
        }

        // Tiempo promedio para rechazadas
        $rejectedQuery = $query->clone()
            ->where('status', RequestStatuses::REJECTED)
            ->whereNotNull('processed_at');

        $avgRejectedSeconds = $rejectedQuery
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) as avg_seconds')
            ->value('avg_seconds');

        $avgRejectedTime = null;
        if ($avgRejectedSeconds) {
            $avgRejectedTime = [
                'seconds' => (float) $avgRejectedSeconds,
                'minutes' => round($avgRejectedSeconds / 60, 2),
                'hours' => round($avgRejectedSeconds / 3600, 2),
                'days' => round($avgRejectedSeconds / 86400, 2),
                'formatted' => $this->formatDuration($avgRejectedSeconds),
            ];
        }

        // Pendientes actuales (sin filtro de fecha)
        $pendingCount = RequestForm::where('status', RequestStatuses::PENDING)->count();

        // Afiliados únicos
        $uniqueAffiliates = $query->clone()
            ->distinct('document_number')
            ->count('document_number');

        // Top 10 afiliados con más solicitudes - normalizar nombres duplicados
        $topAffiliatesRaw = $query->clone()
            ->select('document_number', DB::raw('count(*) as request_count'))
            ->groupBy('document_number')
            ->orderBy('request_count', 'desc')
            ->limit(10)
            ->get();

        $topAffiliates = $topAffiliatesRaw->map(function ($item) use ($total) {
            // Obtener el nombre más reciente para este documento
            $latest = RequestForm::where('document_number', $item->document_number)
                ->orderBy('created_at', 'desc')
                ->first();
            
            return [
                'document_number' => $item->document_number,
                'name' => $latest ? trim($latest->name . ' ' . $latest->last_name) : 'N/A',
                'count' => (int) $item->request_count,
                'percentage' => $total > 0 ? round(($item->request_count / $total) * 100, 2) : 0,
            ];
        })->toArray();

        // Distribución por mes
        $byMonth = $query->clone()
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, count(*) as count')
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month')
            ->toArray();

        // Distribución por día (últimos 30 días si hay datos)
        $byDay = [];
        if ($this->fromDate && $this->fromDate->diffInDays($this->toDate) <= 30) {
            $byDay = $query->clone()
                ->selectRaw('DATE(created_at) as day, count(*) as count')
                ->groupBy('day')
                ->orderBy('day')
                ->pluck('count', 'day')
                ->toArray();
        }

        return [
            'total' => (int) $total,
            'by_status' => $byStatusWithDetails,
            'by_type' => $byTypeWithDetails,
            'avg_processing_time_by_type' => $avgProcessingTimeByType,
            'avg_completed_time' => $avgCompletedTime,
            'avg_rejected_time' => $avgRejectedTime,
            'pending_count' => (int) $pendingCount,
            'unique_affiliates' => (int) $uniqueAffiliates,
            'top_affiliates' => $topAffiliates,
            'by_month' => array_map('intval', $byMonth),
            'by_day' => array_map('intval', $byDay),
            'completion_rate' => $total > 0 ? round(($completedCount / $total) * 100, 2) : 0,
            'rejection_rate' => $total > 0 ? round(($rejectedCount / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Obtener métricas de certificados
     */
    private function getCertificateMetrics(): array
    {
        $query = $this->applyDateFilter(CertificadoConvenioRecord::query(), 'generated_at');

        $total = $query->count();

        // Certificados automáticos vs manuales
        // Automáticos: RequestForm de tipo certificado-convenio que son "simples"
        $automaticQuery = $this->applyDateFilter(RequestForm::query())
            ->where('request_type', RequestTypes::CERTIFICADO_CONVENIO)
            ->where('status', RequestStatuses::COMPLETED);

        $automaticCount = 0;
        $manualCount = 0;

        $certificateRequests = $automaticQuery->get();
        foreach ($certificateRequests as $request) {
            if ($request->esCertificadoConvenioSimple()) {
                $automaticCount++;
            } else {
                $manualCount++;
            }
        }

        // Certificados con/sin compensaciones
        $withCompensations = $query->clone()
            ->where('tiene_compensaciones', true)
            ->count();

        $withoutCompensations = $query->clone()
            ->where('tiene_compensaciones', false)
            ->count();

        // Por tipo
        $byType = $query->clone()
            ->select('tipo_certificado', DB::raw('count(*) as count'))
            ->groupBy('tipo_certificado')
            ->pluck('count', 'tipo_certificado')
            ->toArray();

        // Dirigidos a entidad
        $directedToEntity = $query->clone()
            ->where('dirigido_a_entidad', true)
            ->count();

        $notDirectedToEntity = $query->clone()
            ->where('dirigido_a_entidad', false)
            ->count();

        // Promedio por día/mes
        $avgPerDay = $total > 0 && $this->fromDate ? 
            round($total / max(1, $this->fromDate->diffInDays($this->toDate)), 2) : 0;

        $byMonth = $query->clone()
            ->selectRaw('DATE_FORMAT(generated_at, "%Y-%m") as month, count(*) as count')
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month')
            ->toArray();

        return [
            'total' => $total,
            'automatic' => $automaticCount,
            'manual' => $manualCount,
            'with_compensations' => $withCompensations,
            'without_compensations' => $withoutCompensations,
            'by_type' => $byType,
            'directed_to_entity' => $directedToEntity,
            'not_directed_to_entity' => $notDirectedToEntity,
            'avg_per_day' => $avgPerDay,
            'by_month' => $byMonth,
        ];
    }

    /**
     * Obtener métricas de entregas SST
     */
    private function getSstDeliveryMetrics(): array
    {
        $deliveryQuery = $this->applyDateFilter(SstDeliveryRecord::query(), 'delivered_at');
        $returnQuery = $this->applyDateFilter(SstReturnRecord::query(), 'returned_at');

        $totalDeliveries = $deliveryQuery->count();
        $totalReturns = $returnQuery->count();

        // Por tipo de entrega
        $byDeliveryType = $deliveryQuery->clone()
            ->select('delivery_type', DB::raw('count(*) as count'))
            ->groupBy('delivery_type')
            ->pluck('count', 'delivery_type')
            ->toArray();

        // Promedio de items por entrega
        $avgItemsPerDelivery = SstDeliveryItem::whereIn('delivery_id', $deliveryQuery->clone()->pluck('id'))
            ->selectRaw('AVG(quantity) as avg_quantity')
            ->value('avg_quantity') ?? 0;

        // Promedio de items por devolución
        $avgItemsPerReturn = SstReturnItem::whereIn('return_id', $returnQuery->clone()->pluck('id'))
            ->selectRaw('AVG(quantity) as avg_quantity')
            ->value('avg_quantity') ?? 0;

        // Afiliados únicos
        $uniqueAffiliates = $deliveryQuery->clone()
            ->distinct('affiliate_document_number')
            ->count('affiliate_document_number');

        // Top 10 items más entregados
        $topDeliveredItems = SstDeliveryItem::whereIn('delivery_id', $deliveryQuery->clone()->pluck('id'))
            ->select('item_name', 'item_category', DB::raw('SUM(quantity) as total_quantity'))
            ->groupBy('item_name', 'item_category')
            ->orderBy('total_quantity', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->item_name,
                    'category' => $item->item_category,
                    'quantity' => (int) $item->total_quantity,
                ];
            })
            ->toArray();

        // Top 10 items más devueltos
        $topReturnedItems = SstReturnItem::whereIn('return_id', $returnQuery->clone()->pluck('id'))
            ->select('item_name', 'item_category', DB::raw('SUM(quantity) as total_quantity'))
            ->groupBy('item_name', 'item_category')
            ->orderBy('total_quantity', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->item_name,
                    'category' => $item->item_category,
                    'quantity' => (int) $item->total_quantity,
                ];
            })
            ->toArray();

        // Distribución por hospital
        $byHospital = $deliveryQuery->clone()
            ->select('affiliate_hospital', DB::raw('count(*) as count'))
            ->whereNotNull('affiliate_hospital')
            ->groupBy('affiliate_hospital')
            ->orderBy('count', 'desc')
            ->pluck('count', 'affiliate_hospital')
            ->toArray();

        // Tiempo promedio entre entregas para el mismo afiliado
        $avgTimeBetweenDeliveries = null;
        $affiliateDeliveries = $deliveryQuery->clone()
            ->select('affiliate_document_number', 'delivered_at')
            ->orderBy('affiliate_document_number')
            ->orderBy('delivered_at')
            ->get()
            ->groupBy('affiliate_document_number');

        $intervals = [];
        foreach ($affiliateDeliveries as $deliveries) {
            if ($deliveries->count() > 1) {
                $sorted = $deliveries->sortBy('delivered_at')->values();
                for ($i = 0; $i < $sorted->count() - 1; $i++) {
                    $interval = $sorted[$i]->delivered_at->diffInDays($sorted[$i + 1]->delivered_at);
                    $intervals[] = $interval;
                }
            }
        }

        if (!empty($intervals)) {
            $avgTimeBetweenDeliveries = round(array_sum($intervals) / count($intervals), 2);
        }

        return [
            'total_deliveries' => (int) $totalDeliveries,
            'total_returns' => (int) $totalReturns,
            'by_delivery_type' => array_map('intval', $byDeliveryType),
            'avg_items_per_delivery' => round($avgItemsPerDelivery, 2),
            'avg_items_per_return' => round($avgItemsPerReturn, 2),
            'unique_affiliates' => (int) $uniqueAffiliates,
            'top_delivered_items' => $topDeliveredItems,
            'top_returned_items' => $topReturnedItems,
            'by_hospital' => array_map('intval', $byHospital),
            'avg_time_between_deliveries_days' => $avgTimeBetweenDeliveries ? round($avgTimeBetweenDeliveries, 2) : null,
            'return_rate' => $totalDeliveries > 0 ? round(($totalReturns / $totalDeliveries) * 100, 2) : 0,
        ];
    }

    /**
     * Obtener métricas de entregas de bienestar
     */
    private function getWellnessDeliveryMetrics(): array
    {
        $query = $this->applyDateFilter(WellnessDeliveryRequest::query());

        $total = $query->count();

        // Por tipo de entrega
        $byType = $query->clone()
            ->select('tipo_entrega', DB::raw('count(*) as count'))
            ->groupBy('tipo_entrega')
            ->pluck('count', 'tipo_entrega')
            ->toArray();

        // Por estado
        $byStatus = $query->clone()
            ->select('estado', DB::raw('count(*) as count'))
            ->groupBy('estado')
            ->pluck('count', 'estado')
            ->toArray();

        // Promedio de beneficiarios por entrega
        $deliveries = $query->clone()->get();
        $totalBeneficiaries = 0;
        $deliveriesWithBeneficiaries = 0;

        foreach ($deliveries as $delivery) {
            if (!empty($delivery->beneficiarios) && is_array($delivery->beneficiarios)) {
                $count = count($delivery->beneficiarios);
                $totalBeneficiaries += $count;
                $deliveriesWithBeneficiaries++;
            }
        }

        $avgBeneficiaries = $deliveriesWithBeneficiaries > 0 
            ? round($totalBeneficiaries / $deliveriesWithBeneficiaries, 2) 
            : 0;

        // Cantidad total entregada
        $totalQuantityDelivered = $query->clone()
            ->whereNotNull('cantidad_entregada')
            ->sum('cantidad_entregada') ?? 0;

        // Contar entregadas
        $deliveredCount = $byStatus['entregado'] ?? 0;

        // Afiliados únicos
        $uniqueAffiliates = $query->clone()
            ->distinct('documento_afiliado')
            ->count('documento_afiliado');

        return [
            'total' => (int) $total,
            'by_type' => array_map('intval', $byType),
            'by_status' => array_map('intval', $byStatus),
            'avg_beneficiaries_per_delivery' => round($avgBeneficiaries, 2),
            'total_beneficiaries' => (int) $totalBeneficiaries,
            'total_quantity_delivered' => (int) $totalQuantityDelivered,
            'unique_affiliates' => (int) $uniqueAffiliates,
            'delivery_rate' => $total > 0 ? round(($deliveredCount / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Obtener métricas del chatbot
     */
    private function getChatbotMetrics(): array
    {
        $query = $this->applyDateFilter(ChatbotConversation::query());

        $totalConversations = $query->count();
        $totalQuestions = $totalConversations; // Cada registro es una pregunta

        // Conversaciones con feedback
        $withPositiveFeedback = $query->clone()
            ->where('feedback', 'positive')
            ->count();

        $withNegativeFeedback = $query->clone()
            ->where('feedback', 'negative')
            ->count();

        $withoutFeedback = $query->clone()
            ->whereNull('feedback')
            ->count();

        // Conversaciones únicas
        $uniqueConversations = $query->clone()
            ->distinct('conversation_id')
            ->count('conversation_id');

        // Promedio de preguntas por conversación
        $avgQuestionsPerConversation = $uniqueConversations > 0 
            ? round($totalQuestions / $uniqueConversations, 2) 
            : 0;

        return [
            'total_conversations' => (int) $totalConversations,
            'total_questions' => (int) $totalQuestions,
            'with_positive_feedback' => (int) $withPositiveFeedback,
            'with_negative_feedback' => (int) $withNegativeFeedback,
            'without_feedback' => (int) $withoutFeedback,
            'unique_conversations' => (int) $uniqueConversations,
            'avg_questions_per_conversation' => round($avgQuestionsPerConversation, 2),
            'feedback_rate' => $totalConversations > 0 
                ? round((($withPositiveFeedback + $withNegativeFeedback) / $totalConversations) * 100, 2) 
                : 0,
            'positive_feedback_rate' => ($withPositiveFeedback + $withNegativeFeedback) > 0
                ? round(($withPositiveFeedback / ($withPositiveFeedback + $withNegativeFeedback)) * 100, 2)
                : 0,
        ];
    }

    /**
     * Obtener métricas de convenios (ConvenioEmailTracking)
     */
    private function getAgreementMetrics(): array
    {
        $query = $this->applyDateFilter(ConvenioEmailTracking::query());

        $total = $query->count();

        // Por estado
        $byStatusRaw = $query->clone()
            ->select('estado', DB::raw('count(*) as count'))
            ->groupBy('estado')
            ->pluck('count', 'estado')
            ->toArray();

        // Agregar detalles con traducciones
        $byStatus = [];
        $statusLabels = [
            'pendiente' => 'Pendiente',
            'enviado' => 'Enviado',
            'fallido' => 'Fallido',
        ];
        foreach ($byStatusRaw as $estado => $count) {
            $byStatus[] = [
                'status' => $estado,
                'status_label' => $statusLabels[$estado] ?? ucfirst($estado),
                'count' => (int) $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0,
            ];
        }

        $enviados = $byStatusRaw['enviado'] ?? 0;
        $fallidos = $byStatusRaw['fallido'] ?? 0;
        $pendientes = $byStatusRaw['pendiente'] ?? 0;

        // Tiempo promedio de envío (desde created_at hasta enviado_at)
        $avgSendingTime = null;
        $sentAgreements = $query->clone()
            ->where('estado', 'enviado')
            ->whereNotNull('enviado_at')
            ->get();

        if ($sentAgreements->count() > 0) {
            $totalSeconds = 0;
            $count = 0;
            foreach ($sentAgreements as $agreement) {
                if ($agreement->created_at && $agreement->enviado_at) {
                    $seconds = $agreement->created_at->diffInSeconds($agreement->enviado_at);
                    $totalSeconds += $seconds;
                    $count++;
                }
            }
            if ($count > 0) {
                $avgSeconds = $totalSeconds / $count;
                $avgSendingTime = [
                    'seconds' => (float) $avgSeconds,
                    'minutes' => round($avgSeconds / 60, 2),
                    'hours' => round($avgSeconds / 3600, 2),
                    'days' => round($avgSeconds / 86400, 2),
                    'formatted' => $this->formatDuration($avgSeconds),
                ];
            }
        }

        // Afiliados únicos
        $uniqueAffiliates = $query->clone()
            ->distinct('documento')
            ->count('documento');

        // Por nombre de convenio
        $byConvenio = $query->clone()
            ->select('nombre_convenio', DB::raw('count(*) as count'))
            ->whereNotNull('nombre_convenio')
            ->groupBy('nombre_convenio')
            ->orderBy('count', 'desc')
            ->pluck('count', 'nombre_convenio')
            ->toArray();

        // Promedio de intentos
        $avgAttempts = $query->clone()
            ->whereNotNull('intentos')
            ->avg('intentos') ?? 0;

        return [
            'total' => (int) $total,
            'by_status' => $byStatus,
            'sent' => (int) $enviados,
            'failed' => (int) $fallidos,
            'pending' => (int) $pendientes,
            'avg_sending_time' => $avgSendingTime,
            'unique_affiliates' => (int) $uniqueAffiliates,
            'by_convenio' => array_map('intval', $byConvenio),
            'avg_attempts' => round($avgAttempts, 2),
            'success_rate' => $total > 0 ? round(($enviados / $total) * 100, 2) : 0,
            'failure_rate' => $total > 0 ? round(($fallidos / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Obtener métricas de encuestas
     */
    private function getSurveyMetrics(): array
    {
        $query = $this->applyDateFilter(SocioDemographicSurvey::query());

        $total = $query->count();

        // Por tipo
        $byType = $query->clone()
            ->select('survey_type', DB::raw('count(*) as count'))
            ->groupBy('survey_type')
            ->pluck('count', 'survey_type')
            ->toArray();

        // Por hospital
        $byHospital = $query->clone()
            ->select('hospital', DB::raw('count(*) as count'))
            ->whereNotNull('hospital')
            ->groupBy('hospital')
            ->orderBy('count', 'desc')
            ->pluck('count', 'hospital')
            ->toArray();

        // Tiempo promedio de completado (si hay updated_at)
        $avgCompletionTimeFormatted = null;
        $surveysWithUpdate = $query->clone()
            ->whereNotNull('updated_at')
            ->get();

        if ($surveysWithUpdate->count() > 0) {
            $totalSeconds = 0;
            $count = 0;
            foreach ($surveysWithUpdate as $survey) {
                if ($survey->created_at && $survey->updated_at) {
                    $seconds = $survey->created_at->diffInSeconds($survey->updated_at);
                    $totalSeconds += $seconds;
                    $count++;
                }
            }
            if ($count > 0) {
                $avgSeconds = $totalSeconds / $count;
                $avgCompletionTimeFormatted = [
                    'seconds' => (float) $avgSeconds,
                    'minutes' => round($avgSeconds / 60, 2),
                    'hours' => round($avgSeconds / 3600, 2),
                    'days' => round($avgSeconds / 86400, 2),
                    'formatted' => $this->formatDuration($avgSeconds),
                ];
            }
        }

        return [
            'total' => (int) $total,
            'by_type' => array_map('intval', $byType),
            'by_hospital' => array_map('intval', $byHospital),
            'avg_completion_time' => $avgCompletionTimeFormatted,
        ];
    }

    /**
     * Obtener métricas adicionales
     */
    private function getAdditionalMetrics(): array
    {
        // Cambios de estado de solicitudes
        $statusLogQuery = $this->applyDateFilter(RequestStatusLog::query());
        $totalStatusChanges = $statusLogQuery->count();

        $statusChangesByType = $statusLogQuery->clone()
            ->select('new_status', DB::raw('count(*) as count'))
            ->groupBy('new_status')
            ->pluck('count', 'new_status')
            ->toArray();

        // Usuarios más activos en cambios de estado
        $topUsersByStatusChanges = DB::table('request_status_logs')
            ->select('changed_by', DB::raw('count(*) as count'))
            ->whereNotNull('changed_by');
        
        if ($this->fromDate) {
            $topUsersByStatusChanges->where('created_at', '>=', $this->fromDate);
        }
        $topUsersByStatusChanges->where('created_at', '<=', $this->toDate);
        
        $topUsersByStatusChanges = $topUsersByStatusChanges
            ->groupBy('changed_by')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($log) {
                $user = \App\Models\User::find($log->changed_by);
                return [
                    'user_id' => $log->changed_by,
                    'user_name' => $user?->name ?? 'N/A',
                    'count' => $log->count,
                ];
            })
            ->toArray();

        // Solicitudes de bienestar (WellnessRequest)
        $wellnessRequestQuery = $this->applyDateFilter(WellnessRequest::query());
        $totalWellnessRequests = $wellnessRequestQuery->count();

        $wellnessRequestsByStatus = $wellnessRequestQuery->clone()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $wellnessRequestsByCostCenter = $wellnessRequestQuery->clone()
            ->select('cost_center', DB::raw('count(*) as count'))
            ->whereNotNull('cost_center')
            ->groupBy('cost_center')
            ->orderBy('count', 'desc')
            ->pluck('count', 'cost_center')
            ->toArray();

        return [
            'status_changes' => [
                'total' => (int) $totalStatusChanges,
                'by_type' => array_map('intval', $statusChangesByType),
                'top_users' => $topUsersByStatusChanges,
            ],
            'wellness_requests' => [
                'total' => (int) $totalWellnessRequests,
                'by_status' => array_map('intval', $wellnessRequestsByStatus),
                'by_cost_center' => array_map('intval', $wellnessRequestsByCostCenter),
            ],
        ];
    }

    /**
     * Formatear duración en segundos a formato legible
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds, 1) . ' segundos';
        } elseif ($seconds < 3600) {
            return round($seconds / 60, 1) . ' minutos';
        } elseif ($seconds < 86400) {
            return round($seconds / 3600, 1) . ' horas';
        } else {
            return round($seconds / 86400, 1) . ' días';
        }
    }

    /**
     * Formatear número con separadores de miles
     */
    private function formatNumber($number): string
    {
        return number_format($number, 0, ',', '.');
    }

    /**
     * Obtener nombre traducido del tipo de solicitud
     */
    private function getRequestTypeName(string $type): string
    {
        $requestForm = new RequestForm(['request_type' => $type]);
        return $requestForm->translated_request_type;
    }

    /**
     * Obtener nombre traducido del estado
     */
    private function getStatusName(string $status): string
    {
        $requestForm = new RequestForm(['status' => $status]);
        return $requestForm->translated_status;
    }

    /**
     * Generar salida en formato tabla
     */
    private function outputTable(): void
    {
        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('                    RESUMEN EJECUTIVO');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->displayExecutiveSummary();

        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('                      SOLICITUDES');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->displayRequestMetrics();

        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('                    CERTIFICADOS');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->displayCertificateMetrics();

        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('                 DOTACIÓN Y EPP (SST)');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->displaySstMetrics();

        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('                      BIENESTAR');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->displayWellnessMetrics();

        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('                      CHATBOT');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->displayChatbotMetrics();

        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('                      CONVENIOS');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->displayAgreementMetrics();

        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('                    ENCUESTAS');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->displaySurveyMetrics();

        $this->line('');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('                  MÉTRICAS ADICIONALES');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->displayAdditionalMetrics();
    }

    /**
     * Mostrar resumen ejecutivo
     */
    private function displayExecutiveSummary(): void
    {
        $summary = $this->metrics['executive_summary'];

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Total de Solicitudes', $this->formatNumber($summary['total_requests'])],
                ['Solicitudes Completadas', $this->formatNumber($summary['completed_requests'])],
                ['Solicitudes Pendientes', $this->formatNumber($summary['pending_requests'])],
                ['Total de Certificados', $this->formatNumber($summary['total_certificates'])],
                ['Entregas SST', $this->formatNumber($summary['total_sst_deliveries'])],
                ['Entregas de Bienestar', $this->formatNumber($summary['total_wellness_deliveries'])],
                ['Conversaciones Chatbot', $this->formatNumber($summary['total_chatbot_conversations'])],
                ['Afiliados Únicos', $this->formatNumber($summary['unique_affiliates'])],
            ]
        );
    }

    /**
     * Mostrar métricas de solicitudes
     */
    private function displayRequestMetrics(): void
    {
        $metrics = $this->metrics['requests'];

        $this->line('Total de Solicitudes: ' . $this->formatNumber($metrics['total']));
        $this->line('');

        if (!empty($metrics['by_status'])) {
            $this->info('Por Estado:');
            $statusTable = [];
            foreach ($metrics['by_status'] as $statusData) {
                $statusTable[] = [
                    $statusData['status_label'],
                    $this->formatNumber($statusData['count']),
                    $statusData['percentage'] . '%',
                ];
            }
            $this->table(['Estado', 'Cantidad', 'Porcentaje'], $statusTable);
            $this->line('');
        }

        if (!empty($metrics['by_type'])) {
            $this->info('Por Tipo:');
            $typeTable = [];
            foreach ($metrics['by_type'] as $typeData) {
                $typeTable[] = [
                    $typeData['type_label'],
                    $this->formatNumber($typeData['count']),
                    $typeData['percentage'] . '%',
                ];
            }
            $this->table(['Tipo', 'Cantidad', 'Porcentaje'], $typeTable);
            $this->line('');
        }

        if (!empty($metrics['avg_processing_time_by_type'])) {
            $this->info('Tiempo Promedio de Procesamiento por Tipo:');
            $timeTable = [];
            foreach ($metrics['avg_processing_time_by_type'] as $timeData) {
                $timeTable[] = [
                    $timeData['type_label'],
                    $timeData['formatted'],
                ];
            }
            $this->table(['Tipo', 'Tiempo Promedio'], $timeTable);
            $this->line('');
        }

        if ($metrics['avg_completed_time']) {
            $this->line('Tiempo Promedio (Completadas): ' . $metrics['avg_completed_time']['formatted']);
        }

        if ($metrics['avg_rejected_time']) {
            $this->line('Tiempo Promedio (Rechazadas): ' . $metrics['avg_rejected_time']['formatted']);
        }

        if (isset($metrics['completion_rate'])) {
            $this->line('Tasa de Completitud: ' . $metrics['completion_rate'] . '%');
        }

        if (isset($metrics['rejection_rate'])) {
            $this->line('Tasa de Rechazo: ' . $metrics['rejection_rate'] . '%');
        }

        $this->line('Solicitudes Pendientes Actualmente: ' . $this->formatNumber($metrics['pending_count']));
        $this->line('Afiliados Únicos: ' . $this->formatNumber($metrics['unique_affiliates']));
        $this->line('');

        if (!empty($metrics['top_affiliates'])) {
            $this->info('Top 10 Afiliados con Más Solicitudes:');
            $topTable = [];
            foreach ($metrics['top_affiliates'] as $affiliate) {
                $topTable[] = [
                    $affiliate['document_number'],
                    $affiliate['name'],
                    $this->formatNumber($affiliate['count']),
                ];
            }
            $this->table(['Documento', 'Nombre', 'Solicitudes'], $topTable);
        }
    }

    /**
     * Mostrar métricas de certificados
     */
    private function displayCertificateMetrics(): void
    {
        $metrics = $this->metrics['certificates'];

        $this->line('Total de Certificados: ' . $this->formatNumber($metrics['total']));
        $this->line('');

        $this->line('Certificados Automáticos: ' . $this->formatNumber($metrics['automatic']));
        $this->line('Certificados Manuales: ' . $this->formatNumber($metrics['manual']));
        $this->line('');

        $this->line('Con Compensaciones: ' . $this->formatNumber($metrics['with_compensations']));
        $this->line('Sin Compensaciones: ' . $this->formatNumber($metrics['without_compensations']));
        $this->line('');

        $this->line('Dirigidos a Entidad: ' . $this->formatNumber($metrics['directed_to_entity']));
        $this->line('No Dirigidos a Entidad: ' . $this->formatNumber($metrics['not_directed_to_entity']));
        $this->line('');

        if ($metrics['avg_per_day'] > 0) {
            $this->line('Promedio por Día: ' . round($metrics['avg_per_day'], 2));
        }
    }

    /**
     * Mostrar métricas SST
     */
    private function displaySstMetrics(): void
    {
        $metrics = $this->metrics['sst_deliveries'];

        $this->line('Total de Entregas: ' . $this->formatNumber($metrics['total_deliveries']));
        $this->line('Total de Devoluciones: ' . $this->formatNumber($metrics['total_returns']));
        $this->line('');

        if (!empty($metrics['by_delivery_type'])) {
            $this->info('Por Tipo de Entrega:');
            $typeTable = [];
            foreach ($metrics['by_delivery_type'] as $type => $count) {
                $typeName = $type === 'first_time' ? 'Primera Vez' : 'Periódica';
                $typeTable[] = [$typeName, $this->formatNumber($count)];
            }
            $this->table(['Tipo', 'Cantidad'], $typeTable);
            $this->line('');
        }

        $this->line('Promedio de Items por Entrega: ' . $metrics['avg_items_per_delivery']);
        $this->line('Promedio de Items por Devolución: ' . $metrics['avg_items_per_return']);
        $this->line('Afiliados Únicos: ' . $this->formatNumber($metrics['unique_affiliates']));
        $this->line('');

        if (!empty($metrics['top_delivered_items'])) {
            $this->info('Top 10 Items Más Entregados:');
            $itemsTable = [];
            foreach ($metrics['top_delivered_items'] as $item) {
                $itemsTable[] = [
                    $item['name'],
                    $item['category'],
                    $this->formatNumber($item['quantity']),
                ];
            }
            $this->table(['Item', 'Categoría', 'Cantidad'], $itemsTable);
            $this->line('');
        }

        if ($metrics['avg_time_between_deliveries_days']) {
            $this->line('Tiempo Promedio entre Entregas: ' . 
                round($metrics['avg_time_between_deliveries_days'], 1) . ' días');
        }

        if (isset($metrics['return_rate'])) {
            $this->line('Tasa de Devolución: ' . $metrics['return_rate'] . '%');
        }
    }

    /**
     * Mostrar métricas de bienestar
     */
    private function displayWellnessMetrics(): void
    {
        $metrics = $this->metrics['wellness_deliveries'];

        $this->line('Total de Entregas: ' . $this->formatNumber($metrics['total']));
        $this->line('');

        if (!empty($metrics['by_type'])) {
            $this->info('Por Tipo:');
            $typeTable = [];
            $typeNames = [
                'kit_escolar' => 'Kit Escolar',
                'desayuno' => 'Desayuno',
                'lonchera' => 'Lonchera',
                'otro' => 'Otro',
            ];
            foreach ($metrics['by_type'] as $type => $count) {
                $typeTable[] = [
                    $typeNames[$type] ?? $type,
                    $this->formatNumber($count),
                ];
            }
            $this->table(['Tipo', 'Cantidad'], $typeTable);
            $this->line('');
        }

        if (!empty($metrics['by_status'])) {
            $this->info('Por Estado:');
            $statusTable = [];
            foreach ($metrics['by_status'] as $status => $count) {
                $statusTable[] = [
                    ucfirst($status),
                    $this->formatNumber($count),
                ];
            }
            $this->table(['Estado', 'Cantidad'], $statusTable);
            $this->line('');
        }

        $this->line('Promedio de Beneficiarios por Entrega: ' . $metrics['avg_beneficiaries_per_delivery']);
        $this->line('Total de Beneficiarios: ' . $this->formatNumber($metrics['total_beneficiaries']));
        $this->line('Cantidad Total Entregada: ' . $this->formatNumber($metrics['total_quantity_delivered']));
        $this->line('Afiliados Únicos: ' . $this->formatNumber($metrics['unique_affiliates']));

        if (isset($metrics['delivery_rate'])) {
            $this->line('Tasa de Entrega: ' . $metrics['delivery_rate'] . '%');
        }
    }

    /**
     * Mostrar métricas del chatbot
     */
    private function displayChatbotMetrics(): void
    {
        $metrics = $this->metrics['chatbot'];

        $this->line('Total de Conversaciones: ' . $this->formatNumber($metrics['total_conversations']));
        $this->line('Total de Preguntas: ' . $this->formatNumber($metrics['total_questions']));
        $this->line('Conversaciones Únicas: ' . $this->formatNumber($metrics['unique_conversations']));
        $this->line('Promedio de Preguntas por Conversación: ' . $metrics['avg_questions_per_conversation']);
        $this->line('');

        $this->line('Feedback Positivo: ' . $this->formatNumber($metrics['with_positive_feedback']));
        $this->line('Feedback Negativo: ' . $this->formatNumber($metrics['with_negative_feedback']));
        $this->line('Sin Feedback: ' . $this->formatNumber($metrics['without_feedback']));
        $this->line('');

        if (isset($metrics['feedback_rate'])) {
            $this->line('Tasa de Feedback: ' . $metrics['feedback_rate'] . '%');
        }

        if (isset($metrics['positive_feedback_rate'])) {
            $this->line('Tasa de Feedback Positivo: ' . $metrics['positive_feedback_rate'] . '%');
        }
    }

    /**
     * Mostrar métricas de convenios
     */
    private function displayAgreementMetrics(): void
    {
        $metrics = $this->metrics['agreements'];

        $this->line('Total de Convenios: ' . $this->formatNumber($metrics['total']));
        $this->line('Enviados: ' . $this->formatNumber($metrics['sent']));
        $this->line('Fallidos: ' . $this->formatNumber($metrics['failed']));
        $this->line('Pendientes: ' . $this->formatNumber($metrics['pending']));
        $this->line('');

        if (!empty($metrics['by_status'])) {
            $this->info('Por Estado:');
            $statusTable = [];
            foreach ($metrics['by_status'] as $statusData) {
                $statusTable[] = [
                    $statusData['status_label'],
                    $this->formatNumber($statusData['count']),
                    $statusData['percentage'] . '%',
                ];
            }
            $this->table(['Estado', 'Cantidad', 'Porcentaje'], $statusTable);
            $this->line('');
        }

        if ($metrics['avg_sending_time']) {
            $this->line('Tiempo Promedio de Envío: ' . $metrics['avg_sending_time']['formatted']);
        }

        if (isset($metrics['success_rate'])) {
            $this->line('Tasa de Éxito: ' . $metrics['success_rate'] . '%');
        }

        if (isset($metrics['failure_rate'])) {
            $this->line('Tasa de Fallo: ' . $metrics['failure_rate'] . '%');
        }

        if (isset($metrics['avg_attempts'])) {
            $this->line('Promedio de Intentos: ' . $metrics['avg_attempts']);
        }

        if (!empty($metrics['by_convenio'])) {
            $this->info('Por Convenio (Top 10):');
            $convenioTable = [];
            $count = 0;
            foreach ($metrics['by_convenio'] as $convenio => $convenioCount) {
                if ($count++ >= 10) break;
                $convenioTable[] = [
                    $convenio,
                    $this->formatNumber($convenioCount),
                ];
            }
            $this->table(['Convenio', 'Cantidad'], $convenioTable);
        }

        $this->line('Afiliados Únicos: ' . $this->formatNumber($metrics['unique_affiliates']));
    }

    /**
     * Mostrar métricas de encuestas
     */
    private function displaySurveyMetrics(): void
    {
        $metrics = $this->metrics['surveys'];

        $this->line('Total de Encuestas: ' . $this->formatNumber($metrics['total']));
        $this->line('');

        if (!empty($metrics['by_type'])) {
            $this->info('Por Tipo:');
            $typeTable = [];
            foreach ($metrics['by_type'] as $type => $count) {
                $typeTable[] = [
                    ucfirst($type),
                    $this->formatNumber($count),
                ];
            }
            $this->table(['Tipo', 'Cantidad'], $typeTable);
            $this->line('');
        }

        if (!empty($metrics['by_hospital'])) {
            $this->info('Top Hospitales:');
            $hospitalTable = [];
            $count = 0;
            foreach ($metrics['by_hospital'] as $hospital => $surveyCount) {
                if ($count++ >= 10) break;
                $hospitalTable[] = [
                    $hospital,
                    $this->formatNumber($surveyCount),
                ];
            }
            $this->table(['Hospital', 'Encuestas'], $hospitalTable);
            $this->line('');
        }

        if ($metrics['avg_completion_time']) {
            $this->line('Tiempo Promedio de Completado: ' . $metrics['avg_completion_time']['formatted']);
        }
    }

    /**
     * Mostrar métricas adicionales
     */
    private function displayAdditionalMetrics(): void
    {
        $metrics = $this->metrics['additional'];

        $this->info('Cambios de Estado de Solicitudes:');
        $this->line('Total de Cambios: ' . $this->formatNumber($metrics['status_changes']['total']));
        $this->line('');

        if (!empty($metrics['status_changes']['by_type'])) {
            $this->info('Por Tipo de Estado:');
            $statusTable = [];
            foreach ($metrics['status_changes']['by_type'] as $status => $count) {
                $statusTable[] = [
                    $this->getStatusName($status),
                    $this->formatNumber($count),
                ];
            }
            $this->table(['Estado', 'Cambios'], $statusTable);
            $this->line('');
        }

        if (!empty($metrics['status_changes']['top_users'])) {
            $this->info('Top 10 Usuarios Más Activos:');
            $usersTable = [];
            foreach ($metrics['status_changes']['top_users'] as $user) {
                $usersTable[] = [
                    $user['user_name'],
                    $this->formatNumber($user['count']),
                ];
            }
            $this->table(['Usuario', 'Cambios'], $usersTable);
            $this->line('');
        }

        $this->info('Solicitudes de Bienestar (WellnessRequest):');
        $this->line('Total: ' . $this->formatNumber($metrics['wellness_requests']['total']));
        $this->line('');

        if (!empty($metrics['wellness_requests']['by_status'])) {
            $this->info('Por Estado:');
            $statusTable = [];
            foreach ($metrics['wellness_requests']['by_status'] as $status => $count) {
                $statusTable[] = [
                    ucfirst($status),
                    $this->formatNumber($count),
                ];
            }
            $this->table(['Estado', 'Cantidad'], $statusTable);
            $this->line('');
        }

        if (!empty($metrics['wellness_requests']['by_cost_center'])) {
            $this->info('Por Centro de Costo (Top 10):');
            $costCenterTable = [];
            $count = 0;
            foreach ($metrics['wellness_requests']['by_cost_center'] as $costCenter => $requestCount) {
                if ($count++ >= 10) break;
                $costCenterTable[] = [
                    $costCenter,
                    $this->formatNumber($requestCount),
                ];
            }
            $this->table(['Centro de Costo', 'Solicitudes'], $costCenterTable);
        }
    }

    /**
     * Generar salida en formato JSON
     */
    private function outputJson(?string $outputPath): void
    {
        $json = json_encode($this->metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($outputPath) {
            file_put_contents($outputPath, $json);
            $this->info("✅ Reporte JSON guardado en: {$outputPath}");
        } else {
            $this->line($json);
        }
    }
}

