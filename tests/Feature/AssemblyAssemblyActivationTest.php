<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Assembly;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssemblyAssemblyActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    private function apiCookieForUser(User $user): string
    {
        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }

    public function test_deactivate_sets_cannot_reactivate_and_returns_json(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('assembly.questions.manage');

        $assembly = Assembly::query()->create([
            'name' => 'Activa',
            'is_active' => true,
            'allows_reactivation' => true,
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post("/api/assembly/assemblies/{$assembly->id}/deactivate");

        $response->assertOk()->assertJsonPath('success', true);
        $assembly->refresh();
        $this->assertFalse($assembly->is_active);
        $this->assertFalse($assembly->allows_reactivation);
    }

    public function test_activate_after_deactivate_returns_422(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('assembly.questions.manage');

        $assembly = Assembly::query()->create([
            'name' => 'Fue desactivada',
            'is_active' => false,
            'allows_reactivation' => false,
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post("/api/assembly/assemblies/{$assembly->id}/activate");

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_activate_inactive_never_deactivated_succeeds(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('assembly.questions.manage');

        $assembly = Assembly::query()->create([
            'name' => 'Borrador',
            'is_active' => false,
            'allows_reactivation' => true,
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post("/api/assembly/assemblies/{$assembly->id}/activate");

        $response->assertOk()->assertJsonPath('success', true);
        $assembly->refresh();
        $this->assertTrue($assembly->is_active);
    }

    public function test_activate_when_already_active_returns_success_without_duplicate_side_effects(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('assembly.questions.manage');

        $assembly = Assembly::query()->create([
            'name' => 'Ya activa',
            'is_active' => true,
            'allows_reactivation' => true,
        ]);

        $response = $this->withUnencryptedCookie('prosalud_auth_token', $this->apiCookieForUser($user))
            ->post("/api/assembly/assemblies/{$assembly->id}/activate");

        $response->assertOk()->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'La asamblea ya está activa');
    }
}
