<?php

namespace App\Http\Controllers;

use App\Http\Requests\{AfiliadoRequestOtpRequest, AfiliadoVerifyOtpRequest};
use App\Mail\AfiliadoOtpCode;
use App\Services\{AfiliadoService, ObfuscationService, OtpService};
use App\Services\AssemblyAttendanceService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Log, Mail};

class AfiliadoController extends Controller
{
    private AfiliadoService $afiliadoService;
    public function __construct(
        AfiliadoService $afiliadoService,
        OtpService $otpService,
        ObfuscationService $obfuscationService,
        private AssemblyAttendanceService $attendanceService,
    ) {
        $this->afiliadoService = $afiliadoService;
        $this->otpService = $otpService;
        $this->obfuscationService = $obfuscationService;
    }

    /**
     * Authenticate and get affiliate information.
     */
    public function authenticate(Request $request): JsonResponse
    {
        try {
            // Validate input
            $request->validate([
                'tipo_documento' => 'required|string|max:50',
                'documento' => 'required|string|max:50',
                'fecha_expedicion' => 'required|string|max:50',
                'signature' => 'required|string',
            ]);

            $tipoDocumento = trim($request->input('tipo_documento'));
            $documento = trim($request->input('documento'));
            $fechaExpedicion = trim($request->input('fecha_expedicion'));

            // Log the authentication attempt
            Log::info('Intento de autenticación de afiliado', [
                'tipo_documento' => $tipoDocumento,
                'documento' => $documento,
                'fecha_expedicion' => $fechaExpedicion,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]);

            // Check if the file is available
            if (!$this->afiliadoService->isFileAvailable()) {
                Log::error('Archivo de afiliados no disponible');

                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                    'afiliado' => null,
                ], 503);
            }

            // Authenticate and get affiliate
            $afiliado = $this->afiliadoService->authenticateAndGetAfiliado(
                $tipoDocumento,
                $documento,
                $fechaExpedicion
            );

            if (null === $afiliado) {
                Log::warning('Autenticación fallida - afiliado no encontrado', [
                    'tipo_documento' => $tipoDocumento,
                    'documento' => $documento,
                    'fecha_expedicion' => $fechaExpedicion,
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incorrectas o afiliado no encontrado',
                    'afiliado' => null,
                ], 401);
            }

            // Log successful authentication
            Log::info('Autenticación exitosa', [
                'documento' => $documento,
                'ip_address' => $request->ip(),
                'convenios_count' => count($afiliado['convenios'] ?? []),
                'timestamp' => now()->toISOString(),
            ]);

            $fullName = trim(($afiliado['nombres'] ?? '') . ' ' . ($afiliado['apellidos'] ?? ''));
            $this->attendanceService->record(
                $afiliado['documento'] ?? $documento,
                $fullName,
                $fechaExpedicion,
                $request,
                $request->input('signature')
            );

