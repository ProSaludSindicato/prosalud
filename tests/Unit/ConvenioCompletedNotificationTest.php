<?php

namespace Tests\Unit;

use App\Mail\ConvenioCompletedNotification;
use App\Support\ConvenioEmailSubject;
use Carbon\Carbon;
use Tests\TestCase;

class ConvenioCompletedNotificationTest extends TestCase
{
    public function test_completed_email_uses_standard_layout_and_clear_content(): void
    {
        $html = $this->renderEmail();

        $this->assertStringContainsString('Convenio firmado y completado', $html);
        $this->assertStringContainsString('Tu documento ya cuenta con todas las firmas requeridas', $html);
        $this->assertStringContainsString('JUAN PABLO PABON', $html);
        $this->assertStringContainsString('Adjunto a este correo encontrarás el PDF', $html);
        $this->assertStringContainsString('No es necesario responder este correo ni volver a enviar el PDF', $html);
        $this->assertStringNotContainsString('Detalle del convenio', $html);
        $this->assertStringNotContainsString('Qué debes hacer ahora', $html);
        $this->assertStringContainsString('auxiliartalento.sprosalud@gmail.com', $html);
        $this->assertStringContainsString('prosalud.org.co', $html);
        $this->assertStringNotContainsString('Firma digital de convenio', $html);
    }

    public function test_test_mode_shows_warning_banner(): void
    {
        $mail = new ConvenioCompletedNotification(
            'JUAN PABLO PABON',
            '1025640842',
            'Medico General',
            true,
        );

        $html = $mail->render();
        $mail->build();

        $this->assertStringContainsString('Este es un envío de prueba (TEST).', $html);
        $this->assertStringContainsString('[TEST]', (string) $mail->subject);
    }

    public function test_subject_includes_send_datetime(): void
    {
        $this->travelTo(Carbon::parse('2026-09-04 11:15:32', 'America/Bogota'));

        $mail = $this->makeMail()->build();

        $this->assertSame(
            'Convenio firmado 1025640842 (Medico General) - 04/09/2026 11:15:32 - ProSalud',
            (string) $mail->subject,
        );
    }

    public function test_completed_subject_differs_from_signing_subject(): void
    {
        $sentAt = Carbon::parse('2026-09-04 11:15:32', 'America/Bogota');

        $signingSubject = ConvenioEmailSubject::make('1025640842', 'Medico General', false, $sentAt);
        $completedSubject = ConvenioEmailSubject::makeCompleted('1025640842', 'Medico General', false, $sentAt);

        $this->assertNotSame($signingSubject, $completedSubject);
        $this->assertStringStartsWith('Convenio firmado ', $completedSubject);
        $this->assertStringStartsWith('Convenio 1025640842', $signingSubject);
    }

    public function test_subject_differs_between_sends_at_different_times(): void
    {
        $this->travelTo(Carbon::parse('2026-09-04 11:15:32', 'America/Bogota'));
        $firstSubject = (string) $this->makeMail()->build()->subject;

        $this->travel(1)->seconds();
        $secondSubject = (string) $this->makeMail()->build()->subject;

        $this->assertNotSame($firstSubject, $secondSubject);
    }

    public function test_production_email_uses_talent_help_address(): void
    {
        $mail = $this->makeMail()->build();

        $this->assertTrue($mail->hasReplyTo('auxiliartalento.sprosalud@gmail.com'));
    }

    private function makeMail(bool $isTest = false): ConvenioCompletedNotification
    {
        return new ConvenioCompletedNotification(
            'JUAN PABLO PABON',
            '1025640842',
            'Medico General',
            $isTest,
        );
    }

    private function renderEmail(bool $isTest = false): string
    {
        return $this->makeMail($isTest)->render();
    }
}
