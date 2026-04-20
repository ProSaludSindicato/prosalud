<?php

namespace Tests\Feature;

use App\Jobs\SignConvenioWithPresidentJob;
use App\Models\ApiToken;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvenioPresidentSigningTest extends TestCase
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

    private function createManagerUser(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo('document_signing.manage');
        $user->givePermissionTo('document_signing.view');

        return $user;
    }

    private function postAuthed(string $uri, array $data = [], ?User $user = null): \Illuminate\Testing\TestResponse
    {
        $user = $user ?? $this->createManagerUser();

        return $this->call(
            'POST',
            $uri,
            [],
            ['prosalud_auth_token' => $this->apiCookieForUser($user)],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode($data),
        );
    }

    private function trackingWithAfiliateSignedPdf(): ConvenioEmailTracking
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'pdf_firmado_afiliado_path' => null,
        ]);

        $pdfDir = storage_path("app/convenios-digital/{$tracking->id}");
        if (! is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }

        $pdfPath = $pdfDir.'/firmado-afiliado.pdf';
        file_put_contents($pdfPath, '%PDF-1.4 minimal test content');

        $tracking->update([
            'pdf_firmado_afiliado_path' => "convenios-digital/{$tracking->id}/firmado-afiliado.pdf",
        ]);

        return $tracking->fresh();
    }

    // ──────────────────────────────────────────────────────────
    // Feature flag tests
    // ──────────────────────────────────────────────────────────

    public function test_sign_one_returns_404_when_auto_sign_disabled(): void
    {
        config(['convenio_auto_sign.enabled' => false]);
        Queue::fake();

        $tracking = ConvenioEmailTracking::factory()->create([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
        ]);

        $this->postAuthed("/api/convenios-manual/tracking/{$tracking->id}/president-sign")
            ->assertStatus(404)
            ->assertJson(['error_code' => 'AUTO_SIGN_DISABLED']);

        Queue::assertNothingPushed();
    }

    public function test_sign_bulk_returns_404_when_auto_sign_disabled(): void
    {
        config(['convenio_auto_sign.enabled' => false]);
        Queue::fake();

        $tracking = ConvenioEmailTracking::factory()->create([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
        ]);

        $this->postAuthed('/api/convenios-manual/tracking/president-sign-bulk', [
            'tracking_ids' => [$tracking->id],
        ])->assertStatus(404);

        Queue::assertNothingPushed();
    }

    // ──────────────────────────────────────────────────────────
    // signOne happy path
    // ──────────────────────────────────────────────────────────

    public function test_sign_one_dispatches_job_for_valid_tracking(): void
    {
        config(['convenio_auto_sign.enabled' => true]);
        Queue::fake();

        $tracking = ConvenioEmailTracking::factory()->create([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
        ]);

        $this->postAuthed("/api/convenios-manual/tracking/{$tracking->id}/president-sign")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        Queue::assertPushed(SignConvenioWithPresidentJob::class);
        $this->assertNotNull($tracking->fresh()->president_sign_queued_at);
    }

    public function test_sign_one_also_works_for_error_estado(): void
    {
        config(['convenio_auto_sign.enabled' => true]);
        Queue::fake();

        $tracking = ConvenioEmailTracking::factory()->create([
            'signing_estado' => ConvenioEmailTracking::SIGNING_ERROR_PRESIDENTE,
        ]);

        $this->postAuthed("/api/convenios-manual/tracking/{$tracking->id}/president-sign")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        Queue::assertPushed(SignConvenioWithPresidentJob::class);
    }

    // ──────────────────────────────────────────────────────────
    // signOne — invalid state
    // ──────────────────────────────────────────────────────────

    public function test_sign_one_returns_422_when_estado_is_pendiente_firma(): void
    {
        config(['convenio_auto_sign.enabled' => true]);
        Queue::fake();

        $tracking = ConvenioEmailTracking::factory()->create([
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
        ]);

        $this->postAuthed("/api/convenios-manual/tracking/{$tracking->id}/president-sign")
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        Queue::assertNothingPushed();
    }

    public function test_sign_one_returns_422_when_already_completado(): void
    {
        config(['convenio_auto_sign.enabled' => true]);
        Queue::fake();

        $tracking = ConvenioEmailTracking::factory()->create([
            'signing_estado' => ConvenioEmailTracking::SIGNING_COMPLETADO,
        ]);

        $this->postAuthed("/api/convenios-manual/tracking/{$tracking->id}/president-sign")
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    // ──────────────────────────────────────────────────────────
    // signBulk happy path
    // ──────────────────────────────────────────────────────────

    public function test_sign_bulk_dispatches_job_for_each_valid_tracking(): void
    {
        config(['convenio_auto_sign.enabled' => true]);
        Queue::fake();

        $t1 = ConvenioEmailTracking::factory()->create(['signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO]);
        $t2 = ConvenioEmailTracking::factory()->create(['signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO]);

        $this->postAuthed('/api/convenios-manual/tracking/president-sign-bulk', [
            'tracking_ids' => [$t1->id, $t2->id],
        ])->assertStatus(200)->assertJson(['success' => true, 'accepted' => 2]);

        Queue::assertPushed(SignConvenioWithPresidentJob::class, 2);
    }

    public function test_sign_bulk_rejects_invalid_states_but_accepts_valid_ones(): void
    {
        config(['convenio_auto_sign.enabled' => true]);
        Queue::fake();

        $valid = ConvenioEmailTracking::factory()->create(['signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO]);
        $invalid = ConvenioEmailTracking::factory()->create(['signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA]);

        $response = $this->postAuthed('/api/convenios-manual/tracking/president-sign-bulk', [
            'tracking_ids' => [$valid->id, $invalid->id],
        ])->assertStatus(200)->assertJson(['success' => true, 'accepted' => 1]);

        $this->assertCount(1, $response->json('rejected'));
        Queue::assertPushed(SignConvenioWithPresidentJob::class, 1);
    }

    // ──────────────────────────────────────────────────────────
    // signBulk — validation
    // ──────────────────────────────────────────────────────────

    public function test_sign_bulk_returns_422_when_tracking_ids_is_empty(): void
    {
        config(['convenio_auto_sign.enabled' => true]);

        $this->postAuthed('/api/convenios-manual/tracking/president-sign-bulk', [
            'tracking_ids' => [],
        ])->assertStatus(422);
    }

    // ──────────────────────────────────────────────────────────
    // Service: ConvenioPresidentSigningService
    // ──────────────────────────────────────────────────────────

    public function test_service_signs_and_marks_completado_on_success(): void
    {
        config([
            'convenio_auto_sign.enabled' => true,
            'convenio_auto_sign.api_url' => 'http://fake-sign.test',
            'convenio_auto_sign.api_key' => 'test-key',
        ]);

        $tracking = $this->trackingWithAfiliateSignedPdf();

        Http::fake([
            'http://fake-sign.test/api/auto-sign' => Http::response(
                '%PDF-1.4 signed-content',
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'X-Signature-Detection-Method' => 'graphic_line',
                ],
            ),
        ]);

        app(\App\Services\ConvenioPresidentSigningService::class)->signTracking($tracking);

        $fresh = $tracking->fresh();
        $this->assertEquals(ConvenioEmailTracking::SIGNING_COMPLETADO, $fresh->signing_estado);
        $this->assertNotNull($fresh->firmado_presidente_at);
        $this->assertNotNull($fresh->pdf_final_path);
        $this->assertEquals('graphic_line', $fresh->president_sign_detection_method);
    }

    public function test_service_marks_error_when_anchor_not_found_422(): void
    {
        config([
            'convenio_auto_sign.enabled' => true,
            'convenio_auto_sign.api_url' => 'http://fake-sign.test',
            'convenio_auto_sign.api_key' => 'test-key',
        ]);

        $tracking = $this->trackingWithAfiliateSignedPdf();

        Http::fake([
            'http://fake-sign.test/api/auto-sign' => Http::response(
                ['error' => 'Anchor not found', 'code' => 'anchor_not_found'],
                422,
            ),
        ]);

        try {
            app(\App\Services\ConvenioPresidentSigningService::class)->signTracking($tracking);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('anchor_not_found', $e->getMessage());
        }

        $this->assertEquals(ConvenioEmailTracking::SIGNING_ERROR_PRESIDENTE, $tracking->fresh()->signing_estado);
    }

    public function test_service_skips_when_estado_is_already_completado(): void
    {
        config(['convenio_auto_sign.enabled' => true]);
        Http::fake();

        $tracking = ConvenioEmailTracking::factory()->create([
            'signing_estado' => ConvenioEmailTracking::SIGNING_COMPLETADO,
        ]);

        app(\App\Services\ConvenioPresidentSigningService::class)->signTracking($tracking);

        Http::assertNothingSent();
        $this->assertEquals(ConvenioEmailTracking::SIGNING_COMPLETADO, $tracking->fresh()->signing_estado);
    }

    public function test_service_throws_when_feature_disabled(): void
    {
        config(['convenio_auto_sign.enabled' => false]);

        $tracking = ConvenioEmailTracking::factory()->create([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
        ]);

        $this->expectException(\RuntimeException::class);

        app(\App\Services\ConvenioPresidentSigningService::class)->signTracking($tracking);
    }

    // ──────────────────────────────────────────────────────────
    // Job: failed() callback
    // ──────────────────────────────────────────────────────────

    public function test_job_failed_marks_error_on_firmando_presidente_state(): void
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE,
        ]);

        $job = new SignConvenioWithPresidentJob($tracking->id);
        $job->failed(new \RuntimeException('Connection timeout'));

        $fresh = $tracking->fresh();
        $this->assertEquals(ConvenioEmailTracking::SIGNING_ERROR_PRESIDENTE, $fresh->signing_estado);
        $this->assertStringContainsString('Connection timeout', (string) $fresh->president_sign_last_error);
    }

    public function test_job_failed_does_not_update_non_firmando_states(): void
    {
        $tracking = ConvenioEmailTracking::factory()->create([
            'signing_estado' => ConvenioEmailTracking::SIGNING_ERROR_PRESIDENTE,
            'president_sign_last_error' => 'original error',
        ]);

        $job = new SignConvenioWithPresidentJob($tracking->id);
        $job->failed(new \RuntimeException('Another error'));

        $this->assertEquals(ConvenioEmailTracking::SIGNING_ERROR_PRESIDENTE, $tracking->fresh()->signing_estado);
        $this->assertEquals('original error', $tracking->fresh()->president_sign_last_error);
    }
}
