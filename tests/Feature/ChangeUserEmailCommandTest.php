<?php

namespace Tests\Feature;

use App\Models\RequestTypeAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChangeUserEmailCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_user_email_by_current_email(): void
    {
        $user = User::factory()->create(['email' => 'viejo@empresa.com']);

        $this->artisan('users:change-email', [
            'user' => 'viejo@empresa.com',
            'new-email' => 'nuevo@gmail.com',
            '--force' => true,
        ])->assertSuccessful();

        $user->refresh();

        $this->assertSame('nuevo@gmail.com', $user->email);
    }

    public function test_changes_user_email_by_id(): void
    {
        $user = User::factory()->create(['email' => 'viejo@empresa.com']);

        $this->artisan('users:change-email', [
            'user' => (string) $user->id,
            'new-email' => 'nuevo@gmail.com',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame('nuevo@gmail.com', $user->fresh()->email);
    }

    public function test_preserves_user_id_and_relationships(): void
    {
        $user = User::factory()->create(['email' => 'viejo@empresa.com']);

        RequestTypeAssignment::query()->create([
            'user_id' => $user->id,
            'request_type' => 'certificado',
        ]);

        $this->artisan('users:change-email', [
            'user' => (string) $user->id,
            'new-email' => 'nuevo@gmail.com',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame($user->id, $user->fresh()->id);
        $this->assertSame(1, RequestTypeAssignment::query()->where('user_id', $user->id)->count());
    }

    public function test_updates_password_reset_token_email(): void
    {
        $user = User::factory()->create(['email' => 'viejo@empresa.com']);

        DB::table('password_reset_tokens')->insert([
            'email' => 'viejo@empresa.com',
            'token' => 'test-token',
            'created_at' => now(),
        ]);

        $this->artisan('users:change-email', [
            'user' => (string) $user->id,
            'new-email' => 'nuevo@gmail.com',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'nuevo@gmail.com',
            'token' => 'test-token',
        ]);
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'viejo@empresa.com',
        ]);
    }

    public function test_fails_when_user_not_found(): void
    {
        $this->artisan('users:change-email', [
            'user' => 'noexiste@empresa.com',
            'new-email' => 'nuevo@gmail.com',
            '--force' => true,
        ])->assertFailed();
    }

    public function test_fails_when_new_email_is_already_taken(): void
    {
        User::factory()->create(['email' => 'otro@gmail.com']);
        $user = User::factory()->create(['email' => 'viejo@empresa.com']);

        $this->artisan('users:change-email', [
            'user' => (string) $user->id,
            'new-email' => 'otro@gmail.com',
            '--force' => true,
        ])->assertFailed();

        $this->assertSame('viejo@empresa.com', $user->fresh()->email);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $user = User::factory()->create(['email' => 'viejo@empresa.com']);

        $this->artisan('users:change-email', [
            'user' => (string) $user->id,
            'new-email' => 'nuevo@gmail.com',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame('viejo@empresa.com', $user->fresh()->email);
    }

    public function test_same_email_is_no_op(): void
    {
        $user = User::factory()->create(['email' => 'mismo@gmail.com']);

        $this->artisan('users:change-email', [
            'user' => (string) $user->id,
            'new-email' => 'mismo@gmail.com',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame('mismo@gmail.com', $user->fresh()->email);
    }
}
