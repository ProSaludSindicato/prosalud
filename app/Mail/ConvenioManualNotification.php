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
    ) {}

    /**
     * Build the message.
     */
    public function build(): self
    {
        $logoPath = public_path('assets/logo.png');
        $logoCid = '';

        // Try to embed logo if it exists
        if (file_exists($logoPath)) {
            try {
                $logoContent = file_get_contents($logoPath);
                $logoCid = $this->embedData($logoContent, 'logo.png', 'image/png');
            } catch (\Exception $e) {
                $logoCid = '';
            }
        }

        $subject = "Convenio {$this->documento} ({$this->nombreConvenio}) - ProSalud";

        return $this
            ->subject($subject)
            ->cc('sprosalud.auxiliar@gmail.com')
            ->replyTo('sprosalud.auxiliar@gmail.com')
            ->view('emails.convenio_manual_notification')
            ->with([
                'nombreAfiliado' => $this->nombreAfiliado,
                'documento' => $this->documento,
                'nombreConvenio' => $this->nombreConvenio,
                'logoCid' => $logoCid,
                'signingUrl' => $this->signingUrl,
            ]);
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
