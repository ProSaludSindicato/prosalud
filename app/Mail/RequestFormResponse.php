<?php

namespace App\Mail;

use App\Models\RequestForm;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RequestFormResponse extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Array to store attachment file paths and names
     * We store the file data here instead of UploadedFile objects to avoid serialization issues
     */
    private array $attachmentData = [];

    /**
     * Create a new message instance.
     */
    public function __construct(
        public RequestForm $requestForm,
        public string $emailSubject,
        public string $emailBody,
        public string $status,
        array $uploadedFiles = []
    ) {
        foreach ($uploadedFiles as $file) {
            if ($file && $file->isValid()) {
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
            ->subject($this->emailSubject)
            ->view('emails.request_form_response')
            ->with([
                'requestForm' => $this->requestForm,
                'emailBody' => $this->emailBody,
                'status' => $this->status,
                'logoCid' => $logoCid,
            ]);

        // Attach files if provided (using stored file data)
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
