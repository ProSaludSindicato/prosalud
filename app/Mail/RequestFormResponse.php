<?php

namespace App\Mail;

use App\Models\RequestForm;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RequestFormResponse extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * Array to store attachment file paths and names
     * We store the file data here instead of UploadedFile objects to avoid serialization issues.
     */
    private array $attachmentData = [];

    /**
     * Array to store compressed file download URLs (zip, rar)
     * Format: ['name' => string, 'url' => string, 'expires_at' => string]
     */
    public array $compressedFileUrls = [];

    /**
     * Create a new message instance.
     */
    public function __construct(
        public RequestForm $requestForm,
        public string $emailSubject,
        public string $emailBody,
        public string $status,
        array $uploadedFiles = [],
        array $compressedFileUrls = [],
    ) {
        $fileIndex = 0;
        $compressedExtensions = ['zip', 'rar'];
        
        foreach ($uploadedFiles as $file) {
            // Check if it's already serialized data (from Job)
            if (is_array($file) && isset($file['content']) && isset($file['name']) && isset($file['mime'])) {
                // Already serialized data, use directly
                $this->attachmentData[] = $file;
                ++$fileIndex;
                continue;
            }
            
            // Otherwise, it's an UploadedFile object
            if ($file && $file->isValid()) {
                $extension = strtolower($file->getClientOriginalExtension() ?? '');
                
                // Skip compressed files - they are handled separately via URLs
                if (in_array($extension, $compressedExtensions)) {
                    continue;
                }
                
                // Usar siempre el nombre original del archivo
                $originalName = $file->getClientOriginalName();
                $filename = $originalName;
                
                $this->attachmentData[] = [
                    'content' => file_get_contents($file->getRealPath()),
                    'name' => $filename,
                    'mime' => $file->getMimeType(),
                ];
                ++$fileIndex;
            }
        }
        
        // Store compressed file URLs
        $this->compressedFileUrls = $compressedFileUrls;
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
                'compressedFileUrls' => $this->compressedFileUrls,
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

    /**
     * Get file extension from MIME type.
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'application/x-rar' => 'rar',
        ];

        return $mimeToExt[$mimeType] ?? 'bin';
    }
}
