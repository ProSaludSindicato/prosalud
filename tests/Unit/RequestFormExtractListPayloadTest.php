<?php

namespace Tests\Unit;

use App\Constants\RequestTypes;
use App\Models\RequestForm;
use Tests\TestCase;

class RequestFormExtractListPayloadTest extends TestCase
{
    public function test_certificado_convenio_list_payload_includes_info_certificado(): void
    {
        $requestForm = RequestForm::factory()->make([
            'request_type' => RequestTypes::CERTIFICADO_CONVENIO,
            'payload' => [
                'proceso' => 'Enfermería',
                'dondeRealizaProceso' => 'Hospital La María',
                'infoCertificado' => [
                    'adicionarActividades' => true,
                    'fechaIngresoRetiro' => false,
                ],
                'dirigidoAQuien' => 'Entidad de prueba',
                'otrosDescripcion' => 'Descripción adicional',
            ],
        ]);

        $listPayload = $requestForm->extractListPayload();

        $this->assertSame('Enfermería', $listPayload['proceso']);
        $this->assertTrue($listPayload['infoCertificado']['adicionarActividades']);
        $this->assertSame('Entidad de prueba', $listPayload['dirigidoAQuien']);
        $this->assertSame('Descripción adicional', $listPayload['otrosDescripcion']);
    }

    public function test_non_certificado_convenio_list_payload_omits_info_certificado(): void
    {
        $requestForm = RequestForm::factory()->make([
            'request_type' => RequestTypes::VERIFICACION_PAGOS,
            'payload' => [
                'proceso' => 'Proceso general',
                'infoCertificado' => [
                    'adicionarActividades' => true,
                ],
            ],
        ]);

        $listPayload = $requestForm->extractListPayload();

        $this->assertSame('Proceso general', $listPayload['proceso']);
        $this->assertArrayNotHasKey('infoCertificado', $listPayload);
    }
}
