<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchCandidateVotingRequest;
use App\Models\Assembly;
use App\Services\AfiliadoService;
use App\Services\AssemblyAttendanceService;
use App\Services\ExcelReaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ActivosController extends Controller
{
    public function __construct(
        private ExcelReaderService $excelReaderService,
        private AssemblyAttendanceService $attendanceService,
        private AfiliadoService $afiliadoService,
    ) {}

    /**
     * Search for an affiliate by document number and expedition date in the asamblea delegados file.
     */
    public function searchHospital(Request $request): JsonResponse
    {
        try {
            // Validate input
            $request->validate([
                'documento' => 'required|string|max:50',
                'fecha_expedicion' => 'required|string|max:50',
                'signature' => 'required|string',
            ]);

            $documento = trim($request->input('documento'));
            $fechaExpedicion = trim($request->input('fecha_expedicion'));

            // Log the search attempt
            Log::info('Búsqueda de afiliado en asamblea delegados', [
                'documento' => $documento,
                'fecha_expedicion' => $fechaExpedicion,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]);

            if (! Assembly::getCurrent()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay una asamblea activa. El administrador debe activar una asamblea.',
                    'data' => null,
                ], 503);
            }

            if (! $this->excelReaderService->isAsambleaDelegadosFileAvailable()) {
                Log::error('Archivo de delegados no configurado para la asamblea activa');

                return response()->json([
                    'success' => false,
                    'message' => 'Aún no se ha cargado la lista de delegados para la asamblea activa. Contacte al administrador.',
                    'data' => null,
                ], 503);
            }

            // Search for the affiliate
            $afiliado = $this->excelReaderService->searchAfiliadoInAsamblea(
                $documento,
                $fechaExpedicion
            );

            $response = [
                'success' => true,
                'data' => $afiliado,
                'found' => $afiliado !== null,
            ];

            if ($afiliado) {
                $this->attendanceService->record(
                    $afiliado['cedula'] ?? $documento,
                    $afiliado['nombre_apellidos'] ?? null,
                    $afiliado['fecha_expedicion'] ?? $fechaExpedicion,
                    $request,
                    $request->input('signature')
                );
            }

            // Log the result
            Log::info('Resultado de búsqueda de afiliado', [
                'documento' => $documento,
                'fecha_expedicion' => $fechaExpedicion,
                'encontrado' => $afiliado !== null,
                'afiliado' => $afiliado,
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json($response);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validación fallida en búsqueda de afiliado', [
                'errors' => $e->errors(),
                'ip_address' => $request->ip(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
                'data' => null,
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error inesperado en búsqueda de afiliado', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'documento' => $request->input('documento'),
                'fecha_expedicion' => $request->input('fecha_expedicion'),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Search for an affiliate in the activos (PROSANET) file for delegate candidate voting.
     * Does not use the assembly delegates list; returns hospital/sede for filtering candidates.
     */
    public function searchCandidateVoting(SearchCandidateVotingRequest $request): JsonResponse
    {
        try {
            $tipoDocumento = trim($request->input('tipo_documento'));
            $documento = trim($request->input('documento'));
            $fechaExpedicion = trim($request->input('fecha_expedicion'));

            Log::info('Búsqueda de afiliado en activos para votación de candidatos', [
                'tipo_documento' => $tipoDocumento,
                'documento' => $documento,
                'fecha_expedicion' => $fechaExpedicion,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]);

            if (! $this->afiliadoService->isFileAvailable()) {
                Log::error('Archivo de afiliados activos (PROSANET) no disponible para votación de candidatos');

                return response()->json([
                    'success' => false,
                    'message' => 'El listado de afiliados activos no está disponible. Contacte al administrador.',
                    'data' => null,
                ], 503);
            }

            $afiliadoRaw = $this->afiliadoService->authenticateAndGetAfiliado(
                $tipoDocumento,
                $documento,
                $fechaExpedicion
            );

            $afiliadoData = null;

            if ($afiliadoRaw !== null) {
                $nombres = trim((string) ($afiliadoRaw['nombres'] ?? ''));
                $apellidos = trim((string) ($afiliadoRaw['apellidos'] ?? ''));
                $nombreApellidos = trim($nombres.' '.$apellidos);
                $hospital = trim((string) ($afiliadoRaw['hospital'] ?? ''));

                if ($nombreApellidos !== '' && $hospital !== '') {
                    $afiliadoData = [
                        'nombre_apellidos' => $nombreApellidos,
                        'hospital' => $hospital,
                    ];
                }
            }

            $response = [
                'success' => true,
                'data' => $afiliadoData,
                'found' => $afiliadoData !== null,
            ];

            Log::info('Resultado de búsqueda de afiliado (candidatos)', [
                'documento' => $documento,
                'encontrado' => $afiliadoData !== null,
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error inesperado en búsqueda de afiliado para candidatos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'documento' => $request->input('documento'),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'data' => null,
            ], 500);
        }
    }
}