            return response()->json([
                'success' => true,
                'message' => 'Autenticación exitosa',
                'afiliado' => $afiliado,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validación fallida en autenticación de afiliado', [
                'errors' => $e->errors(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
                'afiliado' => null,
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error inesperado en autenticación de afiliado', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tipo_documento' => $request->input('tipo_documento'),
                'documento' => $request->input('documento'),
                'fecha_expedicion' => $request->input('fecha_expedicion'),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'afiliado' => null,
            ], 500);
        }
    }

    /**
     * Request OTP code for affiliate authentication
     * Validates credentials and sends OTP to registered email.
     */
    public function requestOtp(AfiliadoRequestOtpRequest $request): JsonResponse
    {
        try {
            $tipoDocumento = trim($request->input('tipo_documento'));
            $documento = trim($request->input('documento'));
            $fechaExpedicion = trim($request->input('fecha_expedicion'));

            // Log the OTP request attempt
            Log::info('Solicitud de código OTP para afiliado', [
                'tipo_documento' => $tipoDocumento,
                'documento' => $documento,
                'fecha_expedicion' => $fechaExpedicion,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]);

            // Check rate limiting
            if (!$this->otpService->checkRateLimit($documento)) {
                Log::warning('Rate limit excedido para solicitud OTP', [
                    'documento' => $documento,
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Has solicitado demasiados códigos. Por favor, espera un momento antes de intentar nuevamente.',
                ], 429);
            }

            // Check if the file is available
            if (!$this->afiliadoService->isFileAvailable()) {
                Log::error('Archivo de afiliados no disponible para solicitud OTP');

                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                ], 503);
            }

            // Validate credentials and get email
            $afiliadoData = $this->afiliadoService->validateCredentialsAndGetEmail(
                $tipoDocumento,
                $documento,
                $fechaExpedicion
            );

            if (null === $afiliadoData) {
                Log::warning('Solicitud OTP fallida - credenciales inválidas o sin correo', [
                    'tipo_documento' => $tipoDocumento,
                    'documento' => $documento,
                    'fecha_expedicion' => $fechaExpedicion,
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incorrectas o el afiliado no tiene correo electrónico registrado',
                ], 401);
            }

            // Generate OTP
            $otpCode = $this->otpService->generateOtp();
            $sessionId = $this->otpService->storeOtp($documento, $otpCode);

            // Send OTP via email
            try {
                Mail::to($afiliadoData['correo'])->send(
                    new AfiliadoOtpCode($otpCode, $afiliadoData['nombre'])
                );

                Log::info('Código OTP enviado exitosamente', [
                    'documento' => $documento,
                    'correo' => $afiliadoData['correo'],
                    'session_id' => $sessionId,
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                ]);

                // Obfuscate email for frontend display
                $obfuscatedEmail = $this->obfuscateEmail($afiliadoData['correo']);

                return response()->json([
                    'success' => true,
                    'message' => 'Código de verificación enviado exitosamente a tu correo electrónico',
                    'session_id' => $sessionId,
                    'email_obfuscated' => $obfuscatedEmail,
                ]);
            } catch (\Exception $e) {
                Log::error('Error al enviar código OTP por correo', [
                    'error' => $e->getMessage(),
                    'documento' => $documento,
                    'correo' => $afiliadoData['correo'],
                    'trace' => $e->getTraceAsString(),
                    'timestamp' => now()->toISOString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al enviar el código de verificación. Por favor, intenta nuevamente más tarde.',
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Error inesperado en solicitud de OTP', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tipo_documento' => $request->input('tipo_documento'),
                'documento' => $request->input('documento'),
                'fecha_expedicion' => $request->input('fecha_expedicion'),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Verify OTP code and return complete affiliate information.
     */
    public function verifyOtp(AfiliadoVerifyOtpRequest $request): JsonResponse
    {
        try {
            $tipoDocumento = trim($request->input('tipo_documento'));
            $documento = trim($request->input('documento'));
            $fechaExpedicion = trim($request->input('fecha_expedicion'));
            $sessionId = trim($request->input('session_id'));
            $otp = trim($request->input('otp'));

            // Log the OTP verification attempt
            Log::info('Intento de verificación OTP', [
                'documento' => $documento,
                'session_id' => $sessionId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]);

            // Verify OTP
            $isValid = $this->otpService->verifyOtp($documento, $sessionId, $otp);

            if (!$isValid) {
                Log::warning('Verificación OTP fallida', [
                    'documento' => $documento,
                    'session_id' => $sessionId,
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Código OTP inválido o expirado. Por favor, solicita un nuevo código.',
                ], 401);
            }

            // Check if the file is available
            if (!$this->afiliadoService->isFileAvailable()) {
                Log::error('Archivo de afiliados no disponible para verificación OTP');

                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                ], 503);
            }

            // Get complete affiliate information
            $afiliadoInfo = $this->afiliadoService->getCompleteAfiliadoInfo(
                $tipoDocumento,
                $documento,
                $fechaExpedicion
            );

            if (null === $afiliadoInfo) {
                Log::error('Error al obtener información completa del afiliado después de verificación OTP', [
                    'documento' => $documento,
                    'session_id' => $sessionId,
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al obtener información del afiliado',
                ], 500);
            }

            // Invalidate OTP session after successful verification
            $this->otpService->invalidateOtp($documento, $sessionId);

            // Obfuscate sensitive data in afiliado information
            if (isset($afiliadoInfo['afiliado']) && is_array($afiliadoInfo['afiliado'])) {
                $afiliadoInfo['afiliado'] = $this->obfuscationService->obfuscateAfiliadoData($afiliadoInfo['afiliado']);
            }

            // Log successful verification
            Log::info('Verificación OTP exitosa y datos del afiliado obtenidos', [
                'documento' => $documento,
                'session_id' => $sessionId,
                'ip_address' => $request->ip(),
                'convenios_count' => count($afiliadoInfo['convenios'] ?? []),
                'beneficiarios_count' => count($afiliadoInfo['beneficiarios'] ?? []),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Autenticación exitosa',
                'data' => $afiliadoInfo,
            ]);
        } catch (\Exception $e) {
            Log::error('Error inesperado en verificación de OTP', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tipo_documento' => $request->input('tipo_documento'),
                'documento' => $request->input('documento'),
                'fecha_expedicion' => $request->input('fecha_expedicion'),
                'session_id' => $request->input('session_id'),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Obfuscate email address for display (more restrictive)
     * Example: juan.perez@example.com -> j***z@example.com
     * Example: juan@example.com -> j***n@example.com.
     */
    private function obfuscateEmail(string $email): string
    {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '***@***.***';
        }

        [$localPart, $domain] = explode('@', $email, 2);

        // Remove dots for obfuscation purposes
        $localPartClean = str_replace('.', '', $localPart);
        $length = strlen($localPartClean);

        // If very short (1-2 characters), show only asterisks
        if ($length <= 2) {
            $obfuscatedLocal = '***';
        } elseif (3 === $length) {
            // For 3 chars: show first and last
            $obfuscatedLocal = substr($localPartClean, 0, 1) . '***' . substr($localPartClean, -1);
        } else {
            // For 4+ chars: show first and last character only
            $obfuscatedLocal = substr($localPartClean, 0, 1) . '***' . substr($localPartClean, -1);
        }

        return $obfuscatedLocal . '@' . $domain;
    }
}
