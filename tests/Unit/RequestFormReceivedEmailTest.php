<?php

namespace Tests\Unit;

use App\Constants\RequestTypes;
use App\Mail\RequestFormReceived;
use App\Models\RequestForm;
use Tests\TestCase;

class RequestFormReceivedEmailTest extends TestCase
{
    public function test_compensacion_descanso_email_includes_processing_notices(): void
    {
        $html = $this->renderEmail(RequestTypes::COMPENSACION_DESCANSO);

        $this->assertStringContainsString('Fechas de procesamiento:', $html);
        $this->assertStringContainsString('día 24 del mes', $html);
        $this->assertStringContainsString('Nota Importante:', $html);
        $this->assertStringContainsString('7:00 a.m.', $html);
        $this->assertStringNotContainsString('15 días hábiles', $html);
    }

    public function test_compensacion_anual_email_includes_processing_notices(): void
    {
        $html = $this->renderEmail(RequestTypes::COMPENSACION_ANUAL);

        $this->assertStringContainsString('Fechas de procesamiento:', $html);
        $this->assertStringContainsString('día 24 del mes', $html);
        $this->assertStringContainsString('Nota Importante:', $html);
        $this->assertStringNotContainsString('15 días hábiles', $html);
    }

    public function test_verificacion_pagos_email_includes_response_time_notice(): void
    {
        $html = $this->renderEmail(RequestTypes::VERIFICACION_PAGOS);

        $this->assertStringContainsString('Tiempos de respuesta:', $html);
        $this->assertStringContainsString('15 días hábiles', $html);
        $this->assertStringContainsString('5:00 p.m.', $html);
        $this->assertStringNotContainsString('Fechas de procesamiento:', $html);
    }

    public function test_certificado_convenio_email_excludes_processing_notices(): void
    {
        $html = $this->renderEmail(RequestTypes::CERTIFICADO_CONVENIO);

        $this->assertStringNotContainsString('Fechas de procesamiento:', $html);
        $this->assertStringNotContainsString('Tiempos de respuesta:', $html);
        $this->assertStringNotContainsString('15 días hábiles', $html);
    }

    private function renderEmail(string $requestType): string
    {
        $form = RequestForm::factory()->make([
            'request_type' => $requestType,
            'payload' => ['motivoSolicitud' => 'Vivienda'],
        ]);

        return (new RequestFormReceived($form))->render();
    }
}
