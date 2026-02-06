<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Address;
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
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                'auxiliar.talento@sindicatoprosalud.com',
                'Sindicato ProSalud'
            ),
            cc: [
                new Address(
                    'auxiliar.talento@sindicatoprosalud.com',
                    'Sindicato ProSalud'
                ),
            ],
            replyTo: [
                new Address(
                    'auxiliar.talento@sindicatoprosalud.com',
                    'Sindicato ProSalud'
                ),
            ],
            subject: $this->nombreArchivo,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // Logo will be handled via URL in the view template
        // When using Content() instead of build(), embedded images need different handling
        $logoCid = '';

        return new Content(
            view: 'emails.convenio_firma_notification',
            with: [
                'nombreAfiliado' => $this->nombreAfiliado,
                'nombreArchivo' => $this->nombreArchivo,
                'logoCid' => $logoCid,
            ],
        );
    }
}

