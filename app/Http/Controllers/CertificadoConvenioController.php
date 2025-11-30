<?php

namespace App\Http\Controllers;

use App\Services\{CertificadoConvenioService, CertificadoConvenioAutomaticoService};
use App\Models\CertificadoConvenioRecord;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\{Log, Storage, Validator};
use Symfony\Component\HttpFoundation\{BinaryFileResponse, StreamedResponse};

class CertificadoConvenioController extends Controller
{
    public function __construct(
        private readonly CertificadoConvenioService $certificadoService,
        private readonly CertificadoConvenioAutomaticoService $certificadoAutomaticoService
    ) {
    }

    /**
     * Genera un certificado individual en formato PDF
     */
    public function generar(Request $request): BinaryFileResponse|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'documento' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Genera PDF desde la plantilla Word usando CloudConvert
            $resultado = $this->certificadoService->generarCertificadoPDF($request->documento);

            if (isset($resultado['ruta']) && file_exists($resultado['ruta'])) {
                // Asegurar que el nombre tenga extensión .pdf
                $nombreArchivo = $resultado['nombre'];
                if (!str_ends_with($nombreArchivo, '.pdf')) {
                    $nombreArchivo .= '.pdf';
                }
                
                return response()->download(
                    $resultado['ruta'],
                    $nombreArchivo,
                    [
                        'Content-Type' => 'application/pdf',
                    ]
                )->deleteFileAfterSend(true);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se pudo generar el certificado',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error generando certificado de convenio', [
                'documento' => $request->documento,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el certificado: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Genera un certificado individual en formato Word
     */
    public function generarWord(Request $request): BinaryFileResponse|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'documento' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $resultado = $this->certificadoService->generarCertificadoWord($request->documento);

            return response()->download(
                $resultado['ruta'],
                $resultado['nombre'],
                [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ]
            )->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('Error generando certificado de convenio (Word)', [
                'documento' => $request->documento,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el certificado: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Consulta y valida un certificado por documento y consecutivo
     * Retorna una URL temporal firmada para visualizar el PDF
     */
    public function consultar(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'documento' => 'required|string',
            'consecutivo' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $documento = $request->input('documento');
            $consecutivo = $request->input('consecutivo');

            // Buscar el certificado en la base de datos
            $certificado = CertificadoConvenioRecord::byDocumentoAndConsecutivo($documento, $consecutivo)->first();

            if (!$certificado) {
                return response()->json([
                    'success' => false,
                    'message' => 'Certificado no encontrado. Verifique el número de documento y el consecutivo.',
                ], 404);
            }

            // Intentar encontrar el archivo en los discos disponibles
            $disks = ['prosalud-private', 'local'];
            $storage = null;
            $disk = null;
            
            foreach ($disks as $diskName) {
                $testStorage = Storage::disk($diskName);
                if ($testStorage->exists($certificado->storage_path)) {
                    $storage = $testStorage;
                    $disk = $diskName;
                    break;
                }
            }
            
            if (!$storage || !$disk) {
                Log::error('Certificado encontrado en BD pero archivo no existe en storage', [
                    'documento' => $documento,
                    'consecutivo' => $consecutivo,
                    'storage_path' => $certificado->storage_path,
                    'disks_tried' => $disks,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'El archivo del certificado no está disponible.',
                ], 404);
            }

            // Generar URL temporal firmada (válida por 1 hora)
            $urlTemporal = null;
            $urlExpiresAt = null;

            try {
                // Intentar generar URL temporal (soportado por S3 y otros drivers)
                try {
                    if (method_exists($storage, 'temporaryUrl')) {
                        $urlTemporal = call_user_func([$storage, 'temporaryUrl'], $certificado->storage_path, now()->addHours(1));
                        $urlExpiresAt = now()->addHours(1)->toIso8601String();
                    } else {
                        throw new \Exception('Método temporaryUrl no disponible');
                    }
                } catch (\Exception $tempUrlError) {
                    // Si falla la URL temporal, intentar URL directa
                    Log::warning('No se pudo generar URL temporal, usando URL directa', [
                        'error' => $tempUrlError->getMessage(),
                    ]);
                    try {
                        if (method_exists($storage, 'url')) {
                            $urlTemporal = call_user_func([$storage, 'url'], $certificado->storage_path);
                        } else {
                            throw new \Exception('Método url no disponible');
                        }
                    } catch (\Exception $urlError) {
                        throw new \Exception("No se pudo generar URL para el archivo: " . $urlError->getMessage());
                    }
                }
            } catch (\Exception $e) {
                Log::error('Error generando URL para certificado', [
                    'documento' => $documento,
                    'consecutivo' => $consecutivo,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar URL de acceso al certificado.',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'document_number' => $certificado->document_number,
                    'consecutivo' => $certificado->consecutivo,
                    'generated_at' => $certificado->generated_at->format('Y-m-d H:i:s'),
                    'pdf_url' => $urlTemporal,
                    'url_expires_at' => $urlExpiresAt,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error consultando certificado de convenio', [
                'documento' => $request->input('documento'),
                'consecutivo' => $request->input('consecutivo'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al consultar el certificado: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Solicita un certificado de convenio automáticamente desde el frontend
     * Crea RequestForm, genera certificado, envía correos y cierra la solicitud automáticamente
     */
    public function solicitarAutomatico(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'documento' => 'required|string',
            'tipo_documento' => 'nullable|string|in:CC,CE,TI,PA',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string',
            'tipo_certificado' => 'nullable|string',
            'dirigido_a_entidad' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $solicitudData = [
                'documento' => $request->input('documento'),
                'tipo_documento' => $request->input('tipo_documento', 'CC'),
                'email' => $request->input('email'),
                'telefono' => $request->input('telefono'),
                'tipo_certificado' => $request->input('tipo_certificado', 'fecha_ingreso_retiro'),
                'dirigido_a_entidad' => $request->input('dirigido_a_entidad', ''),
            ];

            Log::info('Iniciando solicitud automática de certificado de convenio', [
                'documento' => $solicitudData['documento'],
                'ip' => $request->ip(),
            ]);

            // Procesar solicitud automática
            $resultado = $this->certificadoAutomaticoService->procesarSolicitudAutomatica($solicitudData);

            return response()->json([
                'success' => true,
                'message' => 'Solicitud procesada exitosamente. Su certificado ha sido enviado por correo electrónico.',
                'data' => [
                    'request_id' => $resultado['request_id'],
                    'consecutivo' => $resultado['consecutivo'],
                    'status' => $resultado['status'],
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en solicitud automática de certificado de convenio', [
                'documento' => $request->input('documento'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la solicitud: ' . $e->getMessage(),
            ], 500);
        }
    }

}

