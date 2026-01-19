<?php

namespace App\Services;

use App\Contracts\DocumentSigningServiceInterface;
use App\Models\DocumentSigningEmailTracking;
use DocuSign\eSign\Api\EnvelopesApi;
use DocuSign\eSign\Client\ApiClient;
use DocuSign\eSign\Client\Auth\OAuth;
use DocuSign\eSign\Model\Document;
use DocuSign\eSign\Model\EnvelopeDefinition;
use DocuSign\eSign\Model\EnvelopeSummary;
use DocuSign\eSign\Model\Expirations;
use DocuSign\eSign\Model\Notification;
use DocuSign\eSign\Model\RecipientViewRequest;
use DocuSign\eSign\Model\Reminders;
use DocuSign\eSign\Model\Signer;
use DocuSign\eSign\Model\SignHere;
use DocuSign\eSign\Model\Tabs;
use DocuSign\eSign\Model\Text;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DocuSignService implements DocumentSigningServiceInterface
{
    /**
     * Cached account ID obtained from user info
     */
    private ?string $accountId = null;

    /**
     * Get authenticated DocuSign API client using JWT authentication.
     * Also obtains and caches the account ID dynamically.
     */
    public function getApiClient(): ApiClient
    {
        // Create OAuth object and set base path
        $oAuth = new OAuth();
        $oAuth->setOAuthBasePath(config('services.docusign.auth_server'));

        // Create ApiClient with OAuth configuration
        $apiClient = new ApiClient(null, $oAuth);

        $privateKey = config('services.docusign.private_key');

        if (empty($privateKey)) {
            throw new \Exception("DocuSign private key is not configured. Please set DOCUSIGN_PRIVATE_KEY environment variable.");
        }

        try {
            // Request JWT token - this automatically sets the Authorization header
            $token = $apiClient->requestJWTUserToken(
                config('services.docusign.integration_key'),
                config('services.docusign.user_id'),
                $privateKey,
                ['signature'],
                60 // Max 60 minutes
            );

            // Get access token from response
            $accessToken = $token[0]->getAccessToken();

            // Get user info to obtain account ID and base URI dynamically
            // getUserInfo returns [UserInfo, statusCode, httpHeader]
            list($userInfo, $statusCode, $httpHeader) = $apiClient->getUserInfo($accessToken);

            // Get accounts from user info object
            $accounts = $userInfo->getAccounts() ?? [];

            if (empty($accounts)) {
                throw new \Exception('No DocuSign accounts found for this user');
            }

            // Find the default account
            $defaultAccount = collect($accounts)->first(function ($account) {
                return method_exists($account, 'getIsDefault') && $account->getIsDefault() === true;
            });

            if (!$defaultAccount) {
                // If no default account, use the first one
                $defaultAccount = $accounts[0];
            }

            // Store account ID
            $this->accountId = $defaultAccount->getAccountId();
            $baseUri = method_exists($defaultAccount, 'getBaseUri') ? $defaultAccount->getBaseUri() : '';

            // Set the API base path using the base_uri from user info
            if (!empty($baseUri)) {
                $apiClient->getConfig()->setHost($baseUri . '/restapi');
            } else {
                // Fallback to configured base path
                $apiClient->getConfig()->setHost(config('services.docusign.base_path'));
            }

            Log::info('DocuSign authentication successful', [
                'account_id' => $this->accountId,
                'base_path' => $apiClient->getConfig()->getHost(),
            ]);

            return $apiClient;
        } catch (\Exception $e) {
            Log::error('DocuSign JWT authentication failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Failed to authenticate with DocuSign: ' . $e->getMessage());
        }
    }

    /**
     * Get the account ID (obtained dynamically from user info).
     */
    public function getAccountId(): string
    {
        if ($this->accountId === null) {
            // Force initialization by getting the API client
            $this->getApiClient();
        }

        if ($this->accountId === null) {
            throw new \Exception('Account ID not available. Authentication may have failed.');
        }

        return $this->accountId;
    }

    /**
     * Create an envelope with a PDF document for embedded signing.
     *
     * @param string $pdfPath Path to the PDF file (can be storage path or absolute path)
     * @param array $signer Signer information: ['email' => string, 'name' => string, 'documento' => string]
     * @param string $emailSubject Subject for the signing email
     * @param string $documentName Name of the document
     * @param bool $sendEmail If true, envelope status will be 'sent' and email will be sent automatically
     * @return array ['envelope_id' => string, 'document_id' => string]
     */
    public function createEnvelope(
        string $pdfPath,
        array $signer,
        string $emailSubject = 'Firma de documento',
        string $documentName = 'Documento',
        bool $sendEmail = false
    ): array {
        $apiClient = $this->getApiClient();
        $envelopeApi = new EnvelopesApi($apiClient);

        // Read PDF file
        if (Storage::exists($pdfPath)) {
            $contentBytes = base64_encode(Storage::get($pdfPath));
        } elseif (file_exists($pdfPath)) {
            $contentBytes = base64_encode(file_get_contents($pdfPath));
        } else {
            throw new \Exception("PDF file not found: {$pdfPath}");
        }

        // Create document
        $document = new Document([
            'document_base64' => $contentBytes,
            'name' => $documentName,
            'file_extension' => 'pdf',
            'document_id' => '1',
        ]);

        // Signature position using anchor string (anchored to "NOMBRE:" text)
        $signHereTab = new SignHere([
            'anchor_string' => 'NOMBRE:',
            'anchor_units' => 'pixels',
            'anchor_x_offset' => '100',
            'anchor_y_offset' => '-15', // SUBE la firma sobre la línea
        ]);

        // Text fields configuration
        // Using fixed coordinates - DocuSign uses bottom-left as origin (0,0)
        // The coordinates provided appear to be from top-left, so we need to convert
        // For a standard letter size PDF (612x792 pixels at 72 DPI):
        // y_from_bottom = page_height - y_from_top - field_height
        // However, if the document has different dimensions, we may need to adjust
        // Using a more conservative conversion that accounts for potential page size variations
        $pageHeight = 792; // Standard letter size height in pixels at 72 DPI
        
        $textFields = [
            // Campo 1: Lugar de nacimiento (anclado a "LUGAR Y FECHA DE NACIMIENTO:")
            [
                'label' => 'Lugar de nacimiento',
                'page' => 1,
                'use_anchor' => true,
                'anchor_string' => 'LUGAR Y FECHA DE NACIMIENTO:',
                'anchor_x_offset' => '180', // A la derecha del texto
                'anchor_y_offset' => '-5', // Misma línea
                'width' => 110,
                'height' => 18,
            ],
            // Campo 2: Fecha de nacimiento (comienza justo al final del campo anterior)
            [
                'label' => 'Fecha de nacimiento',
                'page' => 1,
                'use_anchor' => true,
                'anchor_string' => 'LUGAR Y FECHA DE NACIMIENTO:',
                'anchor_x_offset' => '300', // Después del primer campo (10 + 200 + 10 de espacio)
                'anchor_y_offset' => '-5', // Misma línea
                'width' => 100,
                'height' => 18,
            ],
            // Campo 3: Domicilio (anclado a "domiciliado(a) y residente en ")
            [
                'label' => 'Domicilio',
                'page' => 1,
                'use_anchor' => true,
                'anchor_string' => 'domiciliado(a) y residente en ',
                'anchor_x_offset' => '110', // 2mm aprox = 6 píxeles a 72 DPI (2mm / 25.4mm * 72px)
                'anchor_y_offset' => '-1', // Misma línea
                'width' => 120,
                'height' => 10,
            ],
            // Campo 4: Lugar expedición (anclado a ", quien obra por su propio nombre")
            [
                'label' => 'Lugar expedición',
                'page' => 1,
                'use_anchor' => true,
                'anchor_string' => ', quien obra por su propio nombre',
                'anchor_x_offset' => '-80', // 2mm aprox = 6 píxeles a la izquierda (negativo)
                'anchor_y_offset' => '-1', // Misma línea
                'width' => 80,
                'height' => 10,
            ],
            // Campo 5: Teléfono (anclado a ", TELEFONO" - a la izquierda)
            [
                'label' => 'Direccion',
                'page' => 1,
                'use_anchor' => true,
                'anchor_string' => ', TELEFONO',
                'anchor_x_offset' => '-210', // A la izquierda del texto (negativo)
                'anchor_y_offset' => '-1', // Misma línea
                'width' => 180,
                'height' => 18,
            ],
            // Campo 6: Teléfono 2 (anclado a ", TELEFONO" - a la derecha)
            [
                'label' => 'Teléfono',
                'page' => 1,
                'use_anchor' => true,
                'anchor_string' => ', TELEFONO',
                'anchor_x_offset' => '55', // A la derecha del texto
                'anchor_y_offset' => '-1', // Misma línea
                'width' => 100,
                'height' => 10,
            ],
            // Campo 7: Celular (anclado a ", CELULAR" - a la derecha)
            [
                'label' => 'Celular',
                'page' => 1,
                'use_anchor' => true,
                'anchor_string' => ', CELULAR',
                'anchor_x_offset' => '60', // A la derecha del texto
                'anchor_y_offset' => '-1', // Misma línea
                'width' => 100,
                'height' => 10,
            ],
            // Campo 8: de cedula (anclado a "NOMBRE: " - a la derecha y 3 líneas abajo)
            [
                'label' => 'de cedula',
                'page' => 2,
                'use_anchor' => true,
                'anchor_string' => 'NOMBRE: ',
                'anchor_x_offset' => '70', // A la derecha del texto
                'anchor_y_offset' => '15', // Aprox 3 líneas abajo (3 líneas × 15px ≈ 45px)
                'width' => 80,
                'height' => 10,
            ],
        ];

        // Get afiliado information for prefilling fields
        $afiliado = $signer['afiliado'] ?? [];

        // Map tab_label to afiliado field keys for prefilling
        $fieldMapping = [
            'Lugar de nacimiento' => 'lugar_nacimiento',
            'Fecha de nacimiento' => 'fecha_nacimiento', // Will be formatted to DD/MM/AAAA
            'Domicilio' => 'direccion',
            'Direccion' => 'direccion',
            'Teléfono' => 'telefono',
            'Celular' => 'celular',
            // 'Lugar expedición' => 'lugar_expedicion', // Not available in afiliado file
            // 'de cedula' => '', // Not needed as it's usually derived from documento
        ];

        // Helper function to get prefilled value
        $getPrefilledValue = function($fieldLabel) use ($afiliado, $fieldMapping) {
            if (!isset($fieldMapping[$fieldLabel])) {
                return null;
            }

            $afiliadoKey = $fieldMapping[$fieldLabel];
            $value = $afiliado[$afiliadoKey] ?? '';

            // Format fecha_nacimiento to DD/MM/AAAA if it's a date field
            if ($afiliadoKey === 'fecha_nacimiento' && !empty($value)) {
                try {
                    // Try to parse and format the date
                    $date = \DateTime::createFromFormat('Y-m-d', $value);
                    if ($date) {
                        return $date->format('d/m/Y');
                    }
                    // If already in DD/MM/AAAA format, return as is
                    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
                        return $value;
                    }
                } catch (\Exception $e) {
                    // If parsing fails, return original value
                }
            }

            return !empty($value) ? $value : null;
        };

        // Create TextTabs for each field
        // Support both anchor-based positioning and fixed coordinates
        $textTabs = [];
        foreach ($textFields as $field) {
            $textTabConfig = [
                'document_id' => '1',
                'page_number' => (string)$field['page'],
                'width' => (string)$field['width'],
                'height' => (string)$field['height'],
                'tab_label' => $field['label'],
                'required' => 'true', // Make fields required
            ];

            // Add prefilled value if available
            $prefilledValue = $getPrefilledValue($field['label']);
            if ($prefilledValue !== null) {
                $textTabConfig['value'] = $prefilledValue;
                $textTabConfig['locked'] = 'false'; // Allow editing if needed
            }
            
            // Use anchor string if specified, otherwise use fixed coordinates
            if (isset($field['use_anchor']) && $field['use_anchor'] === true) {
                $textTabConfig['anchor_string'] = $field['anchor_string'];
                $textTabConfig['anchor_units'] = 'pixels';
                $textTabConfig['anchor_x_offset'] = $field['anchor_x_offset'];
                $textTabConfig['anchor_y_offset'] = $field['anchor_y_offset'];
            } else {
                // Convert Y from top to bottom: y_bottom = page_height - y_top - height
                $yFromBottom = $pageHeight - $field['y_from_top'] - $field['height'];
                $textTabConfig['x_position'] = (string)$field['x'];
                $textTabConfig['y_position'] = (string)$yFromBottom;
            }
            
            $textTab = new Text($textTabConfig);
            $textTabs[] = $textTab;
        }

        // Create tabs object with both SignHere and TextTabs
        $tabs = new Tabs([
            'sign_here_tabs' => [$signHereTab],
            'text_tabs' => $textTabs,
        ]);

        // Create signer with documento as clientUserId
        // Note: Using clientUserId makes this an "embedded signer"
        // DocuSign account settings can suppress emails to embedded signers
        $signerModel = new Signer([
            'email' => $signer['email'],
            'name' => $signer['name'],
            'recipient_id' => '1',
            'client_user_id' => $signer['documento'], // Use documento as clientUserId for embedded signing
            // Note: If account has "Suppress emails to embedded signers" enabled,
            // DocuSign won't send email automatically even with status='sent'
        ]);

        // Set tabs using setTabs method
        $signerModel->setTabs($tabs);

        // Create envelope definition
        // DocuSign requires status='sent' to generate embedded signing URLs, even with clientUserId
        // When using clientUserId, DocuSign treats the signer as "embedded" and relies on account settings
        // Account setting "Suppress emails to embedded signers" prevents automatic email sending
        $envelopeDefinition = new EnvelopeDefinition([
            'email_subject' => $emailSubject,
            'documents' => [$document],
            'recipients' => [
                'signers' => [$signerModel],
            ],
            'status' => 'sent', // Always 'sent' - required for embedded signing URL generation
        ]);

        try {
            // Use dynamically obtained account ID
            $accountId = $this->getAccountId();

            $envelopeSummary = $envelopeApi->createEnvelope(
                $accountId,
                $envelopeDefinition
            );

            $envelopeId = $envelopeSummary->getEnvelopeId();

            Log::info('DocuSign envelope created', [
                'envelope_id' => $envelopeId,
                'signer_email' => $signer['email'],
                'status' => $envelopeSummary->getStatus(),
                'sendEmail' => $sendEmail,
            ]);

            // Register email tracking when envelope is created with status 'sent'
            // Note: When sendEmail=false, tracking will be created manually in the job
            if ($sendEmail) {
                $this->registerEmailTracking($envelopeId, $signer);
            }

            return [
                'envelope_id' => $envelopeId,
                'document_id' => '1', // DocuSign uses document_id '1' for the main document
            ];
        } catch (\Exception $e) {
            Log::error('DocuSign envelope creation failed', [
                'error' => $e->getMessage(),
                'signer_email' => $signer['email'] ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Failed to create DocuSign envelope: ' . $e->getMessage());
        }
    }

    /**
     * Generate embedded signing URL for a recipient.
     *
     * @param string $envelopeId The envelope ID
     * @param array $signer Signer information: ['email' => string, 'name' => string, 'documento' => string]
     * @param string $returnUrl URL to redirect after signing
     * @return string The signing URL
     */
    public function getSigningUrl(
        string $envelopeId,
        array $signer,
        string $returnUrl
    ): string {
        $apiClient = $this->getApiClient();
        $envelopeApi = new EnvelopesApi($apiClient);
        $accountId = $this->getAccountId();

        // DocuSign requires envelope to be in 'sent' status to generate embedded signing URL
        // With clientUserId, DocuSign treats this as an embedded signer and relies on account settings
        // to suppress automatic email sending
        $viewRequest = new RecipientViewRequest([
            'authentication_method' => 'none',
            'client_user_id' => $signer['documento'], // Use documento as clientUserId
            'recipient_id' => '1',
            'return_url' => $returnUrl,
            'user_name' => $signer['name'],
            'email' => $signer['email'],
        ]);

        try {
            $view = $envelopeApi->createRecipientView(
                $accountId,
                $envelopeId,
                $viewRequest
            );

            Log::info('DocuSign signing URL generated', [
                'envelope_id' => $envelopeId,
                'signer_email' => $signer['email'],
            ]);

            return $view->getUrl();
        } catch (\Exception $e) {
            Log::error('DocuSign signing URL generation failed', [
                'error' => $e->getMessage(),
                'envelope_id' => $envelopeId,
                'signer_email' => $signer['email'] ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Failed to generate DocuSign signing URL: ' . $e->getMessage());
        }
    }

    /**
     * Create envelope and get signing URL in one call.
     * Note: When status is 'sent', DocuSign automatically sends the email and no URL is returned.
     *
     * @param string $pdfPath Path to the PDF file
     * @param array $signer Signer information: ['email' => string, 'name' => string, 'documento' => string]
     * @param string $returnUrl URL to redirect after signing
     * @param string $emailSubject Subject for the signing email
     * @param string $documentName Name of the document
     * @param bool $sendEmail If true, envelope status will be 'sent' and email will be sent automatically
     * @return array ['envelope_id' => string, 'signing_url' => string|null]
     */
    public function createEnvelopeAndGetSigningUrl(
        string $pdfPath,
        array $signer,
        string $returnUrl,
        string $emailSubject = 'Firma de documento',
        string $documentName = 'Documento',
        bool $sendEmail = false
    ): array {
        // Create envelope with status='sent' (required for embedded signing URL generation)
        // When using clientUserId, DocuSign treats the signer as "embedded"
        // Account setting "Suppress emails to embedded signers" prevents automatic email sending
        // So we can use status='sent' for both cases (sendEmail=true and sendEmail=false)
        $envelopeResult = $this->createEnvelope(
            $pdfPath,
            $signer,
            $emailSubject,
            $documentName,
            $sendEmail
        );

        $envelopeId = $envelopeResult['envelope_id'];

        // If sendEmail is true, DocuSign sends the email automatically, so no URL needed
        if ($sendEmail) {
            return [
                'envelope_id' => $envelopeId,
                'signing_url' => null, // No URL when email is sent by DocuSign
            ];
        }

        // Generate embedded signing URL (requires status='sent', but account settings suppress email)
        $signingUrl = $this->getSigningUrl($envelopeId, $signer, $returnUrl);

        return [
            'envelope_id' => $envelopeId,
            'signing_url' => $signingUrl,
        ];
    }

    /**
     * Register email tracking when envelope is created.
     *
     * @param string $envelopeId
     * @param array $signer
     * @return DocumentSigningEmailTracking
     */
    private function registerEmailTracking(string $envelopeId, array $signer): DocumentSigningEmailTracking
    {
        try {
            $tracking = DocumentSigningEmailTracking::create([
                'envelope_id' => $envelopeId,
                'document_number' => $signer['documento'] ?? null,
                'recipient_email' => $signer['email'],
                'recipient_name' => $signer['name'],
                'provider' => 'docusign',
                'email_status' => 'sent', // DocuSign sends email automatically when status is 'sent'
                'sent_at' => now(),
            ]);

            Log::info('Document signing email tracking registered', [
                'tracking_id' => $tracking->id,
                'envelope_id' => $envelopeId,
                'recipient_email' => $signer['email'],
            ]);

            return $tracking;
        } catch (\Exception $e) {
            Log::error('Failed to register email tracking', [
                'envelope_id' => $envelopeId,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - tracking failure shouldn't break the main flow
            throw $e;
        }
    }

    /**
     * Resend signing email for an existing envelope.
     * Uses DocuSign's notification API to send a reminder/notification.
     *
     * @param string $envelopeId
     * @param array $signer
     * @param string $emailSubject
     * @return DocumentSigningEmailTracking
     */
    public function resendSigningEmail(
        string $envelopeId,
        array $signer,
        string $emailSubject = 'Firma de Convenio de Afiliación'
    ): DocumentSigningEmailTracking {
        $apiClient = $this->getApiClient();
        $envelopeApi = new EnvelopesApi($apiClient);
        $accountId = $this->getAccountId();

        try {
            // Get existing envelope
            $envelope = $envelopeApi->getEnvelope($accountId, $envelopeId);
            $recipients = $envelope->getRecipients();

            if (!$recipients || !$recipients->getSigners() || count($recipients->getSigners()) === 0) {
                throw new \Exception('No signers found in envelope');
            }

            // Use DocuSign's notification API to send a reminder
            // This will send a notification email to the recipient
            $notificationRequest = new \DocuSign\eSign\Model\Notification();
            
            // Create a reminder that triggers immediately
            $reminder = new \DocuSign\eSign\Model\Reminders();
            $reminder->setReminderEnabled('true');
            $reminder->setReminderDelay('0'); // Send immediately
            $notificationRequest->setReminders($reminder);

            // Update envelope with notification settings to trigger reminder
            $envelopeDefinition = new EnvelopeDefinition([
                'notification' => $notificationRequest,
            ]);

            $envelopeApi->update($accountId, $envelopeId, $envelopeDefinition);

            // Alternatively, we can use the updateRecipients method to resend
            // But the reminder approach is simpler and works well

            // Find parent tracking if exists
            $parentTracking = DocumentSigningEmailTracking::where('envelope_id', $envelopeId)
                ->where('recipient_email', $signer['email'])
                ->orderBy('created_at', 'desc')
                ->first();

            // Create new tracking record for resend
            $tracking = DocumentSigningEmailTracking::create([
                'envelope_id' => $envelopeId,
                'document_number' => $signer['documento'] ?? null,
                'recipient_email' => $signer['email'],
                'recipient_name' => $signer['name'],
                'provider' => 'docusign',
                'email_status' => 'sent',
                'sent_at' => now(),
                'parent_tracking_id' => $parentTracking?->id,
                'resend_count' => $parentTracking ? ($parentTracking->resend_count + 1) : 0,
            ]);

            Log::info('DocuSign signing email resent', [
                'tracking_id' => $tracking->id,
                'envelope_id' => $envelopeId,
                'recipient_email' => $signer['email'],
            ]);

            return $tracking;
        } catch (\Exception $e) {
            Log::error('Failed to resend DocuSign signing email', [
                'envelope_id' => $envelopeId,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to resend signing email: ' . $e->getMessage());
        }
    }

    /**
     * Find contract PDF file by document number in resources/convenios directory.
     * Files are named like: "RIONEGRO - MENDOZA ORTEGA AMELIA PATRICIA - 50872457.pdf"
     * The document number is the last part before .pdf
     *
     * @param string $documentNumber The document number to search for
     * @return string|null The full path to the PDF file, or null if not found
     */
    public function findContractPdfByDocumentNumber(string $documentNumber): ?string
    {
        $conveniosPath = resource_path('convenios');

        if (!is_dir($conveniosPath)) {
            Log::error('Directorio de convenios no encontrado', [
                'path' => $conveniosPath,
            ]);
            return null;
        }

        // Normalize document number (remove spaces, dots, etc.)
        $normalizedDocumentNumber = preg_replace('/[^0-9]/', '', $documentNumber);

        // Get all PDF files in the directory
        $files = glob($conveniosPath . '/*.pdf');

        if (empty($files)) {
            Log::warning('No se encontraron archivos PDF en el directorio de convenios', [
                'path' => $conveniosPath,
            ]);
            return null;
        }

        // Search for file ending with the document number
        foreach ($files as $file) {
            $filename = basename($file, '.pdf');

            // Extract document number from filename (last part after last " - ")
            // Format: "RIONEGRO - MENDOZA ORTEGA AMELIA PATRICIA - 50872457"
            $parts = explode(' - ', $filename);

            if (count($parts) >= 2) {
                $fileDocumentNumber = end($parts);
                $normalizedFileDocumentNumber = preg_replace('/[^0-9]/', '', $fileDocumentNumber);

                if ($normalizedFileDocumentNumber === $normalizedDocumentNumber) {
                    Log::info('PDF de convenio encontrado', [
                        'documento' => $documentNumber,
                        'archivo' => basename($file),
                        'ruta_completa' => $file,
                    ]);
                    return $file;
                }
            }
        }

        Log::warning('PDF de convenio no encontrado para documento', [
            'documento' => $documentNumber,
            'documento_normalizado' => $normalizedDocumentNumber,
            'archivos_revisados' => count($files),
        ]);

        return null;
    }

    /**
     * Download a document from a completed envelope.
     *
     * @param string $envelopeId The envelope ID
     * @param string $documentId The document ID (use 'combined' for all documents combined)
     * @return string|null The document content as binary string, or null on error
     */
    public function downloadDocument(string $envelopeId, string $documentId = 'combined'): ?string
    {
        try {
            $apiClient = $this->getApiClient();
            $envelopeApi = new EnvelopesApi($apiClient);
            $accountId = $this->getAccountId();

            Log::info('Downloading document from DocuSign', [
                'envelope_id' => $envelopeId,
                'document_id' => $documentId,
                'account_id' => $accountId,
            ]);

            // Get document - SDK returns raw HTTP body as string for binary content
            $document = $envelopeApi->getDocument($accountId, $documentId, $envelopeId);

            // The SDK returns the content directly as string for binary documents
            $content = '';
            if (is_string($document)) {
                $content = $document;
            } elseif ($document instanceof \SplFileObject) {
                // If it's a SplFileObject, read it
                $document->rewind();
                while (!$document->eof()) {
                    $content .= $document->fgets();
                }
            } elseif (is_resource($document)) {
                // If it's a resource, read it
                $content = stream_get_contents($document);
            } else {
                Log::warning('Unexpected document type from DocuSign', [
                    'envelope_id' => $envelopeId,
                    'document_id' => $documentId,
                    'type' => gettype($document),
                ]);
                return null;
            }

            if (empty($content)) {
                Log::warning('Downloaded document is empty', [
                    'envelope_id' => $envelopeId,
                    'document_id' => $documentId,
                ]);
                return null;
            }

            Log::info('Document downloaded successfully', [
                'envelope_id' => $envelopeId,
                'document_id' => $documentId,
                'size_bytes' => strlen($content),
            ]);

            return $content;
        } catch (\Exception $e) {
            Log::error('Error downloading document from DocuSign', [
                'envelope_id' => $envelopeId,
                'document_id' => $documentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }
}

