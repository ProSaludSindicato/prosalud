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
            if ($file && $file->isValid()) {
                $extension = strtolower($file->getClientOriginalExtension() ?? '');
                
                // Skip compressed files - they are handled separately via URLs
                if (in_array($extension, $compressedExtensions)) {
                    continue;
                }
                
                // Si el nombre original ya es descriptivo (como Certificado_Sindicato_ProSalud_*), usarlo directamente
                $originalName = $file->getClientOriginalName();
                $filename = $this->shouldUseOriginalName($originalName) 
                    ? $originalName 
                    : $this->generateDescriptiveFilename($file, $fileIndex);
                
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
     * Generate a simple but descriptive filename based on the request context
     * Format: Resp-[RequestID]-[DocSuffix]-[Index].[ext].
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
     * Determina si debe usar el nombre original del archivo
     * Usa el nombre original si ya tiene un formato descriptivo (ej: Certificado_Sindicato_ProSalud_*)
     */
    private function shouldUseOriginalName(string $originalName): bool
    {
        // Si el nombre comienza con "Certificado_Sindicato_ProSalud_", usar el nombre original
        return str_starts_with($originalName, 'Certificado_Sindicato_ProSalud_');
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
