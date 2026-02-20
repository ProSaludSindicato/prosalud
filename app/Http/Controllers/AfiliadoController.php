<?php

namespace App\Http\Controllers;

use App\Http\Requests\{AfiliadoRequestOtpRequest, AfiliadoVerifyOtpRequest};
use App\Mail\AfiliadoOtpCode;
use App\Services\{AfiliadoService, LogSanitizationService, ObfuscationService, OtpService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Log, Mail};

class AfiliadoController extends Controller
{
    private AfiliadoService $afiliadoService;
    public function __construct(
        AfiliadoService $afiliadoService,
        OtpService $otpService,
        ObfuscationService $obfuscationService,
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
        // Increase execution time limit for Excel processing
        set_time_limit(120);
        
        try {
            // Validate input
            $request->validate([
                'tipo_documento' => 'required|string|max:50',
                'documento' => 'required|string|max:50',
                'fecha_expedicion' => 'required|string|max:50',
            ]);

            $tipoDocumento = trim($request->input('tipo_documento'));
            $documento = trim($request->input('documento'));
            $fechaExpedicion = trim($request->input('fecha_expedicion'));

            // Log the authentication attempt (sanitized)
            Log::info('Intento de autenticación de afiliado', LogSanitizationService::sanitize([
                'tipo_documento' => $tipoDocumento,
                'documento' => $documento,
                'fecha_expedicion' => $fechaExpedicion,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]));

            // Check if the file is available
            if (!$this->afiliadoService->isFileAvailable()) {
                Log::error('Archivo de afiliados no disponible');

                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                    'afiliado' => null,
                ], 503);
            }

            // Authenticate and get affiliate with detailed failure reason for the frontend
            $authResult = $this->afiliadoService->authenticateAndGetAfiliadoDetailed(
                $tipoDocumento,
                $documento,
                $fechaExpedicion
            );

            $status = $authResult['status'];
            $afiliado = $authResult['afiliado'] ?? null;

