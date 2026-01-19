<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentSigningServiceInterface;
use App\Http\Requests\CreateDocuSignSignatureRequest;
use App\Services\AfiliadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DocuSignController extends Controller
{
    public function __construct(
        private readonly DocumentSigningServiceInterface $documentSigningService,
        private readonly AfiliadoService $afiliadoService
    ) {
    }

    /**
     * Create a DocuSign envelope and return the embedded signing URL.
     * Authenticates the affiliate and finds their PDF contract automatically.
     *
     * @param CreateDocuSignSignatureRequest $request
     * @return JsonResponse
     */
    public function createSignature(CreateDocuSignSignatureRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $tipoDocumento = trim($validated['tipo_documento']);
            $documento = trim($validated['documento']);
            $fechaExpedicion = trim($validated['fecha_expedicion']);

            // Check if the file is available
            if (!$this->afiliadoService->isFileAvailable()) {
                Log::error('Archivo de afiliados no disponible para firma DocuSign');

                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                ], 503);
            }

            // Authenticate affiliate and get complete information
            $afiliadoInfo = $this->afiliadoService->getCompleteAfiliadoInfo(
                $tipoDocumento,
                $documento,
                $fechaExpedicion
            );

            if (null === $afiliadoInfo || !isset($afiliadoInfo['afiliado'])) {
                Log::warning('Autenticación fallida para firma DocuSign - afiliado no encontrado', [
                    'tipo_documento' => $tipoDocumento,
                    'documento' => $documento,
                    'fecha_expedicion' => $fechaExpedicion,
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incorrectas o afiliado no encontrado',
                ], 401);
            }

            $afiliado = $afiliadoInfo['afiliado'];

            // Get email and full name from affiliate data
            $email = $afiliado['correo_personal'] ?? null;
            $nombres = $afiliado['nombres'] ?? '';
            $apellidos = $afiliado['apellidos'] ?? '';
            $nombreCompleto = trim($nombres . ' ' . $apellidos);

            if (empty($email)) {
                Log::warning('Afiliado sin correo electrónico para firma DocuSign', [
                    'documento' => $documento,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'El afiliado no tiene correo electrónico registrado',
                ], 400);
            }

            if (empty($nombreCompleto)) {
                Log::warning('Afiliado sin nombre completo para firma DocuSign', [
                    'documento' => $documento,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo obtener el nombre completo del afiliado',
                ], 400);
            }

            // Find PDF contract file by document number
            $pdfPath = $this->documentSigningService->findContractPdfByDocumentNumber($documento);

            if (null === $pdfPath) {
                Log::warning('PDF de convenio no encontrado para afiliado', [
                    'documento' => $documento,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró el documento de convenio para firmar',
                ], 404);
            }

            // Create envelope and get signing URL
            $result = $this->documentSigningService->createEnvelopeAndGetSigningUrl(
                pdfPath: $pdfPath,
                signer: [
                    'email' => $email,
                    'name' => $nombreCompleto,
                    'documento' => $documento, // Use documento as clientUserId
                    'afiliado' => $afiliado, // Pass afiliado info for prefilled_text
                ],
                returnUrl: $validated['return_url'],
                emailSubject: $validated['email_subject'] ?? 'Firma de Convenio de Afiliación',
                documentName: $validated['document_name'] ?? 'Convenio de Afiliación'
            );

            $provider = config('services.document_signing.provider', 'docusign');
            Log::info('Firma de documento creada exitosamente', [
                'provider' => $provider,
                'envelope_id' => $result['envelope_id'],
                'documento' => $documento,
                'email' => $email,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'envelope_id' => $result['envelope_id'],
                    'signing_url' => $result['signing_url'],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating DocuSign signature', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => [
                    'tipo_documento' => $request->input('tipo_documento'),
                    'documento' => $request->input('documento'),
                ],
            ]);

            $provider = config('services.document_signing.provider', 'docusign');
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la firma de documento: ' . $e->getMessage(),
            ], 500);
        }
    }
}

