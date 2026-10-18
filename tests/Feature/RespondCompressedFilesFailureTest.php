<?php

namespace Tests\Feature;

use App\Constants\RequestStatuses;
use App\Models\ApiToken;
use App\Models\RequestForm;
use App\Models\RequestResponse;
use App\Models\User;
use App\Services\CertificadoConvenioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RespondCompressedFilesFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Storage::fake('prosalud-private');
        Mail::fake();
    }

    private function actingAsAdmin(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $plainToken = 'test-plain-'.Str::random(48);
        ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => 'phpunit',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $this->withCredentials()->withUnencryptedCookie('prosalud_auth_token', $plainToken);
    }

    public function test_response_is_aborted_when_compressed_files_cannot_be_stored(): void
    {
        $this->actingAsAdmin();
        $form = RequestForm::factory()->create(['request_type' => 'otro-tramite', 'status' => RequestStatuses::PENDING]);

        RequestResponse::creating(fn () => throw new \RuntimeException('db down'));

        $this->post("/api/requests/{$form->id}/respond", [
            'status' => 'COMPLETED',
            'email_subject' => 'Respuesta',
            'email_body' => 'Sus archivos adjuntos.',
            'attachments' => [UploadedFile::fake()->create('soporte.zip', 10, 'application/zip')],
        ], ['Accept' => 'application/json'])->assertStatus(500)->assertJsonPath('success', false);

        Mail::assertNothingSent();
        $this->assertSame(RequestStatuses::PENDING, $form->fresh()->status);
    }

    private function certificateForm(): RequestForm
    {
        return RequestForm::factory()->create([
            'request_type' => 'certificado-convenio',
            'status' => RequestStatuses::PENDING,
            'payload' => [
                'proceso' => 'Proceso',
                'dondeRealizaProceso' => 'Hospital',
                'infoCertificado' => ['adicionarActividades' => true],
            ],
        ]);
    }

    public function test_completed_response_stops_when_the_certificate_cannot_be_generated(): void
    {
        $this->actingAsAdmin();
        $form = $this->certificateForm();

        $this->mock(CertificadoConvenioService::class, function ($mock) {
            $mock->shouldReceive('generarCertificadoPDFConActividades')->andThrow(new \RuntimeException('prosanet down'));
        });

        $this->postJson("/api/requests/{$form->id}/respond", [
            'status' => 'COMPLETED',
            'email_subject' => 'Certificado',
            'email_body' => 'Adjunto su certificado.',
            'actividades' => ['Actividad uno'],
        ])->assertStatus(500)->assertJsonPath('success', false);

        Mail::assertNothingSent();
        $this->assertSame(RequestStatuses::PENDING, $form->fresh()->status);
        $this->assertDatabaseCount('request_responses', 0);
    }

    public function test_rejected_response_does_not_depend_on_certificate_generation(): void
    {
        $this->actingAsAdmin();
        $form = $this->certificateForm();

        $this->mock(CertificadoConvenioService::class, function ($mock) {
            $mock->shouldReceive('generarCertificadoPDFConActividades')->never();
        });

        $this->postJson("/api/requests/{$form->id}/respond", [
            'status' => 'REJECTED',
            'rejection_reason' => 'Datos incompletos',
            'email_subject' => 'Solicitud rechazada',
            'email_body' => 'No es posible atender su solicitud.',
        ])->assertOk();

        $this->assertSame(RequestStatuses::REJECTED, $form->fresh()->status);
    }
}
