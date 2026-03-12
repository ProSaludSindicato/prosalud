<?php

namespace App\Console\Commands;

use App\Jobs\BackfillNewEntrySurveyHospitalFromConvenioJob;
use App\Models\SocioDemographicSurvey;
use Illuminate\Console\Command;

class BackfillNewEntrySurveyHospitalFromConvenioCommand extends Command
{
    protected $signature = 'surveys:backfill-new-entry-hospital-from-convenio
                            {--dry : Job en modo simulación (no guarda cambios)}
                            {--force : No pedir confirmación}
                            {--limit=0 : Máximo de encuestas a procesar (0 = todas)}';

    protected $description = 'Encolar actualización de hospital en encuestas de nuevo ingreso usando convenio activo/más reciente del afiliado';

    public function handle(): int
    {
        $this->info('🏥 Backfill de hospital en encuestas de nuevo ingreso (ejecución asíncrona)');
        $this->line('========================================================================');
        $this->line('');

        $limit = (int) $this->option('limit');
        if ($limit < 0) {
            $this->error('El parámetro --limit debe ser un número entero mayor o igual a 0.');

            return 1;
        }

        $baseQuery = SocioDemographicSurvey::query()
            ->where('survey_type', 'new_entry')
            ->where(function ($q) {
                $q->whereIn('hospital', BackfillNewEntrySurveyHospitalFromConvenioJob::HOSPITALES_LEGACY_PERMITIDOS)
                    ->orWhereNull('hospital')
                    ->orWhere('hospital', '');
            });

        $totalCandidatas = $baseQuery->count();
        $totalNewEntry = SocioDemographicSurvey::where('survey_type', 'new_entry')->count();

        if ($totalCandidatas === 0) {
            $this->info('✅ No hay encuestas de nuevo ingreso con hospital legacy a actualizar. Nada por hacer.');
            $this->line('  (Total \'new_entry\': '.$totalNewEntry.'; candidatas: hospital legacy o vacío)');

            return 0;
        }

        $this->info("Se encolará un job para {$totalCandidatas} encuestas candidatas (de {$totalNewEntry} total 'new_entry').");
        $this->line('');

        $sample = (clone $baseQuery)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $this->info('Muestra de encuestas candidatas (máximo 10):');
        foreach ($sample as $survey) {
            $this->line(sprintf(
                '  • ID: %s | %s %s | Doc: %s %s | Hospital: %s | Fecha: %s',
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
            $this->warn('🔍 El job se ejecutará en modo DRY (no guardará cambios).');
        }

        if (! $this->option('force')) {
            $this->warn('⚠️  El job buscará en el archivo de afiliados y actualizará el campo "hospital" con el nombre del convenio cuando aplique.');
            $this->line('');

            if (! $this->confirm('¿Encolar el job ahora?')) {
                $this->info('❌ Operación cancelada.');

                return 0;
            }
        }

        BackfillNewEntrySurveyHospitalFromConvenioJob::dispatch(
            limit: $limit,
            dry: (bool) $this->option('dry'),
        );

        $this->info('✅ Job encolado. El procesamiento se ejecuta en segundo plano.');
        $this->line('  Revisa los logs (y el worker de cola) para el resumen al finalizar.');

        return 0;
    }
}
