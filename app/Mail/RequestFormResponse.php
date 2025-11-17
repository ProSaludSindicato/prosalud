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
        $fileIndex = 0;
        foreach ($uploadedFiles as $file) {
            if ($file && $file->isValid()) {
                $this->attachmentData[] = [
                    'content' => file_get_contents($file->getRealPath()),
                    'name' => $this->generateDescriptiveFilename($file, $fileIndex),
                    'mime' => $file->getMimeType(),
                ];
                $fileIndex++;
            }
        }
    }

    /**
     * Generate a simple but descriptive filename based on the request context
     * Format: Resp-[RequestID]-[DocSuffix]-[Index].[ext]
     */
    private function generateDescriptiveFilename(\Illuminate\Http\UploadedFile $file, int $index): string
    {
        $extension = $file->getClientOriginalExtension() ?: $this->getExtensionFromMimeType($file->getMimeType());
        
        // Get base information from the request
        $requestId = $this->requestForm->id ?? '';
        $documentNumber = $this->requestForm->document_number ?? '';
        
        // Get last 4 digits of document for identification
        $docSuffix = '';
        if ($documentNumber) {
            $docSuffix = strlen($documentNumber) > 4 ? substr($documentNumber, -4) : $documentNumber;
        }
        
        // Generate short unique identifier
        $uniqueId = substr(\Illuminate\Support\Str::uuid()->toString(), 0, 6);
        
        // Build simple filename: Resp-[RequestID]-[DocSuffix]-[UniqueId]-[Index].[ext]
        $parts = ['Resp', $requestId];
        
        if ($docSuffix) {
            $parts[] = $docSuffix;
        }
        
        $parts[] = $uniqueId;
        
        // Add index if multiple files
        if ($index > 0 || count($this->attachmentData) > 1) {
            $parts[] = ($index + 1);
        }
        
        $baseName = implode('-', $parts);
        
        return $baseName . '.' . $extension;
    }

    /**
     * Get file extension from MIME type
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
        ];

        return $mimeToExt[$mimeType] ?? 'bin';
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
