<?php

namespace App\Console\Commands;

use App\Models\SstDeliveryRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CorrectDotacionEppDeliveryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dotacion-epp:correct-delivery
                            {document-number : Número de documento del afiliado}
                            {--document-type= : Tipo de documento (CC, CE, TI, etc.). Si no se especifica, buscará en todos los tipos}
                            {--hospital= : Nuevo hospital a asignar}
                            {--role= : Nuevo proceso/rol a asignar}
                            {--force : Aplicar cambios sin confirmación}
                            {--dry-run : Mostrar qué se cambiaría sin aplicar los cambios}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Corregir manualmente el hospital o proceso (rol) de entregas de dotación/EPP por número de documento';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔧 Corrección de Entregas de Dotación/EPP');
        $this->line('==========================================');

        $documentNumber = $this->argument('document-number');
        $documentType = $this->option('document-type');
        $newHospital = $this->option('hospital');
        $newRole = $this->option('role');

        // Validar que al menos un campo se vaya a actualizar
        if (!$newHospital && !$newRole) {
            $this->error('❌ Error: Debes especificar al menos --hospital o --role para actualizar.');
            $this->line('');
            $this->line('Ejemplo:');
            $this->line('  php artisan dotacion-epp:correct-delivery 1234567890 --hospital="NUEVO HOSPITAL" --role="NUEVO PROCESO"');
            $this->line('  php artisan dotacion-epp:correct-delivery 1234567890 --document-type=CC --hospital="NUEVO HOSPITAL"');

            return 1;
        }

        // Buscar entregas
        $query = SstDeliveryRecord::where('affiliate_document_number', $documentNumber);

        if ($documentType) {
            $query->where('affiliate_document_type', strtoupper($documentType));
        }

        $deliveries = $query->orderBy('delivered_at', 'desc')->get();

        if ($deliveries->isEmpty()) {
            $this->warn("⚠️  No se encontraron entregas para el documento: {$documentNumber}");
            if ($documentType) {
                $this->line("   Tipo de documento: {$documentType}");
            }

            return 0;
        }

        $this->info("📋 Se encontraron {$deliveries->count()} entrega(s) para el documento: {$documentNumber}");
        if ($documentType) {
            $this->line("   Tipo de documento: {$documentType}");
        }

        // Mostrar información actual
        $this->line('');
        $this->info('📊 Entregas encontradas:');
        $this->line('');

        $changesToApply = [];
        $headers = ['ID', 'Fecha', 'Nombre', 'Documento', 'Hospital Actual', 'Proceso Actual', 'Hospital Nuevo', 'Proceso Nuevo'];
        $rows = [];

        foreach ($deliveries as $delivery) {
            $currentHospital = $delivery->affiliate_hospital ?? '(sin asignar)';
            $currentRole = $delivery->affiliate_role ?? '(sin asignar)';
            $newHospitalValue = $newHospital ?? $currentHospital;
            $newRoleValue = $newRole ?? $currentRole;

            $hasChanges = false;
            $changes = [];

            if ($newHospital && $currentHospital !== $newHospital) {
                $hasChanges = true;
                $changes[] = "Hospital: '{$currentHospital}' → '{$newHospital}'";
            }

            if ($newRole && $currentRole !== $newRole) {
                $hasChanges = true;
                $changes[] = "Proceso: '{$currentRole}' → '{$newRole}'";
            }

            $rows[] = [
                substr($delivery->id, 0, 8) . '...',
                $delivery->delivered_at?->format('Y-m-d H:i'),
                trim(($delivery->affiliate_first_name ?? '') . ' ' . ($delivery->affiliate_last_name ?? '')),
                $delivery->affiliate_document_type . ' ' . $delivery->affiliate_document_number,
                $currentHospital,
                $currentRole,
                $hasChanges ? ($newHospitalValue !== $currentHospital ? "→ {$newHospitalValue}" : $newHospitalValue) : '-',
                $hasChanges ? ($newRoleValue !== $currentRole ? "→ {$newRoleValue}" : $newRoleValue) : '-',
            ];

            if ($hasChanges) {
                $changesToApply[] = [
                    'delivery' => $delivery,
                    'changes' => $changes,
                    'new_hospital' => $newHospitalValue,
                    'new_role' => $newRoleValue,
                ];
            }
        }

        $this->table($headers, $rows);

        // Verificar si hay cambios para aplicar
        if (empty($changesToApply)) {
            $this->line('');
            $this->info('✅ No hay cambios que aplicar. Todas las entregas ya tienen los valores especificados.');

            return 0;
        }

        $this->line('');
        $this->warn("⚠️  Se actualizarán " . count($changesToApply) . " entrega(s):");
        foreach ($changesToApply as $change) {
            $this->line("   • Entrega {$change['delivery']->id}:");
            foreach ($change['changes'] as $changeDesc) {
                $this->line("     - {$changeDesc}");
            }
        }

        // Dry run mode
        if ($this->option('dry-run')) {
            $this->line('');
            $this->warn('🔍 DRY RUN MODE - No se aplicarán cambios');
            $this->info('Para aplicar los cambios, ejecuta el comando sin --dry-run');

            return 0;
        }

        // Confirmation prompt (unless --force is used)
        if (!$this->option('force')) {
            $this->line('');
            $this->warn('⚠️  ADVERTENCIA: Se modificarán registros históricos de entregas.');
            $this->warn('⚠️  Esta acción afecta la trazabilidad de los datos.');

            if (!$this->confirm('¿Estás seguro de que deseas continuar?')) {
                $this->info('❌ Operación cancelada por el usuario.');

                return 0;
            }
        }

        // Aplicar cambios
        $this->line('');
        $this->info('🔄 Aplicando correcciones...');

        $updated = 0;
        $failed = 0;

        foreach ($changesToApply as $change) {
            try {
                $delivery = $change['delivery'];
                $oldHospital = $delivery->affiliate_hospital;
                $oldRole = $delivery->affiliate_role;

                // Actualizar campos
                if ($newHospital) {
                    $delivery->affiliate_hospital = $change['new_hospital'];
                }
                if ($newRole) {
                    $delivery->affiliate_role = $change['new_role'];
                }

                $delivery->save();

                // Registrar en log para trazabilidad
                Log::info('Corrección manual de entrega de dotación/EPP', [
                    'command' => 'dotacion-epp:correct-delivery',
                    'delivery_id' => $delivery->id,
                    'affiliate_document_type' => $delivery->affiliate_document_type,
                    'affiliate_document_number' => $delivery->affiliate_document_number,
                    'affiliate_name' => trim(($delivery->affiliate_first_name ?? '') . ' ' . ($delivery->affiliate_last_name ?? '')),
                    'changes' => [
                        'hospital' => [
                            'old' => $oldHospital,
                            'new' => $delivery->affiliate_hospital,
                        ],
                        'role' => [
                            'old' => $oldRole,
                            'new' => $delivery->affiliate_role,
                        ],
                    ],
                    'delivered_at' => $delivery->delivered_at?->toISOString(),
                    'updated_at' => now()->toISOString(),
                    'options' => [
                        'force' => $this->option('force'),
                        'dry_run' => false,
                    ],
                ]);

                $updated++;
                $this->line("   ✅ Entrega {$delivery->id} actualizada correctamente");
            } catch (\Exception $e) {
                $failed++;
                $this->error("   ❌ Error al actualizar entrega {$change['delivery']->id}: {$e->getMessage()}");
                Log::error('Error al corregir entrega de dotación/EPP', [
                    'delivery_id' => $change['delivery']->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->line('');
        if ($updated > 0) {
            $this->info("✅ Se actualizaron correctamente {$updated} entrega(s)");
        }
        if ($failed > 0) {
            $this->error("❌ Falló la actualización de {$failed} entrega(s)");
        }

        return $failed > 0 ? 1 : 0;
    }
}

