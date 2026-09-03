<?php

namespace Tests\Feature;

use App\Enums\ConvenioTextIntegrityStatus;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioManualEmailHistoryTest extends TestCase
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

    public function test_q_param_matches_document_or_nombre_convenio(): void
    {
        $this->seed(RolePermissionSeeder::class);

        ConvenioEmailTracking::factory()->create([
            'documento' => '9998887776',
            'nombre_convenio' => 'CONVENIO BUSQUEDA UNICA',
            'estado' => 'enviado',
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $response = $this->call(
            'GET',
            '/api/convenios-manual/email-history',
            [
                'q' => 'BUSQUEDA',
            ],
            ['prosalud_auth_token' => $this->apiCookieForUser($user)],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_estado_filtro_firma_pendiente_firma(): void
    {
        $this->seed(RolePermissionSeeder::class);

        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_COMPLETADO,
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $response = $this->call(
            'GET',
            '/api/convenios-manual/email-history',
            [
                'estado_filtro' => 'firma_pendiente_firma',
            ],
            ['prosalud_auth_token' => $this->apiCookieForUser($user)],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_email_history_returns_updated_signing_estado_and_integrity_badge(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $tracking = ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');
        $cookie = $this->apiCookieForUser($user);

        $first = $this->call(
            'GET',
            '/api/convenios-manual/email-history',
            [],
            ['prosalud_auth_token' => $cookie],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $first->assertOk()
            ->assertJsonPath('data.data.0.signing_estado', ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA);
        $this->assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'));
        $this->assertNull($first->json('data.data.0.integrity_badge_label'));

        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'text_integrity_status' => ConvenioTextIntegrityStatus::Matched,
        ]);

        $second = $this->call(
            'GET',
            '/api/convenios-manual/email-history',
            [],
            ['prosalud_auth_token' => $cookie],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $second->assertOk()
            ->assertJsonPath('data.data.0.signing_estado', ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO)
            ->assertJsonPath('data.data.0.integrity_badge_label', 'Texto íntegro');
    }

    public function test_email_history_hides_stale_error_when_email_was_sent(): void
    {
        $this->seed(RolePermissionSeeder::class);

        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'error_message' => 'Error al enviar correo: Expected response code "354" but got code "550"',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $response = $this->call(
            'GET',
            '/api/convenios-manual/email-history',
            [],
            ['prosalud_auth_token' => $this->apiCookieForUser($user)],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('data.data.0.estado', 'enviado')
            ->assertJsonPath('data.data.0.error_message', null);
    }

    public function test_email_history_shows_error_when_send_failed(): void
    {
        $this->seed(RolePermissionSeeder::class);

        ConvenioEmailTracking::factory()->create([
            'estado' => 'fallido',
            'error_message' => 'Job falló después de 5 intentos: 550 5.7.0 Too many emails per second',
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $response = $this->call(
            'GET',
            '/api/convenios-manual/email-history',
            [],
            ['prosalud_auth_token' => $this->apiCookieForUser($user)],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('data.data.0.estado', 'fallido')
            ->assertJsonPath(
                'data.data.0.error_message',
                'Job falló después de 5 intentos: 550 5.7.0 Too many emails per second'
            );
    }

    public function test_tracking_detail_hides_stale_error_when_email_was_sent(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $tracking = ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'generated_by_user_id' => $user->id,
            'error_message' => 'Error al enviar correo: 550 5.7.0 Too many emails per second',
        ]);

        $response = $this->call(
            'GET',
            '/api/convenios-manual/tracking/'.$tracking->id,
            [],
            ['prosalud_auth_token' => $this->apiCookieForUser($user)],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('data.tracking.estado', 'enviado')
            ->assertJsonPath('data.tracking.error_message', null);
    }
}
