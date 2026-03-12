<?php

namespace App\Jobs;

use App\Models\SocioDemographicSurvey;
use App\Services\AfiliadoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BackfillNewEntrySurveyHospitalFromConvenioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 7200;

    /**
     * Hospitales "legacy" que se consideran para actualizar. Solo encuestas con uno de estos
     * hospitales serán procesadas (evita reprocesar las ya sobreescritas con nombre de convenio).
     */
    public const HOSPITALES_LEGACY_PERMITIDOS = [
        'E.S.E. HOSPITAL CARISMA',
        'E.S.E. HOSPITAL LA MARÍA',
        'E.S.E. HOSPITAL MARCO FIDEL SUAREZ DE BELLO',
        'E.S.E. HOSPITAL SAN JUAN DE DIOS - RIONEGRO',
        'SEDE ADMINISTRATIVA CALDAS',
    ];

    public function __construct(
        public int $limit = 0,
        public bool $dry = false
    ) {
        $this->onQueue(config('queue.queues.default', 'default'));
    }

    public function handle(AfiliadoService $afiliadoService): void
    {
        set_time_limit($this->timeout);
        ini_set('max_execution_time', (string) $this->timeout);

        $baseQuery = SocioDemographicSurvey::query()
            ->where('survey_type', 'new_entry')
            ->where(function ($q) {
                $q->whereIn('hospital', self::HOSPITALES_LEGACY_PERMITIDOS)
                    ->orWhereNull('hospital')
                    ->orWhere('hospital', '');
            });

        $totalCandidatas = $baseQuery->count();
        $totalNewEntry = SocioDemographicSurvey::where('survey_type', 'new_entry')->count();

        if ($totalCandidatas === 0) {
            Log::info('Backfill new-entry survey hospital: sin encuestas candidatas', [
                'total_new_entry' => $totalNewEntry,
                'dry' => $this->dry,
            ]);

            return;
        }

        Log::info('Backfill new-entry survey hospital: iniciando job', [
            'total_candidatas' => $totalCandidatas,
            'total_new_entry' => $totalNewEntry,
            'limit' => $this->limit,
            'dry' => $this->dry,
        ]);

        $processed = 0;
        $updated = 0;
        $skippedNoAfiliado = 0;
        $skippedInactive = 0;
        $skippedNoConvenio = 0;
        $skippedSameHospital = 0;
        $updatedSurveys = [];

        $query = (clone $baseQuery)->orderBy('id');

        $query->chunkById(100, function ($surveys) use (
            $afiliadoService,
            &$processed,
            &$updated,
            &$skippedNoAfiliado,
            &$skippedInactive,
            &$skippedNoConvenio,
            &$skippedSameHospital,
            &$updatedSurveys,
        ) {
            foreach ($surveys as $survey) {
                if ($this->limit > 0 && $processed >= $this->limit) {
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

                if (! $this->dry) {
                    $survey->hospital = $nuevoHospital;
                    $survey->save();
                }

                $updated++;

                $updatedSurveys[] = [
                    'id' => $survey->id,
                    'documento' => $survey->numero_documento,
                    'tipo_documento' => $survey->tipo_documento,
                    'nombre_completo' => trim(($survey->nombres ?? '').' '.($survey->apellidos ?? '')),
                    'hospital_anterior' => $survey->getOriginal('hospital'),
                    'hospital_nuevo' => $nuevoHospital,
                    'created_at' => $survey->created_at?->format('Y-m-d H:i:s'),
                ];
            }

            if ($this->limit > 0 && $processed >= $this->limit) {
                return false;
            }

            return true;
        });

        Log::info('Backfill de hospital en encuestas de nuevo ingreso desde convenios ejecutado', [
            'command' => 'surveys:backfill-new-entry-hospital-from-convenio',
            'total_new_entry' => $totalNewEntry,
            'total_candidatas' => $totalCandidatas,
            'processed' => $processed,
            'updated' => $updated,
            'skipped_no_afiliado' => $skippedNoAfiliado,
            'skipped_inactive' => $skippedInactive,
            'skipped_no_convenio' => $skippedNoConvenio,
            'skipped_same_hospital' => $skippedSameHospital,
            'limit' => $this->limit,
            'dry' => $this->dry,
            'timestamp' => now()->toIso8601String(),
        ]);

        if ($updated > 0) {
            $detail = array_slice($updatedSurveys, 0, 100);
            Log::info('Backfill new-entry hospital: detalle de encuestas actualizadas', [
                'total_updated' => $updated,
                'detail' => $detail,
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Backfill new-entry survey hospital job failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'limit' => $this->limit,
            'dry' => $this->dry,
        ]);
    }
}
