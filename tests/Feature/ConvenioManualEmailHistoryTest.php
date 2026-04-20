<?php

namespace Tests\Feature;

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

    public function test_sede_param_matches_sede_or_nombre_convenio_partially(): void
    {
        $this->seed(RolePermissionSeeder::class);

        ConvenioEmailTracking::factory()->create([
            'documento' => '1111111111',
            'nombre_convenio' => 'CONVENIO ALFA',
            'sede' => 'Hospital Sur',
            'estado' => 'enviado',
        ]);
        ConvenioEmailTracking::factory()->create([
            'documento' => '2222222222',
            'nombre_convenio' => 'Convenio Hospital Sur Especial',
            'sede' => 'Norte',
            'estado' => 'enviado',
        ]);
        ConvenioEmailTracking::factory()->create([
            'documento' => '3333333333',
            'nombre_convenio' => 'Otro',
            'sede' => 'Occidente',
            'estado' => 'enviado',
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $response = $this->call(
            'GET',
            '/api/convenios-manual/email-history',
            [
                'sede' => 'Sur',
            ],
            ['prosalud_auth_token' => $this->apiCookieForUser($user)],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(2, $response->json('data.total'));
    }
}
