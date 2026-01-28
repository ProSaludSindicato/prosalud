<?php

namespace App\Console\Commands;

use App\Models\WellnessDeliveryRequest;
use Illuminate\Console\Command;

/**
 * COMANDO TEMPORAL Y ESPECÍFICO
 *
 * Este comando existe ÚNICAMENTE para crear manualmente la solicitud de kit escolar
 * de la afiliada:
 *
 *  - Documento afiliado: 1143159357
 *  - Nombre afiliado:    KATHERINE PAOLA ZORRO BRAVO
 *  - Hospital:           HLM - GRUPO 1
 *  - Beneficiario:       HELLEN SOFIA PACHECO ZORRO
 *  - Parentesco:         HIJO_BIOLOGICO
 *  - Edad:               8
 *
 * Características:
 *  - Crea una solicitud de tipo "kit_escolar"
 *  - Sin firma de inscripción (campo firma vacío)
 *  - Fecha de expedición: 09/01/15 (texto plano, formato dd/mm/aa)
 *  - Fecha de registro: fecha/hora actual (created_at / updated_at manejados por Eloquent)
 *
 * IMPORTANTE:
 *  - Este comando está pensado para ejecutarse UNA SOLA VEZ.
 *  - Es seguro eliminar ESTE ARCHIVO después de ejecutarlo.
 */
class CreateManualWellnessDeliveryForKatherineCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wellness-delivery:create-katherine-kit-escolar
                            {--force : Ejecutar sin pedir confirmación}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea manualmente la solicitud de kit escolar para KATHERINE PAOLA ZORRO BRAVO (comando temporal, usar una sola vez).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚑 Creación manual de solicitud de kit escolar (bienestar)');
        $this->line('=========================================================');

        $documentoAfiliado = '1143159357';
        $tipoEntrega = 'kit_escolar';

        // Verificar si ya existe una solicitud para este documento y tipo de entrega
        $existing = WellnessDeliveryRequest::where('documento_afiliado', $documentoAfiliado)
            ->where('tipo_entrega', $tipoEntrega)
            ->whereIn('estado', ['pendiente', 'entregado'])
            ->first();

        if ($existing) {
            $this->warn('⚠️  Ya existe una solicitud para este documento y tipo de entrega.');
            $this->line('ID existente: ' . $existing->id);
            $this->line('Estado: ' . $existing->estado);
            $this->line('Creada el: ' . $existing->created_at?->toDateTimeString());

            $this->info('No se creó un nuevo registro para evitar duplicados.');

            return self::SUCCESS;
        }

        // Datos fijos provistos por el requerimiento
        $data = [
            'tipo_entrega' => $tipoEntrega,
            'documento_afiliado' => $documentoAfiliado,
            'nombre_afiliado' => 'KATHERINE PAOLA ZORRO BRAVO',
            'hospital' => 'HLM - GRUPO 1',
            // La app espera un string tipo dd/mm/aa, usamos 09/01/15
            'fecha_expedicion' => '09/01/15',
            'beneficiarios' => [
                [
                    'beneficiario' => 'HELLEN SOFIA PACHECO ZORRO',
                    'parentesco' => 'HIJO_BIOLOGICO',
                    'edad' => 8,
                ],
            ],
            // Sin firma de inscripción (campo requerido en DB, pero sin contenido real)
            'firma' => '',
            'ip_address' => null,
            'user_agent' => null,
            // La solicitud se registra como pendiente; se podrá marcar como entregada desde el panel
            'estado' => 'pendiente',
            'cantidad_entregada' => null,
            'observaciones' => 'Registro creado manualmente desde el sistema por particularida (sin firma de inscripción).',
            'entregado_por_user_id' => null,
        ];

        $this->info('Creando registro de wellness_delivery_requests con los siguientes datos:');
        foreach ($data as $key => $value) {
            if ($key === 'beneficiarios') {
                $this->line("  - {$key}: " . json_encode($value, JSON_UNESCAPED_UNICODE));
            } else {
                $this->line("  - {$key}: " . (is_null($value) ? 'null' : (string) $value));
            }
        }

        // Si no se pasa --force, pedir confirmación interactiva
        if (!$this->option('force')) {
            if (!$this->confirm('¿Deseas continuar y crear este registro en la base de datos?', true)) {
                $this->info('Operación cancelada por el usuario. No se creó ningún registro.');

                return self::SUCCESS;
            }
        } else {
            $this->warn('Ejecutando en modo --force: no se pidió confirmación interactiva.');
        }

        $request = WellnessDeliveryRequest::create($data);

        $this->info('');
        $this->info('✅ Registro creado exitosamente en wellness_delivery_requests.');
        $this->line('ID: ' . $request->id);
        $this->line('Documento afiliado: ' . $request->documento_afiliado);
        $this->line('Nombre afiliado: ' . $request->nombre_afiliado);
        $this->line('Estado: ' . $request->estado);
        $this->line('Fecha de registro (created_at): ' . $request->created_at?->toDateTimeString());

        $this->info('');
        $this->info('Cuando ya no necesites este comando, puedes ELIMINAR este archivo:');
        $this->line('  app/Console/Commands/CreateManualWellnessDeliveryForKatherineCommand.php');

        return self::SUCCESS;
    }
}


