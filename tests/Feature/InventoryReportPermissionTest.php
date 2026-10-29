<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryReportPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    private function actingAsApiUser(User $user): void
    {
        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $this->withCredentials()->withUnencryptedCookie('prosalud_auth_token', $plainToken);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_guest_cannot_generate_inventory_report(): void
    {
        $this->postJson('/api/inventory/reports/excel', ['reportType' => 'strategic'])
            ->assertUnauthorized();
    }

    public function test_user_without_inventory_permission_is_rejected_by_route_middleware(): void
    {
        $this->actingAsApiUser($this->userWith('requests.view'));

        $this->postJson('/api/inventory/reports/excel', ['reportType' => 'strategic'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Acceso denegado. Permisos insuficientes.');
    }

    public function test_user_missing_some_report_permissions_is_rejected_by_controller(): void
    {
        $this->actingAsApiUser($this->userWith('inventory.view_dashboard'));

        $this->postJson('/api/inventory/reports/excel', ['reportType' => 'strategic'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Acceso denegado. Permisos insuficientes para generar reportes.');
    }

    public function test_user_with_all_report_permissions_generates_the_report(): void
    {
        $this->actingAsApiUser($this->userWith(
            'inventory.view_dashboard',
            'inventory.products.view',
            'inventory.categories.view',
        ));

        $this->postJson('/api/inventory/reports/excel', ['reportType' => 'strategic'])
            ->assertOk();
    }
}
