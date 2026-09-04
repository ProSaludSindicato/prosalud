<?php

namespace Tests\Feature;

use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Services\ConvenioPdfStorageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioResendSignedAffiliateTest extends TestCase
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

    public function test_history_hides_resend_action_when_affiliate_has_signed(): void
    {
        [, $token] = $this->authenticatedManageUser();

        $tracking = $this->createTrackingWithStoredPdf('70853497');
        $tracking->update([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'firmado_afiliado_at' => now(),
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
            ->assertJsonPath('data.data.0.available_actions.resend', false)
            ->assertJsonPath('data.data.0.available_actions.download_original', true);
    }

    public function test_history_allows_resend_when_affiliate_has_not_signed(): void
    {
        [, $token] = $this->authenticatedManageUser();

        $tracking = $this->createTrackingWithStoredPdf('70853498');
        $tracking->update([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
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
            ->assertJsonPath('data.data.0.available_actions.resend', true);
    }

    public function test_resend_rejects_convenio_already_signed_by_affiliate(): void
    {
        Queue::fake();

        [, $token] = $this->authenticatedManageUser();

        $tracking = $this->createTrackingWithStoredPdf('70853499');
        $tracking->update([
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'firmado_afiliado_at' => now(),
        ]);

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
            ->assertJsonPath('data.results.failed.0.tracking_id', $tracking->id)
            ->assertJsonPath('data.results.failed.0.error', 'No se puede reenviar un convenio ya firmado por el afiliado.');

        Queue::assertNotPushed(SendConvenioManualEmailJob::class);
    }

    private function createTrackingWithStoredPdf(string $documento): ConvenioEmailTracking
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => $documento,
            'estado' => 'enviado',
            'ruta_archivo_pdf' => $this->writeTempPdf('%PDF-1.4 signed affiliate test'),
        ]);

        $relative = app(ConvenioPdfStorageService::class)->storeOriginalFromAbsolutePath(
            $tracking,
            $tracking->ruta_archivo_pdf
        );

        $tracking->update([
            'pdf_original_path' => $relative,
            'pdf_original_sha256' => hash('sha256', '%PDF-1.4 signed affiliate test'),
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
            'email' => 'admin-resend-signed@example.com',
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
