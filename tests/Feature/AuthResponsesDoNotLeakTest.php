<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthResponsesDoNotLeakTest extends TestCase
{
    use RefreshDatabase;

    public function test_forbidden_response_does_not_reveal_the_required_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $plain = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->withCredentials()->withUnencryptedCookie('prosalud_auth_token', $plain)
            ->getJson('/api/users')
            ->assertForbidden();

        $this->assertArrayNotHasKey('required_permission', $response->json());
        $this->assertArrayNotHasKey('required_permissions', $response->json());
    }

    public function test_unauthorized_response_does_not_reveal_why_the_token_failed(): void
    {
        $response = $this->getJson('/api/users')->assertUnauthorized();

        $this->assertArrayNotHasKey('reason', $response->json());
    }
}
