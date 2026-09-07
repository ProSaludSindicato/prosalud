<?php

namespace App\Services;

use App\Mail\ConvenioCompletedNotification;
use App\Models\ConvenioEmailTracking;
use App\Models\User;
use App\Support\ConvenioDelivery;
use App\Support\ConvenioRateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class ConvenioCompletedEmailService
{
    public function __construct(
        private AfiliadoService $afiliadoService,
        private ConvenioPdfStorageService $pdfStorage,
    ) {}

    public function send(ConvenioEmailTracking $tracking): void
    {
        if ($tracking->completed_email_sent_at !== null) {
            return;
        }

        if (! filled($tracking->pdf_final_path)) {
            $this->recordFailure($tracking, 'No hay PDF final disponible para enviar.');

            throw new RuntimeException('No hay PDF final disponible para enviar.');
        }

        $pdfContents = $this->pdfStorage->get($tracking->pdf_final_path);

        if ($pdfContents === null) {
            $this->recordFailure($tracking, 'No se pudo leer el PDF final del convenio.');

            throw new RuntimeException('No se pudo leer el PDF final del convenio.');
        }

        $email = $this->resolveRecipientEmail($tracking);

        if ($email === null || $email === '' || $email === 'No disponible') {
            $this->recordFailure($tracking, 'No se encontró correo de destino para el convenio completado.');

            throw new RuntimeException('No se encontró correo de destino para el convenio completado.');
        }

        if (ConvenioRateLimiter::tooManyEmailAttempts()) {
            $availableIn = ConvenioRateLimiter::emailAvailableIn();

            throw new RuntimeException(
                "Límite de envío de correos alcanzado. Intente nuevamente en {$availableIn} segundos.",
            );
        }

        try {
            $mailable = new ConvenioCompletedNotification(
                nombreAfiliado: (string) $tracking->nombre_afiliado,
                documento: (string) $tracking->documento,
                nombreConvenio: (string) $tracking->nombre_convenio,
                isTest: $tracking->isTestRecord() || ConvenioDelivery::isTestMode(),
            );

            $mailable->attachPdfFromContents(
                $pdfContents,
                $tracking->resolveDownloadFilename(),
            );

            Mail::to($email)->send($mailable);
            ConvenioRateLimiter::hitEmail();

            $tracking->update([
                'completed_email_sent_at' => now(),
                'completed_email_last_error' => null,
            ]);

            Log::info('[CONVENIO COMPLETADO] Correo enviado', [
                'tracking_id' => $tracking->id,
                'recipient' => $email,
                'test_mode' => $tracking->isTestRecord() || ConvenioDelivery::isTestMode(),
            ]);
        } catch (\Throwable $exception) {
            $this->recordFailure($tracking, 'No se pudo enviar el correo del convenio completado.');

            Log::error('[CONVENIO COMPLETADO] Error al enviar correo', [
                'tracking_id' => $tracking->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function recordFailure(ConvenioEmailTracking $tracking, string $message): void
    {
        $tracking->update([
            'completed_email_last_error' => $message,
        ]);
    }

    private function resolveRecipientEmail(ConvenioEmailTracking $tracking): ?string
    {
        if ($tracking->isTestRecord() || ConvenioDelivery::isTestMode()) {
            if ($tracking->completed_by_user_id !== null) {
                $email = User::query()->where('id', $tracking->completed_by_user_id)->value('email');
                if (filled($email)) {
                    return $email;
                }
            }

            if ($tracking->president_sign_requested_by_user_id !== null) {
                $email = User::query()->where('id', $tracking->president_sign_requested_by_user_id)->value('email');
                if (filled($email)) {
                    return $email;
                }
            }

            if ($tracking->generated_by_user_id !== null) {
                return User::query()->where('id', $tracking->generated_by_user_id)->value('email');
            }

            return null;
        }

        $storedEmail = $tracking->email_afiliado;
        if (filled($storedEmail) && $storedEmail !== 'No disponible' && filter_var($storedEmail, FILTER_VALIDATE_EMAIL)) {
            return $storedEmail;
        }

        if (ConvenioRateLimiter::tooManyProsanetAttempts()) {
            throw new RuntimeException(
                'Límite de consultas al afiliado alcanzado. Intente nuevamente en '
                .ConvenioRateLimiter::prosanetAvailableIn().' segundos.',
            );
        }

        $afiliado = $this->afiliadoService->getAfiliadoByDocumentoOnly((string) $tracking->documento);
        ConvenioRateLimiter::hitProsanet();

        $email = $afiliado['correo_personal'] ?? null;

        return filled($email) ? $email : null;
    }
}
