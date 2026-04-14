<?php

namespace App\Console\Commands;

use App\Models\InventoryColor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateInventoryColorLabelCommand extends Command
{
    protected $signature = 'inventory:update-color-label
                            {color-id : ID del color en inventory_colors (ej. GRIS)}
                            {new-label : Nueva etiqueta visible (ej. Gris Claro)}
                            {--dry-run : Mostrar cambio sin guardar}
                            {--force : Aplicar sin confirmación}';

    protected $description = 'Actualizar la etiqueta (label) de un color de inventario sin cambiar su ID';

    public function handle(): int
    {
        $colorId = (string) $this->argument('color-id');
        $newLabel = (string) $this->argument('new-label');

        $color = InventoryColor::query()->find($colorId);

        if ($color === null) {
            $this->error("No existe un color con id «{$colorId}» en inventory_colors.");

            return Command::FAILURE;
        }

        $oldLabel = $color->label;

        if ($oldLabel === $newLabel) {
            $this->info('La etiqueta ya es la solicitada; no hay nada que hacer.');

            return Command::SUCCESS;
        }

        $this->line("ID: {$color->id}");
        $this->line("Etiqueta actual: {$oldLabel}");
        $this->line("Etiqueta nueva:   {$newLabel}");

        if ($this->option('dry-run')) {
            $this->warn('Modo dry-run: no se guardó nada.');

            return Command::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! $this->confirm('¿Actualizar la etiqueta en inventory_colors?')) {
                $this->info('Operación cancelada.');

                return Command::SUCCESS;
            }
        }

        $color->label = $newLabel;
        $color->save();

        Log::info('inventory:update-color-label aplicado', [
            'color_id' => $colorId,
            'old_label' => $oldLabel,
            'new_label' => $newLabel,
        ]);

        $this->info('Etiqueta actualizada.');

        return Command::SUCCESS;
    }
}
