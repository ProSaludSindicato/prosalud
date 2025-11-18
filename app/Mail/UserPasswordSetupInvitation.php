<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserPasswordSetupInvitation extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public string $token,
        public string $frontendUrl,
    ) {
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $logoPath = public_path('assets/logo.png');
        $logoCid = '';

        // Intentar incrustar el logo si existe (similar a AfiliadoOtpCode)
        if (file_exists($logoPath)) {
            try {
                $logoContent = file_get_contents($logoPath);
                $logoCid = $this->embedData($logoContent, 'logo.png', 'image/png');
            } catch (\Exception $e) {
                $logoCid = '';
            }
        }

        return $this
            ->subject('Configura tu contraseña - ProSalud')
            ->view('emails.user_password_setup_invitation')
            ->with([
                'user' => $this->user,
                'frontendUrl' => $this->frontendUrl,
                'logoCid' => $logoCid,
            ]);
    }
}
