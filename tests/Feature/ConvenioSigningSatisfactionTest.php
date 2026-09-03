<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioSigningSatisfactionTest extends TestCase
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

    private function signedTracking(string $plainToken, array $overrides = []): ConvenioEmailTracking
    {
        return ConvenioEmailTracking::factory()->create(array_merge([
            'estado' => 'enviado',
            'signing_token_hash' => hash('sha256', $plainToken),
            'token_expires_at' => now()->addDay(),
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'firmado_afiliado_at' => now(),
        ], $overrides));
    }

    public function test_affiliate_can_rate_after_signing(): void
    {
        $plainToken = str_repeat('s', 64);
        $tracking = $this->signedTracking($plainToken);

        $this->postJson('/api/public/convenio-firma/'.$plainToken.'/satisfaction-rating', [
            'score' => 5,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.satisfaction_score', 5);

        $tracking->refresh();
        $this->assertSame(5, $tracking->signing_satisfaction_score);
        $this->assertNotNull($tracking->signing_satisfaction_rated_at);
    }

    public function test_completed_signing_can_still_be_rated(): void
    {
        $plainToken = str_repeat('c', 64);
        $this->signedTracking($plainToken, [
            'signing_estado' => ConvenioEmailTracking::SIGNING_COMPLETADO,
            'firmado_presidente_at' => now(),
        ]);

        $this->postJson('/api/public/convenio-firma/'.$plainToken.'/satisfaction-rating', [
            'score' => 4,
        ])->assertOk()
            ->assertJsonPath('data.satisfaction_score', 4);
    }

    public function test_cannot_rate_before_signing(): void
    {
        $plainToken = str_repeat('p', 64);
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_token_hash' => hash('sha256', $plainToken),
            'token_expires_at' => now()->addDay(),
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);

        $this->postJson('/api/public/convenio-firma/'.$plainToken.'/satisfaction-rating', [
            'score' => 5,
        ])->assertStatus(403)
            ->assertJsonPath('code', 'not_eligible');
    }

    public function test_cannot_rate_rejected_convenio(): void
    {
        $plainToken = str_repeat('r', 64);
        $this->signedTracking($plainToken, [
            'signing_estado' => ConvenioEmailTracking::SIGNING_RECHAZADO,
            'rechazado_at' => now(),
        ]);

        $this->postJson('/api/public/convenio-firma/'.$plainToken.'/satisfaction-rating', [
            'score' => 1,
        ])->assertStatus(403)
            ->assertJsonPath('code', 'not_eligible');
    }

    public function test_cannot_change_rating_once_submitted(): void
    {
        $plainToken = str_repeat('d', 64);
        $this->signedTracking($plainToken);

        $this->postJson('/api/public/convenio-firma/'.$plainToken.'/satisfaction-rating', [
            'score' => 3,
        ])->assertOk();

        $this->postJson('/api/public/convenio-firma/'.$plainToken.'/satisfaction-rating', [
            'score' => 5,
        ])->assertStatus(409)
            ->assertJsonPath('code', 'already_rated');

        $this->assertSame(3, ConvenioEmailTracking::query()->first()->signing_satisfaction_score);
    }

    public function test_rejects_score_outside_range(): void
    {
        $plainToken = str_repeat('v', 64);
        $this->signedTracking($plainToken);

        $this->postJson('/api/public/convenio-firma/'.$plainToken.'/satisfaction-rating', [
            'score' => 6,
        ])->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->postJson('/api/public/convenio-firma/'.$plainToken.'/satisfaction-rating', [])
            ->assertStatus(422);
    }

    public function test_unknown_token_returns_404(): void
    {
        $this->postJson('/api/public/convenio-firma/'.str_repeat('z', 64).'/satisfaction-rating', [
            'score' => 4,
        ])->assertStatus(404);
    }

    public function test_metadata_exposes_rating_availability(): void
    {
        $plainToken = str_repeat('m', 64);
        $this->signedTracking($plainToken);

        $this->getJson('/api/public/convenio-firma/'.$plainToken.'/metadata')
            ->assertOk()
            ->assertJsonPath('data.can_rate_satisfaction', true)
            ->assertJsonPath('data.satisfaction_score', null);

        $this->postJson('/api/public/convenio-firma/'.$plainToken.'/satisfaction-rating', [
            'score' => 2,
        ])->assertOk();

        $this->getJson('/api/public/convenio-firma/'.$plainToken.'/metadata')
            ->assertOk()
            ->assertJsonPath('data.can_rate_satisfaction', false)
            ->assertJsonPath('data.satisfaction_score', 2);
    }

    public function test_statistics_include_satisfaction_metrics(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'sede' => 'BELLO',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'signing_satisfaction_score' => 5,
            'signing_satisfaction_rated_at' => now(),
        ]);
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'sede' => 'BELLO',
            'signing_estado' => ConvenioEmailTracking::SIGNING_COMPLETADO,
            'signing_satisfaction_score' => 3,
            'signing_satisfaction_rated_at' => now(),
        ]);
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'sede' => 'COPACABANA',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
        ]);
        ConvenioEmailTracking::factory()->test()->create([
            'estado' => 'enviado',
            'sede' => 'BELLO',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'signing_satisfaction_score' => 1,
            'signing_satisfaction_rated_at' => now(),
        ]);

        $response = $this->call('GET', '/api/convenios-manual/statistics', [], [
            'prosalud_auth_token' => $this->apiCookieForUser($user),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.satisfaction.ratings_count', 2)
            ->assertJsonPath('data.satisfaction.eligible_count', 3)
            ->assertJsonPath('data.satisfaction.response_rate', 66.7)
            ->assertJsonPath('data.satisfaction.average', 4)
            ->assertJsonPath('data.satisfaction.distribution.5', 1)
            ->assertJsonPath('data.satisfaction.distribution.3', 1)
            ->assertJsonPath('data.satisfaction.distribution.1', 0);

        $bello = collect($response->json('data.by_sede'))->firstWhere('sede', 'BELLO');
        $this->assertNotNull($bello);
        $this->assertSame(2, $bello['satisfaction_count']);
        $this->assertEquals(4, $bello['satisfaction_average']);
    }

    public function test_tracking_detail_includes_satisfaction_score(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $tracking = ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'signing_satisfaction_score' => 4,
            'signing_satisfaction_rated_at' => now(),
        ]);

        $response = $this->call(
            'GET',
            '/api/convenios-manual/tracking/'.$tracking->id,
            [],
            ['prosalud_auth_token' => $this->apiCookieForUser($user)],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        $response->assertOk()
            ->assertJsonPath('data.tracking.signing_satisfaction_score', 4);
        $this->assertNotNull($response->json('data.tracking.signing_satisfaction_rated_at'));
    }
}
