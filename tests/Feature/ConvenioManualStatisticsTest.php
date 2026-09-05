<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioManualStatisticsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_statistics_includes_signing_derived_and_by_sede(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'sede' => 'BELLO',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'sede' => 'BELLO',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
        ]);
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'sede' => 'COPACABANA',
            'signing_estado' => ConvenioEmailTracking::SIGNING_COMPLETADO,
        ]);
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'sede' => '   ',
            'signing_estado' => ConvenioEmailTracking::SIGNING_RECHAZADO,
        ]);

        $plainToken = $this->apiCookieForUser($user);

        $response = $this->call('GET', '/api/convenios-manual/statistics', [], [
            'prosalud_auth_token' => $plainToken,
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.signing_derived.pendientes_firma', 1)
            ->assertJsonPath('data.signing_derived.firmados_afiliado_o_finalizados', 2);

        $bySede = $response->json('data.by_sede');
        $this->assertIsArray($bySede);
        $bello = collect($bySede)->firstWhere('sede', 'BELLO');
        $this->assertNotNull($bello);
        $this->assertSame(2, $bello['total']);
        $this->assertSame(1, $bello['pendiente_firma']);
        $this->assertSame(1, $bello['firmado_afiliado']);

        $sinSede = collect($bySede)->firstWhere('sede', 'Sin sede');
        $this->assertNotNull($sinSede);
        $this->assertSame(1, $sinSede['rechazado']);

        $response->assertJsonPath('data.satisfaction.ratings_count', 0)
            ->assertJsonPath('data.satisfaction.eligible_count', 2)
            ->assertJsonPath('data.satisfaction.average', null);
        $this->assertSame(0, $bello['satisfaction_count']);
        $this->assertNull($bello['satisfaction_average']);
    }

    public function test_statistics_exclude_test_records(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'is_test' => false,
        ]);
        ConvenioEmailTracking::factory()->test()->create([
            'estado' => 'enviado',
        ]);

        $response = $this->call('GET', '/api/convenios-manual/statistics', [], [
            'prosalud_auth_token' => $this->apiCookieForUser($user),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.sent', 1);
    }

    public function test_statistics_filters_by_semester_period(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 4)->setTime(12, 0));

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'created_at' => '2026-08-15 10:00:00',
        ]);
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'created_at' => '2026-02-10 10:00:00',
        ]);

        $response = $this->call('GET', '/api/convenios-manual/statistics', [
            'periodo' => '20262',
        ], [
            'prosalud_auth_token' => $this->apiCookieForUser($user),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('filter_options.current', '20262');
        $this->assertContains('20261', $response->json('filter_options.periodos'));
    }
}
