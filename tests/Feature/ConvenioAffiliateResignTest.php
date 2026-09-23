<?php

namespace Tests\Feature;

use App\Enums\ConvenioPdfStage;
use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Services\ConvenioPdfStorageService;
use App\Services\ConvenioPresidentSignReviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioAffiliateResignTest extends TestCase
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

    public function test_review_rejected_convenio_exposes_request_affiliate_resign_action(): void
    {
        [, $token] = $this->authenticatedManageUser();

        $tracking = $this->createTrackingWithStoredPdf('70853501');
        $signedPath = app(ConvenioPdfStorageService::class)->storeFromContents(
            $tracking,
            ConvenioPdfStage::FirmadoAfiliado,
            '%PDF-1.4 signed affiliate',
        );
        $finalPath = app(ConvenioPdfStorageService::class)->storeFromContents(
            $tracking,
            ConvenioPdfStage::Final,
            '%PDF-1.4 final with president',
        );

        $tracking->update([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION,
            'pdf_firmado_afiliado_path' => $signedPath,
            'pdf_final_path' => $finalPath,
            'firmado_afiliado_at' => now(),
            'firmado_presidente_at' => now(),
        ]);

        app(ConvenioPresidentSignReviewService::class)->markReviewError($tracking, 'Firma mal ubicada');
        $tracking->refresh();

        $this->assertFalse($tracking->isEligibleForPresidentSign());
        $this->assertTrue($tracking->isEligibleForAffiliateResign());

        $this->call(
            'GET',
            '/api/convenios-manual/email-history',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath('data.data.0.available_actions.request_affiliate_resign', true)
            ->assertJsonPath('data.data.0.available_actions.president_sign', false);
    }

    public function test_request_affiliate_resign_resets_state_and_dispatches_email_job(): void
    {
        Queue::fake();

        [, $token] = $this->authenticatedManageUser();

        $tracking = $this->createTrackingWithStoredPdf('70853502');
        $signedPath = app(ConvenioPdfStorageService::class)->storeFromContents(
            $tracking,
            ConvenioPdfStage::FirmadoAfiliado,
            '%PDF-1.4 signed affiliate',
        );
        $finalPath = app(ConvenioPdfStorageService::class)->storeFromContents(
            $tracking,
            ConvenioPdfStage::Final,
            '%PDF-1.4 final with president',
        );

        $tracking->update([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE,
            'pdf_firmado_afiliado_path' => $signedPath,
            'pdf_final_path' => $finalPath,
            'firmado_afiliado_at' => now()->subHour(),
            'firmado_presidente_at' => now(),
            'president_sign_last_error' => 'Firma mal ubicada',
        ]);

        $this->call(
            'POST',
            '/api/convenios-manual/tracking/'.$tracking->id.'/request-affiliate-resign',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath('success', true);

        $tracking->refresh();

        $this->assertSame(ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA, $tracking->signing_estado);
        $this->assertNull($tracking->pdf_firmado_afiliado_path);
        $this->assertNull($tracking->pdf_final_path);
        $this->assertNull($tracking->firmado_afiliado_at);
        $this->assertNull($tracking->firmado_presidente_at);
        $this->assertNull($tracking->president_sign_last_error);
        $this->assertSame(2, $tracking->intentos);

        $storage = app(ConvenioPdfStorageService::class);
        $this->assertTrue($storage->hasOriginal($tracking));
        $this->assertFalse($storage->hasStage($tracking, ConvenioPdfStage::FirmadoAfiliado));
        $this->assertFalse($storage->hasStage($tracking, ConvenioPdfStage::Final));

        Queue::assertPushed(SendConvenioManualEmailJob::class, function (SendConvenioManualEmailJob $job) use ($tracking): bool {
            return $job->existingTrackingId === $tracking->id;
        });
    }

    public function test_request_affiliate_resign_rejects_completed_convenio(): void
    {
        Queue::fake();

        [, $token] = $this->authenticatedManageUser();

        $tracking = $this->createTrackingWithStoredPdf('70853503');
        $tracking->update([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_COMPLETADO,
            'firmado_afiliado_at' => now(),
            'firmado_presidente_at' => now(),
        ]);

        $this->call(
            'POST',
            '/api/convenios-manual/tracking/'.$tracking->id.'/request-affiliate-resign',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        )
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        Queue::assertNotPushed(SendConvenioManualEmailJob::class);
    }

    private function createTrackingWithStoredPdf(string $documento): ConvenioEmailTracking
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => $documento,
            'estado' => 'enviado',
            'intentos' => 1,
            'ruta_archivo_pdf' => $this->writeTempPdf('%PDF-1.4 affiliate resign test'),
        ]);

        $relative = app(ConvenioPdfStorageService::class)->storeOriginalFromAbsolutePath(
            $tracking,
            $tracking->ruta_archivo_pdf
        );

        $tracking->update([
            'pdf_original_path' => $relative,
            'pdf_original_sha256' => hash('sha256', '%PDF-1.4 affiliate resign test'),
        ]);

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
            'email' => 'admin-affiliate-resign@example.com',
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
