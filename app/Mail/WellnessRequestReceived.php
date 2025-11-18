<?php

namespace App\Mail;

use App\Models\WellnessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WellnessRequestReceived extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public WellnessRequest $wellnessRequest,
    ) {
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        return $this
            ->subject("Nueva solicitud de bienestar - {$this->wellnessRequest->activity_name}")
            ->view('emails.wellness_request_received')
            ->with([
                'wellnessRequest' => $this->wellnessRequest,
            ]);
    }
}
