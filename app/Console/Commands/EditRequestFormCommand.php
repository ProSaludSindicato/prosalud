<?php

namespace App\Console\Commands;

use App\Constants\RequestStatuses;
use App\Models\RequestForm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EditRequestFormCommand extends Command
{
    protected $signature = 'request-forms:edit
                            {id : ID de la solicitud a editar}
                            {--status= : Nuevo estado (PENDING, IN_REVIEW, REJECTED, COMPLETED, pending, processed)}
                            {--name= : Nombre}
                            {--last-name= : Apellido}
                            {--email= : Correo electrónico}
                            {--phone-number= : Teléfono}
                            {--document-type= : Tipo de documento (CC, CE, TI, etc.)}
                            {--document-number= : Número de documento}
                            {--request-type= : Tipo de solicitud}
                            {--rejection-reason= : Motivo de rechazo (opcional; recomendado si status=REJECTED)}
                            {--processed-at= : Fecha de procesamiento (Y-m-d H:i:s o "now")}
                            {--force : Omitir confirmación interactiva}
                            {--dry-run : Mostrar qué se actualizaría sin ejecutar los cambios}';

    protected $description = 'Editar datos y estado de una solicitud (RequestForm)';

    /** Estados aceptados (API y legacy). */
    private const VALID_STATUSES = [
        RequestStatuses::PENDING,
        RequestStatuses::IN_REVIEW,
        RequestStatuses::REJECTED,
        RequestStatuses::COMPLETED,
        'pending',
        'processed',
    ];

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        $requestForm = RequestForm::find($id);

        if (!$requestForm) {
            $this->error("No se encontró la solicitud con ID: {$id}");
            return 1;
        }

        $this->info('Edición de solicitud (RequestForm)');
        $this->line(str_repeat('=', 50));
        $this->showCurrent($requestForm);

        $updates = $this->buildUpdates($requestForm);

        if (empty($updates)) {
            $this->warn('No se indicó ningún campo para actualizar. Usa las opciones --status, --name, etc.');
            return 0;
        }

        $this->newLine();
        $this->info('Cambios propuestos:');
        foreach ($updates as $key => $value) {
            $current = $requestForm->getAttribute($key);
            $displayCurrent = $this->displayValue($current);
            $displayNew = $this->displayValue($value);
            $this->line("  {$key}: {$displayCurrent} → {$displayNew}");
        }

        if ($dryRun) {
            $this->info('[DRY RUN] No se aplicaron cambios.');
            return 0;
        }

        if (!$force && !$this->confirm('¿Aplicar estos cambios?')) {
            $this->info('Operación cancelada.');
            return 0;
        }

        try {
            DB::transaction(function () use ($requestForm, $updates) {
                $requestForm->fill($updates);

                if (array_key_exists('processed_at', $updates) && $updates['processed_at'] !== null) {
                    $requestForm->processed_at = $updates['processed_at'];
                }

                $requestForm->save();
            });

            $this->info('Solicitud actualizada correctamente.');
            $requestForm->refresh();
            $this->showCurrent($requestForm);
        } catch (\Throwable $e) {
            $this->error('Error al actualizar: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function showCurrent(RequestForm $requestForm): void
    {
        $this->line("ID: {$requestForm->id}");
        $this->line("Tipo: {$requestForm->request_type}");
        $this->line("Nombre: {$requestForm->full_name}");
        $this->line("Documento: {$requestForm->document_type} {$requestForm->document_number}");
        $this->line("Email: {$requestForm->email}");
        $this->line("Teléfono: {$requestForm->phone_number}");
        $this->line("Estado: {$requestForm->status}");
        $processedAt = $requestForm->processed_at;
        $this->line("Procesado: " . ($processedAt instanceof \DateTimeInterface ? $processedAt->format('Y-m-d H:i') : '-'));
        if ($requestForm->rejection_reason) {
            $this->line("Motivo rechazo: {$requestForm->rejection_reason}");
        }
    }

    private function buildUpdates(RequestForm $requestForm): array
    {
        $updates = [];

        $status = $this->option('status');
        if ($status !== null && $status !== '') {
            $normalized = trim($status);
            $allowed = array_merge(
                [RequestStatuses::PENDING, RequestStatuses::IN_REVIEW, RequestStatuses::REJECTED, RequestStatuses::COMPLETED],
                ['pending', 'processed']
            );
            if (!in_array($normalized, $allowed, true)) {
                $this->error("Estado no válido: {$status}. Válidos: PENDING, IN_REVIEW, REJECTED, COMPLETED, pending, processed");
                return [];
            }
            $updates['status'] = $normalized;
        }

        $stringOptions = [
            'name' => 'name',
            'last-name' => 'last_name',
            'email' => 'email',
            'phone-number' => 'phone_number',
            'document-type' => 'document_type',
            'document-number' => 'document_number',
            'request-type' => 'request_type',
            'rejection-reason' => 'rejection_reason',
        ];

        foreach ($stringOptions as $option => $attribute) {
            $value = $this->option($option);
            if ($value !== null && $value !== '') {
                $updates[$attribute] = $value;
            }
        }

        $processedAt = $this->option('processed-at');
        if ($processedAt !== null && $processedAt !== '') {
            if (strtolower($processedAt) === 'now') {
                $updates['processed_at'] = now();
            } else {
                $parsed = \Carbon\Carbon::parse($processedAt);
                $updates['processed_at'] = $parsed;
            }
        } elseif (!empty($updates)) {
            // Por defecto, fecha de procesamiento = fecha actual si no se pasa una en específico
            $updates['processed_at'] = now();
        }

        return $updates;
    }

    private function displayValue($value): string
    {
        if ($value === null || $value === '') {
            return '(vacío)';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        return (string) $value;
    }
}
