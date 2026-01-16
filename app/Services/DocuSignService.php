<?php

namespace App\Services;

use DocuSign\eSign\Api\EnvelopesApi;
use DocuSign\eSign\Client\ApiClient;
use DocuSign\eSign\Client\Auth\OAuth;
use DocuSign\eSign\Model\Document;
use DocuSign\eSign\Model\EnvelopeDefinition;
use DocuSign\eSign\Model\RecipientViewRequest;
use DocuSign\eSign\Model\Signer;
use DocuSign\eSign\Model\SignHere;
use DocuSign\eSign\Model\Tabs;
use DocuSign\eSign\Model\Text;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DocuSignService
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
     * @return \DocuSign\eSign\Model\EnvelopeSummary
     */
    public function createEnvelope(
        string $pdfPath,
        array $signer,
        string $emailSubject = 'Firma de documento',
        string $documentName = 'Documento'
    ) {
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
            'anchor_y_offset' => '-10', // SUBE la firma sobre la línea
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
                'anchor_x_offset' => '190', // A la derecha del texto
                'anchor_y_offset' => '-5', // Misma línea
                'width' => 100,
                'height' => 18,
            ],
            // Campo 2: Fecha de nacimiento (comienza justo al final del campo anterior)
            [
                'label' => 'Fecha de nacimiento',
                'page' => 1,
                'use_anchor' => true,
                'anchor_string' => 'LUGAR Y FECHA DE NACIMIENTO:',
                'anchor_x_offset' => '310', // Después del primer campo (10 + 200 + 10 de espacio)
                'anchor_y_offset' => '-5', // Misma línea
                'width' => 120,
                'height' => 18,
            ],
            // Campo 3: Domicilio (anclado a "domiciliado(a) y residente en ")
            [
                'label' => 'Domicilio',
                'page' => 1,
                'use_anchor' => true,
                'anchor_string' => 'domiciliado(a) y residente en ',
                'anchor_x_offset' => '120', // 2mm aprox = 6 píxeles a 72 DPI (2mm / 25.4mm * 72px)
                'anchor_y_offset' => '-1', // Misma línea
                'width' => 160,
                'height' => 10,
            ],
            // Campo 4: Lugar expedición (anclado a ", quien obra por su propio nombre")
            [
                'label' => 'Lugar expedición',
                'page' => 1,
                'use_anchor' => true,
                'anchor_string' => ', quien obra por su propio nombre',
                'anchor_x_offset' => '-80', // 2mm aprox = 6 píxeles a la izquierda (negativo)
                'anchor_y_offset' => '-3', // Misma línea
                'width' => 80,
                'height' => 10,
            ],
            // Campo 5: Teléfono (anclado a ", TELEFONO" - a la izquierda)
            [
                'label' => 'Teléfono',
                'page' => 1,
                'use_anchor' => true,
                'anchor_string' => ', TELEFONO',
                'anchor_x_offset' => '-190', // A la izquierda del texto (negativo)
                'anchor_y_offset' => '-1', // Misma línea
                'width' => 180,
                'height' => 18,
            ],
            // Campo 6: Teléfono 2 (anclado a ", TELEFONO" - a la derecha)
            [
                'label' => 'Teléfono 2',
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
                'anchor_x_offset' => '50', // A la derecha del texto
                'anchor_y_offset' => '45', // Aprox 3 líneas abajo (3 líneas × 15px ≈ 45px)
                'width' => 120,
                'height' => 10,
            ],
        ];

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
        $signerModel = new Signer([
            'email' => $signer['email'],
            'name' => $signer['name'],
            'recipient_id' => '1',
            'client_user_id' => $signer['documento'], // Use documento as clientUserId for embedded signing
        ]);

        // Set tabs using setTabs method
        $signerModel->setTabs($tabs);

        // Create envelope definition
        $envelopeDefinition = new EnvelopeDefinition([
            'email_subject' => $emailSubject,
            'documents' => [$document],
            'recipients' => [
                'signers' => [$signerModel],
            ],
            'status' => 'sent',
        ]);

        try {
            // Use dynamically obtained account ID
            $accountId = $this->getAccountId();

            $envelopeSummary = $envelopeApi->createEnvelope(
                $accountId,
                $envelopeDefinition
            );

            Log::info('DocuSign envelope created', [
                'envelope_id' => $envelopeSummary->getEnvelopeId(),
                'signer_email' => $signer['email'],
            ]);

            return $envelopeSummary;
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

        $viewRequest = new RecipientViewRequest([
            'authentication_method' => 'none',
            'client_user_id' => $signer['documento'], // Use documento as clientUserId
            'recipient_id' => '1',
            'return_url' => $returnUrl,
            'user_name' => $signer['name'],
            'email' => $signer['email'],
        ]);

        try {
            // Use dynamically obtained account ID
            $accountId = $this->getAccountId();

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
     *
     * @param string $pdfPath Path to the PDF file
     * @param array $signer Signer information: ['email' => string, 'name' => string, 'documento' => string]
     * @param string $returnUrl URL to redirect after signing
     * @param string $emailSubject Subject for the signing email
     * @param string $documentName Name of the document
     * @return array ['envelope_id' => string, 'signing_url' => string]
     */
    public function createEnvelopeAndGetSigningUrl(
        string $pdfPath,
        array $signer,
        string $returnUrl,
        string $emailSubject = 'Firma de documento',
        string $documentName = 'Documento'
    ): array {
        $envelopeSummary = $this->createEnvelope(
            $pdfPath,
            $signer,
            $emailSubject,
            $documentName
        );

        $envelopeId = $envelopeSummary->getEnvelopeId();

        $signingUrl = $this->getSigningUrl($envelopeId, $signer, $returnUrl);

        return [
            'envelope_id' => $envelopeId,
            'signing_url' => $signingUrl,
        ];
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

