<?php

namespace App\Console\Commands;

use App\Models\Assembly;
use App\Models\AssemblyAttendance;
use App\Models\AssemblyQuestion;
use App\Models\AssemblyVote;
use App\Models\QuorumConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateInitialAssemblyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'assembly:create-initial
                            {--name= : Nombre de la asamblea inicial (opcional)}
                            {--description= : Descripción de la asamblea inicial (opcional)}
                            {--dry-run : Simula la operación sin realizar cambios}
                            {--force : Omite confirmaciones}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea la asamblea inicial basándose en los datos existentes y asocia todos los registros a ella';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Analizando datos existentes para crear asamblea inicial...');
        $this->line('');

        // Verificar si ya existe una asamblea
        $existingAssembly = Assembly::first();
        $useExistingAssembly = false;
        
        if ($existingAssembly) {
            $this->warn('⚠️  Ya existe al menos una asamblea en el sistema.');
            $this->line("   Asamblea encontrada: {$existingAssembly->name} (ID: {$existingAssembly->id})");
            
            // Si es una asamblea genérica creada por las migraciones, ofrecer actualizarla
            if (str_contains(strtolower($existingAssembly->name), 'principal') || 
                str_contains(strtolower($existingAssembly->name), 'default')) {
                $this->info('   Esta parece ser una asamblea genérica creada por las migraciones.');
                
                if (!$this->option('force')) {
                    $this->line('');
                    $choice = $this->choice(
                        '¿Qué deseas hacer?',
                        ['Actualizar la asamblea existente', 'Crear una nueva asamblea', 'Cancelar'],
                        0
                    );
                    
                    if ($choice === 'Cancelar') {
                        $this->info('Operación cancelada.');
                        return Command::SUCCESS;
                    }
                    
                    if ($choice === 'Actualizar la asamblea existente') {
                        $useExistingAssembly = true;
                    }
                } else {
                    // En modo force, actualizamos la existente si es genérica
                    $useExistingAssembly = true;
                }
            } else {
                if (!$this->option('force')) {
                    if (!$this->confirm('¿Deseas continuar y crear otra asamblea inicial?', false)) {
                        $this->info('Operación cancelada.');
                        return Command::SUCCESS;
                    }
                }
            }
        }

        // Analizar datos existentes
        $analysis = $this->analyzeExistingData();

        if ($analysis['has_data']) {
            $this->info('📊 Datos encontrados:');
            $this->line("   - Preguntas: {$analysis['questions_count']}");
            $this->line("   - Votos: {$analysis['votes_count']}");
            $this->line("   - Asistencias: {$analysis['attendances_count']}");
            $this->line("   - Configuraciones de quórum: {$analysis['quorum_configs_count']}");
            $this->line("   - Fecha más antigua: {$analysis['earliest_date']}");
            $this->line("   - Fecha más reciente: {$analysis['latest_date']}");
        } else {
            $this->info('📭 No se encontraron datos existentes. Se creará una asamblea vacía.');
        }

        $this->line('');

        // Determinar nombre y descripción de la asamblea
        $assemblyName = $this->option('name') ?? $this->determineAssemblyName($analysis);
        $assemblyDescription = $this->option('description') ?? $this->determineAssemblyDescription($analysis);

        // Mostrar resumen
        $this->info('📋 Resumen de la asamblea a crear:');
        $this->line("   Nombre: {$assemblyName}");
        if ($assemblyDescription) {
            $this->line("   Descripción: {$assemblyDescription}");
        }
        $this->line("   Fecha de inicio: {$analysis['earliest_date']}");
        if ($analysis['latest_date'] !== $analysis['earliest_date']) {
            $this->line("   Fecha de fin: {$analysis['latest_date']}");
        }
        $this->line("   Se activará: Sí");
        $this->line('');

        if ($this->option('dry-run')) {
            $this->warn('🔍 MODO SIMULACIÓN - No se realizarán cambios');
            $this->line('');
            $this->info('Operaciones que se realizarían:');
            $this->line('   1. Crear asamblea inicial');
            $this->line('   2. Asociar ' . $analysis['questions_count'] . ' preguntas');
            $this->line('   3. Asociar ' . $analysis['votes_count'] . ' votos');
            $this->line('   4. Asociar ' . $analysis['attendances_count'] . ' asistencias');
            $this->line('   5. Asociar ' . $analysis['quorum_configs_count'] . ' configuraciones de quórum');
            $this->line('   6. Activar la asamblea');
            return Command::SUCCESS;
        }

        if (!$this->option('force')) {
            if (!$this->confirm('¿Deseas continuar y crear la asamblea inicial?', true)) {
                $this->info('Operación cancelada.');
                return Command::SUCCESS;
            }
        }

        try {
            DB::beginTransaction();

            // Crear o actualizar la asamblea
            if ($useExistingAssembly && $existingAssembly) {
                $this->info('🏗️  Actualizando asamblea existente...');
                $assembly = $existingAssembly;
                $assembly->update([
                    'name' => $assemblyName,
                    'description' => $assemblyDescription,
                    'start_date' => $analysis['earliest_date'] ? date('Y-m-d', strtotime($analysis['earliest_date'])) : now()->toDateString(),
                    'end_date' => ($analysis['latest_date'] && $analysis['latest_date'] !== $analysis['earliest_date']) 
                        ? date('Y-m-d', strtotime($analysis['latest_date'])) 
                        : null,
                    'is_active' => true,
                ]);
                $this->line("   ✅ Asamblea actualizada: {$assembly->id}");
            } else {
                $this->info('🏗️  Creando asamblea inicial...');
                $assembly = Assembly::create([
                    'id' => (string) Str::uuid(),
                    'name' => $assemblyName,
                    'description' => $assemblyDescription,
                    'start_date' => $analysis['earliest_date'] ? date('Y-m-d', strtotime($analysis['earliest_date'])) : now()->toDateString(),
                    'end_date' => ($analysis['latest_date'] && $analysis['latest_date'] !== $analysis['earliest_date']) 
                        ? date('Y-m-d', strtotime($analysis['latest_date'])) 
                        : null,
                    'is_active' => true,
                ]);
                $this->line("   ✅ Asamblea creada: {$assembly->id}");
            }

            // Asociar preguntas
            if ($analysis['questions_count'] > 0) {
                $this->info("📝 Asociando {$analysis['questions_count']} preguntas...");
                $updated = AssemblyQuestion::whereNull('assembly_id')
                    ->update(['assembly_id' => $assembly->id]);
                $this->line("   ✅ {$updated} preguntas asociadas");
            }

            // Asociar votos (obtienen assembly_id desde sus preguntas relacionadas)
            if ($analysis['votes_count'] > 0) {
                $this->info("🗳️  Asociando {$analysis['votes_count']} votos...");
                
                // Para votos, necesitamos obtener el assembly_id desde la pregunta
                // Primero actualizamos los votos de preguntas que ya tienen assembly_id
                $votesUpdated = DB::table('assembly_votes')
                    ->join('assembly_questions', 'assembly_votes.question_id', '=', 'assembly_questions.id')
                    ->where('assembly_questions.assembly_id', $assembly->id)
                    ->whereNull('assembly_votes.assembly_id')
                    ->update(['assembly_votes.assembly_id' => $assembly->id]);
                
                // Si hay votos sin assembly_id y la columna existe como nullable, los asociamos
                // (esto maneja el caso donde los votos no tienen assembly_id aún)
                $votesWithoutQuestion = DB::table('assembly_votes')
                    ->leftJoin('assembly_questions', 'assembly_votes.question_id', '=', 'assembly_questions.id')
                    ->whereNull('assembly_questions.id')
                    ->whereNull('assembly_votes.assembly_id')
                    ->count();
                
                if ($votesWithoutQuestion > 0) {
                    $this->warn("   ⚠️  {$votesWithoutQuestion} votos sin pregunta relacionada encontrados");
                }
                
                $this->line("   ✅ {$votesUpdated} votos asociados");
            }

            // Asociar asistencias
            if ($analysis['attendances_count'] > 0) {
                $this->info("👥 Asociando {$analysis['attendances_count']} asistencias...");
                $updated = AssemblyAttendance::whereNull('assembly_id')
                    ->update(['assembly_id' => $assembly->id]);
                $this->line("   ✅ {$updated} asistencias asociadas");
            }

            // Asociar configuraciones de quórum
            if ($analysis['quorum_configs_count'] > 0) {
                $this->info("📊 Asociando {$analysis['quorum_configs_count']} configuraciones de quórum...");
                $updated = QuorumConfig::whereNull('assembly_id')
                    ->update(['assembly_id' => $assembly->id]);
                $this->line("   ✅ {$updated} configuraciones de quórum asociadas");
            }

            DB::commit();

            $this->line('');
            $this->info('✅ ¡Asamblea inicial creada exitosamente!');
            $this->line('');
            $this->table(
                ['Campo', 'Valor'],
                [
                    ['ID', $assembly->id],
                    ['Nombre', $assembly->name],
                    ['Estado', $assembly->is_active ? 'Activa' : 'Inactiva'],
                    ['Fecha inicio', $assembly->start_date?->format('Y-m-d') ?? 'N/A'],
                    ['Fecha fin', $assembly->end_date?->format('Y-m-d') ?? 'N/A'],
                    ['Preguntas', $assembly->questions()->count()],
                    ['Votos', $assembly->questions()->withCount('votes')->get()->sum('votes_count')],
                    ['Asistencias', $assembly->attendances()->count()],
                    ['Quórum configs', $assembly->quorumConfigs()->count()],
                ]
            );

            Log::info('Initial assembly created successfully', [
                'assembly_id' => $assembly->id,
                'name' => $assembly->name,
                'questions_count' => $analysis['questions_count'],
                'votes_count' => $analysis['votes_count'],
                'attendances_count' => $analysis['attendances_count'],
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('❌ Error al crear la asamblea inicial: ' . $e->getMessage());
            $this->error($e->getTraceAsString());

            Log::error('Error creating initial assembly', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Analiza los datos existentes para determinar información de la asamblea
     */
    private function analyzeExistingData(): array
    {
        // Obtener registros sin assembly_id (datos que necesitan ser asociados)
        $questions = AssemblyQuestion::select('created_at', 'opened_at', 'closed_at')
            ->whereNull('assembly_id')
            ->get();
        
        $votes = AssemblyVote::select('created_at', 'voted_at')
            ->whereNull('assembly_id')
            ->get();
        
        $attendances = AssemblyAttendance::select('created_at', 'authenticated_at')
            ->whereNull('assembly_id')
            ->get();

        // También contar todos los registros (con y sin assembly_id) para estadísticas
        $totalQuestions = AssemblyQuestion::count();
        $totalVotes = AssemblyVote::count();
        $totalAttendances = AssemblyAttendance::count();
        $totalQuorumConfigs = QuorumConfig::count();

        $dates = collect();

        // Recolectar todas las fechas relevantes
        foreach ($questions as $question) {
            if ($question->created_at) $dates->push($question->created_at);
            if ($question->opened_at) $dates->push($question->opened_at);
            if ($question->closed_at) $dates->push($question->closed_at);
        }

        foreach ($votes as $vote) {
            if ($vote->created_at) $dates->push($vote->created_at);
            if ($vote->voted_at) $dates->push($vote->voted_at);
        }

        foreach ($attendances as $attendance) {
            if ($attendance->created_at) $dates->push($attendance->created_at);
            if ($attendance->authenticated_at) $dates->push($attendance->authenticated_at);
        }

        $earliestDate = $dates->min();
        $latestDate = $dates->max();

        // Para las fechas, usar todos los registros (no solo los sin assembly_id)
        $allQuestions = AssemblyQuestion::select('created_at', 'opened_at', 'closed_at')->get();
        $allVotes = AssemblyVote::select('created_at', 'voted_at')->get();
        $allAttendances = AssemblyAttendance::select('created_at', 'authenticated_at')->get();

        foreach ($allQuestions as $question) {
            if ($question->created_at) $dates->push($question->created_at);
            if ($question->opened_at) $dates->push($question->opened_at);
            if ($question->closed_at) $dates->push($question->closed_at);
        }

        foreach ($allVotes as $vote) {
            if ($vote->created_at) $dates->push($vote->created_at);
            if ($vote->voted_at) $dates->push($vote->voted_at);
        }

        foreach ($allAttendances as $attendance) {
            if ($attendance->created_at) $dates->push($attendance->created_at);
            if ($attendance->authenticated_at) $dates->push($attendance->authenticated_at);
        }

        $earliestDate = $dates->min();
        $latestDate = $dates->max();

        // Contar registros sin assembly_id (que necesitan ser asociados)
        $questionsWithoutAssembly = AssemblyQuestion::whereNull('assembly_id')->count();
        $votesWithoutAssembly = AssemblyVote::whereNull('assembly_id')->count();
        $attendancesWithoutAssembly = AssemblyAttendance::whereNull('assembly_id')->count();
        $quorumConfigsWithoutAssembly = QuorumConfig::whereNull('assembly_id')->count();

        return [
            'has_data' => $totalQuestions > 0 || $totalVotes > 0 || $totalAttendances > 0 || $totalQuorumConfigs > 0,
            'questions_count' => $questionsWithoutAssembly > 0 ? $questionsWithoutAssembly : $totalQuestions,
            'votes_count' => $votesWithoutAssembly > 0 ? $votesWithoutAssembly : $totalVotes,
            'attendances_count' => $attendancesWithoutAssembly > 0 ? $attendancesWithoutAssembly : $totalAttendances,
            'quorum_configs_count' => $quorumConfigsWithoutAssembly > 0 ? $quorumConfigsWithoutAssembly : $totalQuorumConfigs,
            'earliest_date' => $earliestDate ? $earliestDate->format('Y-m-d') : null,
            'latest_date' => $latestDate ? $latestDate->format('Y-m-d') : null,
        ];
    }

    /**
     * Determina el nombre de la asamblea basándose en los datos
     */
    private function determineAssemblyName(array $analysis): string
    {
        if ($analysis['earliest_date']) {
            $year = date('Y', strtotime($analysis['earliest_date']));
            return "Asamblea General {$year}";
        }

        $currentYear = now()->year;
        return "Asamblea General {$currentYear}";
    }

    /**
     * Determina la descripción de la asamblea
     */
    private function determineAssemblyDescription(array $analysis): ?string
    {
        if (!$analysis['has_data']) {
            return 'Asamblea inicial del sistema';
        }

        $description = 'Asamblea inicial creada automáticamente con datos existentes. ';
        
        if ($analysis['questions_count'] > 0) {
            $description .= "Incluye {$analysis['questions_count']} pregunta(s)";
            if ($analysis['votes_count'] > 0) {
                $description .= ", {$analysis['votes_count']} voto(s)";
            }
            if ($analysis['attendances_count'] > 0) {
                $description .= ", {$analysis['attendances_count']} asistencia(s)";
            }
            $description .= '.';
        }

        return $description;
    }
}
