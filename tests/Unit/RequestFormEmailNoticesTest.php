<?php

namespace Tests\Unit;

use App\Constants\RequestTypes;
use App\Models\RequestForm;
use App\Support\RequestFormEmailNotices;
use Tests\TestCase;

class RequestFormEmailNoticesTest extends TestCase
{
    public function test_compensacion_descanso_returns_processing_and_schedule_notices(): void
    {
        $form = RequestForm::factory()->make([
            'request_type' => RequestTypes::COMPENSACION_DESCANSO,
        ]);

        $notices = RequestFormEmailNotices::noticesFor($form);

        $this->assertCount(2, $notices);
        $this->assertSame('amber', $notices[0]['variant']);
        $this->assertSame('Fechas de procesamiento:', $notices[0]['title']);
        $this->assertStringContainsString('día 24 del mes', $notices[0]['body']);
        $this->assertSame('gray', $notices[1]['variant']);
        $this->assertSame('Nota Importante:', $notices[1]['title']);
        $this->assertStringContainsString('7:00 a.m.', $notices[1]['body']);
    }

    public function test_compensacion_anual_returns_processing_and_schedule_notices(): void
    {
        $form = RequestForm::factory()->make([
            'request_type' => RequestTypes::COMPENSACION_ANUAL,
        ]);

        $notices = RequestFormEmailNotices::noticesFor($form);

        $this->assertCount(2, $notices);
        $this->assertSame('Fechas de procesamiento:', $notices[0]['title']);
        $this->assertStringContainsString('día 24 del mes', $notices[0]['body']);
        $this->assertStringContainsString('7:00 a.m.', $notices[1]['body']);
    }

    public function test_verificacion_pagos_returns_response_time_notice(): void
    {
        $form = RequestForm::factory()->make([
            'request_type' => RequestTypes::VERIFICACION_PAGOS,
        ]);

        $notices = RequestFormEmailNotices::noticesFor($form);

        $this->assertCount(1, $notices);
        $this->assertSame('amber', $notices[0]['variant']);
        $this->assertSame('Tiempos de respuesta:', $notices[0]['title']);
        $this->assertStringContainsString('15 días hábiles', $notices[0]['body']);
        $this->assertStringContainsString('5:00 p.m.', $notices[0]['body']);
    }

    public function test_other_request_types_return_empty_notices(): void
    {
        $form = RequestForm::factory()->make([
            'request_type' => RequestTypes::CERTIFICADO_CONVENIO,
        ]);

        $this->assertSame([], RequestFormEmailNotices::noticesFor($form));
    }
}
