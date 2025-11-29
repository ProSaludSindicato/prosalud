<?php

namespace App\Http\Controllers;

use App\Services\DocxToPdfCloudConvertService;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\Log;
use Exception;

class ConvertDocumentController extends Controller
{
    public function __construct(
        private readonly DocxToPdfCloudConvertService $converterService
    ) {
    }

    /**
     * Convierte un archivo .docx a .pdf
     *
     * @param Request $request
     * @return Response|JsonResponse
     */
    public function convertDocxToPdf(Request $request): Response|JsonResponse
    {
        try {
            // Validar request
            $request->validate([
                'file_path' => 'required|string',
                'download' => 'sometimes|boolean',
            ]);

            $docxPath = $request->input('file_path');
            $download = $request->input('download', false);

            // Si la ruta es relativa, convertir a absoluta
            if (!file_exists($docxPath)) {
                // Intentar como ruta relativa desde storage
                $storagePath = storage_path('app/' . ltrim($docxPath, '/'));
                if (file_exists($storagePath)) {
                    $docxPath = $storagePath;
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'El archivo .docx no existe en la ruta especificada',
                        'file_path' => $docxPath,
                    ], 404);
                }
            }

            // Convertir a PDF
            $result = $this->converterService->convert($docxPath, !$download);

            if ($download) {
                // Retornar contenido binario como descarga
                $pdfContent = $result['content'];
                $originalName = pathinfo($docxPath, PATHINFO_FILENAME);

                return response($pdfContent, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $originalName . '.pdf"',
                    'Content-Length' => $result['size'],
                ]);
            } else {
                // Retornar ruta del archivo guardado
                return response()->json([
                    'success' => true,
                    'message' => 'Archivo convertido exitosamente',
                    'pdf_path' => $result['path'],
                    'size' => $result['size'],
                ]);
            }
        } catch (Exception $e) {
            Log::error('Error en ConvertDocumentController::convertDocxToPdf', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al convertir el archivo',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ruta de prueba para verificar la configuración y autenticación
     *
     * @return JsonResponse
     */
    public function test(): JsonResponse
    {
        try {
            $apiKey = config('cloudconvert.api_key');
            $apiKeyLength = strlen($apiKey ?? '');
            $apiKeyPrefix = $apiKey ? substr($apiKey, 0, 20) . '...' : null;
            
            $config = [
                'api_key_configured' => !empty($apiKey),
                'api_key_length' => $apiKeyLength,
                'api_key_prefix' => $apiKeyPrefix,
                'timeout' => config('cloudconvert.timeout'),
                'max_file_size' => config('cloudconvert.max_file_size'),
                'temp_storage_path' => config('cloudconvert.temp_storage_path'),
            ];

            // Intentar hacer una llamada de prueba a la API para verificar autenticación
            $authTest = [
                'success' => false,
                'message' => 'No se pudo verificar la autenticación',
            ];

            try {
                // Intentar obtener información del usuario (endpoint simple que requiere autenticación)
                $baseUrl = config('cloudconvert.base_url', 'https://api.cloudconvert.com/v2');
                $apiKey = config('cloudconvert.api_key');
                
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                ])
                ->timeout(10)
                ->get($baseUrl . '/users/me');
                
                if ($response->successful()) {
                    $userData = $response->json('data');
                    $authTest = [
                        'success' => true,
                        'message' => 'Autenticación exitosa',
                        'user_id' => $userData['id'] ?? 'N/A',
                    ];
                } else {
                    $errorBody = $response->json();
                    $authTest = [
                        'success' => false,
                        'message' => 'Error de autenticación: ' . ($errorBody['message'] ?? 'Error desconocido'),
                        'status_code' => $response->status(),
                    ];
                }
            } catch (\Exception $authException) {
                $authTest = [
                    'success' => false,
                    'message' => 'Error de autenticación: ' . $authException->getMessage(),
                    'error_class' => get_class($authException),
                ];
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Configuración de CloudConvert verificada',
                'config' => $config,
                'authentication_test' => $authTest,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar configuración',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }
}

