<?php

namespace Tests\Feature;

use App\Models\RequestForm;
use App\Services\CertificadoConvenioAutomaticoService;
use App\Services\CertificadoConvenioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificadoRecipientEmailTest extends TestCase
{
    use RefreshDatabase;

    private function service(?array $afiliadoData, bool $throws = false): CertificadoConvenioAutomaticoService
    {
        $this->mock(CertificadoConvenioService::class, function ($mock) use ($afiliadoData, $throws) {
            $expectation = $mock->shouldReceive('obtenerDatosAfiliado')->with('1234567890');
            $throws ? $expectation->andThrow(new \RuntimeException('prosanet down')) : $expectation->andReturn($afiliadoData);
        });

        return app(CertificadoConvenioAutomaticoService::class);
    }

    private function form(string $email = 'atacante@example.com'): RequestForm
    {
        return RequestForm::factory()->create(['document_number' => '1234567890', 'email' => $email]);
    }

    public function test_certificate_goes_to_registered_email_not_the_form_email(): void
    {
        $service = $this->service(['afiliado' => ['correo_personal' => 'afiliado@example.com']]);

        $this->assertSame('afiliado@example.com', $service->resolveRecipientEmail($this->form()));
    }

    public function test_falls_back_to_form_email_when_no_registered_email(): void
    {
        $service = $this->service(['afiliado' => ['correo_personal' => '']]);

        $this->assertSame('contacto@example.com', $service->resolveRecipientEmail($this->form('contacto@example.com')));
    }

    public function test_falls_back_to_form_email_when_registered_email_is_invalid(): void
    {
        $service = $this->service(['afiliado' => ['correo_personal' => 'no-es-un-correo']]);

        $this->assertSame('contacto@example.com', $service->resolveRecipientEmail($this->form('contacto@example.com')));
    }

    public function test_falls_back_to_form_email_when_prosanet_lookup_fails(): void
    {
        $service = $this->service(null, throws: true);

        $this->assertSame('contacto@example.com', $service->resolveRecipientEmail($this->form('contacto@example.com')));
    }
}
