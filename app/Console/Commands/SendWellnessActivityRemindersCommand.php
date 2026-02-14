<?php

namespace App\Console\Commands;

use App\Jobs\SendWellnessActivityReminderJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendWellnessActivityRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wellness:send-activity-reminders 
                            {--force : Forzar el envío incluso si ya se ejecutó hoy}
                            {--date= : Especificar fecha específica (formato YYYY-MM-DD)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enviar recordatorios de actividades de bienestar realizadas';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🎯 Iniciando envío de recordatorios de actividades de bienestar...');
        
        $force = $this->option('force');
        $specificDate = $this->option('date');

        if ($specificDate) {
            try {
                $date = \Carbon\Carbon::createFromFormat('Y-m-d', $specificDate);
                $this->info("📅 Procesando fecha específica: {$date->format('d/m/Y')}");
            } catch (\Exception $e) {
                $this->error("❌ Formato de fecha inválido. Use YYYY-MM-DD");
                return 1;
            }
        } else {
            $date = now()->subDay();
            $this->info("📅 Procesando fecha de ayer: {$date->format('d/m/Y')}");
        }

        // Verificar si ya se ejecutó hoy (a menos que se use --force)
        if (!$force && !$specificDate) {
            $cacheKey = 'wellness_reminders_last_run';
            $lastRun = cache()->get($cacheKey);
            
            if ($lastRun && $lastRun->format('Y-m-d') === now()->format('Y-m-d')) {
                $this->warn("⚠️  Los recordatorios ya fueron enviados hoy a las {$lastRun->format('H:i')}");
                $this->info("💡 Use --force para forzar el envío o especifique una fecha con --date=YYYY-MM-DD");
                return 0;
            }
        }

        try {
            // Despachar el job
            SendWellnessActivityReminderJob::dispatch();

            // Marcar como ejecutado (solo si no es fecha específica)
            if (!$specificDate) {
                cache()->put('wellness_reminders_last_run', now(), now()->addHours(25));
            }

            $this->info('✅ Tarea de recordatorios despachada exitosamente');
            $this->info('📧 Los correos serán enviados en segundo plano');
            
            if ($specificDate) {
                $this->info("📋 Se procesarán las solicitudes con fecha: {$specificDate}");
            } else {
                $this->info('📋 Se procesarán las solicitudes de ayer que no tienen actividad registrada');
            }

            Log::info('Comando de recordatorios de bienestar ejecutado', [
                'command' => 'wellness:send-activity-reminders',
                'force' => $force,
                'specific_date' => $specificDate,
                'processed_date' => $date->format('Y-m-d'),
            ]);

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Error al despachar la tarea de recordatorios: {$e->getMessage()}");
            
            Log::error('Error en comando de recordatorios de bienestar', [
                'command' => 'wellness:send-activity-reminders',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }
    }
}
