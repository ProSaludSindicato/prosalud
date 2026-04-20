<?php

namespace App\Http\Controllers;

use App\Jobs\SignConvenioWithPresidentJob;
use App\Models\ConvenioEmailTracking;
use App\Services\ConvenioDigitalSigningService;
use App\Services\ConvenioPdfStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ConvenioPublicSigningController extends Controller
{
    public function __construct(
        private readonly ConvenioDigitalSigningService $signingService,
        private readonly ConvenioPdfStorageService $pdfStorageService,
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
                'header_title' => $headerTitle,
                'expires_at' => $tracking->token_expires_at?->toIso8601String(),
                'firmado_afiliado_at' => $tracking->firmado_afiliado_at?->toIso8601String(),
                'firmado_presidente_at' => $tracking->firmado_presidente_at?->toIso8601String(),
                'rechazado_at' => $tracking->rechazado_at?->toIso8601String(),
                'motivo_rechazo' => $tracking->signing_estado === ConvenioEmailTracking::SIGNING_RECHAZADO
                    ? $tracking->motivo_rechazo
                    : null,
            ],
        ]);
    }

    public function documentPdf(string $token): JsonResponse|Response|BinaryFileResponse
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

        $path = $tracking->pdf_original_path;
        $abs = $this->pdfStorageService->absolutePathForRelative($path);
        if ($abs === null || ! is_file($abs)) {
            return response()->json([
                'success' => false,
                'message' => 'El documento no está disponible en este momento.',
            ], 404);
        }

        return response()->file($abs, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="convenio.pdf"',
        ]);
    }

    public function submitAffiliateSignature(Request $request, string $token): JsonResponse
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

        $maxKb = (int) config('convenio_signing.affiliate_submitted_max_kb', 12288);

        $validator = Validator::make($request->all(), [
            'pdf' => 'required|file|mimes:pdf|max:'.$maxKb,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $uploaded = $request->file('pdf');
        if ($uploaded === null) {
            return response()->json([
                'success' => false,
                'message' => 'No se recibió el archivo PDF.',
            ], 422);
        }

        $stream = fopen($uploaded->getRealPath(), 'rb');
        if ($stream === false) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo leer el archivo enviado.',
            ], 422);
        }

        $header = fread($stream, 5);
        fclose($stream);
        if ($header === false || ! str_starts_with((string) $header, '%PDF')) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo no es un PDF válido.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($tracking, $uploaded): void {
                $locked = ConvenioEmailTracking::query()
                    ->whereKey($tracking->id)
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

                $relativeDir = 'convenios-digital/'.$locked->id;
                $relativePath = $relativeDir.'/firmado-afiliado.pdf';
                $targetAbs = storage_path('app/'.$relativePath);

                $dir = dirname($targetAbs);
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                if (! @copy((string) $uploaded->getRealPath(), $targetAbs)) {
                    throw new \RuntimeException('No se pudo guardar el PDF firmado.');
                }

                $locked->update([
                    'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
                    'pdf_firmado_afiliado_path' => $relativePath,
                    'firmado_afiliado_at' => now(),
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

        Log::info('[CONVENIO DIGITAL] Firma del afiliado registrada', [
            'tracking_id' => $tracking->id,
        ]);

        // Fase 2 (auto-dispatch): si está habilitado, despachar la firma presidencial automáticamente
        if (config('convenio_auto_sign.enabled') && config('convenio_auto_sign.auto_dispatch_after_affiliate')) {
            $tracking->update(['president_sign_queued_at' => now()]);
            SignConvenioWithPresidentJob::dispatch($tracking->id);

            Log::info('[CONVENIO DIGITAL] Job de firma presidencial auto-despachado', [
                'tracking_id' => $tracking->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tu convenio firmado fue recibido correctamente.',
            'data' => [
                'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            ],
        ]);
    }
}
