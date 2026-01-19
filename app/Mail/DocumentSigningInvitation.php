<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DocumentSigningInvitation extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $nombreAfiliado,
        public string $documento,
        public string $signingUrl,
        public string $emailSubject = 'Firma de Convenio de Afiliación',
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
            ->subject($this->emailSubject)
            ->view('emails.document_signing_invitation')
            ->with([
                'nombreAfiliado' => $this->nombreAfiliado,
                'documento' => $this->documento,
                'signingUrl' => $this->signingUrl,
                'logoCid' => $logoCid,
            ]);
    }
}
