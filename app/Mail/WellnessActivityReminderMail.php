<?php

namespace App\Mail;

use App\Models\WellnessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Address, Content, Envelope};
use Illuminate\Queue\SerializesModels;

class WellnessActivityReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public WellnessRequest $wellnessRequest
    ) {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('noreply@sindicatoprosalud.com', 'Sindicato ProSalud'),
            subject: 'Recordatorio: Cargar información de actividad de bienestar realizada',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.wellness_activity_reminder',
            with: [
                'wellnessRequest' => $this->wellnessRequest,
                'requesterName' => $this->wellnessRequest->requester?->name ?? 'Usuario',
                'activityName' => $this->wellnessRequest->activity_name,
                'proposedDate' => $this->wellnessRequest->proposed_date->format('d/m/Y'),
                'locations' => $this->wellnessRequest->locations_text,
                'participantCount' => $this->wellnessRequest->participant_count,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
