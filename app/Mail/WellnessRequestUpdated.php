<?php

namespace App\Mail;

use App\Models\WellnessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WellnessRequestUpdated extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public WellnessRequest $wellnessRequest,
        public array $changes,
        public ?array $oldDetails = null,
        public ?array $newDetails = null,
        public bool $statusOnly = false,
    ) {
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $view = $this->statusOnly
            ? 'emails.wellness_request_status_changed'
            : 'emails.wellness_request_updated';

        $subject = $this->statusOnly
            ? "Actualización de estado - {$this->wellnessRequest->activity_name}"
            : "Actualización de solicitud de bienestar - {$this->wellnessRequest->activity_name}";

        return $this
            ->subject($subject)
            ->view($view)
            ->with([
                'wellnessRequest' => $this->wellnessRequest,
                'changes' => $this->changes,
                'oldDetails' => $this->oldDetails,
                'newDetails' => $this->newDetails,
            ]);
    }
}
