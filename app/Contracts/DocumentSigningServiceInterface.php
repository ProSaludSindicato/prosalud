<?php

namespace App\Contracts;

interface DocumentSigningServiceInterface
{
    /**
     * Create an envelope/document with a PDF for signing.
     *
     * @param string $pdfPath Path to the PDF file (can be storage path or absolute path)
     * @param array $signer Signer information: ['email' => string, 'name' => string, 'documento' => string]
     * @param string $emailSubject Subject for the signing email
     * @param string $documentName Name of the document
     * @return array ['envelope_id' => string, 'document_id' => string] or similar structure
     */
    public function createEnvelope(
        string $pdfPath,
        array $signer,
        string $emailSubject = 'Firma de documento',
        string $documentName = 'Documento'
    ): array;

    /**
     * Generate embedded signing URL for a recipient.
     *
     * @param string $envelopeId The envelope/document ID
     * @param array $signer Signer information: ['email' => string, 'name' => string, 'documento' => string]
     * @param string $returnUrl URL to redirect after signing
     * @return string The signing URL
     */
    public function getSigningUrl(
        string $envelopeId,
        array $signer,
        string $returnUrl
    ): string;

    /**
     * Create envelope and get signing URL in one call.
     * Note: When sendEmail is true, the envelope status will be 'sent' and email will be sent automatically.
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
    ): array;

    /**
     * Find contract PDF file by document number in resources/convenios directory.
     *
     * @param string $documentNumber The document number to search for
     * @return string|null The full path to the PDF file, or null if not found
     */
    public function findContractPdfByDocumentNumber(string $documentNumber): ?string;

    /**
     * Download a document from a completed envelope.
     *
     * @param string $envelopeId The envelope/document ID
     * @param string $documentId The document ID (use 'combined' for all documents combined)
     * @return string|null The document content as binary string, or null on error
     */
    public function downloadDocument(string $envelopeId, string $documentId = 'combined'): ?string;
}

