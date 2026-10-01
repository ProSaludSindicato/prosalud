<?php

namespace App\Http\Controllers;

use App\Enums\ConvenioPdfStage;
use App\Http\Requests\ReportConvenioSigningClientErrorRequest;
use App\Http\Requests\SubmitAffiliateConvenioSignatureRequest;
use App\Http\Requests\SubmitConvenioSigningSatisfactionRequest;
use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioDigitalSigningService;
use App\Services\ConvenioPdfIntegrityService;
use App\Services\ConvenioPdfStorageService;
use App\Support\ConvenioDisplayFilename;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConvenioPublicSigningController extends Controller
{
    public function __construct(
        private readonly ConvenioDigitalSigningService $signingService,
        private readonly ConvenioPdfStorageService $pdfStorageService,
        private readonly ConvenioPdfIntegrityService $integrityService,
    ) {}

    public function metadata(string $token): JsonResponse
    {
        $tracking = $this->signingService->findByPlainToken($token);
        if (! $tracking instanceof ConvenioEmailTracking) {
            return response()->json([
                'success' => false,
                'message' => 'Enlace inválido o expirado.',
            ], 404);
        }

        $canSign = $this->signingService->affiliateCanSign($tracking);

        $headerTitle = $tracking->viewer_header_title !== null && $tracking->viewer_header_title !== ''
            ? (string) $tracking->viewer_header_title
            : (string) config('convenio_signing.viewer_header_title');

        return response()->json([
            'success' => true,
            'data' => [
                'signing_estado' => $tracking->signing_estado,
                'can_sign' => $canSign,
                'nombre_afiliado' => $tracking->nombre_afiliado,
                'nombre_convenio' => $tracking->nombre_convenio,
                'documento' => $tracking->documento,
                'download_filename' => ConvenioDisplayFilename::fromTracking($tracking),
                'header_title' => $headerTitle,
                'expires_at' => $tracking->token_expires_at?->toIso8601String(),
                'firmado_afiliado_at' => $tracking->firmado_afiliado_at?->toIso8601String(),
                'firmado_presidente_at' => $tracking->firmado_presidente_at?->toIso8601String(),
                'rechazado_at' => $tracking->rechazado_at?->toIso8601String(),
                'can_rate_satisfaction' => $this->signingService->affiliateCanRateSatisfaction($tracking),
                'satisfaction_score' => $tracking->signing_satisfaction_score,
            ],
        ]);
    }

    public function documentPdf(string $token): JsonResponse|Response
    {
        $tracking = $this->signingService->findByPlainToken($token);
        if (! $tracking instanceof ConvenioEmailTracking) {
            return response()->json([
                'success' => false,
                'message' => 'Enlace inválido o expirado.',
            ], 404);
        }

        if (! $this->signingService->affiliateCanSign($tracking)) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede acceder al documento con este enlace.',
            ], 403);
        }

        $contents = $this->pdfStorageService->get($tracking->pdf_original_path);
        if ($contents === null) {
            return response()->json([
                'success' => false,
                'message' => 'El documento no está disponible en este momento.',
            ], 404);
        }

        return $this->pdfStorageService->downloadResponse(
            $contents,
            ConvenioDisplayFilename::fromTracking($tracking),
            'inline',
        );
    }

    public function submitAffiliateSignature(SubmitAffiliateConvenioSignatureRequest $request, string $token): JsonResponse
    {
        $tracking = $this->signingService->findByPlainToken($token);
        if (! $tracking instanceof ConvenioEmailTracking) {
            return response()->json([
                'success' => false,
                'message' => 'Enlace inválido o expirado.',
            ], 404);
        }

        if (! $this->signingService->affiliateCanSign($tracking)) {
            return response()->json([
                'success' => false,
                'message' => 'Este convenio ya no admite firma con este enlace.',
            ], 403);
        }

        $uploaded = $request->file('pdf');
        if ($uploaded === null) {
            return response()->json([
                'success' => false,
                'message' => 'No se recibió el archivo PDF.',
            ], 422);
        }

        $lockSeconds = max(30, (int) config('convenio_signing.affiliate_signature_submit_lock_seconds', 180));
        $submitLock = Cache::lock('convenio-affiliate-sign:'.$tracking->id, $lockSeconds);

        if (! $submitLock->get()) {
            return response()->json([
                'success' => false,
                'message' => 'Ya hay un envío de firma en proceso para este convenio. Espera un momento e intenta de nuevo.',
                'code' => 'signature_submit_in_progress',
            ], 409);
        }

        try {
            return $this->processAffiliateSignatureSubmit($request, $tracking, $uploaded);
        } finally {
            $submitLock->release();
        }
    }

    private function processAffiliateSignatureSubmit(
        SubmitAffiliateConvenioSignatureRequest $request,
        ConvenioEmailTracking $tracking,
        \Illuminate\Http\UploadedFile $uploaded,
    ): JsonResponse {

        $signedContents = file_get_contents((string) $uploaded->getRealPath());
        if ($signedContents === false || $signedContents === '' || ! str_starts_with($signedContents, '%PDF')) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo no es un PDF válido.',
            ], 422);
        }

        $originalContents = $this->pdfStorageService->get($tracking->pdf_original_path);
        if ($originalContents === null) {
            return response()->json([
                'success' => false,
                'message' => 'El documento original no está disponible para verificación.',
            ], 422);
        }

        $originalSha256 = $tracking->pdf_original_sha256 ?? $this->integrityService->hash($originalContents);
        $signedSha256 = $this->integrityService->hash($signedContents);

        $originalPageCount = $tracking->pdf_original_page_count;
        $originalTextFingerprint = $tracking->pdf_original_text_fingerprint;

        if ($originalTextFingerprint === null) {
            $this->integrityService->persistOriginalIntegrityMetadata($tracking, $originalContents);
            $tracking->refresh();
            $originalPageCount = $tracking->pdf_original_page_count;
            $originalTextFingerprint = $tracking->pdf_original_text_fingerprint;
        }

        $comparison = $this->integrityService->compareSignedAgainstOriginal(
            $originalPageCount,
            $originalTextFingerprint,
            $signedContents,
        );

        if ($this->integrityService->hasMismatch($comparison)) {
            Log::warning('[CONVENIO DIGITAL] Firma rechazada por integridad del documento', [
                'tracking_id' => $tracking->id,
                'mismatch_reason' => $comparison['mismatch_reason'],
                'signed_ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'El documento enviado no coincide con el convenio original. No se altere el contenido del PDF; solo coloque su firma y vuelva a intentarlo.',
                'code' => 'document_integrity_mismatch',
            ], 422);
        }

        $textIntegrityStatus = $this->integrityService->resolveTextIntegrityStatus($comparison);
        $auditLog = $request->decodedAuditLog();
        $signedIp = $request->ip();
        $signedUserAgent = $request->userAgent();

        try {
            DB::transaction(function () use (
                $tracking,
                $uploaded,
                $originalSha256,
                $signedSha256,
                $textIntegrityStatus,
                $auditLog,
                $signedIp,
                $signedUserAgent,
            ): void {
                $locked = ConvenioEmailTracking::query()
                    ->where('id', $tracking->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked instanceof ConvenioEmailTracking) {
                    throw new \RuntimeException('Registro no encontrado.');
                }

                if ($locked->signing_estado !== ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA) {
                    throw new \RuntimeException('Estado no permite firma.');
                }

                if ($locked->token_expires_at === null || $locked->token_expires_at->isPast()) {
                    throw new \RuntimeException('Token expirado.');
                }

                $relativePath = $this->pdfStorageService->storeFromAbsolutePath(
                    $locked,
                    ConvenioPdfStage::FirmadoAfiliado,
                    (string) $uploaded->getRealPath(),
                );

                $locked->update([
                    'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
                    'pdf_firmado_afiliado_path' => $relativePath,
                    'pdf_original_sha256' => $originalSha256,
                    'pdf_firmado_afiliado_sha256' => $signedSha256,
                    'text_integrity_status' => $textIntegrityStatus,
                    'firmado_afiliado_at' => now(),
                    'signed_ip' => $signedIp,
                    'signed_user_agent' => $signedUserAgent,
                    'signing_audit_log' => $auditLog,
                    'terms_accepted_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning('[CONVENIO DIGITAL] Error al registrar firma del afiliado', [
                'tracking_id' => $tracking->id,
                'error' => $e->getMessage(),
            ]);

            if ($e->getMessage() === 'Estado no permite firma.' || $e->getMessage() === 'Token expirado.') {
                return response()->json([
                    'success' => false,
                    'message' => 'Este convenio ya no admite firma con este enlace.',
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se pudo guardar el documento firmado. Intenta nuevamente.',
            ], 500);
        }

        if ($textIntegrityStatus?->value === 'unavailable') {
            Log::warning('[CONVENIO DIGITAL] Firma aceptada sin verificación de texto', [
                'tracking_id' => $tracking->id,
            ]);
        }

        Log::info('[CONVENIO DIGITAL] Firma del afiliado registrada', [
            'tracking_id' => $tracking->id,
            'text_integrity_status' => $textIntegrityStatus?->value,
            'signed_ip' => $signedIp,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tu convenio firmado fue recibido correctamente.',
            'data' => [
                'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
                'can_rate_satisfaction' => true,
            ],
        ]);
    }

    public function submitSatisfactionRating(SubmitConvenioSigningSatisfactionRequest $request, string $token): JsonResponse
    {
        $tracking = $this->signingService->findByPlainToken($token);
        if (! $tracking instanceof ConvenioEmailTracking) {
            return response()->json([
                'success' => false,
                'message' => 'Enlace inválido o expirado.',
            ], 404);
        }

        $score = $request->score();

        try {
            DB::transaction(function () use ($tracking, $score): void {
                $locked = ConvenioEmailTracking::query()
                    ->where('id', $tracking->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked instanceof ConvenioEmailTracking) {
                    throw new \RuntimeException('Registro no encontrado.');
                }

                if ($locked->hasSigningSatisfactionRating()) {
                    throw new \RuntimeException('already_rated');
                }

                if (! $locked->canReceiveSigningSatisfactionRating()) {
                    throw new \RuntimeException('not_eligible');
                }

                $locked->update([
                    'signing_satisfaction_score' => $score,
                    'signing_satisfaction_rated_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            if ($e->getMessage() === 'already_rated') {
                return response()->json([
                    'success' => false,
                    'message' => 'Este convenio ya tiene una calificación.',
                    'code' => 'already_rated',
                ], 409);
            }

            if ($e->getMessage() === 'not_eligible') {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo puedes calificar después de enviar el convenio firmado.',
                    'code' => 'not_eligible',
                ], 403);
            }

            Log::warning('[CONVENIO DIGITAL] Error al registrar calificación de satisfacción', [
                'tracking_id' => $tracking->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo guardar la calificación. Intenta nuevamente.',
            ], 500);
        }

        Log::info('[CONVENIO DIGITAL] Calificación de satisfacción registrada', [
            'tracking_id' => $tracking->id,
            'score' => $score,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Gracias por tu calificación.',
            'data' => [
                'satisfaction_score' => $score,
            ],
        ]);
    }

    public function reportClientError(ReportConvenioSigningClientErrorRequest $request): JsonResponse
    {
        Log::error('[CONVENIO DIGITAL][FRONTEND] Error no controlado en visor de firma', [
            'message' => $request->validated('message'),
            'url' => $request->validated('url'),
            'token' => $request->validated('token'),
            'stack' => $request->validated('stack'),
            'component_stack' => $request->validated('component_stack'),
            'context' => $request->validated('context'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'success' => true,
        ], 202);
    }
}
