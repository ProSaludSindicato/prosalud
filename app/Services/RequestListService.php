<?php

namespace App\Services;

use App\Constants\RequestStatuses;
use App\Constants\RequestTypes;
use App\Models\RequestForm;
use App\Models\RequestSubtypeAssignment;
use App\Models\RequestTypeAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RequestListService
{
    /** @var list<string> */
    private const VALIDATION_REQUIRED_TYPES = [
        RequestTypes::COMPENSACION_DESCANSO,
        RequestTypes::COMPENSACION_ANUAL,
        RequestTypes::VERIFICACION_PAGOS,
        'descanso-laboral',
    ];

    public function buildFilteredQuery(User $user, array $filters = []): Builder
    {
        $query = RequestForm::query();
        $this->applyAssignmentFilters($query, $user);
        $this->applyListFilters($query, $filters);
        $this->applySorting($query, $filters);

        return $query;
    }

    public function applyListEagerLoads(Builder $query): Builder
    {
        return $query
            ->withCount('responses')
            ->with([
                'validator:id,email',
                'statusLogs' => fn ($statusLogQuery) => $statusLogQuery
                    ->orderByDesc('created_at')
                    ->limit(1)
                    ->with('user:id,name,email'),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatListItem(RequestForm $request): array
    {
        $lastStatusLog = $request->relationLoaded('statusLogs')
            ? $request->statusLogs->first()
            : null;

        $lastStatusChange = null;
        if ($lastStatusLog) {
            $lastStatusChange = [
                'old_status' => $lastStatusLog->old_status,
                'new_status' => $lastStatusLog->new_status,
                'reason' => $lastStatusLog->reason,
                'changed_by' => $lastStatusLog->changed_by,
                'changed_by_name' => $lastStatusLog->user?->name,
                'changed_by_email' => $lastStatusLog->user?->email,
                'changed_at' => $lastStatusLog->created_at?->toIso8601String(),
                'changed_at_formatted' => $lastStatusLog->created_at
                    ? $lastStatusLog->created_at->format('d/m/Y H:i:s')
                    : null,
            ];
        }

        return [
            'id' => $request->id,
            'request_type' => $request->request_type,
            'document_type' => $request->document_type,
            'document_number' => $request->document_number,
            'name' => $request->name,
            'last_name' => $request->last_name,
            'full_name' => $request->full_name,
            'request_subtype' => $request->request_subtype,
            'has_bank_info_update' => $request->hasBankInfoUpdate(),
            'email' => $request->getRawOriginal('email') ?? $request->getAttribute('email'),
            'phone_number' => $request->getRawOriginal('phone_number') ?? $request->getAttribute('phone_number'),
            'status' => $request->status,
            'rejection_reason' => $request->rejection_reason,
            'payload' => $request->extractListPayload(),
            'created_at' => $request->created_at?->toIso8601String(),
            'formatted_created_at' => $request->formatted_created_at,
            'processed_at' => $request->processed_at?->toIso8601String(),
            'formatted_processed_at' => $request->formatted_processed_at,
            'validated_at' => $request->validated_at?->toIso8601String(),
            'validated_by' => $request->validator?->email,
            'last_status_change' => $lastStatusChange,
            'responses_count' => (int) ($request->responses_count ?? 0),
            'files_count' => is_array($request->files) ? count($request->files) : 0,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    public function getStats(User $user): array
    {
        $baseQuery = $this->buildFilteredQuery($user, [])->reorder();

        $counts = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $now = now();
        $thisMonth = (clone $baseQuery)
            ->whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->count();

        $unvalidated = (clone $baseQuery)
            ->whereIn('status', [RequestStatuses::PENDING, RequestStatuses::IN_REVIEW])
            ->whereIn('request_type', self::VALIDATION_REQUIRED_TYPES)
            ->whereNull('validated_at')
            ->count();

        $avgResolutionHours = (clone $baseQuery)
            ->where('status', RequestStatuses::COMPLETED)
            ->whereNotNull('processed_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, processed_at)) as avg_hours')
            ->value('avg_hours');

        $monthlyCounts = $this->getMonthlyCounts($user, 6);

        return [
            'total' => (int) (clone $baseQuery)->count(),
            'pending' => (int) ($counts[RequestStatuses::PENDING] ?? 0),
            'in_progress' => (int) ($counts[RequestStatuses::IN_REVIEW] ?? 0),
            'resolved' => (int) ($counts[RequestStatuses::COMPLETED] ?? 0),
            'rejected' => (int) ($counts[RequestStatuses::REJECTED] ?? 0),
            'this_month' => (int) $thisMonth,
            'unvalidated' => (int) $unvalidated,
            'avg_resolution_time' => (int) round((float) ($avgResolutionHours ?? 0)),
            'monthly_counts' => $monthlyCounts,
        ];
    }

    /**
     * @return array{request_types: list<string>, subtypes: list<array{label: string, value: string}>}
     */
    public function getFilterOptions(User $user): array
    {
        $requests = $this->buildFilteredQuery($user, [])->reorder()
            ->select(['request_type', 'payload'])
            ->get();

        $requestTypes = $requests
            ->pluck('request_type')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $subtypes = $requests
            ->filter(fn (RequestForm $request) => $request->request_type === RequestTypes::VERIFICACION_PAGOS)
            ->map(fn (RequestForm $request) => $request->request_subtype)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $subtype) => [
                'label' => $subtype,
                'value' => $subtype,
            ])
            ->all();

        return [
            'request_types' => $requestTypes,
            'subtypes' => $subtypes,
        ];
    }

    /**
     * @return list<array{year: int, month: int, count: int}>
     */
    private function getMonthlyCounts(User $user, int $monthsBack): array
    {
        $startDate = now()->startOfMonth()->subMonths($monthsBack - 1);
        $rows = $this->buildFilteredQuery($user, [])->reorder()
            ->where('created_at', '>=', $startDate)
            ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, COUNT(*) as count')
            ->groupByRaw('YEAR(created_at), MONTH(created_at)')
            ->orderByRaw('YEAR(created_at), MONTH(created_at)')
            ->get();

        $countsByKey = $rows->keyBy(fn ($row) => "{$row->year}-{$row->month}");

        $result = [];
        for ($i = $monthsBack - 1; $i >= 0; $i--) {
            $date = now()->startOfMonth()->subMonths($i);
            $key = "{$date->year}-{$date->month}";
            $result[] = [
                'year' => $date->year,
                'month' => $date->month,
                'count' => (int) ($countsByKey[$key]->count ?? 0),
            ];
        }

        return $result;
    }

    private function applyAssignmentFilters(Builder $query, User $user): void
    {
        if ($user->hasRole('admin')) {
            return;
        }

        $userId = $user->id;
        $assignedTypes = RequestTypeAssignment::where('user_id', $userId)
            ->pluck('request_type')
            ->toArray();

        $assignedSubtypes = RequestSubtypeAssignment::where('user_id', $userId)
            ->get()
            ->groupBy('request_type')
            ->map(fn (Collection $assignments) => $assignments->pluck('subtype')->toArray())
            ->toArray();

        if (empty($assignedTypes) && empty($assignedSubtypes)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($assignmentQuery) use ($assignedTypes, $assignedSubtypes) {
            $typesWithoutSubtypes = array_filter($assignedTypes, fn ($type) => ! RequestTypes::hasSubtypes($type));

            if (! empty($typesWithoutSubtypes)) {
                $typesToSearch = [];
                foreach ($typesWithoutSubtypes as $type) {
                    $typesToSearch[] = $type;
                    if ($type === RequestTypes::INCAPACIDADES_LICENCIAS) {
                        $typesToSearch[] = 'incapacidad-licencia';
                        $typesToSearch[] = 'incapacidad-laboral';
                    }
                    if ($type === RequestTypes::SOLICITUD_RETIRO_SINDICAL) {
                        $typesToSearch[] = 'retiro-sindical';
                    }
                }
                $assignmentQuery->whereIn('request_type', array_unique($typesToSearch));
            }

            $typesWithSubtypes = array_filter($assignedTypes, fn ($type) => RequestTypes::hasSubtypes($type));
            foreach ($typesWithSubtypes as $type) {
                $assignmentQuery->orWhere(fn ($typeQuery) => $typeQuery->where('request_type', $type));
            }

            foreach ($assignedSubtypes as $type => $subtypes) {
                if (! in_array($type, $assignedTypes, true)) {
                    $assignmentQuery->orWhere(function ($typeQuery) use ($type, $subtypes) {
                        $typeQuery->where('request_type', $type);
                        $typeQuery->where(function ($subtypeQuery) use ($subtypes) {
                            foreach ($subtypes as $subtype) {
                                $this->applySubtypeMatch($subtypeQuery, $subtype);
                            }
                        });
                    });
                }
            }
        });
    }

    private function applyListFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('id', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('document_number', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $this->normalizeStatusFilter((string) $filters['status']));
        }

        if (! empty($filters['request_type']) && $filters['request_type'] !== 'all') {
            $query->where('request_type', RequestTypes::normalize((string) $filters['request_type']));
        }

        if (! empty($filters['request_subtype']) && $filters['request_subtype'] !== 'all') {
            $normalizedSubtype = $this->normalizeSubtypeValue((string) $filters['request_subtype']);
            $query->where(function ($subtypeQuery) use ($normalizedSubtype) {
                $subtypeQuery->whereRaw(
                    "REPLACE(REPLACE(UPPER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.solicitudRelacionadaCon')))), ' Y/O DESCANSO', ''), 'Y/O DESCANSO', '') LIKE ? OR ? LIKE CONCAT('%', REPLACE(REPLACE(UPPER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.solicitudRelacionadaCon')))), ' Y/O DESCANSO', ''), 'Y/O DESCANSO', ''), '%')",
                    [$normalizedSubtype.'%', $normalizedSubtype]
                );
            });
        }
    }

    private function applySorting(Builder $query, array $filters): void
    {
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'name') {
            $query->orderBy('name', $sortOrder)->orderBy('last_name', $sortOrder);

            return;
        }

        $query->orderBy('created_at', $sortOrder);
    }

    private function applySubtypeMatch(Builder $query, string $subtype): void
    {
        $normalizedSubtype = $this->normalizeSubtypeValue($subtype);
        $query->orWhere(function ($subtypeCondition) use ($normalizedSubtype) {
            $subtypeCondition->whereRaw(
                "REPLACE(REPLACE(UPPER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.solicitudRelacionadaCon')))), ' Y/O DESCANSO', ''), 'Y/O DESCANSO', '') LIKE ? OR ? LIKE CONCAT('%', REPLACE(REPLACE(UPPER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.solicitudRelacionadaCon')))), ' Y/O DESCANSO', ''), 'Y/O DESCANSO', ''), '%')",
                [$normalizedSubtype.'%', $normalizedSubtype]
            );
        });
    }

    private function normalizeStatusFilter(string $status): string
    {
        return match (strtolower($status)) {
            'pending' => RequestStatuses::PENDING,
            'in_progress', 'in-review' => RequestStatuses::IN_REVIEW,
            'resolved', 'completed' => RequestStatuses::COMPLETED,
            'rejected' => RequestStatuses::REJECTED,
            default => strtoupper($status),
        };
    }

    private function normalizeSubtypeValue(string $subtype): string
    {
        $normalized = mb_strtoupper(trim($subtype));
        $normalized = preg_replace('/\s*Y\/O\s*DESCANSO\s*/i', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }
}
