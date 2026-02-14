<?php

namespace App\Jobs;

use App\Mail\WellnessActivityReminderMail;
use App\Models\WellnessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWellnessActivityReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Iniciando envío de recordatorios de actividades de bienestar');

            // Obtener la fecha de ayer (día anterior a hoy)
            $yesterday = now()->subDay()->format('Y-m-d');
            
            // Buscar solicitudes aprobadas (resolved) con fecha propuesta = ayer
            // que no tengan una actividad realizada registrada
            $approvedRequests = WellnessRequest::with(['requester'])
                ->where('status', 'resolved')
                ->whereDate('proposed_date', $yesterday)
                ->whereDoesntHave('activityRealized')
                ->get();

            Log::info('Solicitudes encontradas para recordatorio', [
                'date' => $yesterday,
                'count' => $approvedRequests->count(),
            ]);

            $emailsSent = 0;
            $emailsFailed = 0;

            foreach ($approvedRequests as $request) {
                try {
                    // Enviar correo al solicitante
                    if ($request->requester && $request->requester->email) {
                        Mail::to($request->requester->email)
                            ->cc('directoradmon@sindicatoprosalud.com')
                            ->send(new WellnessActivityReminderMail($request));

                        $emailsSent++;

                        Log::info('Recordatorio de actividad de bienestar enviado', [
                            'request_id' => $request->id,
                            'activity_name' => $request->activity_name,
                            'proposed_date' => $request->proposed_date,
                            'requester_email' => $request->requester->email,
                        ]);
                    } else {
                        Log::warning('Solicitud sin email de solicitante válido', [
                            'request_id' => $request->id,
                            'requester_id' => $request->requester_id,
                        ]);
                    }
                } catch (\Exception $e) {
                    $emailsFailed++;
                    
                    Log::error('Error al enviar recordatorio de actividad de bienestar', [
                        'request_id' => $request->id,
                        'activity_name' => $request->activity_name,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            Log::info('Proceso de recordatorios de actividades de bienestar completado', [
                'total_requests' => $approvedRequests->count(),
                'emails_sent' => $emailsSent,
                'emails_failed' => $emailsFailed,
                'date_processed' => $yesterday,
            ]);

        } catch (\Exception $e) {
            Log::error('Error general en el proceso de recordatorios de actividades de bienestar', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
