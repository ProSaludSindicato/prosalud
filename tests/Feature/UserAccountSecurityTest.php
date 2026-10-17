<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use App\Services\PasswordResetService;
use App\Services\UserInvitationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Nuev4-Clave-Segura!2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['services.recaptcha.enabled' => false]);
        Mail::fake();
    }

    private function actingWithToken(User $user): static
    {
        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $this->withCredentials()->withUnencryptedCookie('prosalud_auth_token', $plainToken);
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function roleWithPermissions(string $name, string ...$permissions): Role
    {
        $role = Role::query()->create(['name' => $name, 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);

        return $role;
    }

    public function test_non_admin_cannot_assign_the_admin_role(): void
    {
        $actor = $this->userWithPermissions('users.edit');
        $target = User::factory()->create();

        $this->actingWithToken($actor)->putJson("/api/users/{$target->id}", ['role' => 'admin'])
            ->assertForbidden();

        $this->assertFalse($target->fresh()->hasRole('admin'));
    }

    public function test_non_admin_cannot_assign_a_role_with_more_permissions_than_their_own(): void
    {
        $this->roleWithPermissions('supervisor', 'requests.view', 'users.create');
        $actor = $this->userWithPermissions('users.edit', 'requests.view');
        $target = User::factory()->create();

        $this->actingWithToken($actor)->putJson("/api/users/{$target->id}", ['role' => 'supervisor'])
            ->assertForbidden();
    }

    public function test_non_admin_can_assign_a_role_whose_permissions_they_already_hold(): void
    {
        $this->roleWithPermissions('lector', 'requests.view');
        $actor = $this->userWithPermissions('users.edit', 'requests.view');
        $target = User::factory()->create();

        $this->actingWithToken($actor)->putJson("/api/users/{$target->id}", ['role' => 'lector'])
            ->assertOk();

        $this->assertTrue($target->fresh()->hasRole('lector'));
    }

    public function test_non_admin_cannot_edit_an_admin_account(): void
    {
        $actor = $this->userWithPermissions('users.edit');
        $admin = $this->adminUser();

        $this->actingWithToken($actor)->putJson("/api/users/{$admin->id}", [
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertForbidden();
    }

    public function test_user_cannot_change_their_own_role(): void
    {
        $this->roleWithPermissions('lector', 'requests.view');
        $actor = $this->userWithPermissions('users.edit', 'requests.view');

        $this->actingWithToken($actor)->putJson("/api/users/{$actor->id}", ['role' => 'lector'])
            ->assertForbidden();
    }

    public function test_user_can_resubmit_their_own_unchanged_access_fields(): void
    {
        $this->roleWithPermissions('lector', 'requests.view');
        $actor = $this->userWithPermissions('users.edit', 'requests.view');
        $actor->assignRole('lector');

        $this->actingWithToken($actor)->putJson("/api/users/{$actor->id}", [
            'name' => 'Nuevo Nombre',
            'role' => 'lector',
            'is_active' => true,
        ])->assertOk();

        $this->assertSame('Nuevo Nombre', $actor->fresh()->name);
    }

    public function test_changing_active_flag_through_update_requires_change_status_permission(): void
    {
        $actor = $this->userWithPermissions('users.edit');
        $target = User::factory()->create(['is_active' => true]);

        $this->actingWithToken($actor)->putJson("/api/users/{$target->id}", ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue((bool) $target->fresh()->is_active);

        $actor->givePermissionTo('users.change_status');
        \Illuminate\Support\Facades\Cache::forget("user:{$actor->id}:permissions");

        $this->actingWithToken($actor)->putJson("/api/users/{$target->id}", ['is_active' => false])
            ->assertOk();
    }

    public function test_user_cannot_deactivate_or_delete_themselves(): void
    {
        $actor = $this->userWithPermissions('users.change_status');

        $this->actingWithToken($actor)->patchJson("/api/users/{$actor->id}/status", ['is_active' => false])
            ->assertForbidden();
        $this->actingWithToken($actor)->deleteJson("/api/users/{$actor->id}")
            ->assertForbidden();

        $this->assertTrue((bool) $actor->fresh()->is_active);
    }

    public function test_non_admin_cannot_deactivate_or_delete_an_admin(): void
    {
        $actor = $this->userWithPermissions('users.change_status');
        $admin = $this->adminUser();

        $this->actingWithToken($actor)->patchJson("/api/users/{$admin->id}/status", ['is_active' => false])
            ->assertForbidden();
        $this->actingWithToken($actor)->deleteJson("/api/users/{$admin->id}")
            ->assertForbidden();

        $this->assertNotNull(User::query()->find($admin->id));
        $this->assertTrue((bool) $admin->fresh()->is_active);
    }

    public function test_admin_can_still_manage_other_users_and_assign_admin(): void
    {
        $admin = $this->adminUser();
        $target = User::factory()->create();

        $this->actingWithToken($admin)->putJson("/api/users/{$target->id}", ['role' => 'admin'])
            ->assertOk();

        $this->assertTrue($target->fresh()->hasRole('admin'));
    }

    public function test_non_admin_cannot_create_an_admin_user(): void
    {
        $actor = $this->userWithPermissions('users.create');

        $this->actingWithToken($actor)->postJson('/api/users', [
            'name' => 'Nuevo Admin',
            'email' => 'nuevo-admin@example.test',
            'role' => 'admin',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'nuevo-admin@example.test']);
    }

    public function test_non_admin_can_create_a_user_with_a_role_they_hold(): void
    {
        $this->roleWithPermissions('lector', 'requests.view');
        $actor = $this->userWithPermissions('users.create', 'requests.view');

        $this->actingWithToken($actor)->postJson('/api/users', [
            'name' => 'Nuevo Lector',
            'email' => 'nuevo-lector@example.test',
            'role' => 'lector',
        ])->assertCreated();
    }

    public function test_reset_token_can_only_be_used_once(): void
    {
        $user = User::factory()->create();
        $token = app(PasswordResetService::class)->generateToken($user);
        $payload = [
            'token' => $token,
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ];

        $this->postJson('/api/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/auth/reset-password', $payload)->assertStatus(422);
    }

    public function test_reset_token_is_rejected_by_set_password_and_vice_versa(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $payload = [
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ];

        $this->postJson('/api/auth/set-password', $payload + [
            'token' => app(PasswordResetService::class)->generateToken($user),
        ])->assertStatus(422);
        $this->assertFalse((bool) $user->fresh()->is_active);

        $active = User::factory()->create();
        $this->postJson('/api/auth/reset-password', $payload + [
            'token' => app(UserInvitationService::class)->generateToken($active),
        ])->assertStatus(422);
    }

    public function test_legacy_tokens_without_purpose_are_rejected(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $legacy = Crypt::encryptString($user->id.'|'.now()->addHour()->getTimestamp());

        $this->postJson('/api/auth/set-password', [
            'token' => $legacy,
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertStatus(422);

        $this->assertFalse((bool) $user->fresh()->is_active);
    }

    public function test_invitation_activates_the_user_once_and_cannot_reactivate_a_deactivated_account(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $payload = [
            'token' => app(UserInvitationService::class)->generateToken($user),
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ];

        $this->postJson('/api/auth/set-password', $payload)->assertOk();
        $this->assertTrue((bool) $user->fresh()->is_active);

        User::query()->whereKey($user->id)->update(['is_active' => false]);

        $this->postJson('/api/auth/set-password', $payload)->assertStatus(422);
        $this->assertFalse((bool) $user->fresh()->is_active);
    }

    public function test_set_password_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/set-password', ['token' => 'invalid'])->assertStatus(422);
        }

        $this->postJson('/api/auth/set-password', ['token' => 'invalid'])->assertStatus(429);
    }
}
