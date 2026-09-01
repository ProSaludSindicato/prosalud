<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ConvenioManualNotification extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $nombreAfiliado,
        public string $documento,
        public string $nombreConvenio,
        public ?string $signingUrl = null,
        public bool $isTest = false,
    ) {}

    /**
     * Build the message.
     */
    public function build(): self
    {
        $logoPath = public_path('assets/logo.png');
        $logoCid = '';

        if (file_exists($logoPath)) {
            try {
                $logoContent = file_get_contents($logoPath);
                $logoCid = $this->embedData($logoContent, 'logo.png', 'image/png');
            } catch (\Exception $e) {
                $logoCid = '';
            }
        }

        $subject = "Convenio {$this->documento} ({$this->nombreConvenio}) - ProSalud";
        if ($this->isTest) {
            $subject = '[TEST] '.$subject;
        }

        $mail = $this
            ->subject($subject)
            ->replyTo('sprosalud.auxiliar@gmail.com')
            ->view('emails.convenio_manual_notification')
            ->with([
                'nombreAfiliado' => $this->nombreAfiliado,
                'documento' => $this->documento,
                'nombreConvenio' => $this->nombreConvenio,
                'logoCid' => $logoCid,
                'signingUrl' => $this->signingUrl,
                'isTest' => $this->isTest,
            ]);

        if (! $this->isTest) {
            $mail->cc('sprosalud.auxiliar@gmail.com');
        }

        return $mail;
    }

    /**
     * Attach PDF file from path
     */
    public function attachPdfFromPath(string $pdfPath): self
    {
        if (file_exists($pdfPath)) {
            $pdfContent = file_get_contents($pdfPath);
            $filename = basename($pdfPath);

            $this->attachData(
                $pdfContent,
                $filename,
                [
                    'mime' => 'application/pdf',
                ]
            );
        }

        return $this;
    }
}
