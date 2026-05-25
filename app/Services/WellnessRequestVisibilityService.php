<?php

namespace App\Services;

use App\Enums\WellnessHospitalScope;
use App\Models\User;
use App\Models\WellnessHospitalAssignment;
use App\Models\WellnessRequest;
use Illuminate\Database\Eloquent\Builder;

class WellnessRequestVisibilityService
{
    public function canViewAllHospitals(User $user): bool
    {
        if ($user->can('wellness_requests.update_status')) {
            return true;
        }

        return $this->hasAllHospitalsScope($user);
    }

    public function canEditAllRequests(User $user): bool
    {
        return $user->can('wellness_requests.update_status');
    }

    /**
     * @return list<WellnessHospitalScope>
     */
    public function assignedHospitalScopes(User $user): array
    {
        return WellnessHospitalAssignment::query()
            ->where('user_id', $user->id)
            ->pluck('hospital_scope')
            ->map(fn (WellnessHospitalScope|string $scope) => $scope instanceof WellnessHospitalScope
                ? $scope
                : WellnessHospitalScope::from($scope))
            ->values()
            ->all();
    }

    public function hasHospitalAssignments(User $user): bool
    {
        return WellnessHospitalAssignment::query()
            ->where('user_id', $user->id)
            ->exists();
    }

    public function applyListScope(Builder $query, User $user): Builder
    {
        if ($this->canViewAllHospitals($user)) {
            return $query;
        }

        $scopes = $this->assignedHospitalScopes($user);

        if ($scopes === []) {
            return $query->where('requester_id', $user->id);
        }

        return $query->where(function (Builder $visibilityQuery) use ($user, $scopes) {
            $visibilityQuery->where('requester_id', $user->id);

            $visibilityQuery->orWhere(function (Builder $hospitalQuery) use ($scopes) {
                foreach ($scopes as $scope) {
                    $hospitalQuery->orWhere(function (Builder $scopeQuery) use ($scope) {
                        $this->applyCostCenterScope($scopeQuery, $scope);
                    });
                }
            });
        });
    }

    public function canViewRequest(User $user, WellnessRequest $wellnessRequest): bool
    {
        if ($this->canViewAllHospitals($user)) {
            return true;
        }

        if ($wellnessRequest->requester_id === $user->id) {
            return true;
        }

        return $this->requestMatchesAssignedHospitals($wellnessRequest, $this->assignedHospitalScopes($user));
    }

    public function canEditRequest(User $user, WellnessRequest $wellnessRequest): bool
    {
        if ($this->canEditAllRequests($user)) {
            return true;
        }

        return $wellnessRequest->requester_id === $user->id;
    }

    public function isOwnRequest(User $user, WellnessRequest $wellnessRequest): bool
    {
        return $wellnessRequest->requester_id === $user->id;
    }

    private function hasAllHospitalsScope(User $user): bool
    {
        return in_array(WellnessHospitalScope::Todos, $this->assignedHospitalScopes($user), true);
    }

    /**
     * @param  list<WellnessHospitalScope>  $scopes
     */
    private function requestMatchesAssignedHospitals(WellnessRequest $wellnessRequest, array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($scope->matchesCostCenter($wellnessRequest->cost_center)) {
                return true;
            }
        }

        return false;
    }

    private function applyCostCenterScope(Builder $query, WellnessHospitalScope $scope): Builder
    {
        return match ($scope) {
            WellnessHospitalScope::Bello => $query->where('cost_center', 'Bello'),
            WellnessHospitalScope::Rionegro => $query->where('cost_center', 'Rionegro'),
            WellnessHospitalScope::LaMaria => $query->where('cost_center', 'like', 'La Maria%'),
            WellnessHospitalScope::Carisma => $query->where(function (Builder $carismaQuery) {
                $carismaQuery->where('cost_center', 'Carisma')
                    ->orWhere('cost_center', 'like', 'Carisma%');
            }),
            WellnessHospitalScope::Todos => $query,
        };
    }
}
