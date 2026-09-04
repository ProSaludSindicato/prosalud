<?php

namespace Tests\Feature;

use App\Jobs\SendConvenioManualEmailJob;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioFailedEmailRetryTest extends TestCase
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

    /**
     * @return array{0: User, 1: string}
     */
    private function authenticatedManageUser(): array
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'email' => 'retry-admin@example.com',
        ]);
        $user->givePermissionTo(['document_signing.manage', 'document_signing.view']);

        return [$user, $this->apiCookieForUser($user)];
    }

    public function test_retry_failed_defaults_to_today_and_reuses_existing_tracking(): void
    {
        config(['convenios.delivery_mode' => 'production']);
        Bus::fake([SendConvenioManualEmailJob::class]);

        [$user, $token] = $this->authenticatedManageUser();
        $pdfPath = $this->writeTempPdf('%PDF-1.4 retry today');

        $failedToday = ConvenioEmailTracking::factory()->create([
            'documento' => '1017252506',
            'email_afiliado' => 'afiliado@example.com',
            'ruta_archivo_pdf' => $pdfPath,
            'estado' => 'fallido',
            'error_message' => 'App\Jobs\SendConvenioManualEmailJob has been attempted too many times.',
            'intentos' => 1,
            'created_at' => now(),
        ]);

        $sentToday = ConvenioEmailTracking::factory()->create([
            'documento' => '1111111111',
            'email_afiliado' => 'enviado@example.com',
            'ruta_archivo_pdf' => $pdfPath,
            'estado' => 'enviado',
            'created_at' => now(),
        ]);

        $failedYesterday = ConvenioEmailTracking::factory()->create([
            'documento' => '2222222222',
            'email_afiliado' => 'ayer@example.com',
            'ruta_archivo_pdf' => $pdfPath,
            'estado' => 'fallido',
            'error_message' => 'Error anterior',
            'created_at' => now()->subDay(),
        ]);

        $response = $this->call(
            'POST',
            '/api/convenios-manual/retry-failed-emails',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('data.success_count', 1)
            ->assertJsonPath('data.failed_count', 0)
            ->assertJsonPath('data.fecha_desde', now()->toDateString())
            ->assertJsonPath('data.fecha_hasta', now()->toDateString());

        $failedToday->refresh();
        $this->assertSame('pendiente', $failedToday->estado);
        $this->assertNull($failedToday->error_message);
        $this->assertSame(2, $failedToday->intentos);

        $sentToday->refresh();
        $this->assertSame('enviado', $sentToday->estado);

        $failedYesterday->refresh();
        $this->assertSame('fallido', $failedYesterday->estado);

        $this->assertSame(3, ConvenioEmailTracking::count());

        Bus::assertDispatched(SendConvenioManualEmailJob::class, function (SendConvenioManualEmailJob $job) use ($failedToday): bool {
            return $job->existingTrackingId === $failedToday->id
                && $job->documento === '1017252506'
                && $job->optionalEmail === 'afiliado@example.com'
                && $job->parentTrackingId === null;
        });

        Bus::assertDispatchedTimes(SendConvenioManualEmailJob::class, 1);
    }

    public function test_retry_failed_skips_records_without_pdf(): void
    {
        config(['convenios.delivery_mode' => 'production']);
        Bus::fake([SendConvenioManualEmailJob::class]);

        [, $token] = $this->authenticatedManageUser();

        $tracking = ConvenioEmailTracking::factory()->create([
            'documento' => '3333333333',
            'ruta_archivo_pdf' => '/tmp/does-not-exist-retry.pdf',
            'pdf_original_path' => null,
            'estado' => 'fallido',
            'created_at' => now(),
        ]);

        $response = $this->call(
            'POST',
            '/api/convenios-manual/retry-failed-emails',
            [],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('data.success_count', 0)
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.results.failed.0.tracking_id', $tracking->id);

        $tracking->refresh();
        $this->assertSame('fallido', $tracking->estado);

        Bus::assertNothingDispatched();
    }

    public function test_retry_failed_by_tracking_ids_ignores_non_failed(): void
    {
        config(['convenios.delivery_mode' => 'production']);
        Bus::fake([SendConvenioManualEmailJob::class]);

        [, $token] = $this->authenticatedManageUser();
        $pdfPath = $this->writeTempPdf('%PDF-1.4 retry ids');

        $failed = ConvenioEmailTracking::factory()->create([
            'documento' => '4444444444',
            'email_afiliado' => 'fallido@example.com',
            'ruta_archivo_pdf' => $pdfPath,
            'estado' => 'fallido',
            'created_at' => now()->subDays(3),
        ]);

        $sent = ConvenioEmailTracking::factory()->create([
            'documento' => '5555555555',
            'ruta_archivo_pdf' => $pdfPath,
            'estado' => 'enviado',
            'created_at' => now()->subDays(3),
        ]);

        $response = $this->call(
            'POST',
            '/api/convenios-manual/retry-failed-emails',
            ['tracking_ids' => [$failed->id, $sent->id]],
            ['prosalud_auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('data.success_count', 1);

        Bus::assertDispatchedTimes(SendConvenioManualEmailJob::class, 1);
        Bus::assertDispatched(SendConvenioManualEmailJob::class, function (SendConvenioManualEmailJob $job) use ($failed): bool {
            return $job->existingTrackingId === $failed->id;
        });
    }

    public function test_retry_failed_requires_manage_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.view');

        $response = $this->call(
            'POST',
            '/api/convenios-manual/retry-failed-emails',
            [],
            ['prosalud_auth_token' => $this->apiCookieForUser($user)],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertForbidden();
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
}
