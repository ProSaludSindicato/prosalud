<?php

namespace App\Http\Controllers;

use App\Services\CertificadoConvenioService;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\{Log, Validator};
use Symfony\Component\HttpFoundation\{BinaryFileResponse, StreamedResponse};

class CertificadoConvenioController extends Controller
{
    public function __construct(
        private readonly CertificadoConvenioService $certificadoService
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

}

