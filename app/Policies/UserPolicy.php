<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

class UserPolicy
{
    private const ADMIN_ROLE = 'admin';

    /**
     * Whether the actor may modify the target account (profile, status or deletion).
     * Only admins may touch other admins, and non-admins may only touch accounts whose
     * permissions they already hold (otherwise changing a password or email would take over a
     * more privileged account).
     */
    public function manage(User $actor, User $target): bool
    {
        if ($actor->hasRole(self::ADMIN_ROLE)) {
            return true;
        }

        if ($target->hasRole(self::ADMIN_ROLE)) {
            return false;
        }

        return $actor->is($target) || $this->holdsAllPermissionsOf($actor, $target);
    }

    private function holdsAllPermissionsOf(User $actor, User $target): bool
    {
        return $target->getAllPermissions()->pluck('name')
            ->diff($actor->getAllPermissions()->pluck('name'))
            ->isEmpty();
    }

    /**
     * Whether the actor may give a role to an existing user. Non-admins can only grant roles
     * whose permissions they already hold, and never the admin role.
     */
    public function assignRole(User $actor, User $target, string $roleName): bool
    {
        return $this->canGrantRole($actor, $roleName);
    }

    /**
     * Whether the actor may create an account with the given role (same rules as assignRole).
     */
    public function createWithRole(User $actor, string $roleName): bool
    {
        return $this->canGrantRole($actor, $roleName);
    }

    private function canGrantRole(User $actor, string $roleName): bool
    {
        if ($actor->hasRole(self::ADMIN_ROLE)) {
            return true;
        }

        if ($roleName === self::ADMIN_ROLE) {
            return false;
        }

        $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

        if ($role === null) {
            return false;
        }

        $actorPermissions = $actor->getAllPermissions()->pluck('name');

        return $role->permissions->pluck('name')->diff($actorPermissions)->isEmpty();
    }

    /**
     * Whether the actor may change the target's role or active flag. Nobody can change
     * their own role or deactivate themselves through the user management endpoints.
     */
    public function changeAccess(User $actor, User $target): bool
    {
        return ! $actor->is($target);
    }

    /**
     * Whether the actor may delete the target account.
     */
    public function delete(User $actor, User $target): bool
    {
        return ! $actor->is($target) && $this->manage($actor, $target);
    }
}
