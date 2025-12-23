<?php

namespace App\Mail;

use App\Models\RequestForm;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\{Log, Storage};

class RequestFormReceived extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * Array to store attachment file data.
     */
    private array $attachmentData = [];

    /**
     * Track used names to avoid duplicates.
     */
    private array $usedNames = [];

    /**
     * Track original file names from multipart to avoid duplicates from storage.
     */
    private array $multipartFileNames = [];

    public function __construct(
        public RequestForm $requestForm,
        array $originalFiles = [],
    ) {
        // Add multipart files from request first
        foreach ($originalFiles as $file) {
            if ($file && ($file instanceof \Illuminate\Http\UploadedFile) && $file->isValid()) {
                $originalName = $file->getClientOriginalName();
                $this->multipartFileNames[] = $originalName;
                $uniqueName = $this->makeUniqueName($originalName);
                $this->attachmentData[] = [
                    'content' => file_get_contents($file->getRealPath()),
                    'name' => $uniqueName,
                    'mime' => $file->getMimeType(),
                ];
            }
        }

        // Also load files from storage (for base64 files that were stored)
        // Only load files that weren't already added from multipart
        $this->loadFilesFromStorage();
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $logoPath = public_path('logo.png');
        $logoCid = file_exists($logoPath) ? $this->embed($logoPath) : '';

        $mail = $this
            ->subject("Confirmación de recepción {$this->requestForm->translated_request_type} – ProSalud")
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

    /**
     * Load files from storage based on files metadata.
     */
    private function loadFilesFromStorage(): void
    {
        $filesMetadata = $this->requestForm->files ?? [];

        if (empty($filesMetadata)) {
            return;
        }

        // File keys that should be attached for update data requests
        $updateDataFileKeys = [
            'certificacionBancaria',
            'diplomaEducativo',
            'actaGrado',
            'certificadoEps',
            'certificadoAfp',
        ];

        foreach ($filesMetadata as $key => $fileMetadata) {
            // Only attach update data files for actualizar-datos-personales requests
            // For other request types, attach all files
            if ('actualizar-datos-personales' === $this->requestForm->request_type) {
                if (!in_array($key, $updateDataFileKeys)) {
                    continue;
                }
            }

            $path = $fileMetadata['path'] ?? null;
            $disk = $fileMetadata['disk'] ?? 'prosalud-private';

            if (!$path) {
                continue;
            }

            try {
                if (!Storage::disk($disk)->exists($path)) {
                    Log::warning('File not found in storage for email attachment', [
                        'request_id' => $this->requestForm->id,
                        'file_key' => $key,
                        'path' => $path,
                        'disk' => $disk,
                    ]);
                    continue;
                }

                $fileContent = Storage::disk($disk)->get($path);
                $originalName = $fileMetadata['original_name'] ?? $fileMetadata['original_key'] ?? $key;
                $mimeType = $fileMetadata['mime_type'] ?? 'application/octet-stream';

                // Ensure original name has extension if missing
                $normalizedName = $originalName;
                if (!pathinfo($normalizedName, PATHINFO_EXTENSION)) {
                    $extension = $this->getExtensionFromMimeType($mimeType);
                    if ($extension) {
                        $normalizedName = $originalName . '.' . $extension;
                    }
                }

                // Skip if this file was already added from multipart (check both original and normalized names)
                if (in_array($normalizedName, $this->multipartFileNames) || in_array($originalName, $this->multipartFileNames)) {
                    continue;
                }

                $originalName = $normalizedName;

                // Create a unique name for the attachment to avoid conflicts
                $uniqueName = $this->makeUniqueName($originalName);

                $this->attachmentData[] = [
                    'content' => $fileContent,
                    'name' => $uniqueName,
                    'mime' => $mimeType,
                ];
            } catch (\Exception $e) {
                Log::error('Error loading file from storage for email attachment', [
                    'request_id' => $this->requestForm->id,
                    'file_key' => $key,
                    'path' => $path,
                    'disk' => $disk,
                    'error' => $e->getMessage(),
                ]);
            }
        }
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
        ];

        return $mimeToExt[$mimeType] ?? '';
    }

    /**
     * Make a unique filename to avoid duplicate attachment names
     * If the name already exists, adds a numeric suffix (1), (2), etc.
     */
    private function makeUniqueName(string $originalName): string
    {
        // If name hasn't been used, use it as-is
        if (!in_array($originalName, $this->usedNames)) {
            $this->usedNames[] = $originalName;

            return $originalName;
        }

        // Name is duplicate, create a unique version with numeric suffix
        $pathInfo = pathinfo($originalName);
        $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
        $baseName = $pathInfo['filename'];

        // Use counter to create unique name
        $counter = 1;
        do {
            $uniqueName = $baseName . ' (' . $counter . ')' . $extension;
            ++$counter;
        } while (in_array($uniqueName, $this->usedNames));

        $this->usedNames[] = $uniqueName;

        return $uniqueName;
    }
}
