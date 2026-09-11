<?php

namespace Tests\Feature;

use App\Enums\ConvenioPdfStage;
use App\Jobs\ApplyPresidentSignatureJob;
use App\Jobs\EnqueuePresidentSignBatchJob;
use App\Jobs\SendConvenioCompletedEmailJob;
use App\Mail\ConvenioCompletedNotification;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\ConvenioPresidentSignBatch;
use App\Models\User;
use App\Services\AutoSignApiService;
use App\Services\ConvenioCompletedEmailService;
use App\Services\ConvenioPdfStorageService;
use App\Services\ConvenioPresidentSignReviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioPresidentSignReviewAndBulkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prosalud-private');
        $this->configureAutoSign(true, bulkEnabled: true, requireReview: true);
    }

    public function test_bulk_endpoints_return_503_when_bulk_disabled(): void
    {
        $this->configureAutoSign(true, bulkEnabled: false, requireReview: true);

        [, $token] = $this->userWithPermission('document_signing.manage');

        $this->call(
            'GET',
            '/api/convenios-manual/tracking/president-sign-bulk/preview',
            ['scope' => 'all'],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertStatus(503);

        $this->call(
            'POST',
            '/api/convenios-manual/tracking/president-sign-campaign',
            ['scope' => 'all'],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertStatus(503);
    }

    public function test_individual_president_sign_still_works_when_bulk_disabled(): void
    {
        Bus::fake([ApplyPresidentSignatureJob::class]);
        $this->configureAutoSign(true, bulkEnabled: false, requireReview: true);

        $tracking = $this->trackingReadyForPresidentSign();
        [, $token] = $this->userWithPermission('document_signing.manage');

        $this->call(
            'POST',
            '/api/convenios-manual/tracking/'.$tracking->id.'/president-sign',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertStatus(202);
    }

    public function test_campaign_preview_accepts_query_string_boolean_params(): void
    {
        $this->trackingReadyForPresidentSign();
        [, $token] = $this->userWithPermission('document_signing.manage');

        $this->call(
            'GET',
            '/api/convenios-manual/tracking/president-sign-bulk/preview',
            ['scope' => 'all', 'include_errors' => 'false'],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 1);
    }

    public function test_campaign_preview_and_start_create_batch(): void
    {
        Bus::fake([ApplyPresidentSignatureJob::class]);

        $this->trackingReadyForPresidentSign();
        [, $token] = $this->userWithPermission('document_signing.manage');

        $this->call(
            'GET',
            '/api/convenios-manual/tracking/president-sign-bulk/preview',
            ['scope' => 'all'],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 1);

        $this->call(
            'POST',
            '/api/convenios-manual/tracking/president-sign-campaign',
            ['scope' => 'all'],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('accepted', 1);

        $this->assertDatabaseHas('convenio_president_sign_batches', [
            'scope' => ConvenioPresidentSignBatch::SCOPE_ALL,
            'total' => 1,
            'status' => ConvenioPresidentSignBatch::STATUS_PROCESSING,
        ]);

        Bus::assertDispatched(ApplyPresidentSignatureJob::class, 1);
    }

    public function test_campaign_enqueues_all_eligible_trackings_in_background(): void
    {
        Bus::fake([ApplyPresidentSignatureJob::class]);

        for ($i = 0; $i < 35; $i++) {
            $this->trackingReadyForPresidentSign();
        }

        [, $token] = $this->userWithPermission('document_signing.manage');

        $this->call(
            'POST',
            '/api/convenios-manual/tracking/president-sign-campaign',
            ['scope' => 'all'],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertStatus(202)
            ->assertJsonPath('accepted', 35);

        $this->assertDatabaseHas('convenio_president_sign_batches', [
            'scope' => ConvenioPresidentSignBatch::SCOPE_ALL,
            'total' => 35,
            'status' => ConvenioPresidentSignBatch::STATUS_PROCESSING,
        ]);

        Bus::assertDispatched(ApplyPresidentSignatureJob::class, 35);
    }

    public function test_enqueue_batch_job_can_resume_campaign_after_partial_dispatch(): void
    {
        Bus::fake([ApplyPresidentSignatureJob::class]);

        $first = $this->trackingReadyForPresidentSign();
        $second = $this->trackingReadyForPresidentSign();

        $batch = ConvenioPresidentSignBatch::query()->create([
            'requested_by_user_id' => User::factory()->create()->id,
            'scope' => ConvenioPresidentSignBatch::SCOPE_ALL,
            'include_errors' => false,
            'total' => 2,
            'status' => ConvenioPresidentSignBatch::STATUS_PROCESSING,
            'require_review' => true,
        ]);

        app(\App\Services\ConvenioPresidentSignService::class)->queue(
            $first,
            $batch->requested_by_user_id,
            $batch->id,
        );

        (new EnqueuePresidentSignBatchJob($batch->id))->handle(
            app(\App\Services\ConvenioPresidentSignService::class),
        );

        $batch->refresh();
        $this->assertSame(2, $batch->total);
        $this->assertSame(
            ConvenioPresidentSignBatch::STATUS_PROCESSING,
            $batch->status,
        );

        Bus::assertDispatched(ApplyPresidentSignatureJob::class, 2);
        $this->assertSame(2, ConvenioEmailTracking::query()->where('president_sign_batch_id', $batch->id)->count());
        $this->assertSame(
            ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
            $first->fresh()->signing_estado,
        );
        $this->assertSame(
            ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
            $second->fresh()->signing_estado,
        );
    }

    public function test_active_batch_returns_finished_batch_with_pending_review(): void
    {
        $tracking = $this->trackingReadyForPresidentSign();
        $batch = ConvenioPresidentSignBatch::query()->create([
            'requested_by_user_id' => User::factory()->create()->id,
            'scope' => ConvenioPresidentSignBatch::SCOPE_IDS,
            'include_errors' => false,
            'total' => 1,
            'signing' => 0,
            'ready_for_review' => 1,
            'errors' => 0,
            'completed' => 0,
            'status' => ConvenioPresidentSignBatch::STATUS_FINISHED,
            'require_review' => true,
        ]);

        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
            'president_sign_batch_id' => $batch->id,
            'pdf_final_path' => 'convenios/test/final.pdf',
            'firmado_presidente_at' => now(),
        ]);

        [, $token] = $this->userWithPermission('document_signing.manage');

        $this->call(
            'GET',
            '/api/convenios-manual/president-sign-batches/active',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('batch.id', $batch->id)
            ->assertJsonPath('batch.status', ConvenioPresidentSignBatch::STATUS_FINISHED)
            ->assertJsonPath('batch.ready_for_review', 1);
    }

    public function test_active_batch_is_null_when_no_processing_or_pending_review(): void
    {
        ConvenioPresidentSignBatch::query()->create([
            'requested_by_user_id' => User::factory()->create()->id,
            'scope' => ConvenioPresidentSignBatch::SCOPE_ALL,
            'include_errors' => false,
            'total' => 2,
            'signing' => 0,
            'ready_for_review' => 0,
            'errors' => 0,
            'completed' => 2,
            'status' => ConvenioPresidentSignBatch::STATUS_FINISHED,
            'require_review' => true,
        ]);

        [, $token] = $this->userWithPermission('document_signing.manage');

        $this->call(
            'GET',
            '/api/convenios-manual/president-sign-batches/active',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('batch', null);
    }

    public function test_job_with_review_enabled_marks_pending_review_without_email(): void
    {
        Mail::fake();
        Bus::fake([SendConvenioCompletedEmailJob::class]);
        Http::fake([
            'https://auto-sign.test/api/auto-sign' => Http::response('%PDF-1.4 signed-final', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $tracking = $this->trackingReadyForPresidentSign();
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
        ]);

        (new ApplyPresidentSignatureJob($tracking->id))->handle(
            app(AutoSignApiService::class),
            app(ConvenioPdfStorageService::class),
            app(ConvenioCompletedEmailService::class),
        );

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION, $tracking->signing_estado);
        $this->assertNotNull($tracking->pdf_final_path);
        Mail::assertNothingSent();
        Bus::assertNotDispatched(SendConvenioCompletedEmailJob::class);
    }

    public function test_complete_moves_to_completed_after_email_is_sent(): void
    {
        Mail::fake();
        RateLimiter::clear('convenio-email-send');
        config(['convenios.delivery_mode' => 'test']);

        $user = User::factory()->create(['email' => 'admin@test.com']);
        $tracking = $this->trackingReadyForPresidentSign();
        $path = app(ConvenioPdfStorageService::class)->storeFromContents(
            $tracking,
            ConvenioPdfStage::Final,
            '%PDF-1.4 final',
        );
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
            'pdf_final_path' => $path,
            'firmado_presidente_at' => now(),
        ]);

        app(ConvenioPresidentSignReviewService::class)->complete($tracking, $user->id);

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_COMPLETADO, $tracking->signing_estado);
        $this->assertSame($user->id, $tracking->completed_by_user_id);
        $this->assertNotNull($tracking->completed_email_sent_at);
        Mail::assertSent(ConvenioCompletedNotification::class, function (ConvenioCompletedNotification $mail) use ($user): bool {
            return $mail->hasTo($user->email);
        });
    }

    public function test_complete_stays_pending_when_email_fails(): void
    {
        $user = User::factory()->create(['email' => 'admin@test.com']);
        $tracking = $this->trackingReadyForPresidentSign();
        $path = app(ConvenioPdfStorageService::class)->storeFromContents(
            $tracking,
            ConvenioPdfStage::Final,
            '%PDF-1.4 final',
        );
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
            'pdf_final_path' => $path,
            'firmado_presidente_at' => now(),
        ]);

        $this->mock(ConvenioCompletedEmailService::class, function ($mock): void {
            $mock->shouldReceive('send')
                ->once()
                ->andThrow(new \RuntimeException('No se pudo enviar el correo del convenio completado.'));
        });

        try {
            app(ConvenioPresidentSignReviewService::class)->complete($tracking, $user->id);
            $this->fail('Se esperaba una excepción al fallar el envío del correo.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('No se pudo enviar el correo', $exception->getMessage());
        }

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION, $tracking->signing_estado);
        $this->assertNull($tracking->completed_at);
    }

    public function test_review_error_returns_to_error_state_for_retry(): void
    {
        $tracking = $this->trackingReadyForPresidentSign();
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
            'pdf_final_path' => 'convenios/test/final.pdf',
            'firmado_presidente_at' => now(),
        ]);

        app(ConvenioPresidentSignReviewService::class)->markReviewError($tracking, 'Firma mal ubicada');

        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE, $tracking->signing_estado);
        $this->assertSame('Firma mal ubicada', $tracking->president_sign_last_error);
        $this->assertTrue($tracking->isEligibleForPresidentSign());
    }

    public function test_review_rejected_convenio_allows_downloading_affiliate_signed_pdf(): void
    {
        $tracking = $this->trackingReadyForPresidentSign();
        $finalPath = app(ConvenioPdfStorageService::class)->storeFromContents(
            $tracking,
            ConvenioPdfStage::Final,
            '%PDF-1.4 final-with-president',
        );
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
            'pdf_final_path' => $finalPath,
            'firmado_presidente_at' => now(),
        ]);

        app(ConvenioPresidentSignReviewService::class)->markReviewError($tracking, 'Firma mal ubicada');
        $tracking->refresh();

        [, $token] = $this->userWithPermission('document_signing.view');

        $this->call(
            'GET',
            '/api/convenios-manual/tracking/'.$tracking->id.'/download-final',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/pdf'],
        )
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertTrue($tracking->resolveAvailableActions(true, false)['download_final']);
    }

    public function test_review_rejected_convenio_download_falls_back_to_final_when_affiliate_path_missing(): void
    {
        $tracking = $this->trackingReadyForPresidentSign();
        $finalPath = app(ConvenioPdfStorageService::class)->storeFromContents(
            $tracking,
            ConvenioPdfStage::Final,
            '%PDF-1.4 final-with-president',
        );
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE,
            'pdf_firmado_afiliado_path' => null,
            'pdf_final_path' => $finalPath,
            'firmado_presidente_at' => now(),
            'president_sign_last_error' => 'Firma del afiliado mal ubicada',
        ]);

        [, $token] = $this->userWithPermission('document_signing.view');

        $response = $this->call(
            'GET',
            '/api/convenios-manual/tracking/'.$tracking->id.'/download-final',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/pdf'],
        );

        $response->assertOk();
        $this->assertSame('%PDF-1.4 final-with-president', $response->getContent());
        $this->assertTrue($tracking->resolveAvailableActions(true, false)['download_final']);
    }

    public function test_completed_email_in_test_mode_goes_to_completing_user(): void
    {
        Mail::fake();
        RateLimiter::clear('convenio-email-send');
        config(['convenios.delivery_mode' => 'test']);

        $user = User::factory()->create(['email' => 'admin@test.com']);
        $tracking = $this->trackingReadyForPresidentSign();
        $path = app(ConvenioPdfStorageService::class)->storeFromContents(
            $tracking,
            ConvenioPdfStage::Final,
            '%PDF-1.4 final',
        );

        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
            'pdf_final_path' => $path,
            'firmado_presidente_at' => now(),
            'email_afiliado' => 'afiliado@real.com',
        ]);

        app(ConvenioPresidentSignReviewService::class)->complete($tracking, $user->id);

        Mail::assertSent(ConvenioCompletedNotification::class, function (ConvenioCompletedNotification $mail) use ($user): bool {
            return $mail->hasTo($user->email);
        });
    }

    public function test_preview_pdf_inline_for_pending_review(): void
    {
        $tracking = $this->trackingReadyForPresidentSign();
        $path = app(ConvenioPdfStorageService::class)->storeFromContents(
            $tracking,
            ConvenioPdfStage::Final,
            '%PDF-1.4 final-inline',
        );
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
            'pdf_final_path' => $path,
        ]);

        [, $token] = $this->userWithPermission('document_signing.view');

        $response = $this->call(
            'GET',
            '/api/convenios-manual/tracking/'.$tracking->id.'/preview-pdf',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        $response->assertOk();
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 final-inline', $response->getContent());
    }

    public function test_batch_trackings_endpoint_is_paginated_and_excludes_heavy_fields(): void
    {
        $batch = ConvenioPresidentSignBatch::query()->create([
            'requested_by_user_id' => User::factory()->create()->id,
            'scope' => ConvenioPresidentSignBatch::SCOPE_IDS,
            'include_errors' => false,
            'total' => 3,
            'signing' => 0,
            'ready_for_review' => 3,
            'errors' => 0,
            'completed' => 0,
            'status' => ConvenioPresidentSignBatch::STATUS_FINISHED,
            'require_review' => true,
        ]);

        foreach (range(1, 3) as $index) {
            $tracking = $this->trackingReadyForPresidentSign();
            $tracking->update([
                'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
                'president_sign_batch_id' => $batch->id,
                'pdf_final_path' => 'convenios/test/final-'.$index.'.pdf',
                'firmado_presidente_at' => now(),
                'nombre_afiliado' => 'Afiliado '.$index,
                'documento' => '10000'.$index,
            ]);
        }

        [, $token] = $this->userWithPermission('document_signing.manage');

        $this->call(
            'GET',
            '/api/convenios-manual/president-sign-batches/'.$batch->id.'/trackings',
            ['per_page' => 2, 'page' => 1],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonCount(2, 'data.data')
            ->assertJsonMissingPath('data.data.0.available_actions')
            ->assertJsonMissingPath('data.data.0.download_filename');

        $this->call(
            'GET',
            '/api/convenios-manual/president-sign-batches/'.$batch->id.'/trackings',
            ['ids_only' => '1'],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 3)
            ->assertJsonCount(3, 'data');

        $this->call(
            'GET',
            '/api/convenios-manual/president-sign-batches/'.$batch->id.'/trackings',
            ['q' => 'Afiliado 2'],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.nombre_afiliado', 'Afiliado 2');
    }

    public function test_job_releases_when_auto_sign_rate_limit_is_hit(): void
    {
        RateLimiter::clear('convenio-auto-sign');
        for ($i = 0; $i < 8; $i++) {
            RateLimiter::hit('convenio-auto-sign', 60);
        }

        $tracking = $this->trackingReadyForPresidentSign();
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
        ]);

        $job = new class($tracking->id) extends ApplyPresidentSignatureJob
        {
            public ?int $releasedFor = null;

            public function release($delay = 0): void
            {
                $this->releasedFor = is_numeric($delay) ? (int) $delay : 0;
            }
        };

        $job->handle(
            app(AutoSignApiService::class),
            app(ConvenioPdfStorageService::class),
            app(ConvenioCompletedEmailService::class),
        );

        $this->assertNotNull($job->releasedFor);
        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE, $tracking->signing_estado);
    }

    public function test_job_survives_multiple_rate_limit_releases_for_bulk_processing(): void
    {
        RateLimiter::clear('convenio-auto-sign');
        for ($i = 0; $i < 8; $i++) {
            RateLimiter::hit('convenio-auto-sign', 60);
        }

        $tracking = $this->trackingReadyForPresidentSign();
        $tracking->update([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
        ]);

        $job = new class($tracking->id) extends ApplyPresidentSignatureJob
        {
            public int $releaseCount = 0;

            public function release($delay = 0): void
            {
                $this->releaseCount++;
            }
        };

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $job->handle(
                app(AutoSignApiService::class),
                app(ConvenioPdfStorageService::class),
                app(ConvenioCompletedEmailService::class),
            );
        }

        $this->assertSame(5, $job->releaseCount);
        $tracking->refresh();
        $this->assertSame(ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE, $tracking->signing_estado);
        $this->assertNull($tracking->president_sign_last_error);
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
            'firmado_afiliado_at' => now()->subDay(),
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

    private function configureAutoSign(bool $enabled, bool $bulkEnabled, bool $requireReview): void
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
