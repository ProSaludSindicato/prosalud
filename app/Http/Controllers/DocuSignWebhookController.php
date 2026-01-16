<?php

namespace App\Http\Controllers;

use App\Models\DocusignConvenioFirmado;
use App\Services\{AuditLogService, DocuSignService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Log, Storage};

class DocuSignWebhookController extends Controller
{
    public function __construct(
        private readonly DocuSignService $docuSignService,
        private readonly AuditLogService $auditLogService
    ) {
    }

    /**
     * Handle DocuSign webhook events.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function handle(Request $request): JsonResponse
    {
        $startTime = microtime(true);

        try {
            // Log incoming webhook
            Log::info('DocuSign webhook received', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'headers' => $request->headers->all(),
            ]);

            // Validate HMAC signature
            if (!$this->validateHmacSignature($request)) {
                Log::warning('DocuSign webhook HMAC validation failed', [
                    'ip' => $request->ip(),
                ]);

                $this->auditLogService->logSecurityEvent('docusign_webhook_invalid_signature', [
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'error' => 'Invalid signature',
                ], 401);
            }

            // Parse webhook payload
            // DocuSign can send events in different formats - handle both single event and array of events
            $payload = $request->json()->all();

            Log::info('DocuSign webhook payload received', [
                'event' => $payload['event'] ?? ($payload[0]['event'] ?? 'unknown'),
                'envelope_id' => $payload['data']['envelopeId'] ?? ($payload[0]['data']['envelopeId'] ?? null),
                'payload_structure' => is_array($payload) && isset($payload[0]) ? 'array' : 'single',
            ]);

            // DocuSign can send single event or array of events
            $events = isset($payload[0]) && is_array($payload[0]) ? $payload : [$payload];

            // Process each event
            foreach ($events as $eventPayload) {
                $this->processWebhookEvents($eventPayload);
            }

            $executionTime = (microtime(true) - $startTime) * 1000;

            Log::info('DocuSign webhook processed successfully', [
                'execution_time_ms' => round($executionTime, 2),
            ]);

            // Always return 200 OK within 5 seconds
            return response()->json([
                'status' => 'success',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error processing DocuSign webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip(),
            ]);

            // Always return 200 OK even on error (DocuSign requirement)
            return response()->json([
                'status' => 'error',
                'message' => 'Internal error',
            ], 200);
        }
    }

    /**
     * Validate HMAC signature from DocuSign webhook.
     * 
     * @param Request $request
     * @return bool
     */
    private function validateHmacSignature(Request $request): bool
    {
        $signature = $request->header('X-DocuSign-Signature-1');
        $webhookSecret = config('services.docusign.webhook_secret');

        if (empty($signature) || empty($webhookSecret)) {
            Log::warning('DocuSign webhook missing signature or secret', [
                'has_signature' => !empty($signature),
                'has_secret' => !empty($webhookSecret),
            ]);
            return false;
        }

        // Get raw request body
        $body = $request->getContent();

        // Calculate HMAC
        $calculatedSignature = base64_encode(
            hash_hmac('sha256', $body, $webhookSecret, true)
        );

        // Compare signatures using constant-time comparison
        $isValid = hash_equals($calculatedSignature, $signature);

        if (!$isValid) {
            Log::warning('DocuSign webhook HMAC mismatch', [
                'received' => substr($signature, 0, 20) . '...',
                'calculated' => substr($calculatedSignature, 0, 20) . '...',
            ]);
        }

        return $isValid;
    }

    /**
     * Process webhook events.
     * 
     * @param array $payload
     * @return void
     */
    private function processWebhookEvents(array $payload): void
    {
        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? [];

        if (!$event) {
            Log::warning('DocuSign webhook missing event type', [
                'payload' => $payload,
            ]);
            return;
        }

        switch ($event) {
            case 'recipient-completed':
                $this->handleRecipientCompleted($data);
                break;

            case 'envelope-completed':
                $this->handleEnvelopeCompleted($data);
                break;

            default:
                Log::info('DocuSign webhook unhandled event', [
                    'event' => $event,
                    'envelope_id' => $data['envelopeId'] ?? null,
                ]);
        }
    }

