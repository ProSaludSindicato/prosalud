<?php

namespace App\Services;

use App\Contracts\DocumentSigningServiceInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request;

class SignNowService implements DocumentSigningServiceInterface
{
    private string $baseUrl;
    private string $accessToken;

    public function __construct()
    {
        // Always use api.signnow.com for API calls, not app.signnow.com
        $this->baseUrl = 'https://api.signnow.com';
        $this->accessToken = config('services.signnow.access_token');

        if (empty($this->accessToken)) {
            throw new \Exception("SignNow access token is not configured. Please set SIGNNOW_ACCESS_TOKEN environment variable.");
        }
    }

    /**
     * Create a document with a PDF for signing using text tags.
     *
     * @param string $pdfPath Path to the PDF file (can be storage path or absolute path)
     * @param array $signer Signer information: ['email' => string, 'name' => string, 'documento' => string, 'afiliado' => array]
     * @param string $emailSubject Subject for the signing email
     * @param string $documentName Name of the document
     * @return array ['envelope_id' => string, 'document_id' => string]
     */
    public function createEnvelope(
        string $pdfPath,
        array $signer,
        string $emailSubject = 'Firma de documento',
        string $documentName = 'Documento'
    ): array {
        try {
            Log::info('SignNow: Uploading document with text tags', [
                'pdf_path' => $pdfPath,
                'document_name' => $documentName,
                'signer_email' => $signer['email'],
            ]);

            // Read PDF file
            if (!file_exists($pdfPath)) {
                throw new \Exception("PDF file not found: {$pdfPath}");
            }

            $pdfContent = file_get_contents($pdfPath);
            $fileName = basename($pdfPath);

            // First, upload the document without tags
            // Then we'll add fields using the Document PUT endpoint
            $client = new Client();

            // Step 1: Upload document without tags
            $multipart = [
                [
                    'name' => 'file',
                    'contents' => $pdfContent,
                    'filename' => $fileName,
                    'headers' => [
                        'Content-Type' => 'application/pdf',
                    ],
                ],
            ];

            Log::info('SignNow: Uploading document without tags first');

            $response = $client->request('POST', $this->baseUrl . '/document', [
                'multipart' => $multipart,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();

            if ($statusCode !== 200 && $statusCode !== 201) {
                Log::error('SignNow: Document upload failed', [
                    'status' => $statusCode,
                    'body' => $responseBody,
                ]);
                throw new \Exception('Failed to upload document to SignNow: ' . $responseBody);
            }

            $responseData = json_decode($responseBody, true);
            $documentId = $responseData['id'] ?? null;

            if (!$documentId) {
                throw new \Exception('Document ID not returned from SignNow API');
            }

            Log::info('SignNow: Document uploaded, now adding fields', [
                'document_id' => $documentId,
            ]);

            // Step 2: Add fields to the document using PUT /document/{document_id}
            // We'll use the same field structure as before but with coordinates
            // Pass afiliado information for prefilled_text
            $afiliado = $signer['afiliado'] ?? [];
            $fields = $this->createFieldsForDocument($signer, $afiliado);

            $response = $client->request('PUT', $this->baseUrl . '/document/' . $documentId, [
                'json' => [
                    'fields' => $fields,
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();

            if ($statusCode !== 200) {
                Log::error('SignNow: Failed to add fields to document', [
                    'status' => $statusCode,
                    'body' => $responseBody,
                    'document_id' => $documentId,
                ]);
                // Don't throw error - document was uploaded, fields might be optional
                Log::warning('SignNow: Document uploaded but fields could not be added', [
                    'document_id' => $documentId,
                ]);
            } else {
                Log::info('SignNow: Fields added to document successfully', [
                    'document_id' => $documentId,
                    'fields_count' => count($fields),
                ]);
            }

            Log::info('SignNow: Document uploaded successfully', [
                'document_id' => $documentId,
            ]);

            return [
                'envelope_id' => $documentId,
                'document_id' => $documentId,
            ];
        } catch (\Exception $e) {
            Log::error('SignNow: Document creation failed', [
                'error' => $e->getMessage(),
                'signer_email' => $signer['email'] ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Failed to create SignNow document: ' . $e->getMessage());
        }
    }

    /**
     * Create fields for document using PUT /document/{document_id} endpoint.
     * Returns an array of field configurations with coordinates and prefilled_text when available.
     *
     * @param array $signer Signer information
     * @param array $afiliado Afiliado information for prefilling fields
     * @return array Array of field configurations
     */
    private function createFieldsForDocument(array $signer, array $afiliado = []): array
    {
        // TODO: Prefilled text functionality temporarily disabled
        // To re-enable in the future, uncomment the helper function and field mapping section below
        /*
        // Helper function to get prefilled text if available
        $getPrefilledText = function($fieldKey) use ($afiliado) {
            $value = $afiliado[$fieldKey] ?? '';
            
            // Format fecha_nacimiento to DD/MM/AAAA if it's a date field
            if ($fieldKey === 'fecha_nacimiento' && !empty($value)) {
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
            
            return !empty($value) ? $value : '';
        };
        */

        // Create fields with coordinates for the document
        // Using the same coordinates as DocuSign for consistency
        // SignNow uses top-left origin (0,0)
        $fields = [
            // Signature field - positioned near "NOMBRE:" text (page 2)
            [
                'x' => 362,
                'y' => 498,
                'width' => 120,
                'height' => 30,
                'type' => 'signature',
                'page_number' => 1,
                'required' => true,
                'role' => 'Firmante',
                'label' => 'Firma',
                'tag_name' => 'signature_field'
            ],
                // Text fields - using approximate coordinates
            // These can be adjusted based on actual PDF layout
            [
                'x' => 248,
                'y' => 130,
                'width' => 80,
                'height' => 14,
                'type' => 'text',
                'page_number' => 0,
                'required' => true,
                'role' => 'Firmante',
                'label' => 'Lugar de nacimiento',
                'tag_name' => 'lugar_nacimiento',
            ],
            [
                'x' => 360,
                'y' => 130,
                'width' => 120,
                'height' => 14,
                'type' => 'text',
                'page_number' => 0,
                'required' => true,
                'role' => 'Firmante',
                'tag_name' => 'fecha_nacimiento',
                'label' => 'Fecha de nacimiento',
                'validator_id' => '059b068ef8ee5cc27e09ba79af58f9e805b7c2b3',
            ],
            [
                'x' => 315,
                'y' => 247,
                'width' => 160,
                'height' => 8,
                'type' => 'text',
                'page_number' => 0,
                'required' => true,
                'role' => 'Firmante',
                'tag_name' => 'domicilio',
                'label' => 'Domicilio',
            ],
            [
                'x' => 211,
                'y' => 256,
                'width' => 60,
                'height' => 8,
                'type' => 'text',
                'page_number' => 0,
                'required' => true,
                'role' => 'Firmante',
                'tag_name' => 'lugar_expedicion',
                'label' => 'Lugar expedición',
            ],
            [
                'x' => 40,
                'y' => 398,
                'width' => 160,
                'height' => 8,
                'type' => 'text',
                'page_number' => 1,
                'required' => true,
                'role' => 'Firmante',
                'tag_name' => 'direccion_2 ',
                'label' => 'Dirección',
            ],
            [
                'x' => 310,
                'y' => 398,
                'width' => 80,
                'height' => 8,
                'type' => 'text',
                'page_number' => 1,
                'required' => true,
                'role' => 'Firmante',
                'tag_name' => 'telefono_2',
                'label' => 'Teléfono',
            ],
            [
                'x' => 460,
                'y' => 398,
                'width' => 100,
                'height' => 8,
                'type' => 'text',
                'page_number' => 1,
                'required' => true,
                'role' => 'Firmante',
                'tag_name' => 'celular',
                'label' => 'Celular',
            ],
            [
                'x' => 390,
                'y' => 548,
                'width' => 60,
                'height' => 12,
                'type' => 'text',
                'page_number' => 1,
                'required' => true,
                'role' => 'Firmante',
                'tag_name' => 'lugar_expedicion',
                'label' => 'Lugar de Expedición',
            ],
        ];

        // TODO: Prefilled text functionality temporarily disabled
        // To re-enable in the future, uncomment the section below
        /*
        // Add prefilled_text to fields that have data available
        // Map tag_name to afiliado field keys
        $fieldMapping = [
            'lugar_nacimiento' => 'lugar_nacimiento',
            'fecha_nacimiento' => 'fecha_nacimiento', // Will be formatted to DD/MM/AAAA
            'domicilio' => 'direccion',
            // 'lugar_expedicion' => 'lugar_expedicion', // Not available in afiliado file
            'direccion_2 ' => 'direccion',
            'telefono_2' => 'telefono',
            'celular' => 'celular',
        ];

        // Add prefilled_text to each field if data is available
        foreach ($fields as &$field) {
            if (isset($field['tag_name']) && isset($fieldMapping[$field['tag_name']])) {
                $afiliadoKey = $fieldMapping[$field['tag_name']];
                $prefilledValue = $getPrefilledText($afiliadoKey);
                if (!empty($prefilledValue)) {
                    $field['prefilled_text'] = $prefilledValue;
                }
            }
        }
        unset($field); // Break reference
        */

        return $fields;
    }

    /**
     * Generate signing link for a document.
     * This endpoint allows users to generate a signing link for signing and filling the document.
     *
     * @param string $envelopeId The document ID
     * @param array $signer Signer information: ['email' => string, 'name' => string, 'documento' => string]
     * @param string $returnUrl URL to redirect after signing
     * @return string The signing URL (url_no_signup)
     */
    public function getSigningUrl(
        string $envelopeId,
        array $signer,
        string $returnUrl
    ): string {
        try {
            Log::info('SignNow: Creating signing link', [
                'document_id' => $envelopeId,
                'signer_email' => $signer['email'],
                'return_url' => $returnUrl,
            ]);

            // Parse signer name to extract firstname and lastname
            $nameParts = explode(' ', trim($signer['name'] ?? ''), 2);
            $firstname = $nameParts[0] ?? '';
            $lastname = $nameParts[1] ?? '';

            // Create signing link using POST /link endpoint
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/link', [
                'document_id' => $envelopeId,
                'redirect_uri' => $returnUrl,
                'firstname' => $firstname,
                'lastname' => $lastname,
            ]);

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error('SignNow: Signing link creation failed', [
                    'status' => $response->status(),
                    'body' => $errorBody,
                    'headers' => $response->headers(),
                    'document_id' => $envelopeId,
                ]);
                throw new \Exception('Failed to create signing link: ' . $errorBody);
            }

            $responseData = $response->json();

            // Use url_no_signup for non-registered users (as specified)
            $signingUrl = $responseData['url_no_signup'] ?? null;

            // Fallback to url if url_no_signup is not available
            if (!$signingUrl) {
                $signingUrl = $responseData['url'] ?? null;
            }

            if (!$signingUrl) {
                throw new \Exception('Signing link not returned from SignNow API');
            }

            Log::info('SignNow: Signing link generated successfully', [
                'document_id' => $envelopeId,
                'signer_email' => $signer['email'],
                'signing_url' => $signingUrl,
                'url_no_signup' => $responseData['url_no_signup'] ?? null,
                'url' => $responseData['url'] ?? null,
            ]);

            return $signingUrl;
        } catch (\Exception $e) {
            Log::error('SignNow: Signing link generation failed', [
                'error' => $e->getMessage(),
                'document_id' => $envelopeId,
                'signer_email' => $signer['email'] ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Failed to generate SignNow signing link: ' . $e->getMessage());
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
        string $documentName = 'Documento',
        bool $sendEmail = false
    ): array {
        // TEMPORAL: Para pruebas, usar email hardcodeado
        // TODO: Remover este cambio temporal después de las pruebas
        $testSigner = $signer;
        $testSigner['email'] = 'juanpapabon@gmail.com';

        $result = $this->createEnvelope(
            $pdfPath,
            $testSigner,
            $emailSubject,
            $documentName
        );

        $documentId = $result['document_id'];

        // Replace placeholders in returnUrl with actual values
        // SignNow doesn't accept placeholders like {envelope_id} or {event}
        $processedReturnUrl = str_replace(
            ['{envelope_id}', '{event}'],
            [$documentId, 'signing_complete'],
            $returnUrl
        );

        $signingUrl = $this->getSigningUrl($documentId, $testSigner, $processedReturnUrl);

        return [
            'envelope_id' => $documentId,
            'signing_url' => $signingUrl,
        ];
    }

    /**
     * Find contract PDF file by document number.
     *
     * @param string $documentNumber The document number to search for
     * @return string|null The full path to the PDF file, or null if not found
     */
    public function findContractPdfByDocumentNumber(string $documentNumber): ?string
    {
        $conveniosPath = resource_path('convenios');

        if (!is_dir($conveniosPath)) {
            Log::warning('SignNow: Convenios directory not found', [
                'path' => $conveniosPath,
            ]);
            return null;
        }

        $files = scandir($conveniosPath);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (pathinfo($file, PATHINFO_EXTENSION) !== 'pdf') {
                continue;
            }

            if (str_contains($file, $documentNumber)) {
                $fullPath = $conveniosPath . '/' . $file;
                Log::info('SignNow: PDF de convenio encontrado', [
                    'documento' => $documentNumber,
                    'archivo' => $file,
                    'ruta_completa' => $fullPath,
                ]);
                return $fullPath;
            }
        }

        Log::warning('SignNow: PDF de convenio no encontrado', [
            'documento' => $documentNumber,
            'directorio' => $conveniosPath,
        ]);

        return null;
    }

    /**
     * Download a document from SignNow.
     *
     * @param string $envelopeId The document ID
     * @param string $documentId The document ID (not used in SignNow, kept for interface compatibility)
     * @return string|null The document content as binary string, or null on error
     */
    public function downloadDocument(string $envelopeId, string $documentId = 'combined'): ?string
    {
        try {
            Log::info('SignNow: Downloading document', [
                'document_id' => $envelopeId,
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
            ])->get($this->baseUrl . '/document/' . $envelopeId . '/download');

            if (!$response->successful()) {
                Log::error('SignNow: Document download failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'document_id' => $envelopeId,
                ]);
                return null;
            }

            Log::info('SignNow: Document downloaded successfully', [
                'document_id' => $envelopeId,
                'size' => strlen($response->body()),
            ]);

            return $response->body();
        } catch (\Exception $e) {
            Log::error('SignNow: Document download failed', [
                'error' => $e->getMessage(),
                'document_id' => $envelopeId,
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }
}
