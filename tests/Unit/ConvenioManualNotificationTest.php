<?php

namespace Tests\Unit;

use App\Mail\ConvenioManualNotification;
use Tests\TestCase;

class ConvenioManualNotificationTest extends TestCase
{
    public function test_digital_signing_email_explains_how_to_sign_and_keeps_backup_link(): void
    {
        $signingUrl = 'https://firma.test/sign/abc123token';
        $html = $this->renderEmail($signingUrl);

        $this->assertStringContainsString('Firma digital de convenio', $html);
        $this->assertStringContainsString('Abrir convenio para firmar', $html);
        $this->assertStringContainsString('enlace de respaldo', $html);
        $this->assertStringContainsString($signingUrl, $html);
        $this->assertStringContainsString('segunda hoja', $html);
        $this->assertStringContainsString('línea de firma', $html);
        $this->assertStringContainsString('Agrega o dibuja tu firma digital', $html);
        $this->assertStringContainsString('no es necesario responder este correo ni adjuntar el PDF', $html);
        $this->assertStringContainsString('auxiliartalento.sprosalud@gmail.com', $html);
        $this->assertStringNotContainsString('Imprimir el convenio', $html);
    }

    public function test_production_email_uses_talent_help_address_and_does_not_cc_auxiliar(): void
    {
        $mail = new ConvenioManualNotification(
            'YENIFER NAYELLI CANO ARBOLEDA',
            '1234567890',
            'TEST CONVENIO',
            'https://firma.test/sign/abc123token',
        );

        $mail->build();

        $this->assertTrue($mail->hasReplyTo('auxiliartalento.sprosalud@gmail.com'));
        $this->assertSame([], $mail->cc);
    }

    public function test_manual_email_keeps_print_and_return_instructions(): void
    {
        $html = $this->renderEmail(null);

        $this->assertStringContainsString('Convenio pendiente de firma', $html);
        $this->assertStringContainsString('Imprimir el convenio', $html);
        $this->assertStringContainsString('sprosalud.auxiliar@gmail.com', $html);
        $this->assertStringNotContainsString('Abrir convenio para firmar', $html);
        $this->assertStringNotContainsString('segunda hoja', $html);
        $this->assertStringNotContainsString('enlace de respaldo', $html);
    }

    public function test_test_mode_shows_warning_banner(): void
    {
        $mail = new ConvenioManualNotification(
            'YENIFER NAYELLI CANO ARBOLEDA',
            '1234567890',
            'TEST CONVENIO',
            'https://firma.test/sign/test-token',
            true,
        );

        $html = $mail->render();
        $mail->build();

        $this->assertStringContainsString('Este es un envío de prueba (TEST).', $html);
        $this->assertStringContainsString('[TEST]', (string) $mail->subject);
    }

    private function renderEmail(?string $signingUrl, bool $isTest = false): string
    {
        return (new ConvenioManualNotification(
            'YENIFER NAYELLI CANO ARBOLEDA',
            '1234567890',
            'TEST CONVENIO',
            $signingUrl,
            $isTest,
        ))->render();
    }
}
