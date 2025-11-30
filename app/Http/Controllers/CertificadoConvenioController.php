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

            // Generar URL temporal usando el método privado
            $urlData = $this->generarUrlTemporalCertificado($certificado);
            
            if (!$urlData['pdf_url']) {
                Log::error('Certificado encontrado en BD pero no se pudo generar URL', [
                    'documento' => $documento,
                    'consecutivo' => $consecutivo,
                    'storage_path' => $certificado->storage_path,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'El archivo del certificado no está disponible.',
                ], 404);
            }

            $urlTemporal = $urlData['pdf_url'];
            $urlExpiresAt = $urlData['url_expires_at'];

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
     * Lista los certificados de convenio generados con filtros opcionales
     * Permite filtrar por documento, consecutivo y rango de fechas
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'documento' => 'nullable|string',
                'consecutivo' => 'nullable|string',
                'fecha_desde' => 'nullable|date|date_format:Y-m-d',
                'fecha_hasta' => 'nullable|date|date_format:Y-m-d|after_or_equal:fecha_desde',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $query = CertificadoConvenioRecord::query();

            // Filtro por número de documento
            if ($request->has('documento') && $request->filled('documento')) {
                $documento = trim($request->input('documento'));
                $query->where('document_number', 'like', "%{$documento}%");
            }

            // Filtro por consecutivo
            if ($request->has('consecutivo') && $request->filled('consecutivo')) {
                $consecutivo = trim($request->input('consecutivo'));
                $query->where('consecutivo', 'like', "%{$consecutivo}%");
            }

            // Filtro por rango de fechas
            $fechaDesde = $request->input('fecha_desde');
            $fechaHasta = $request->input('fecha_hasta');
            
            if ($fechaDesde || $fechaHasta) {
                $query->byFechaRango($fechaDesde, $fechaHasta);
            }

            // Ordenar por fecha de generación (más recientes primero)
            $query->orderBy('generated_at', 'desc');

            // Paginación
            $perPage = $request->input('per_page', 15);
            $certificados = $query->paginate($perPage);

            // Formatear los resultados con URLs temporales
            $certificadosFormateados = $certificados->getCollection()->map(function ($certificado) {
                $urlData = $this->generarUrlTemporalCertificado($certificado);
                
                return [
                    'id' => $certificado->id,
                    'document_number' => $certificado->document_number,
                    'consecutivo' => $certificado->consecutivo,
                    'generated_at' => $certificado->generated_at->format('Y-m-d H:i:s'),
                    'generated_at_formatted' => $certificado->generated_at->format('d/m/Y H:i:s'),
                    'storage_path' => $certificado->storage_path,
                    'pdf_url' => $urlData['pdf_url'] ?? null,
                    'url_expires_at' => $urlData['url_expires_at'] ?? null,
                ];
            });

            Log::info('Lista de certificados de convenio consultada', [
                'total' => $certificados->total(),
                'filters' => $request->only(['documento', 'consecutivo', 'fecha_desde', 'fecha_hasta']),
            ]);

            return response()->json([
                'success' => true,
                'data' => $certificadosFormateados,
                'pagination' => [
                    'total' => $certificados->total(),
                    'per_page' => $certificados->perPage(),
                    'current_page' => $certificados->currentPage(),
                    'last_page' => $certificados->lastPage(),
                    'from' => $certificados->firstItem(),
                    'to' => $certificados->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error consultando lista de certificados de convenio', [
                'filters' => $request->only(['documento', 'consecutivo', 'fecha_desde', 'fecha_hasta']),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al consultar los certificados: ' . $e->getMessage(),
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

    /**
     * Genera una URL temporal para un certificado
     * Retorna la URL y la fecha de expiración, o null si hay error
     * 
     * @param CertificadoConvenioRecord $certificado
     * @return array{pdf_url: string|null, url_expires_at: string|null}
     */
    private function generarUrlTemporalCertificado(CertificadoConvenioRecord $certificado): array
    {
        // Intentar encontrar el archivo en los discos disponibles
        $disks = ['prosalud-private', 'local'];
        $storage = null;
        
        foreach ($disks as $diskName) {
            $testStorage = Storage::disk($diskName);
            if ($testStorage->exists($certificado->storage_path)) {
                $storage = $testStorage;
                break;
            }
        }
        
        if (!$storage) {
            Log::warning('Archivo de certificado no encontrado en storage', [
                'certificado_id' => $certificado->id,
                'storage_path' => $certificado->storage_path,
            ]);
            return ['pdf_url' => null, 'url_expires_at' => null];
        }

        $urlTemporal = null;
        $urlExpiresAt = null;

        try {
            // Intentar generar URL temporal (soportado por S3 y otros drivers)
            try {
                if (method_exists($storage, 'temporaryUrl')) {
                    $expiresAt = now()->addHours(1);
                    $urlTemporal = call_user_func([$storage, 'temporaryUrl'], $certificado->storage_path, $expiresAt);
                    $urlExpiresAt = $expiresAt->toIso8601String();
                } else {
                    throw new \Exception('Método temporaryUrl no disponible');
                }
            } catch (\Exception $tempUrlError) {
                // Si falla la URL temporal, intentar URL directa
                Log::warning('No se pudo generar URL temporal, usando URL directa', [
                    'certificado_id' => $certificado->id,
                    'error' => $tempUrlError->getMessage(),
                ]);
                try {
                    if (method_exists($storage, 'url')) {
                        $urlTemporal = call_user_func([$storage, 'url'], $certificado->storage_path);
                        // Las URLs directas no tienen expiración
                        $urlExpiresAt = null;
                    } else {
                        throw new \Exception('Método url no disponible');
                    }
                } catch (\Exception $urlError) {
                    Log::error('Error generando URL para certificado', [
                        'certificado_id' => $certificado->id,
                        'error' => $urlError->getMessage(),
                    ]);
                    return ['pdf_url' => null, 'url_expires_at' => null];
                }
            }
        } catch (\Exception $e) {
            Log::error('Error generando URL para certificado', [
                'certificado_id' => $certificado->id,
                'error' => $e->getMessage(),
            ]);
            return ['pdf_url' => null, 'url_expires_at' => null];
        }

        return [
            'pdf_url' => $urlTemporal,
            'url_expires_at' => $urlExpiresAt,
        ];
    }

}

