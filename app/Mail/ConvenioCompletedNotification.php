<?php

namespace App\Mail;

use App\Support\ConvenioEmailSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ConvenioCompletedNotification extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $nombreAfiliado,
        public string $documento,
        public string $nombreConvenio,
        public bool $isTest = false,
    ) {}

    public function build(): self
    {
        $logoPath = public_path('assets/logo.png');
        $logoCid = '';

        if (file_exists($logoPath)) {
            try {
                $logoContent = file_get_contents($logoPath);
                $logoCid = $this->embedData($logoContent, 'logo.png', 'image/png');
            } catch (\Exception) {
                $logoCid = '';
            }
        }

        $subject = ConvenioEmailSubject::makeCompleted(
            $this->documento,
            $this->nombreConvenio,
            $this->isTest,
            now(),
        );

        return $this
            ->subject($subject)
            ->replyTo('auxiliartalento.sprosalud@gmail.com')
            ->view('emails.convenio_completed_notification')
            ->with([
                'nombreAfiliado' => $this->nombreAfiliado,
                'documento' => $this->documento,
                'nombreConvenio' => $this->nombreConvenio,
                'logoCid' => $logoCid,
                'isTest' => $this->isTest,
            ]);
    }

    public function attachPdfFromContents(string $pdfContents, string $filename): self
    {
        $this->attachData(
            $pdfContents,
            $filename,
            [
                'mime' => 'application/pdf',
            ],
        );

        return $this;
    }
}