    /**
     * Handle recipient-completed event.
     * 
     * @param array $data
     * @return void
     */
    private function handleRecipientCompleted(array $data): void
    {
        try {
            // DocuSign webhook format can vary - handle both formats
            $envelopeId = $data['envelopeId'] ?? $data['envelope_id'] ?? null;
            $recipientId = $data['recipientId'] ?? $data['recipient_id'] ?? null;
            $email = $data['email'] ?? null;
            $completedDateTime = $data['completedDateTime'] ?? $data['completed_date_time'] ?? null;
            $clientUserId = $data['clientUserId'] ?? $data['client_user_id'] ?? null;

            if (!$envelopeId || !$recipientId || !$email) {
                Log::warning('DocuSign recipient-completed missing required fields', [
                    'data' => $data,
                ]);
                return;
            }

            Log::info('Processing recipient-completed event', [
                'envelope_id' => $envelopeId,
                'recipient_id' => $recipientId,
                'email' => $email,
                'completed_datetime' => $completedDateTime,
                'client_user_id' => $clientUserId,
            ]);

            DB::transaction(function () use ($envelopeId, $recipientId, $email, $completedDateTime, $clientUserId, $data) {
                // Find or create record
                $convenio = DocusignConvenioFirmado::firstOrNew([
                    'envelope_id' => $envelopeId,
                ]);

                // Update recipient information
                $convenio->recipient_id = $recipientId;
                $convenio->recipient_email = $email;
                $convenio->recipient_name = $data['name'] ?? $data['userName'] ?? null;
                
                // Parse completed datetime
                if ($completedDateTime) {
                    try {
                        $convenio->recipient_completed_at = now()->parse($completedDateTime);
                    } catch (\Exception $e) {
                        Log::warning('Error parsing completedDateTime', [
                            'datetime' => $completedDateTime,
                            'error' => $e->getMessage(),
                        ]);
                        $convenio->recipient_completed_at = now();
                    }
                } else {
                    $convenio->recipient_completed_at = now();
                }
                
                $convenio->status = 'recipient_completed';

                // Extract document number from clientUserId (which is the documento)
                if ($clientUserId) {
                    $convenio->document_number = $clientUserId;
                }

                // Store metadata
                $metadata = $convenio->metadata ?? [];
                $metadata['recipient_completed'] = [
                    'recipient_id' => $recipientId,
                    'email' => $email,
                    'completed_datetime' => $completedDateTime,
                    'client_user_id' => $clientUserId,
                    'processed_at' => now()->toISOString(),
                ];
                $convenio->metadata = $metadata;

                $convenio->save();

                Log::info('Recipient completed status updated', [
                    'envelope_id' => $envelopeId,
                    'document_number' => $convenio->document_number,
                ]);
            });

            $this->auditLogService->logBusinessProcess('docusign_convenio', 'recipient_completed', [
                'envelope_id' => $envelopeId,
                'recipient_email' => $email,
            ]);
        } catch (\Exception $e) {
            Log::error('Error handling recipient-completed event', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);
            // Don't throw - webhook must always return 200
        }
    }

