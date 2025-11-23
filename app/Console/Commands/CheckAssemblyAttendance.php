<?php

namespace App\Console\Commands;

use App\Models\AssemblyAttendance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckAssemblyAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'assembly:check-attendance 
                            {--document= : Filtrar por número de documento}
                            {--id= : Buscar un registro específico por ID}
                            {--list : Listar todos los registros}
                            {--count : Mostrar solo el conteo total}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica el estado de los registros de asistencia de la asamblea';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $document = $this->option('document');
        $id = $this->option('id');
        $list = $this->option('list');
        $count = $this->option('count');

        // Si solo se quiere el conteo
        if ($count) {
            $total = AssemblyAttendance::query()->count();
            $this->info("Total de registros de asistencia: {$total}");
            return Command::SUCCESS;
        }

        // Buscar por ID específico
        if ($id) {
            $attendance = AssemblyAttendance::query()->find($id);
            
            if (!$attendance) {
                $this->error("No se encontró un registro de asistencia con ID: {$id}");
                return Command::FAILURE;
            }

            $this->displayAttendanceDetails($attendance);
            return Command::SUCCESS;
        }

        // Filtrar por documento
        if ($document) {
            $attendances = AssemblyAttendance::query()
                ->where('document_number', 'like', "%{$document}%")
                ->orderByDesc('authenticated_at')
                ->get();

            if ($attendances->isEmpty()) {
                $this->warn("No se encontraron registros con documento que contenga: {$document}");
                return Command::SUCCESS;
            }

            $this->info("Registros encontrados: {$attendances->count()}");
            $this->newLine();

            foreach ($attendances as $attendance) {
                $this->displayAttendanceDetails($attendance);
                $this->newLine();
            }

            return Command::SUCCESS;
        }

        // Listar todos o mostrar resumen
        if ($list) {
            $attendances = AssemblyAttendance::query()
                ->orderByDesc('authenticated_at')
                ->get();

            $this->info("Total de registros: {$attendances->count()}");
            $this->newLine();

            $headers = ['ID', 'Documento', 'Nombre', 'Fecha Autenticación', 'IP', 'Tiene Firma'];
            $rows = [];

            foreach ($attendances as $attendance) {
                $rows[] = [
                    $attendance->id,
                    $attendance->document_number,
                    $attendance->full_name ?? 'N/A',
                    $attendance->authenticated_at->format('Y-m-d H:i:s'),
                    $attendance->ip_address ?? 'N/A',
                    $attendance->signature_path ? 'Sí' : 'No',
                ];
            }

            $this->table($headers, $rows);
            return Command::SUCCESS;
        }

        // Mostrar resumen por defecto
        $this->displaySummary();

        return Command::SUCCESS;
    }

    /**
     * Display summary statistics.
     */
    private function displaySummary(): void
    {
        $total = AssemblyAttendance::query()->count();
        
        $this->info('=== RESUMEN DE REGISTROS DE ASISTENCIA ===');
        $this->newLine();
        
        $this->line("Total de registros: <fg=cyan>{$total}</>");
        
        if ($total > 0) {
            $withSignature = AssemblyAttendance::query()
                ->whereNotNull('signature_path')
                ->count();
            
            $withoutSignature = $total - $withSignature;
            
            $this->line("Con firma: <fg=green>{$withSignature}</>");
            $this->line("Sin firma: <fg=yellow>{$withoutSignature}</>");
            
            $this->newLine();
            
            // Últimos 5 registros
            $recent = AssemblyAttendance::query()
                ->orderByDesc('authenticated_at')
                ->limit(5)
                ->get();
            
            $this->info('Últimos 5 registros:');
            $this->newLine();
            
            $headers = ['ID', 'Documento', 'Nombre', 'Fecha'];
            $rows = [];
            
            foreach ($recent as $attendance) {
                $rows[] = [
                    $attendance->id,
                    $attendance->document_number,
                    $attendance->full_name ?? 'N/A',
                    $attendance->authenticated_at->format('Y-m-d H:i:s'),
                ];
            }
            
            $this->table($headers, $rows);
            
            // Verificación directa en BD
            $this->newLine();
            $this->info('Verificación directa en base de datos:');
            $dbCount = DB::table('assembly_attendances')->count();
            $this->line("Registros en tabla 'assembly_attendances': <fg=cyan>{$dbCount}</>");
            
            if ($total !== $dbCount) {
                $this->error("⚠️  DISCREPANCIA: El modelo muestra {$total} pero la BD tiene {$dbCount}");
            } else {
                $this->info("✓ Conteo coincide entre modelo y base de datos");
            }
        }
    }

    /**
     * Display detailed information about an attendance record.
     */
    private function displayAttendanceDetails(AssemblyAttendance $attendance): void
    {
        $this->info("=== REGISTRO ID: {$attendance->id} ===");
        $this->line("Documento: <fg=cyan>{$attendance->document_number}</>");
        $this->line("Nombre: <fg=cyan>" . ($attendance->full_name ?? 'N/A') . "</>");
        $this->line("Fecha Expedición: <fg=cyan>" . ($attendance->issue_date_normalized?->format('Y-m-d') ?? 'N/A') . "</>");
        $this->line("Fecha Autenticación: <fg=cyan>{$attendance->authenticated_at->format('Y-m-d H:i:s')}</>");
        $this->line("IP: <fg=cyan>" . ($attendance->ip_address ?? 'N/A') . "</>");
        $this->line("User Agent: <fg=cyan>" . ($attendance->user_agent ?? 'N/A') . "</>");
        $this->line("Firma: <fg=" . ($attendance->signature_path ? 'green' : 'yellow') . ">" . ($attendance->signature_path ? 'Sí (' . $attendance->signature_path . ')' : 'No') . "</>");
        $this->line("Creado: <fg=cyan>{$attendance->created_at->format('Y-m-d H:i:s')}</>");
        $this->line("Actualizado: <fg=cyan>{$attendance->updated_at->format('Y-m-d H:i:s')}</>");
        
        // Verificar existencia en BD
        $existsInDb = DB::table('assembly_attendances')
            ->where('id', $attendance->id)
            ->exists();
        
        $this->line("Existe en BD: <fg=" . ($existsInDb ? 'green' : 'red') . ">" . ($existsInDb ? 'Sí' : 'No') . "</>");
    }
}
