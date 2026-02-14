<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Enviar recordatorios de actividades de bienestar todos los días a las 7:00 AM
        // (hora del servidor, que debería estar configurada en zona horaria de Colombia)
        $schedule->command('wellness:send-activity-reminders')
                ->everyMinute()
                // ->dailyAt('12:29')
                ->timezone('America/Bogota')
                ->description('Enviar recordatorios de actividades de bienestar del día anterior')
                ->withoutOverlapping()
                ->runInBackground()
                ->onSuccess(function () {
                    \Log::info('Recordatorios de bienestar programados ejecutados exitosamente');
                })
                ->onFailure(function () {
                    \Log::error('Error en la ejecución programada de recordatorios de bienestar');
                });

        // Comando de limpieza de cache (opcional, semanal)
        $schedule->command('cache:clear')
                ->weekly()
                ->sundays()
                ->at('02:00')
                ->timezone('America/Bogota')
                ->description('Limpiar cache semanalmente')
                ->withoutOverlapping();

        // Comando de optimización (opcional, diario)
        $schedule->command('optimize:clear')
                ->daily()
                ->at('03:00')
                ->timezone('America/Bogota')
                ->description('Limpiar optimizaciones diariamente')
                ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
