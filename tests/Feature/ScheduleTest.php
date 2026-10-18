<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_commands_are_scheduled_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (['model:prune', 'queue:prune-failed', 'queue:prune-batches'] as $command) {
            $event = $events->first(fn ($e) => str_contains($e->command, $command));

            $this->assertNotNull($event, "{$command} is not scheduled");
            $this->assertTrue($event->withoutOverlapping, "{$command} may overlap");
            $this->assertTrue($event->onOneServer, "{$command} may run on several servers");
        }
    }

    public function test_wellness_reminders_run_daily_at_eight_bogota_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'wellness:send-activity-reminders'));

        $this->assertNotNull($event);
        $this->assertSame('0 8 * * *', $event->expression);
        $this->assertSame('America/Bogota', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_only_tokens_expired_for_over_a_week_are_pruned(): void
    {
        $user = User::factory()->create();
        $make = fn (string $name, $expiresAt) => ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token' => hash('sha256', $name),
            'expires_at' => $expiresAt,
        ]);

        $make('old', now()->subWeeks(2));
        $make('recently-expired', now()->subDay());
        $make('active', now()->addDay());

        $this->artisan('model:prune', ['--model' => [ApiToken::class]])->assertSuccessful();

        $this->assertSame(['active', 'recently-expired'], ApiToken::query()->orderBy('name')->pluck('name')->all());
    }
}
