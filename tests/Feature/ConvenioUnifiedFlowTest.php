<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioUnifiedFlowTest extends TestCase
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

    public function test_send_bulk_emails_is_deprecated(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.manage');

        $response = $this->call(
            'POST',
            '/api/convenios-manual/send-bulk-emails',
            ['document_numbers' => ['1234567890']],
            ['prosalud_auth_token' => $this->apiCookieForUser($user)],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertStatus(410)
            ->assertJsonPath('deprecated', true)
            ->assertJsonPath('alternatives.history_resend.endpoint', '/api/convenios-manual/resend-emails');
    }

    public function test_email_history_includes_ui_metadata_and_available_actions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $pdfPath = storage_path('app/temp/convenios/history-ui-test.pdf');
        if (! is_dir(dirname($pdfPath))) {
            mkdir(dirname($pdfPath), 0755, true);
        }
        file_put_contents($pdfPath, '%PDF-1.4 history ui');

        ConvenioEmailTracking::factory()->create([
            'documento' => '1234567890',
            'estado' => ConvenioEmailTracking::ESTADO_VERIFICACION,
            'ruta_archivo_pdf' => $pdfPath,
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
            ->assertJsonPath('ui.tabs.2.id', 'history')
            ->assertJsonPath('ui.bulk_actions.0.id', 'resend')
            ->assertJsonPath('data.data.0.available_actions.resend', true)
            ->assertJsonPath('data.data.0.available_actions.download_original', true);
    }

    public function test_email_history_filters_verificacion_estado(): void
    {
        $this->seed(RolePermissionSeeder::class);

        ConvenioEmailTracking::factory()->create([
            'estado' => ConvenioEmailTracking::ESTADO_VERIFICACION,
        ]);
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
        ]);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $response = $this->call(
            'GET',
            '/api/convenios-manual/email-history',
            ['estado_filtro' => 'verificacion'],
            ['prosalud_auth_token' => $this->apiCookieForUser($user)],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame(ConvenioEmailTracking::ESTADO_VERIFICACION, $response->json('data.data.0.estado'));
    }
}
