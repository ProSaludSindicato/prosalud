<?php

namespace App\Mail;

use App\Models\HospitalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class HospitalRequestStatusUpdated extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public HospitalRequest $hospitalRequest,
        public string $previousStatus,
        public string $newStatus,
        /**
         * @var array<string, string>
         */
        public array $statusLabels = [
            'pending' => 'Pendiente',
            'approved' => 'Aprobada',
            'preparing' => 'Preparando',
            'delivered' => 'Entregada',
            'rejected' => 'Rechazada',
        ],
        /**
         * @var array<string, array{bg:string,text:string,border:string}>
         */
        public array $statusStyles = [
            'pending' => ['bg' => '#FEF3C7', 'text' => '#92400E', 'border' => '#FDE68A'],
            'approved' => ['bg' => '#D1FAE5', 'text' => '#065F46', 'border' => '#A7F3D0'],
            'preparing' => ['bg' => '#E0E7FF', 'text' => '#3730A3', 'border' => '#C7D2FE'],
            'delivered' => ['bg' => '#CFFAFE', 'text' => '#155E75', 'border' => '#A5F3FC'],
            'rejected' => ['bg' => '#FEE2E2', 'text' => '#B91C1C', 'border' => '#FECACA'],
        ],
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject("Actualización solicitud hospitalaria #{$this->hospitalRequest->id}")
            ->view('emails.hospital_request_status_updated')
            ->with([
                'hospitalRequest' => $this->hospitalRequest,
                'previousStatus' => $this->previousStatus,
                'newStatus' => $this->newStatus,
                'statusLabels' => $this->statusLabels,
                'statusStyles' => $this->statusStyles,
            ]);
    }
}

