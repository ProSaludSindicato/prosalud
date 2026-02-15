<?php

namespace App\Console\Commands;

use App\Models\RequestForm;
use App\Services\{AfiliadoService, CertificadoConvenioService};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BatchUpdateRequestPayloadCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'request-forms:batch-update-payload
                            {ids* : Lista de IDs de solicitudes a actualizar (separadas por espacio)}
                            {--force : Omitir confirmación interactiva}
                            {--dry-run : Mostrar qué se actualizaría sin ejecutar los cambios}
                            {--batch-size=10 : Número de solicitudes a procesar por lote}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Actualizar en lote el campo payload JSON de solicitudes usando información de convenios del afiliado';

    /**
     * Execute the console command.
     */
    public function handle(AfiliadoService $afiliadoService, CertificadoConvenioService $certificadoConvenioService)
    {
        $requestIds = $this->argument('ids');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $batchSize = (int) $this->option('batch-size');

        $this->info('🔧 Actualización Masiva de Payload de Solicitudes');
        $this->line('============================================');

        if (empty($requestIds)) {
            $this->error('❌ Debes proporcionar al menos un ID de solicitud');
            return 1;
        }

        $this->info("📋 Procesando " . count($requestIds) . " solicitudes");
        $this->info("📦 Tamaño de lote: {$batchSize}");
        $this->info("🔍 Modo Dry-Run: " . ($dryRun ? 'SÍ' : 'NO'));

        // Estadísticas
        $stats = [
            'total' => count($requestIds),
            'encontradas' => 0,
            'actualizadas' => 0,
            'con_datos_convenio' => 0,
            'sin_datos_convenio' => 0,
            'errores' => 0,
            'detalles' => []
        ];

        // Procesar en lotes
        $chunks = array_chunk($requestIds, $batchSize);
        $totalChunks = count($chunks);

        foreach ($chunks as $chunkIndex => $chunk) {
            $currentChunk = $chunkIndex + 1;
            $this->info("\n📦 Procesando lote {$currentChunk}/{$totalChunks} (" . count($chunk) . " solicitudes)");

            foreach ($chunk as $requestId) {
                $result = $this->processRequest($requestId, $certificadoConvenioService, $dryRun, $force);
                
                $stats['encontradas'] += $result['encontrada'] ? 1 : 0;
                $stats['actualizadas'] += $result['actualizada'] ? 1 : 0;
                $stats['con_datos_convenio'] += $result['con_datos_convenio'] ? 1 : 0;
                $stats['sin_datos_convenio'] += $result['sin_datos_convenio'] ? 1 : 0;
                $stats['errores'] += $result['error'] ? 1 : 0;

                if ($result['detalle']) {
                    $stats['detalles'][] = $result['detalle'];
                }

                // Mostrar progreso
                $symbol = $result['error'] ? '❌' : ($result['actualizada'] ? '✅' : ($result['encontrada'] ? '⚠️' : '⭕'));
                $this->line("  {$symbol} {$requestId}: {$result['mensaje']}");
            }
        }

        // Mostrar resumen final
        $this->showSummary($stats, $dryRun);

        return $stats['errores'] > 0 ? 1 : 0;
    }

    /**
     * Procesa una solicitud individual
     */
    private function processRequest(string $requestId, CertificadoConvenioService $certificadoConvenioService, bool $dryRun, bool $force): array
    {
        try {
            // Buscar la solicitud
            $requestForm = RequestForm::find($requestId);
            if (!$requestForm) {
                return [
                    'encontrada' => false,
                    'actualizada' => false,
                    'con_datos_convenio' => false,
                    'sin_datos_convenio' => false,
                    'error' => false,
                    'mensaje' => 'No encontrada',
                    'detalle' => null
                ];
            }

            // Verificar si ya tiene los datos
            $payload = $requestForm->payload ?? [];
            if (!empty($payload['proceso']) || !empty($payload['dondeRealizaProceso'])) {
                return [
                    'encontrada' => true,
                    'actualizada' => false,
                    'con_datos_convenio' => false,
                    'sin_datos_convenio' => false,
                    'error' => false,
                    'mensaje' => 'Ya tiene datos',
                    'detalle' => null
                ];
            }

            // Obtener información del afiliado y sus convenios usando el método optimizado
            $documento = $requestForm->document_number;
            $afiliadoData = $certificadoConvenioService->obtenerDatosAfiliado($documento);

            if (!$afiliadoData || empty($afiliadoData['convenio'])) {
                return [
                    'encontrada' => true,
                    'actualizada' => false,
                    'con_datos_convenio' => false,
                    'sin_datos_convenio' => true,
                    'error' => false,
                    'mensaje' => 'Sin datos de convenio',
                    'detalle' => [
                        'id' => $requestId,
                        'documento' => $documento,
                        'nombre' => $requestForm->full_name,
                        'motivo' => 'No se encontraron convenios para el afiliado'
                    ]
                ];
            }

            // Obtener el convenio más reciente (ya viene filtrado por el servicio)
            $convenio = $afiliadoData['convenio'];
            if (!$convenio) {
                return [
                    'encontrada' => true,
                    'actualizada' => false,
                    'con_datos_convenio' => false,
                    'sin_datos_convenio' => true,
                    'error' => false,
                    'mensaje' => 'Sin convenio válido',
                    'detalle' => [
                        'id' => $requestId,
                        'documento' => $documento,
                        'nombre' => $requestForm->full_name,
                        'motivo' => 'No se encontró un convenio válido'
                    ]
                ];
            }

            // Preparar los cambios
            $changes = [];
            $newPayload = $payload;

            if (!empty($convenio['proceso'])) {
                $changes['proceso'] = [
                    'antes' => $payload['proceso'] ?? null,
                    'después' => $convenio['proceso']
                ];
                $newPayload['proceso'] = $convenio['proceso'];
            }

            if (!empty($convenio['cliente'])) {
                $changes['dondeRealizaProceso'] = [
                    'antes' => $payload['dondeRealizaProceso'] ?? null,
                    'después' => $convenio['cliente']
                ];
                $newPayload['dondeRealizaProceso'] = $convenio['cliente'];
            }

            if (empty($changes)) {
                return [
                    'encontrada' => true,
                    'actualizada' => false,
                    'con_datos_convenio' => true,
                    'sin_datos_convenio' => false,
                    'error' => false,
                    'mensaje' => 'Convenio sin datos útiles',
                    'detalle' => [
                        'id' => $requestId,
                        'documento' => $documento,
                        'nombre' => $requestForm->full_name,
                        'convenio' => $convenio,
                        'motivo' => 'El convenio no tiene proceso o cliente'
                    ]
                ];
            }

            // Si es dry-run, solo mostrar los cambios
            if ($dryRun) {
                return [
                    'encontrada' => true,
                    'actualizada' => false,
                    'con_datos_convenio' => true,
                    'sin_datos_convenio' => false,
                    'error' => false,
                    'mensaje' => 'Dry-run - Se actualizaría',
                    'detalle' => [
                        'id' => $requestId,
                        'documento' => $documento,
                        'nombre' => $requestForm->full_name,
                        'cambios' => $changes,
                        'convenio' => $convenio
                    ]
                ];
            }

            // Aplicar cambios
            if (!$force && !$this->confirm("¿Actualizar solicitud {$requestId} ({$requestForm->full_name})?")) {
                return [
                    'encontrada' => true,
                    'actualizada' => false,
                    'con_datos_convenio' => true,
                    'sin_datos_convenio' => false,
                    'error' => false,
                    'mensaje' => 'Cancelada por usuario',
                    'detalle' => null
                ];
            }

            DB::transaction(function () use ($requestForm, $newPayload) {
                $requestForm->payload = $newPayload;
                $requestForm->save();
            });

            return [
                'encontrada' => true,
                'actualizada' => true,
                'con_datos_convenio' => true,
                'sin_datos_convenio' => false,
                'error' => false,
                'mensaje' => 'Actualizada',
                'detalle' => [
                    'id' => $requestId,
                    'documento' => $documento,
                    'nombre' => $requestForm->full_name,
                    'cambios' => $changes,
                    'convenio' => $convenio
                ]
            ];

        } catch (\Exception $e) {
            Log::error("Error procesando solicitud {$requestId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'encontrada' => false,
                'actualizada' => false,
                'con_datos_convenio' => false,
                'sin_datos_convenio' => false,
                'error' => true,
                'mensaje' => 'Error: ' . $e->getMessage(),
                'detalle' => [
                    'id' => $requestId,
                    'error' => $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Muestra el resumen final del procesamiento
     */
    private function showSummary(array $stats, bool $dryRun): void
    {
        $this->line("\n" . str_repeat("=", 50));
        $this->info("📊 RESUMEN DEL PROCESAMIENTO");
        $this->line(str_repeat("=", 50));

        $this->line("📋 Total solicitudes procesadas: {$stats['total']}");
        $this->line("✅ Solicitudes encontradas: {$stats['encontradas']}");
        $this->line("🔄 Solicitudes actualizadas: " . ($dryRun ? "({$stats['actualizadas']} se actualizarían)" : $stats['actualizadas']));
        $this->line("📦 Con datos de convenio: {$stats['con_datos_convenio']}");
        $this->line("⚠️  Sin datos de convenio: {$stats['sin_datos_convenio']}");
        $this->line("❌ Errores: {$stats['errores']}");

        if (!empty($stats['detalles'])) {
            $this->line("\n📋 Detalles de actualizaciones:");
            foreach ($stats['detalles'] as $detalle) {
                if ($detalle) {
                    $this->line("  • ID {$detalle['id']} ({$detalle['documento']}): {$detalle['nombre']}");
                    if (isset($detalle['cambios'])) {
                        foreach ($detalle['cambios'] as $campo => $cambio) {
                            $this->line("    - {$campo}: '{$cambio['antes']}' → '{$cambio['después']}'");
                        }
                    }
                    if (isset($detalle['motivo'])) {
                        $this->line("    ℹ️  {$detalle['motivo']}");
                    }
                }
            }
        }

        if (!$dryRun && $stats['actualizadas'] > 0) {
            $this->info("\n✅ Proceso completado. {$stats['actualizadas']} solicitudes actualizadas exitosamente.");
        } elseif ($dryRun) {
            $this->info("\n🔍 Modo Dry-Run completado. {$stats['actualizadas']} solicitudes se actualizarían.");
        }
    }
}
