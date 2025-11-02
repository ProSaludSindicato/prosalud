<?php

namespace App\Mail;

use App\Models\RequestForm;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RequestFormReceived extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Array to store attachment file data
     */
    private array $attachmentData = [];

    public function __construct(
        public RequestForm $requestForm,
        array $originalFiles = []
    ) {
        foreach ($originalFiles as $file) {
            if ($file && ($file instanceof \Illuminate\Http\UploadedFile) && $file->isValid()) {
                $this->attachmentData[] = [
                    'content' => file_get_contents($file->getRealPath()),
                    'name' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType(),
                ];
            }
        }
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $logoPath = public_path('logo.png');
        $logoCid = file_exists($logoPath) ? $this->embed($logoPath) : '';

        $mail = $this
            ->subject("Confirmación de recepción {$this->requestForm->request_type} – ProSalud")
            ->view('emails.request_form_received')
            ->with([
                'requestForm' => $this->requestForm,
                'logoCid' => $logoCid,
            ]);

        foreach ($this->attachmentData as $attachment) {
            $mail->attachData(
                $attachment['content'],
                $attachment['name'],
                [
                    'mime' => $attachment['mime'],
                ]
            );
        }

        return $mail;
    }
}
