<?php

namespace App\Console\Commands;

use App\Models\RequestForm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateRequestPayloadCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'request-forms:update-payload
                            {id : ID de la solicitud a actualizar}
                            {--proceso= : Valor del campo proceso a agregar/actualizar}
                            {--donde-realiza-proceso= : Valor del campo dondeRealizaProceso a agregar/actualizar}
                            {--force : Omitir confirmación interactiva}
                            {--dry-run : Mostrar qué se actualizaría sin ejecutar los cambios}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Actualizar el campo payload JSON de una solicitud con los valores de proceso y/o dondeRealizaProceso';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $requestId = $this->argument('id');
        $proceso = $this->option('proceso');
        $dondeRealizaProceso = $this->option('donde-realiza-proceso');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('🔧 Actualización de Payload de Solicitud');
        $this->line('=====================================');

        // Validar que al menos se proporcione un valor para actualizar
        if (!$proceso && !$dondeRealizaProceso) {
            $this->error('❌ Debes proporcionar al menos uno de los valores: --proceso o --donde-realiza-proceso');
            return 1;
        }

        // Buscar la solicitud
        $requestForm = RequestForm::find($requestId);

        if (!$requestForm) {
            $this->error("❌ No se encontró la solicitud con ID: {$requestId}");
            return 1;
        }

        $this->info("📋 Solicitud encontrada:");
        $this->line("   ID: {$requestForm->id}");
        $this->line("   Tipo: {$requestForm->request_type}");
        $this->line("   Nombre: {$requestForm->full_name}");
        $this->line("   Estado: {$requestForm->status}");

        // Mostrar payload actual
        $currentPayload = $requestForm->payload ?? [];
        $this->info("\n📦 Payload actual:");
        if (empty($currentPayload)) {
            $this->line("   (vacío)");
        } else {
            foreach ($currentPayload as $key => $value) {
                $displayValue = is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : $value;
                $this->line("   {$key}: {$displayValue}");
            }
        }

        // Preparar los cambios
        $changes = [];
        $newPayload = $currentPayload;

        if ($proceso !== null) {
            $currentProceso = $currentPayload['proceso'] ?? null;
            if ($currentProceso !== $proceso) {
                $changes['proceso'] = [
                    'antes' => $currentProceso,
                    'después' => $proceso
                ];
                $newPayload['proceso'] = $proceso;
            }
        }

        if ($dondeRealizaProceso !== null) {
            $currentDondeRealiza = $currentPayload['dondeRealizaProceso'] ?? null;
            if ($currentDondeRealiza !== $dondeRealizaProceso) {
                $changes['dondeRealizaProceso'] = [
                    'antes' => $currentDondeRealiza,
                    'después' => $dondeRealizaProceso
                ];
                $newPayload['dondeRealizaProceso'] = $dondeRealizaProceso;
            }
        }

        if (empty($changes)) {
            $this->info("\n✅ No hay cambios que realizar. Los valores ya están configurados.");
            return 0;
        }

        // Mostrar cambios propuestos
        $this->info("\n🔄 Cambios propuestos:");
        foreach ($changes as $field => $change) {
            $antes = $change['antes'] ?? '(vacío)';
            $después = $change['después'];
            $this->line("   {$field}:");
            $this->line("     Antes: {$antes}");
            $this->line("     Después: {$después}");
        }

        if ($dryRun) {
            $this->info("\n🔍 MODO DRY RUN - No se realizarán cambios reales");
            return 0;
        }

        // Confirmar cambios
        if (!$dryRun && !$force && !$this->confirm("\n¿Deseas aplicar estos cambios?")) {
            $this->info("❌ Operación cancelada por el usuario.");
            return 0;
        }

        // Aplicar cambios usando transacción para seguridad
        try {
            DB::transaction(function () use ($requestForm, $newPayload) {
                $requestForm->payload = $newPayload;
                $requestForm->save();
            });

            $this->info("\n✅ Payload actualizado exitosamente");

            // Mostrar payload final
            $this->info("\n📦 Payload final:");
            foreach ($newPayload as $key => $value) {
                $displayValue = is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : $value;
                $this->line("   {$key}: {$displayValue}");
            }

        } catch (\Exception $e) {
            $this->error("\n❌ Error al actualizar el payload: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
