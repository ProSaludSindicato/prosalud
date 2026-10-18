<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT = 'Clave-Actual!2026x';

    private const NEW = 'Nuev4-Clave-Segura!2026';

    private function userWithSessions(): array
    {
        $user = User::factory()->create(['password' => self::CURRENT]);

        $makeToken = function (string $name) use ($user): string {
            $plain = 'test-plain-'.Str::random(48);
            ApiToken::query()->create([
                'user_id' => $user->id,
                'name' => $name,
                'token' => hash('sha256', $plain),
                'expires_at' => now()->addDay(),
            ]);

            return $plain;
        };

        return [$user, $makeToken('current'), $makeToken('other-device')];
    }

    public function test_user_changes_password_and_other_sessions_are_revoked(): void
    {
        [$user, $currentToken] = $this->userWithSessions();

        $this->withCredentials()->withUnencryptedCookie('prosalud_auth_token', $currentToken)
            ->postJson('/api/auth/change-password', [
                'current_password' => self::CURRENT,
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW, $user->fresh()->password));
        $this->assertSame(1, $user->apiTokens()->count());
        $this->assertDatabaseHas('api_tokens', ['token' => hash('sha256', $currentToken)]);
    }

    public function test_wrong_current_password_is_rejected_and_nothing_changes(): void
    {
        [$user, $currentToken] = $this->userWithSessions();

        $this->withCredentials()->withUnencryptedCookie('prosalud_auth_token', $currentToken)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'incorrecta',
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ])->assertStatus(422);

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
        $this->assertSame(2, $user->apiTokens()->count());
    }

    public function test_weak_or_unchanged_new_password_is_rejected(): void
    {
        [$user, $currentToken] = $this->userWithSessions();
        $client = $this->withCredentials()->withUnencryptedCookie('prosalud_auth_token', $currentToken);

        $client->postJson('/api/auth/change-password', [
            'current_password' => self::CURRENT,
            'password' => 'corta',
            'password_confirmation' => 'corta',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $client->postJson('/api/auth/change-password', [
            'current_password' => self::CURRENT,
            'password' => self::CURRENT,
            'password_confirmation' => self::CURRENT,
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_guest_cannot_change_a_password(): void
    {
        $this->postJson('/api/auth/change-password', [
            'current_password' => self::CURRENT,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ])->assertStatus(401);
    }

    public function test_user_seeder_does_not_create_the_default_admin_in_production(): void
    {
        Role::findOrCreate('admin', 'web');
        $this->app['env'] = 'production';

        $seeder = new UserSeeder;
        $seeder->setContainer($this->app);
        $seeder->setCommand(\Mockery::mock(\Illuminate\Console\Command::class)->shouldIgnoreMissing());
        $seeder->run();

        $this->assertDatabaseMissing('users', ['email' => 'admin@prosalud.com']);
    }
}
