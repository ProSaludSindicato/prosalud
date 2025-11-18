<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AfiliadoOtpCode extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $otpCode,
        public string $nombreAfiliado,
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
                // Use embedData with file content for Laravel 12 compatibility
                $logoContent = file_get_contents($logoPath);
                $logoCid = $this->embedData($logoContent, 'logo.png', 'image/png');
            } catch (\Exception $e) {
                // If embedding fails, logo will be empty and template will handle it
                $logoCid = '';
            }
        }

        return $this
            ->subject('Código de verificación - ProSalud')
            ->view('emails.afiliado_otp_code')
            ->with([
                'otpCode' => $this->otpCode,
                'nombreAfiliado' => $this->nombreAfiliado,
                'logoCid' => $logoCid,
            ]);
    }
}
