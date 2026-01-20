<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ConvenioFirmaNotification extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $nombreAfiliado,
        public string $nombreArchivo,
    ) {
    }

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

        return $this
            ->subject($this->nombreArchivo)
            ->replyTo('auxiliar.talento@sindicatoprosalud.com', 'Sindicato ProSalud')
            ->view('emails.convenio_firma_notification')
            ->with([
                'nombreAfiliado' => $this->nombreAfiliado,
                'nombreArchivo' => $this->nombreArchivo,
                'logoCid' => $logoCid,
            ])
            ->withSymfonyMessage(function ($message) {
                $message->getHeaders()->addTextHeader('Reply-To', 'auxiliar.talento@sindicatoprosalud.com');
            });
    }
}

