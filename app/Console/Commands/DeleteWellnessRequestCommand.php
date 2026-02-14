<?php

namespace App\Console\Commands;

use App\Models\WellnessRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeleteWellnessRequestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wellness:delete-request 
                            {id : ID de la solicitud de bienestar a eliminar}
                            {--force : Forzar la eliminación sin confirmación}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Eliminar una solicitud de bienestar y su evidencia relacionada (útil para pruebas en producción)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $id = $this->argument('id');
        $force = $this->option('force');

        // Validar que el ID sea un número
        if (!is_numeric($id)) {
            $this->error('❌ El ID debe ser un número válido');
            return 1;
        }

        // Buscar la solicitud
        $wellnessRequest = WellnessRequest::find($id);

        if (!$wellnessRequest) {
            $this->error("❌ No se encontró la solicitud de bienestar con ID: {$id}");
            return 1;
        }

        // Mostrar información de la solicitud
        $this->info('📋 Información de la solicitud a eliminar:');
        $this->table(
            ['Campo', 'Valor'],
            [
                ['ID', $wellnessRequest->id],
                ['Actividad', $wellnessRequest->activity_name],
                ['Centro de Costos', $wellnessRequest->cost_center],
                ['Fecha Propuesta', $wellnessRequest->proposed_date->format('d/m/Y')],
                ['Estado', $wellnessRequest->status],
                ['Solicitante', $wellnessRequest->requester?->name ?? 'N/A'],
                ['Creada', $wellnessRequest->created_at->format('d/m/Y H:i')],
            ]
        );

        // Verificar si tiene actividad realizada
        $hasActivityRealized = $wellnessRequest->activityRealized()->exists();
        
        if ($hasActivityRealized) {
            $this->warn('⚠️  Esta solicitud tiene una actividad realizada registrada');
            $activityRealized = $wellnessRequest->activityRealized;
            $this->info('📸 Evidencias asociadas:');
            $this->table(
                ['ID', 'Fecha Realización', 'Estado', 'Evidencias'],
                [[
                    $activityRealized->id,
                    $activityRealized->realized_date->format('d/m/Y'),
                    $activityRealized->status,
                    $activityRealized->evidences->count() . ' archivos'
                ]]
            );
        }

        // Confirmar eliminación (a menos que se use --force)
        if (!$force) {
            $confirmed = $this->confirm(
                "¿Está seguro que desea eliminar esta solicitud" . 
                ($hasActivityRealized ? ' y toda su evidencia relacionada?' : '?'),
                false
            );

            if (!$confirmed) {
                $this->info('❌ Operación cancelada');
                return 0;
            }
        } else {
            $this->warn('🔥 Modo FORCE activado - eliminando sin confirmación');
        }

        try {
            DB::beginTransaction();

            // Eliminar evidencias relacionadas si existen
            if ($hasActivityRealized) {
                $activityRealized = $wellnessRequest->activityRealized;
                
                // Eliminar evidencias físicas (archivos)
                foreach ($activityRealized->evidences as $evidence) {
                    try {
                        // Eliminar archivo del storage si existe
                        if ($evidence->file_path && Storage::disk('public')->exists($evidence->file_path)) {
                            Storage::disk('public')->delete($evidence->file_path);
                        }
                        
                        // Eliminar registro de evidencia
                        $evidence->delete();
                        
                        $this->line("   🗑️  Evidencia eliminada: {$evidence->id}");
                    } catch (\Exception $e) {
                        $this->warn("   ⚠️  Error eliminando evidencia {$evidence->id}: {$e->getMessage()}");
                    }
                }

                // Eliminar la actividad realizada
                $activityRealized->delete();
                $this->line("   🗑️  Actividad realizada eliminada: {$activityRealized->id}");
            }

            // Eliminar detalles de la solicitud si existen
            if ($wellnessRequest->details()->exists()) {
                $detailsCount = $wellnessRequest->details()->delete();
                $this->line("   🗑️  Detalles eliminados: {$detailsCount}");
            }

            // Eliminar la solicitud
            $requestId = $wellnessRequest->id;
            $wellnessRequest->delete();

            DB::commit();

            $this->newLine();
            $this->info("✅ Solicitud de bienestar #{$requestId} eliminada exitosamente");
            
            if ($hasActivityRealized) {
                $this->info("   📸 Todas las evidencias relacionadas fueron eliminadas");
            }

            // Log de la operación
            Log::info('Solicitud de bienestar eliminada', [
                'command' => 'wellness:delete-request',
                'request_id' => $requestId,
                'had_activity_realized' => $hasActivityRealized,
                'forced' => $force,
                'user' => get_current_user() ?? 'system',
            ]);

            return 0;

        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->error("❌ Error al eliminar la solicitud: {$e->getMessage()}");
            
            Log::error('Error eliminando solicitud de bienestar', [
                'command' => 'wellness:delete-request',
                'request_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }
    }
}
