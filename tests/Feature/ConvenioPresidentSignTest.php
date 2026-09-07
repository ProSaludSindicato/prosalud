<?php

namespace Tests\Feature;

use App\Enums\ConvenioPdfStage;
use App\Exceptions\AutoSignApiException;
use App\Jobs\ApplyPresidentSignatureJob;
use App\Mail\ConvenioCompletedNotification;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Services\AutoSignApiService;
use App\Services\ConvenioPdfStorageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioPresidentSignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        $this->configureAutoSign(true, bulkEnabled: true);
    }

    public function test_queues_individual_president_sign(): void
    {
        Bus::fake([ApplyPresidentSignatureJob::class]);

        $tracking = $this->trackingReadyForPresidentSign();
        [, $token] = $this->userWithPermission('document_signing.manage');

        $this->call(
            'POST',
            '/api/convenios-manual/tracking/'.$tracking->id.'/president-sign',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertStatus(202)->assertJsonPath('success', true);

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE, $tracking->signing_estado);
        $this->assertNull($tracking->president_sign_last_error);

        Bus::assertDispatched(ApplyPresidentSignatureJob::class, function (ApplyPresidentSignatureJob $job) use ($tracking): bool {
            return $job->trackingId === $tracking->id;
        });
    }

    public function test_bulk_accepts_eligible_and_rejects_invalid_states(): void
    {
        Bus::fake([ApplyPresidentSignatureJob::class]);

        $eligible = $this->trackingReadyForPresidentSign();
        $pending = ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);

        [, $token] = $this->userWithPermission('document_signing.manage');

        $this->call(
            'POST',
            '/api/convenios-manual/tracking/president-sign-bulk',
            ['tracking_ids' => [$eligible->id, $pending->id]],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('rejected.0.tracking_id', $pending->id)
            ->assertJsonPath('batch_id', fn ($value) => $value !== null);

        Bus::assertDispatched(ApplyPresidentSignatureJob::class, 1);
    }

    public function test_president_sign_requires_manage_permission(): void
    {
        $tracking = $this->trackingReadyForPresidentSign();
        [, $token] = $this->userWithPermission('document_signing.view');

        $this->call(
            'POST',
            '/api/convenios-manual/tracking/'.$tracking->id.'/president-sign',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertForbidden();
    }

    public function test_president_sign_returns_503_when_disabled(): void
    {
        $this->configureAutoSign(false);

        $tracking = $this->trackingReadyForPresidentSign();
        [, $token] = $this->userWithPermission('document_signing.manage');

        $this->call(
            'POST',
            '/api/convenios-manual/tracking/'.$tracking->id.'/president-sign',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertStatus(503)->assertJsonPath('success', false);
    }

    public function test_job_stores_final_pdf_and_marks_completed(): void
    {
        $this->configureAutoSign(true, bulkEnabled: true, requireReview: false);

        Http::fake([
            'https://auto-sign.test/api/auto-sign' => Http::response('%PDF-1.4 signed-final', 200, [
                'Content-Type' => 'application/pdf',
                'X-Signature-Detection-Method' => 'text_fallback',
                'X-Signature-Page' => '2',
                'X-Duration-Ms' => '88',
            ]),
        ]);

        Mail::fake();
        RateLimiter::clear('convenio-email-send');
        config(['convenios.delivery_mode' => 'test']);

        $user = User::factory()->create(['email' => 'admin@test.com']);
        $tracking = $this->trackingReadyForPresidentSign();
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
            'president_sign_requested_by_user_id' => $user->id,
        ]);

        (new ApplyPresidentSignatureJob($tracking->id))->handle(
            app(AutoSignApiService::class),
            app(ConvenioPdfStorageService::class),
            app(\App\Services\ConvenioCompletedEmailService::class),
        );

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_COMPLETADO, $tracking->signing_estado);
        $this->assertNotNull($tracking->pdf_final_path);
        $this->assertNotNull($tracking->firmado_presidente_at);
        $this->assertNotNull($tracking->completed_email_sent_at);
        $this->assertNull($tracking->president_sign_last_error);
        $this->assertSame('%PDF-1.4 signed-final', Storage::disk('prosalud-private')->get($tracking->pdf_final_path));
        Mail::assertSent(ConvenioCompletedNotification::class, function (ConvenioCompletedNotification $mail) use ($user): bool {
            return $mail->hasTo($user->email);
        });
    }

    public function test_job_without_review_stays_pending_when_email_fails(): void
    {
        $this->configureAutoSign(true, bulkEnabled: true, requireReview: false);

        Http::fake([
            'https://auto-sign.test/api/auto-sign' => Http::response('%PDF-1.4 signed-final', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $user = User::factory()->create(['email' => 'admin@test.com']);
        $tracking = $this->trackingReadyForPresidentSign();
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
            'president_sign_requested_by_user_id' => $user->id,
        ]);

        $this->mock(\App\Services\ConvenioCompletedEmailService::class, function ($mock): void {
            $mock->shouldReceive('send')
                ->once()
                ->andThrow(new \RuntimeException('No se pudo enviar el correo del convenio completado.'));
        });

        (new ApplyPresidentSignatureJob($tracking->id))->handle(
            app(AutoSignApiService::class),
            app(ConvenioPdfStorageService::class),
            app(\App\Services\ConvenioCompletedEmailService::class),
        );

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION, $tracking->signing_estado);
        $this->assertNotNull($tracking->pdf_final_path);
        $this->assertNull($tracking->completed_at);
    }

    public function test_job_failure_marks_error_state(): void
    {
        $tracking = $this->trackingReadyForPresidentSign();
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
        ]);

        $job = new ApplyPresidentSignatureJob($tracking->id);
        $job->failed(new AutoSignApiException('No se encontró el ancla', 'anchor_not_found', 422));

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE, $tracking->signing_estado);
        $this->assertSame(
            'No se encontró el texto ancla de la firma del presidente en el PDF.',
            $tracking->president_sign_last_error,
        );
    }

    public function test_job_failure_maps_detection_error_to_incompatible_affiliate_pdf_message(): void
    {
        $tracking = $this->trackingReadyForPresidentSign();
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
        ]);

        $job = new ApplyPresidentSignatureJob($tracking->id);
        $job->failed(new AutoSignApiException('Error procesando el PDF', 'detection_error', 500));

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE, $tracking->signing_estado);
        $this->assertSame(
            'El PDF firmado por el afiliado no es compatible con autofirma.',
            $tracking->president_sign_last_error,
        );
    }

    public function test_history_exposes_auto_sign_flag_and_error_filter(): void
    {
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE,
            'president_sign_last_error' => 'No se encontró el texto ancla de la firma del presidente en el PDF.',
        ]);
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
        ]);

        [, $token] = $this->userWithPermission('document_signing.view');

        $this->call(
            'GET',
            '/api/convenios-manual/email-history',
            ['estado_filtro' => 'firma_error_presidente'],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('auto_sign_enabled', true)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.signing_estado', ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE);
    }

    public function test_statistics_include_president_sign_counts_and_flag(): void
    {
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
        ]);
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
        ]);
        ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE,
        ]);

        [, $token] = $this->userWithPermission('document_signing.view');

        $this->call(
            'GET',
            '/api/convenios-manual/statistics',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('data.auto_sign_enabled', true)
            ->assertJsonPath('data.signing.firmado_afiliado', 1)
            ->assertJsonPath('data.signing.firmando_presidente', 1)
            ->assertJsonPath('data.signing.error_firma_presidente', 1)
            ->assertJsonPath('data.signing_derived.por_firmar_presidente', 2)
            ->assertJsonPath('data.signing_derived.firmando_presidente', 1);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function userWithPermission(string $permission): array
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return [$user, $plainToken];
    }

    private function trackingReadyForPresidentSign(): ConvenioEmailTracking
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'nombre_afiliado' => 'USUARIO PRUEBA',
            'documento' => '1234567890',
            'nombre_convenio' => 'TEST',
        ]);

        $path = app(ConvenioPdfStorageService::class)->storeFromContents(
            $tracking,
            ConvenioPdfStage::FirmadoAfiliado,
            '%PDF-1.4 affiliate-signed',
        );

        $tracking->update([
            'pdf_firmado_afiliado_path' => $path,
        ]);

        return $tracking->fresh();
    }

    private function configureAutoSign(bool $enabled, bool $bulkEnabled = false, bool $requireReview = true): void
    {
        config([
            'convenio_signing.enabled' => true,
            'convenio_signing.auto_sign.enabled' => $enabled,
            'convenio_signing.auto_sign.per_minute' => 8,
            'convenio_signing.president_sign_bulk_enabled' => $bulkEnabled,
            'convenio_signing.president_sign_require_review' => $requireReview,
            'convenio_signing.president.search_text' => 'JORGE IVAN ÁLVAREZ SOTO',
            'convenio_signing.president.secondary_anchor' => 'PRESIDENTE',
            'convenio_signing.president.search_page' => 2,
            'convenio_signing.president.width' => 48,
            'convenio_signing.president.height' => 63,
            'convenio_signing.president.offset_x' => 0,
            'convenio_signing.president.offset_y' => -14,
            'convenio_signing.president.signature_path' => 'resources/signatures/presidente.png',
            'services.auto_sign.url' => 'https://auto-sign.test',
            'services.auto_sign.api_key' => $enabled ? 'test-auto-sign-key' : '',
            'services.auto_sign.timeout' => 30,
            'services.auto_sign.connect_timeout' => 5,
            'services.auto_sign.verify_ssl' => false,
        ]);
    }
}
