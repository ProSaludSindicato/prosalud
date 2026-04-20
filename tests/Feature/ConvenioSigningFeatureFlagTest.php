<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioSigningFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    private function apiTokenForUser(User $user): string
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

    private function userWithPermission(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        return [$user, $this->apiTokenForUser($user)];
    }

    // ─── Statistics ─────────────────────────────────────────────────────────────

    public function test_statistics_includes_signing_data_when_flag_is_enabled(): void
    {
        config(['convenio_signing.enabled' => true]);

        [, $plainToken] = $this->userWithPermission();

        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'sede' => 'BELLO',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);

        $response = $this->call('GET', '/api/convenios-manual/statistics', [], [
            'prosalud_auth_token' => $plainToken,
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.digital_signing_enabled', true)
            ->assertJsonStructure(['data' => ['signing', 'signing_derived']]);

        $this->assertNotNull($response->json('data.signing'));
        $this->assertNotNull($response->json('data.signing_derived'));

        $bySede = $response->json('data.by_sede');
        $this->assertIsArray($bySede);
        $bello = collect($bySede)->firstWhere('sede', 'BELLO');
        $this->assertArrayHasKey('pendiente_firma', $bello);
        $this->assertArrayHasKey('firmado_afiliado', $bello);
        $this->assertArrayHasKey('completado', $bello);
    }

    public function test_statistics_omits_signing_data_when_flag_is_disabled(): void
    {
        config(['convenio_signing.enabled' => false]);

        [, $plainToken] = $this->userWithPermission();

        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'sede' => 'BELLO',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);

        $response = $this->call('GET', '/api/convenios-manual/statistics', [], [
            'prosalud_auth_token' => $plainToken,
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.digital_signing_enabled', false)
            ->assertJsonPath('data.signing', null)
            ->assertJsonPath('data.signing_derived', null);

        $bySede = $response->json('data.by_sede');
        $this->assertIsArray($bySede);
        $bello = collect($bySede)->firstWhere('sede', 'BELLO');
        $this->assertNotNull($bello);
        $this->assertArrayNotHasKey('pendiente_firma', $bello);
        $this->assertArrayNotHasKey('firmado_afiliado', $bello);
        $this->assertArrayNotHasKey('completado', $bello);
    }

    // ─── History ────────────────────────────────────────────────────────────────

    public function test_history_returns_digital_signing_enabled_flag(): void
    {
        config(['convenio_signing.enabled' => true]);

        [, $plainToken] = $this->userWithPermission();

        $response = $this->call('GET', '/api/convenios-manual/email-history', [], [
            'prosalud_auth_token' => $plainToken,
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk()->assertJsonPath('digital_signing_enabled', true);
    }

    public function test_history_returns_signing_estado_when_flag_is_enabled(): void
    {
        config(['convenio_signing.enabled' => true]);

        [, $plainToken] = $this->userWithPermission();

        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);

        $response = $this->call('GET', '/api/convenios-manual/email-history', [], [
            'prosalud_auth_token' => $plainToken,
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk()->assertJsonPath('digital_signing_enabled', true);

        $first = collect($response->json('data.data'))->first();
        $this->assertSame(ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA, $first['signing_estado']);
    }

    public function test_history_nullifies_signing_estado_when_flag_is_disabled(): void
    {
        config(['convenio_signing.enabled' => false]);

        [, $plainToken] = $this->userWithPermission();

        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);

        $response = $this->call('GET', '/api/convenios-manual/email-history', [], [
            'prosalud_auth_token' => $plainToken,
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk()->assertJsonPath('digital_signing_enabled', false);

        $first = collect($response->json('data.data'))->first();
        $this->assertNull($first['signing_estado']);
    }

    public function test_history_ignores_signing_estado_filter_when_flag_is_disabled(): void
    {
        config(['convenio_signing.enabled' => false]);

        [, $plainToken] = $this->userWithPermission();

        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => null,
        ]);

        // Requesting firma_pendiente_firma filter should return all records (filter ignored)
        $response = $this->call('GET', '/api/convenios-manual/email-history', [
            'estado_filtro' => 'firma_pendiente_firma',
        ], [
            'prosalud_auth_token' => $plainToken,
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk();
        $this->assertSame(2, $response->json('data.total'));
    }

    // ─── Public signing routes ───────────────────────────────────────────────────

    public function test_public_signing_routes_return_404_when_flag_is_disabled(): void
    {
        config(['convenio_signing.enabled' => false]);

        $token = str_repeat('z', 64);

        $this->getJson('/api/public/convenio-firma/'.$token.'/metadata')
            ->assertStatus(404);

        $this->get('/api/public/convenio-firma/'.$token.'/document.pdf')
            ->assertStatus(404);

        $this->postJson('/api/public/convenio-firma/'.$token.'/submit-affiliate-signature')
            ->assertStatus(404);
    }

    public function test_public_signing_routes_are_accessible_when_flag_is_enabled(): void
    {
        config(['convenio_signing.enabled' => true]);

        $token = str_repeat('z', 64);

        // Route exists but token is unknown – should return 404 from the controller, not route-missing 404
        $this->getJson('/api/public/convenio-firma/'.$token.'/metadata')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    // ─── Download final ──────────────────────────────────────────────────────────

    public function test_download_final_returns_404_when_flag_is_disabled(): void
    {
        config(['convenio_signing.enabled' => false]);

        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');
        $plainToken = $this->apiTokenForUser($user);

        $tracking = ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
        ]);

        $this->call('GET', '/api/convenios-manual/tracking/'.$tracking->id.'/download-final', [], [
            'prosalud_auth_token' => $plainToken,
        ], [], ['HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
