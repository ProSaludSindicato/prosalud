<?php

namespace Tests\Feature;

use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Services\ConvenioPdfStorageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        config([
            'convenios.storage_disk' => 'prosalud-private',
            'convenio_signing.enabled' => true,
            'convenios.delivery_mode' => 'production',
        ]);
    }

    public function test_history_exposes_mark_invalid_for_pending_convenio(): void
    {
        [, $token] = $this->authenticatedManageUser();
        $tracking = $this->createPendingTracking('71226924');

        $response = $this->call(
            'GET',
            '/api/convenios-manual/email-history',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('data.data.0.id', $tracking->id)
            ->assertJsonPath('data.data.0.available_actions.mark_invalid', true)
            ->assertJsonPath('data.data.0.available_actions.resend', true);
    }

    public function test_history_exposes_mark_invalid_for_firmado_afiliado_convenio(): void
    {
        [, $token] = $this->authenticatedManageUser();
        $tracking = $this->createPendingTracking('71226927');
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'firmado_afiliado_at' => now(),
            'pdf_firmado_afiliado_path' => 'convenios/production/2026/09/71226927/'.$tracking->id.'/signed.pdf',
        ]);

        $response = $this->call(
            'GET',
            '/api/convenios-manual/email-history',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('data.data.0.id', $tracking->id)
            ->assertJsonPath('data.data.0.available_actions.mark_invalid', true)
            ->assertJsonPath('data.data.0.available_actions.president_sign', true);
    }

    public function test_can_invalidate_pending_convenio_and_blocks_affiliate_signing(): void
    {
        $plainToken = str_repeat('c', 64);
        $tracking = $this->createPendingTracking('71226924', $plainToken);
        [, $token] = $this->authenticatedManageUser();

        $response = $this->call(
            'POST',
            '/api/convenios-manual/tracking/'.$tracking->id.'/invalidate',
            ['reason' => 'El convenio tenía errores de captura.'],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $tracking->refresh();
        $this->assertTrue($tracking->isInvalidated());
        $this->assertNotNull($tracking->rechazado_at);
        $this->assertSame('El convenio tenía errores de captura.', $tracking->motivo_rechazo);
        $this->assertTrue($tracking->token_expires_at?->lessThanOrEqualTo(now()));

        $this->getJson('/api/public/convenio-firma/'.$plainToken.'/metadata')
            ->assertOk()
            ->assertJsonPath('data.can_sign', false)
            ->assertJsonPath('data.signing_estado', ConvenioEmailTracking::SIGNING_RECHAZADO)
            ->assertJsonPath('data.motivo_rechazo', 'El convenio tenía errores de captura.');

        $uploadPdf = $this->writeTempPdf('%PDF-1.4 signed after invalidate');
        $file = new UploadedFile($uploadPdf, 'signed.pdf', 'application/pdf', null, true);

        $this->post('/api/public/convenio-firma/'.$plainToken.'/submit-affiliate-signature', [
            'pdf' => $file,
            'audit_log' => json_encode([
                'sessionId' => 'session-test',
                'startedAt' => now()->toIso8601String(),
                'events' => [],
                'summary' => [
                    'documentName' => 'convenio.pdf',
                    'totalPages' => 1,
                    'signaturePage' => 1,
                    'signatureMethod' => 'draw',
                    'submittedAt' => now()->toIso8601String(),
                    'downloadedAt' => null,
                ],
            ]),
            'terms_accepted' => '1',
        ])->assertStatus(403);
    }

    public function test_can_invalidate_firmado_afiliado_convenio_and_blocks_president_sign(): void
    {
        [, $token] = $this->authenticatedManageUser();
        $tracking = $this->createPendingTracking('71226924');
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'firmado_afiliado_at' => now(),
            'pdf_firmado_afiliado_path' => 'convenios/production/2026/09/71226924/'.$tracking->id.'/signed.pdf',
        ]);

        $response = $this->call(
            'POST',
            '/api/convenios-manual/tracking/'.$tracking->id.'/invalidate',
            ['reason' => 'Convenio firmado con errores, se envió uno nuevo.'],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $tracking->refresh();
        $this->assertTrue($tracking->isInvalidated());
        $this->assertFalse($tracking->isEligibleForPresidentSign());
    }

    public function test_cannot_invalidate_completado_convenio(): void
    {
        [, $token] = $this->authenticatedManageUser();
        $tracking = $this->createPendingTracking('71226924');
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_COMPLETADO,
            'firmado_afiliado_at' => now(),
            'firmado_presidente_at' => now(),
            'completed_at' => now(),
        ]);

        $response = $this->call(
            'POST',
            '/api/convenios-manual/tracking/'.$tracking->id.'/invalidate',
            ['reason' => 'Intento de invalidar un completado.'],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'No se puede invalidar un convenio ya completado.');

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_COMPLETADO, $tracking->signing_estado);
    }

    public function test_resend_is_blocked_after_invalidation(): void
    {
        Queue::fake();
        [, $token] = $this->authenticatedManageUser();
        $tracking = $this->createPendingTracking('71226925');

        $this->call(
            'POST',
            '/api/convenios-manual/tracking/'.$tracking->id.'/invalidate',
            ['reason' => 'Convenio duplicado, se envió uno nuevo.'],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        )->assertOk();

        $response = $this->call(
            'POST',
            '/api/convenios-manual/resend-emails',
            ['tracking_ids' => [$tracking->id]],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('data.success_count', 0)
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.results.failed.0.error', 'No se puede reenviar un convenio invalidado.');

        Queue::assertNotPushed(SendConvenioManualEmailJob::class);
    }

    public function test_invalidate_requires_reason_and_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');
        $plainToken = $this->apiCookieForUser($user);
        $tracking = $this->createPendingTracking('71226926');

        $this->call(
            'POST',
            '/api/convenios-manual/tracking/'.$tracking->id.'/invalidate',
            ['reason' => 'Motivo suficiente para invalidar.'],
            ['prosalud_auth_token' => $plainToken],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        )->assertForbidden();

        [, $manageToken] = $this->authenticatedManageUser();

        $this->call(
            'POST',
            '/api/convenios-manual/tracking/'.$tracking->id.'/invalidate',
            ['reason' => 'corto'],
            ['prosalud_auth_token' => $manageToken],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        )->assertStatus(422);
    }

    private function createPendingTracking(string $documento, ?string $plainToken = null): ConvenioEmailTracking
    {
        $tracking = ConvenioEmailTracking::factory()->pendienteFirma()->create([
            'documento' => $documento,
            'sede' => 'BELLO',
            'nombre_convenio' => 'BELLO',
            'ruta_archivo_pdf' => $this->writeTempPdf('%PDF-1.4 invalidation test'),
        ]);

        $relative = app(ConvenioPdfStorageService::class)->storeOriginalFromAbsolutePath(
            $tracking,
            $tracking->ruta_archivo_pdf
        );

        $updates = [
            'pdf_original_path' => $relative,
        ];

        if ($plainToken !== null) {
            $updates['signing_token_hash'] = hash('sha256', $plainToken);
            $updates['token_expires_at'] = now()->addDay();
        }

        $tracking->update($updates);

        return $tracking->fresh();
    }

    private function writeTempPdf(string $contents): string
    {
        $dir = storage_path('app/temp/convenios/tests');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir.'/test-'.uniqid('', true).'.pdf';
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function authenticatedManageUser(): array
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'email' => 'admin-invalidate-'.Str::random(8).'@example.com',
        ]);
        $user->givePermissionTo(['document_signing.manage', 'document_signing.view']);

        return [$user, $this->apiCookieForUser($user)];
    }

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
}