    /**
     * Handle envelope-completed event.
     * 
     * @param array $data
     * @return void
     */
    private function handleEnvelopeCompleted(array $data): void
    {
        $convenio = null;
        
        try {
            // DocuSign webhook format can vary - handle both formats
            $envelopeId = $data['envelopeId'] ?? $data['envelope_id'] ?? null;
            $completedDateTime = $data['completedDateTime'] ?? $data['completed_date_time'] ?? null;

            if (!$envelopeId) {
                Log::warning('DocuSign envelope-completed missing envelopeId', [
                    'data' => $data,
                ]);
                return;
            }

            Log::info('Processing envelope-completed event', [
                'envelope_id' => $envelopeId,
                'completed_datetime' => $completedDateTime,
            ]);

            DB::transaction(function () use ($envelopeId, $completedDateTime, $data, &$convenio) {
                // Find existing record
                $convenio = DocusignConvenioFirmado::where('envelope_id', $envelopeId)->first();

                if (!$convenio) {
                    Log::warning('DocuSign envelope-completed: record not found, creating new', [
                        'envelope_id' => $envelopeId,
                    ]);

                    // Create new record if it doesn't exist
                    $convenio = new DocusignConvenioFirmado();
                    $convenio->envelope_id = $envelopeId;
                    $convenio->status = 'pending';
                }

                // Update completion timestamp
                if ($completedDateTime) {
                    try {
                        $convenio->envelope_completed_at = now()->parse($completedDateTime);
                    } catch (\Exception $e) {
                        Log::warning('Error parsing completedDateTime', [
                            'datetime' => $completedDateTime,
                            'error' => $e->getMessage(),
                        ]);
                        $convenio->envelope_completed_at = now();
                    }
                } else {
                    $convenio->envelope_completed_at = now();
                }
                
                $convenio->status = 'completed';

                // Update metadata
                $metadata = $convenio->metadata ?? [];
                $metadata['envelope_completed'] = [
                    'completed_datetime' => $completedDateTime,
                    'processed_at' => now()->toISOString(),
                ];
                $convenio->metadata = $metadata;

                // Download and store the signed PDF
                $this->downloadAndStoreSignedPdf($convenio);

                $convenio->save();

                Log::info('Envelope completed and PDF stored', [
                    'envelope_id' => $envelopeId,
                    'storage_path' => $convenio->storage_path,
                    'document_number' => $convenio->document_number,
                ]);
            });

            $this->auditLogService->logBusinessProcess('docusign_convenio', 'envelope_completed', [
                'envelope_id' => $envelopeId,
                'document_number' => $convenio->document_number ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Error handling envelope-completed event', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);

            // Update record with error status
            if (isset($envelopeId)) {
                try {
                    $convenio = DocusignConvenioFirmado::where('envelope_id', $envelopeId)->first();
                    if ($convenio) {
                        $convenio->status = 'error';
                        $convenio->error_message = $e->getMessage();
                        $convenio->save();
                    }
                } catch (\Exception $updateError) {
                    Log::error('Error updating record with error status', [
                        'error' => $updateError->getMessage(),
                    ]);
                }
            }
            // Don't throw - webhook must always return 200
        }
    }

    /**
     * Download and store the signed PDF from DocuSign.
     * 
     * @param DocusignConvenioFirmado $convenio
     * @return void
     */
    private function downloadAndStoreSignedPdf(DocusignConvenioFirmado $convenio): void
    {
        try {
            // Download combined document (all pages with signatures)
            $pdfContent = $this->docuSignService->downloadDocument($convenio->envelope_id, 'combined');

            if (!$pdfContent) {
                throw new \Exception('Failed to download PDF from DocuSign');
            }

            // Generate storage path: convenios/{year}/{month}/{envelopeId}.pdf
            $year = now()->format('Y');
            $month = now()->format('m');
            $storagePath = "convenios/{$year}/{$month}/{$convenio->envelope_id}.pdf";

            // Store in S3 private bucket (prosalud-private)
            $primaryDisk = 'prosalud-private';
            $fallbackDisk = 'local';

            $stored = Storage::disk($primaryDisk)->put($storagePath, $pdfContent);

            if (!$stored) {
                Log::warning('Failed to store PDF in private bucket, trying fallback', [
                    'envelope_id' => $convenio->envelope_id,
                    'path' => $storagePath,
                ]);
                $stored = Storage::disk($fallbackDisk)->put($storagePath, $pdfContent);
                if ($stored) {
                    $convenio->storage_disk = $fallbackDisk;
                }
            } else {
                $convenio->storage_disk = $primaryDisk;
            }

            if (!$stored) {
                throw new \Exception('Failed to store PDF in storage');
            }

            $convenio->storage_path = $storagePath;

            Log::info('Signed PDF stored successfully', [
                'envelope_id' => $convenio->envelope_id,
                'storage_path' => $storagePath,
                'disk' => $convenio->storage_disk,
                'size_bytes' => strlen($pdfContent),
            ]);
        } catch (\Exception $e) {
            Log::error('Error downloading/storing signed PDF', [
                'envelope_id' => $convenio->envelope_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $convenio->error_message = 'Failed to download/store PDF: ' . $e->getMessage();
            // Don't throw - allow record to be saved with error status
        }
    }
}

