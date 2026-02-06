<?php

namespace App\Console\Commands;

use App\Models\SocioDemographicSurvey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Log};

class UpdateSurveyHospitalAndProfesionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'surveys:update-hospital-profesion
                            {--documento= : Número de documento del afiliado}
                            {--tipo-documento= : Tipo de documento (CC, TI, CE, PA, RC, PT, NUIP). Por defecto: CC}
                            {--hospital= : Nuevo hospital}
                            {--profesion= : Nueva profesión/proceso}
                            {--interactive : Modo interactivo para buscar y actualizar}
                            {--dry-run : Show what would be changed without actually changing}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Actualizar hospital y profesión/proceso de una encuesta sociodemográfica por número de documento';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔄 Actualización de Hospital y Profesión/Proceso en Encuestas');
        $this->line('===========================================================');
        $this->line('');

        // Si está en modo interactivo, buscar por documento
        if ($this->option('interactive') || (!$this->option('documento') && !$this->option('hospital') && !$this->option('profesion'))) {
            return $this->handleInteractive();
        }

        // Validar que se proporcione el documento
        $documento = $this->option('documento');
        if (!$documento) {
            $this->error('❌ Error: Debes proporcionar el número de documento con --documento o usar --interactive');
            $this->line('');
            $this->line('Ejemplos:');
            $this->line('  php artisan surveys:update-hospital-profesion --documento=1234567890 --hospital="Hospital La María" --profesion="AUXILIAR DE ENFERMERIA"');
            $this->line('  php artisan surveys:update-hospital-profesion --interactive');
            $this->line('');

            return 1;
        }

        $tipoDocumento = $this->option('tipo-documento') ?? 'CC';
        $nuevoHospital = $this->option('hospital');
        $nuevaProfesion = $this->option('profesion');

        // Validar que al menos uno de los campos a actualizar esté presente
        if (!$nuevoHospital && !$nuevaProfesion) {
            $this->error('❌ Error: Debes proporcionar al menos --hospital o --profesion para actualizar');
            $this->line('');

            return 1;
        }

        // Buscar la encuesta
        $survey = SocioDemographicSurvey::byDocument($tipoDocumento, $documento)->first();

        if (!$survey) {
            $this->error("❌ No se encontró ninguna encuesta con documento {$tipoDocumento} {$documento}");
            $this->line('');

            return 1;
        }

        // Mostrar información actual
        $this->showSurveyInfo($survey);

        // Modo dry-run
        if ($this->option('dry-run')) {
            $this->warn('🔍 DRY RUN MODE - No se realizarán cambios');
            $this->line('');
            $this->showChanges($survey, $nuevoHospital, $nuevaProfesion);

            return 0;
        }

        // Confirmar cambios (a menos que --force esté activo)
        $this->showChanges($survey, $nuevoHospital, $nuevaProfesion);
        if (!$this->option('force') && !$this->confirm('¿Deseas continuar con estos cambios?')) {
            $this->info('❌ Operación cancelada por el usuario.');
            $this->line('');

            return 0;
        }

        // Realizar la actualización
        return $this->updateSurvey($survey, $nuevoHospital, $nuevaProfesion);
    }

    /**
     * Handle interactive mode.
     */
    private function handleInteractive(): int
    {
        $this->info('🔍 Modo Interactivo');
        $this->line('');

        // Solicitar número de documento
        $documento = $this->ask('Ingresa el número de documento del afiliado');

        if (!$documento) {
            $this->error('❌ El número de documento es requerido');
            $this->line('');

            return 1;
        }

        // Solicitar tipo de documento
        $tipoDocumento = $this->choice(
            'Tipo de documento',
            ['CC', 'TI', 'CE', 'PA', 'RC', 'PT', 'NUIP'],
            'CC'
        );

        // Buscar la encuesta
        $survey = SocioDemographicSurvey::byDocument($tipoDocumento, $documento)->first();

        if (!$survey) {
            $this->error("❌ No se encontró ninguna encuesta con documento {$tipoDocumento} {$documento}");
            $this->line('');

            return 1;
        }

        // Mostrar información actual
        $this->line('');
        $this->info('📋 Encuesta encontrada:');
        $this->showSurveyInfo($survey);
        $this->line('');

        // Preguntar qué campos actualizar
        $actualizarHospital = $this->confirm('¿Deseas actualizar el hospital?', false);
        $nuevoHospital = null;
        if ($actualizarHospital) {
            $nuevoHospital = $this->ask('Ingresa el nuevo hospital', $survey->hospital);
        }

        $actualizarProfesion = $this->confirm('¿Deseas actualizar la profesión/proceso?', false);
        $nuevaProfesion = null;
        if ($actualizarProfesion) {
            $nuevaProfesion = $this->ask('Ingresa la nueva profesión/proceso', $survey->profesion);
        }

        if (!$nuevoHospital && !$nuevaProfesion) {
            $this->info('ℹ️  No se seleccionaron campos para actualizar.');
            $this->line('');

            return 0;
        }

        // Mostrar cambios
        $this->line('');
        $this->showChanges($survey, $nuevoHospital, $nuevaProfesion);

        if (!$this->option('force') && !$this->confirm('¿Deseas continuar con estos cambios?')) {
            $this->info('❌ Operación cancelada por el usuario.');
            $this->line('');

            return 0;
        }

        // Realizar la actualización
        return $this->updateSurvey($survey, $nuevoHospital, $nuevaProfesion);
    }

    /**
     * Show survey information.
     */
    private function showSurveyInfo(SocioDemographicSurvey $survey): void
    {
        $this->line('  ID: ' . $survey->id);
        $this->line('  Tipo de encuesta: ' . $survey->survey_type);
        $this->line('  Nombre: ' . ($survey->nombres ?? 'N/A') . ' ' . ($survey->apellidos ?? ''));
        $this->line('  Documento: ' . $survey->tipo_documento . ' ' . $survey->numero_documento);
        $this->line('  Email: ' . $survey->correo);
        $this->line('  Hospital actual: ' . ($survey->hospital ?? 'N/A'));
        $this->line('  Profesión/Proceso actual: ' . ($survey->profesion ?? 'N/A'));
        $this->line('  Fecha de creación: ' . ($survey->created_at?->format('d/m/Y H:i:s') ?? 'N/A'));
    }

    /**
     * Show what will be changed.
     */
    private function showChanges(SocioDemographicSurvey $survey, ?string $nuevoHospital, ?string $nuevaProfesion): void
    {
        $this->info('📝 Cambios a realizar:');
        $this->line('');

        if ($nuevoHospital) {
            $this->line("  Hospital: '{$survey->hospital}' → '{$nuevoHospital}'");
        }

        if ($nuevaProfesion) {
            $this->line("  Profesión/Proceso: '{$survey->profesion}' → '{$nuevaProfesion}'");
        }

        $this->line('');
    }

    /**
     * Update the survey.
     */
    private function updateSurvey(SocioDemographicSurvey $survey, ?string $nuevoHospital, ?string $nuevaProfesion): int
    {
        $this->info('🔄 Actualizando encuesta...');
        $this->line('');

        try {
            DB::beginTransaction();

            $changes = [];
            if ($nuevoHospital !== null) {
                $changes['hospital'] = $nuevoHospital;
            }
            if ($nuevaProfesion !== null) {
                $changes['profesion'] = $nuevaProfesion;
            }

            $survey->update($changes);

            DB::commit();

            // Log the operation
            Log::info('Encuesta sociodemográfica actualizada (hospital/profesion)', [
                'command' => 'surveys:update-hospital-profesion',
                'survey_id' => $survey->id,
                'documento' => $survey->numero_documento,
                'tipo_documento' => $survey->tipo_documento,
                'changes' => $changes,
                'timestamp' => now()->toISOString(),
            ]);

            $this->info('✅ Encuesta actualizada exitosamente.');
            $this->line('');

            // Mostrar información actualizada
            $survey->refresh();
            $this->info('📋 Información actualizada:');
            $this->showSurveyInfo($survey);
            $this->line('');

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();

            $this->error('❌ Error al actualizar la encuesta:');
            $this->error($e->getMessage());
            $this->line('');

            Log::error('Error actualizando encuesta sociodemográfica', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'survey_id' => $survey->id,
                'timestamp' => now()->toISOString(),
            ]);

            return 1;
        }
    }
}

