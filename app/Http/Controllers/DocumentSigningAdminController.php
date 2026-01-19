<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentSigningServiceInterface;
use App\Jobs\ProcessBulkDocumentSigningEmailsJob;
use App\Models\DocumentSigningEmailTracking;
use App\Services\AfiliadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DocumentSigningAdminController extends Controller
{
    public function __construct(
        private readonly DocumentSigningServiceInterface $documentSigningService,
        private readonly AfiliadoService $afiliadoService
    ) {
    }

    /**
     * Send signing emails in bulk to multiple affiliates.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendBulkEmails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document_numbers' => 'required|array',
            'document_numbers.*' => 'required|string|max:50',
            'email_subject' => 'nullable|string|max:255',
            'document_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $documentNumbers = $request->input('document_numbers');
        $emailSubject = $request->input('email_subject', 'Firma de Convenio de Afiliación');
        $documentName = $request->input('document_name', 'Convenio de Afiliación');

        // Dispatch async job to process bulk emails
        ProcessBulkDocumentSigningEmailsJob::dispatch(
            documentNumbers: $documentNumbers,
            emailSubject: $emailSubject,
            documentName: $documentName,
            tipoDocumento: 'CC' // Default, could be made configurable
        );

        Log::info('Bulk document signing emails job dispatched', [
            'total_documents' => count($documentNumbers),
            'email_subject' => $emailSubject,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'El proceso de envío masivo ha sido iniciado. Los correos se enviarán de forma asíncrona.',
            'data' => [
                'total' => count($documentNumbers),
                'status' => 'queued',
                'note' => 'Puedes consultar el estado de los envíos en el historial de correos.',
            ],
        ]);
    }

    /**
     * List email tracking history with filters.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function listEmailHistory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document_number' => 'nullable|string|max:50',
            'email_status' => 'nullable|string|in:pending,sent,delivered,opened,failed,bounced',
            'provider' => 'nullable|string|in:docusign,signnow',
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = DocumentSigningEmailTracking::query();

        // Apply filters
        if ($request->has('document_number')) {
            $query->byDocumentNumber($request->input('document_number'));
        }

        if ($request->has('email_status')) {
            $query->byEmailStatus($request->input('email_status'));
        }

        if ($request->has('provider')) {
            $query->byProvider($request->input('provider'));
        }

        if ($request->has('fecha_desde') || $request->has('fecha_hasta')) {
            $query->byDateRange(
                $request->input('fecha_desde'),
                $request->input('fecha_hasta')
            );
        }

        // Order by most recent first
        $query->orderBy('created_at', 'desc');

        // Paginate
        $perPage = $request->input('per_page', 15);
        $trackings = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $trackings,
        ]);
    }

    /**
     * Resend signing emails to one or multiple affiliates.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resendEmails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tracking_ids' => 'required|array',
            'tracking_ids.*' => 'required|integer|exists:document_signing_email_trackings,id',
            'email_subject' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $trackingIds = $request->input('tracking_ids');
        $emailSubject = $request->input('email_subject', 'Firma de Convenio de Afiliación');

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($trackingIds as $trackingId) {
            try {
                $tracking = DocumentSigningEmailTracking::findOrFail($trackingId);

                // Get affiliate information
                $afiliadoInfo = $this->afiliadoService->getCompleteAfiliadoInfo(
                    tipoDocumento: 'CC', // Default
                    documento: $tracking->document_number,
                    fechaExpedicion: null
                );

                if (!$afiliadoInfo || !isset($afiliadoInfo['afiliado'])) {
                    $results['failed'][] = [
                        'tracking_id' => $trackingId,
                        'error' => 'Afiliado no encontrado',
                    ];
                    continue;
                }

                $afiliado = $afiliadoInfo['afiliado'];
                $nombres = $afiliado['nombres'] ?? '';
                $apellidos = $afiliado['apellidos'] ?? '';
                $nombreCompleto = trim($nombres . ' ' . $apellidos);

                // Resend email using the service
                if ($tracking->provider === 'docusign' && method_exists($this->documentSigningService, 'resendSigningEmail')) {
                    $newTracking = $this->documentSigningService->resendSigningEmail(
                        envelopeId: $tracking->envelope_id,
                        signer: [
                            'email' => $tracking->recipient_email,
                            'name' => $nombreCompleto ?: $tracking->recipient_name,
                            'documento' => $tracking->document_number,
                            'afiliado' => $afiliado,
                        ],
                        emailSubject: $emailSubject
                    );

                    $results['success'][] = [
                        'tracking_id' => $trackingId,
                        'new_tracking_id' => $newTracking->id,
                        'envelope_id' => $tracking->envelope_id,
                    ];
                } else {
                    // For other providers or if method doesn't exist, create new envelope
                    $pdfPath = $this->documentSigningService->findContractPdfByDocumentNumber($tracking->document_number);

                    if (null === $pdfPath) {
                        $results['failed'][] = [
                            'tracking_id' => $trackingId,
                            'error' => 'No se encontró el PDF del convenio',
                        ];
                        continue;
                    }

                    $result = $this->documentSigningService->createEnvelopeAndGetSigningUrl(
                        pdfPath: $pdfPath,
                        signer: [
                            'email' => $tracking->recipient_email,
                            'name' => $nombreCompleto ?: $tracking->recipient_name,
                            'documento' => $tracking->document_number,
                            'afiliado' => $afiliado,
                        ],
                        returnUrl: config('app.frontend_url') . '/servicios/firma-convenio?envelope_id={envelope_id}&documento=' . $tracking->document_number . '&event={event}',
                        emailSubject: $emailSubject,
                        documentName: 'Convenio de Afiliación',
                        sendEmail: true
                    );

                    $results['success'][] = [
                        'tracking_id' => $trackingId,
                        'new_envelope_id' => $result['envelope_id'],
                    ];
                }

                Log::info('Email resent successfully', [
                    'tracking_id' => $trackingId,
                    'envelope_id' => $tracking->envelope_id,
                ]);
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'tracking_id' => $trackingId,
                    'error' => $e->getMessage(),
                ];

                Log::error('Failed to resend email', [
                    'tracking_id' => $trackingId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total' => count($trackingIds),
                'success_count' => count($results['success']),
                'failed_count' => count($results['failed']),
                'results' => $results,
            ],
        ]);
    }

    /**
     * Get email tracking statistics.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStatistics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = DocumentSigningEmailTracking::query();

        if ($request->has('fecha_desde') || $request->has('fecha_hasta')) {
            $query->byDateRange(
                $request->input('fecha_desde'),
                $request->input('fecha_hasta')
            );
        }

        $stats = [
            'total' => $query->count(),
            'by_status' => $query->selectRaw('email_status, COUNT(*) as count')
                ->groupBy('email_status')
                ->pluck('count', 'email_status')
                ->toArray(),
            'by_provider' => $query->selectRaw('provider, COUNT(*) as count')
                ->groupBy('provider')
                ->pluck('count', 'provider')
                ->toArray(),
            'sent_today' => (clone $query)->whereDate('sent_at', today())->count(),
            'opened_today' => (clone $query)->whereDate('opened_at', today())->count(),
            'signed_today' => (clone $query)->whereDate('signed_at', today())->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
