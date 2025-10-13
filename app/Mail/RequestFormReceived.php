<?php

namespace App\Mail;

use App\Models\RequestForm;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RequestFormReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public RequestForm $requestForm)
    {
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $logoPath = public_path('logo.png');
        $logoCid = file_exists($logoPath) ? $this->embed($logoPath) : '';

        return $this
            ->subject("Confirmación de recepción {$this->requestForm->request_type} – ProSalud")
            ->view('emails.request_form_received')
            ->with([
                'requestForm' => $this->requestForm,
                'logoCid' => $logoCid,
            ]);
    }
}
