<?php

namespace App\Http\Controllers;

use App\Services\AfiliadoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AfiliadoController extends Controller
{
    private AfiliadoService $afiliadoService;

    public function __construct(AfiliadoService $afiliadoService)
    {
        $this->afiliadoService = $afiliadoService;
    }

    /**
     * Authenticate and get affiliate information
     */
    public function authenticate(Request $request): JsonResponse
    {
        try {
            // Validate input
            $request->validate([
                'tipo_documento' => 'required|string|max:50',
                'documento' => 'required|string|max:50',
                'fecha_expedicion' => 'required|string|max:50'
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
                'timestamp' => now()->toISOString()
            ]);

            // Check if the file is available
            if (!$this->afiliadoService->isFileAvailable()) {
                Log::error('Archivo de afiliados no disponible');
                return response()->json([
                    'success' => false,
                    'message' => 'Servicio temporalmente no disponible',
                    'afiliado' => null
                ], 503);
            }

            // Authenticate and get affiliate
            $afiliado = $this->afiliadoService->authenticateAndGetAfiliado(
                $tipoDocumento,
                $documento,
                $fechaExpedicion
            );

            if ($afiliado === null) {
                Log::warning('Autenticación fallida - afiliado no encontrado', [
                    'tipo_documento' => $tipoDocumento,
                    'documento' => $documento,
                    'fecha_expedicion' => $fechaExpedicion,
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toISOString()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incorrectas o afiliado no encontrado',
                    'afiliado' => null
                ], 401);
            }

            // Log successful authentication
            Log::info('Autenticación exitosa', [
                'documento' => $documento,
                'ip_address' => $request->ip(),
                'convenios_count' => count($afiliado['convenios'] ?? []),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Autenticación exitosa',
                'afiliado' => $afiliado
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validación fallida en autenticación de afiliado', [
                'errors' => $e->errors(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
                'afiliado' => null
            ], 422);

        } catch (\Exception $e) {
            Log::error('Error inesperado en autenticación de afiliado', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tipo_documento' => $request->input('tipo_documento'),
                'documento' => $request->input('documento'),
                'fecha_expedicion' => $request->input('fecha_expedicion'),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'afiliado' => null
            ], 500);
        }
    }
}
