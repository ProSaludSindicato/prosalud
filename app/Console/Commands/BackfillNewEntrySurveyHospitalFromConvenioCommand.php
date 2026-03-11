<?php

namespace App\Console\Commands;

use App\Models\SocioDemographicSurvey;
use App\Services\AfiliadoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillNewEntrySurveyHospitalFromConvenioCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'surveys:backfill-new-entry-hospital-from-convenio
                            {--dry : Show what would be updated without saving}
                            {--force : Skip confirmation prompt}
                            {--limit=0 : Optional maximum number of surveys to process (0 = all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Actualizar el hospital de encuestas de nuevo ingreso usando el convenio activo/más reciente del afiliado';

    /**
     * Execute the console command.
     */
    public function handle(AfiliadoService $afiliadoService): int
    {
        $this->info('🏥 Backfill de hospital en encuestas de nuevo ingreso usando convenios de afiliados');
        $this->line('================================================================================');
        $this->line('');

        $limit = (int) $this->option('limit');
        if ($limit < 0) {
            $this->error('El parámetro --limit debe ser un número entero mayor o igual a 0.');

            return 1;
        }

        $baseQuery = SocioDemographicSurvey::query()
            ->where('survey_type', 'new_entry');

        $totalNewEntry = $baseQuery->count();

        if ($totalNewEntry === 0) {
            $this->info('✅ No se encontraron encuestas con tipo "new_entry". Nada por hacer.');

            return 0;
        }

        $this->info("Se encontraron {$totalNewEntry} encuestas con tipo 'new_entry'.");
        $this->line('');

        $sample = (clone $baseQuery)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $this->info('Muestra de encuestas de nuevo ingreso (máximo 10):');
        foreach ($sample as $survey) {
            $this->line(sprintf(
                '  • ID: %s | %s %s | Doc: %s %s | Hospital encuesta: %s | Fecha: %s',
                $survey->id,
                $survey->nombres ?? '',
                $survey->apellidos ?? '',
                $survey->tipo_documento,
                $survey->numero_documento,
                $survey->hospital ?? '-',
                $survey->created_at?->format('Y-m-d H:i:s') ?? 'N/A',
            ));
        }

        $this->line('');

        if ($this->option('dry')) {
            $this->warn('🔍 MODO DRY RUN - No se actualizarán registros, solo se mostrará un resumen.');
        }

        if (! $this->option('force') && ! $this->option('dry')) {
            $this->warn('⚠️  Este comando buscará en el archivo de afiliados los convenios activos/más recientes de los documentos encontrados en encuestas de nuevo ingreso.');
            $this->warn('⚠️  Cuando el afiliado exista, esté ACTIVO y tenga convenio asignado, se sobreescribirá el campo "hospital" de la encuesta con el nombre del convenio (cliente).');
            $this->line('');

            if (! $this->confirm('¿Deseas continuar con esta operación?')) {
                $this->info('❌ Operación cancelada por el usuario.');

                return 0;
            }
        }

        $processed = 0;
        $updated = 0;
        $skippedNoAfiliado = 0;
        $skippedInactive = 0;
        $skippedNoConvenio = 0;
        $skippedSameHospital = 0;

        $query = clone $baseQuery;
        $query->orderBy('id');

        $query->chunkById(100, function ($surveys) use (
            $afiliadoService,
            &$processed,
            &$updated,
            &$skippedNoAfiliado,
            &$skippedInactive,
            &$skippedNoConvenio,
            &$skippedSameHospital,
            $limit,
        ) {
            foreach ($surveys as $survey) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }

                $processed++;

                $afiliado = $afiliadoService->getAfiliadoByDocumentoOnly($survey->numero_documento);

                if ($afiliado === null) {
                    $skippedNoAfiliado++;

                    continue;
                }

                $estado = strtoupper(trim((string) ($afiliado['estado'] ?? '')));
                if ($estado !== 'ACTIVO') {
                    $skippedInactive++;

                    continue;
                }

                $nuevoHospital = trim((string) ($afiliado['hospital'] ?? ''));
                if ($nuevoHospital === '' || $nuevoHospital === 'SIN ASIGNAR') {
                    $skippedNoConvenio++;

                    continue;
                }

                if (trim((string) $survey->hospital) === $nuevoHospital) {
                    $skippedSameHospital++;

                    continue;
                }

                if (! $this->option('dry')) {
                    $survey->hospital = $nuevoHospital;
                    $survey->save();
                }

                $updated++;
            }

            if ($limit > 0 && $processed >= $limit) {
                return false;
            }

            return true;
        });

        $this->line('');
        $this->info('📊 Resumen de ejecución:');
        $this->line("  • Encuestas de nuevo ingreso procesadas: {$processed}".($limit > 0 ? " (límite: {$limit})" : ''));
        $this->line("  • Encuestas con hospital actualizado desde convenio: {$updated}");
        $this->line("  • Omitidas (sin afiliado en archivo): {$skippedNoAfiliado}");
        $this->line("  • Omitidas (afiliado con estado diferente a ACTIVO): {$skippedInactive}");
        $this->line("  • Omitidas (sin convenio asignado/SIN ASIGNAR): {$skippedNoConvenio}");
        $this->line("  • Omitidas (hospital ya coincidía con convenio): {$skippedSameHospital}");
        $this->line('');

        Log::info('Backfill de hospital en encuestas de nuevo ingreso desde convenios ejecutado', [
            'command' => 'surveys:backfill-new-entry-hospital-from-convenio',
            'total_new_entry' => $totalNewEntry,
            'processed' => $processed,
            'updated' => $updated,
            'skipped_no_afiliado' => $skippedNoAfiliado,
            'skipped_inactive' => $skippedInactive,
            'skipped_no_convenio' => $skippedNoConvenio,
            'skipped_same_hospital' => $skippedSameHospital,
            'limit' => $limit,
            'dry' => (bool) $this->option('dry'),
            'forced' => (bool) $this->option('force'),
            'timestamp' => now()->toIso8601String(),
        ]);

        if ($this->option('dry')) {
            $this->info('✅ DRY RUN finalizado. No se realizaron cambios en base de datos.');
        } else {
            $this->info('✅ Proceso finalizado. Los hospitales de encuestas de nuevo ingreso fueron actualizados cuando aplicaba.');
        }

        return 0;
    }
}