            if ($status !== 'success') {
                $reason = $status === 'affiliate_data_mismatch'
                    ? 'affiliate_data_mismatch'
                    : 'affiliate_not_found';
                Log::warning('Autenticación fallida', LogSanitizationService::sanitize([
                    'auth_failure_reason' => $reason,
                    'tipo_documento' => $tipoDocumento,
                    'documento' => $documento,
                    'fecha_expedicion' => $fechaExpedicion,
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                ]));

                $message = $reason === 'affiliate_data_mismatch'
                    ? 'El número de documento existe pero el tipo de documento o la fecha de expedición no coinciden. Verifica los datos.'
                    : 'No existe un afiliado con ese número de documento.';

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'auth_failure_reason' => $reason,
                    'afiliado' => null,
                ], 401);
            }

            // Log successful authentication (sanitized)
            Log::info('Autenticación exitosa', LogSanitizationService::sanitize([
                'documento' => $documento,
                'ip_address' => $request->ip(),
                'convenios_count' => count($afiliado['convenios'] ?? []),
                'timestamp' => now()->toISOString(),
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Autenticación exitosa',
                'afiliado' => $afiliado,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validación fallida en autenticación de afiliado', LogSanitizationService::sanitize([
                'errors' => $e->errors(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]));

            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
                'afiliado' => null,
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error inesperado en autenticación de afiliado', LogSanitizationService::sanitize([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tipo_documento' => $request->input('tipo_documento'),
                'documento' => $request->input('documento'),
                'fecha_expedicion' => $request->input('fecha_expedicion'),
                'timestamp' => now()->toISOString(),
            ]));

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

            // Log the OTP request attempt (sanitized)
            Log::info('Solicitud de código OTP para afiliado', LogSanitizationService::sanitize([
                'tipo_documento' => $tipoDocumento,
                'documento' => $documento,
                'fecha_expedicion' => $fechaExpedicion,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]));

            // Check rate limiting
            if (!$this->otpService->checkRateLimit($documento)) {
                Log::warning('Rate limit excedido para solicitud OTP', LogSanitizationService::sanitize([
                    'documento' => $documento,
                    'ip_address' => $request->ip(),
                ]));

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
                Log::warning('Solicitud OTP fallida - credenciales inválidas o sin correo', LogSanitizationService::sanitize([
                    'tipo_documento' => $tipoDocumento,
                    'documento' => $documento,
                    'fecha_expedicion' => $fechaExpedicion,
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                ]));

                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incorrectas o el afiliado no tiene correo electrónico registrado',
                ], 401);
            }

            // Generate OTP
            $otpCode = $this->otpService->generateOtp();
            $sessionId = $this->otpService->storeOtp($documento, $otpCode);

            // Obfuscate email for frontend display
            $obfuscatedEmail = $this->obfuscateEmail($afiliadoData['correo']);

            // Preparar la respuesta antes de enviar el correo
            $response = response()->json([
                'success' => true,
                'message' => 'Código de verificación enviado exitosamente a tu correo electrónico',
                'session_id' => $sessionId,
                'email_obfuscated' => $obfuscatedEmail,
            ]);

            // Enviar OTP por correo de forma asíncrona después de enviar la respuesta HTTP
            // Esto evita que el envío de correo bloquee la respuesta al frontend
            $otpCodeForEmail = $otpCode;
            $afiliadoDataForEmail = $afiliadoData;
            $requestIp = $request->ip();
            dispatch(function () use ($otpCodeForEmail, $afiliadoDataForEmail, $sessionId, $requestIp) {
                try {
                    Mail::to($afiliadoDataForEmail['correo'])->send(
                        new AfiliadoOtpCode($otpCodeForEmail, $afiliadoDataForEmail['nombre'])
                    );

                    Log::info('Código OTP enviado exitosamente', LogSanitizationService::sanitize([
                        'documento' => $afiliadoDataForEmail['documento'] ?? 'N/A',
                        'correo' => $afiliadoDataForEmail['correo'],
                        'session_id' => $sessionId,
                        'ip_address' => $requestIp,
                        'timestamp' => now()->toISOString(),
                    ]));
                } catch (\Exception $e) {
                    Log::error('Error al enviar código OTP por correo', LogSanitizationService::sanitize([
                        'error' => $e->getMessage(),
                        'documento' => $afiliadoDataForEmail['documento'] ?? 'N/A',
                        'correo' => $afiliadoDataForEmail['correo'],
                        'trace' => $e->getTraceAsString(),
                        'timestamp' => now()->toISOString(),
                    ]));
                }
            })->afterResponse();

            return $response;
        } catch (\Exception $e) {
            Log::error('Error inesperado en solicitud de OTP', LogSanitizationService::sanitize([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tipo_documento' => $request->input('tipo_documento'),
                'documento' => $request->input('documento'),
                'fecha_expedicion' => $request->input('fecha_expedicion'),
                'timestamp' => now()->toISOString(),
            ]));

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
        // Increase execution time limit for Excel processing
        set_time_limit(120);
        
        try {
            $tipoDocumento = trim($request->input('tipo_documento'));
            $documento = trim($request->input('documento'));
            $fechaExpedicion = trim($request->input('fecha_expedicion'));
            $sessionId = trim($request->input('session_id'));
            $otp = trim($request->input('otp'));

            // Log the OTP verification attempt (sanitized)
            Log::info('Intento de verificación OTP', LogSanitizationService::sanitize([
                'documento' => $documento,
                'session_id' => $sessionId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]));

            // Verify OTP
            $isValid = $this->otpService->verifyOtp($documento, $sessionId, $otp);

            if (!$isValid) {
                Log::warning('Verificación OTP fallida', LogSanitizationService::sanitize([
                    'documento' => $documento,
                    'session_id' => $sessionId,
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                ]));

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
                Log::error('Error al obtener información completa del afiliado después de verificación OTP', LogSanitizationService::sanitize([
                    'documento' => $documento,
                    'session_id' => $sessionId,
                    'ip_address' => $request->ip(),
                ]));

                return response()->json([
                    'success' => false,
                    'message' => 'Error al obtener información del afiliado',
                ], 500);
            }

            // Invalidate OTP session after successful verification
            $this->otpService->invalidateOtp($documento, $sessionId);

            // Obfuscate sensitive data in afiliado information, except email and phone after OTP authentication
            if (isset($afiliadoInfo['afiliado']) && is_array($afiliadoInfo['afiliado'])) {
                $obfuscatedData = $this->obfuscationService->obfuscateAfiliadoData($afiliadoInfo['afiliado']);

                // Restaurar correo y celular sin ofuscar después de autenticación OTP exitosa
                if (isset($afiliadoInfo['afiliado']['correo_personal'])) {
                    $obfuscatedData['correo_personal'] = $afiliadoInfo['afiliado']['correo_personal'];
                }
                if (isset($afiliadoInfo['afiliado']['celular'])) {
                    $obfuscatedData['celular'] = $afiliadoInfo['afiliado']['celular'];
                }

                $afiliadoInfo['afiliado'] = $obfuscatedData;
            }

            // Log successful verification (sanitized)
            Log::info('Verificación OTP exitosa y datos del afiliado obtenidos', LogSanitizationService::sanitize([
                'documento' => $documento,
                'session_id' => $sessionId,
                'ip_address' => $request->ip(),
                'convenios_count' => count($afiliadoInfo['convenios'] ?? []),
                'beneficiarios_count' => count($afiliadoInfo['beneficiarios'] ?? []),
                'timestamp' => now()->toISOString(),
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Autenticación exitosa',
                'data' => $afiliadoInfo,
            ]);
        } catch (\Exception $e) {
            Log::error('Error inesperado en verificación de OTP', LogSanitizationService::sanitize([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tipo_documento' => $request->input('tipo_documento'),
                'documento' => $request->input('documento'),
                'fecha_expedicion' => $request->input('fecha_expedicion'),
                'session_id' => $request->input('session_id'),
                'timestamp' => now()->toISOString(),
            ]));

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
            ], 500);
        }
    }

    /**
     * Authenticate and get complete affiliate information for data update.
     * This endpoint is specifically for the personal data update service in the frontend.
     * It returns the same complete information as verifyOtp but without requiring OTP validation.
     */
    public function authenticateForDataUpdate(Request $request): JsonResponse
    {
        // Increase execution time limit for Excel processing
        set_time_limit(120);
        
        try {
            // Validate input
            $request->validate([
                'tipo_documento' => 'required|string|max:50',
                'documento' => 'required|string|max:50',
                'fecha_expedicion' => 'required|string|max:50',
            ]);

            $tipoDocumento = trim($request->input('tipo_documento'));
            $documento = trim($request->input('documento'));
            $fechaExpedicion = trim($request->input('fecha_expedicion'));

            // Log the authentication attempt (sanitized)
            Log::info('Intento de autenticación de afiliado para actualización de datos', LogSanitizationService::sanitize([
                'tipo_documento' => $tipoDocumento,
                'documento' => $documento,
                'fecha_expedicion' => $fechaExpedicion,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]));

            // Check if the file is available
            if (!$this->afiliadoService->isFileAvailable()) {
                Log::error('Archivo de afiliados no disponible para autenticación de actualización de datos');

                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                ], 503);
            }

            // Authenticate with detailed failure reason (affiliate_not_found vs affiliate_data_mismatch)
            $authResult = $this->afiliadoService->authenticateAndGetAfiliadoDetailed(
                $tipoDocumento,
                $documento,
                $fechaExpedicion
            );

            if ($authResult['status'] !== 'success') {
                $reason = $authResult['status'] === 'affiliate_data_mismatch'
                    ? 'affiliate_data_mismatch'
                    : 'affiliate_not_found';
                Log::warning('Autenticación fallida para actualización de datos', LogSanitizationService::sanitize([
                    'auth_failure_reason' => $reason,
                    'tipo_documento' => $tipoDocumento,
                    'documento' => $documento,
                    'fecha_expedicion' => $fechaExpedicion,
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString(),
                ]));

                $message = $reason === 'affiliate_data_mismatch'
                    ? 'El número de documento existe pero el tipo de documento o la fecha de expedición no coinciden. Verifica los datos.'
                    : 'No existe un afiliado con ese número de documento.';

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'auth_failure_reason' => $reason,
                ], 401);
            }

            // Get complete affiliate information (convenios, beneficiarios, etc.) for data update
            $afiliadoInfo = $this->afiliadoService->getCompleteAfiliadoInfo(
                $tipoDocumento,
                $documento,
                $fechaExpedicion
            );

            if (null === $afiliadoInfo) {
                Log::error('Afiliado autenticado pero getCompleteAfiliadoInfo retornó null');
                return response()->json([
                    'success' => false,
                    'message' => 'Error al obtener información del afiliado',
                ], 500);
            }

            // Obfuscate sensitive data in afiliado information, except email, phone and address (for data update)
            // This matches the behavior of verifyOtp for consistency
            if (isset($afiliadoInfo['afiliado']) && is_array($afiliadoInfo['afiliado'])) {
                $obfuscatedData = $this->obfuscationService->obfuscateAfiliadoData($afiliadoInfo['afiliado']);

                // Restaurar correo, celular y dirección sin ofuscar (necesarios para actualización de datos)
                if (isset($afiliadoInfo['afiliado']['correo_personal'])) {
                    $obfuscatedData['correo_personal'] = $afiliadoInfo['afiliado']['correo_personal'];
                }
                if (isset($afiliadoInfo['afiliado']['celular'])) {
                    $obfuscatedData['celular'] = $afiliadoInfo['afiliado']['celular'];
                }
                if (isset($afiliadoInfo['afiliado']['direccion'])) {
                    $obfuscatedData['direccion'] = $afiliadoInfo['afiliado']['direccion'];
                }

                $afiliadoInfo['afiliado'] = $obfuscatedData;
            }

            // Log successful authentication (sanitized)
            Log::info('Autenticación exitosa para actualización de datos', LogSanitizationService::sanitize([
                'documento' => $documento,
                'ip_address' => $request->ip(),
                'convenios_count' => count($afiliadoInfo['convenios'] ?? []),
                'beneficiarios_count' => count($afiliadoInfo['beneficiarios'] ?? []),
                'timestamp' => now()->toISOString(),
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Autenticación exitosa',
                'data' => $afiliadoInfo,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validación fallida en autenticación de afiliado para actualización de datos', LogSanitizationService::sanitize([
                'errors' => $e->errors(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]));

            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error inesperado en autenticación de afiliado para actualización de datos', LogSanitizationService::sanitize([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tipo_documento' => $request->input('tipo_documento'),
                'documento' => $request->input('documento'),
                'fecha_expedicion' => $request->input('fecha_expedicion'),
                'timestamp' => now()->toISOString(),
            ]));

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
