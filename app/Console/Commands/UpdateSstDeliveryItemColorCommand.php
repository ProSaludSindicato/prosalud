<?php

namespace App\Console\Commands;

use App\Models\SstDeliveryItem;
use App\Models\SstReturnItem;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateSstDeliveryItemColorCommand extends Command
{
    protected $signature = 'dotacion-epp:update-delivery-item-color
                            {--from= : Valor actual de variant_color (ej. GRIS_REFLECTIVO)}
                            {--to= : Nuevo valor de color (ej. GRIS)}
                            {--item-name= : Filtrar: el nombre del artículo contiene este texto (sin distinguir mayúsculas)}
                            {--hospital= : Filtrar por affiliate_hospital exacto en la entrega}
                            {--delivered-from= : Fecha mínima de entrega (Y-m-d, zona America/Bogota)}
                            {--delivered-to= : Fecha máxima de entrega (Y-m-d, zona America/Bogota)}
                            {--include-returns : También actualizar ítems de devoluciones (sst_return_items)}
                            {--dry-run : Mostrar filas sin guardar}
                            {--force : Aplicar sin confirmación}';

    protected $description = 'Actualizar el color registrado en ítems de entregas de dotación/EPP (variant_color y variant_payload) para corrección de trazabilidad';

    public function handle(): int
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if ($from === null || $from === '' || $to === null || $to === '') {
            $this->error('Debes indicar --from y --to (valores tal como están guardados en variant_color, p. ej. GRIS_REFLECTIVO y GRIS).');

            return Command::FAILURE;
        }

        if ($from === $to) {
            $this->error('--from y --to no pueden ser iguales.');

            return Command::FAILURE;
        }

        $deliveryItems = $this->baseDeliveryItemQuery($from)->get();
        $returnItems = $this->option('include-returns')
            ? $this->baseReturnItemQuery($from)->get()
            : collect();

        $this->info('Ítems de entrega afectados: '.$deliveryItems->count());
        if ($this->option('include-returns')) {
            $this->info('Ítems de devolución afectados: '.$returnItems->count());
        }

        if ($deliveryItems->isEmpty() && $returnItems->isEmpty()) {
            $this->warn('No hay registros que coincidan con los filtros.');

            return Command::SUCCESS;
        }

        $this->table(
            ['Tipo', 'Ítem ID', 'Entrega/Devolución', 'Artículo', 'Color actual', 'Color nuevo'],
            $deliveryItems->map(fn (SstDeliveryItem $item) => [
                'entrega',
                substr($item->id, 0, 8).'…',
                substr($item->delivery_id, 0, 8).'…',
                $item->item_name,
                $item->variant_color,
                $to,
            ])->merge(
                $returnItems->map(fn (SstReturnItem $item) => [
                    'devolución',
                    substr($item->id, 0, 8).'…',
                    substr($item->return_id, 0, 8).'…',
                    $item->item_name,
                    $item->variant_color,
                    $to,
                ])
            )->all()
        );

        if ($this->option('dry-run')) {
            $this->warn('Modo dry-run: no se guardó nada.');

            return Command::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn('Se modificarán datos históricos de dotación/EPP.');
            if (! $this->confirm('¿Continuar?')) {
                $this->info('Operación cancelada.');

                return Command::SUCCESS;
            }
        }

        $updatedDeliveries = 0;
        $updatedReturns = 0;

        DB::transaction(function () use ($deliveryItems, $returnItems, $from, $to, &$updatedDeliveries, &$updatedReturns): void {
            foreach ($deliveryItems as $item) {
                $this->persistItemColorChange($item, $from, $to);
                $updatedDeliveries++;
            }
            foreach ($returnItems as $item) {
                $this->persistReturnItemColorChange($item, $from, $to);
                $updatedReturns++;
            }
        });

        Log::info('dotacion-epp:update-delivery-item-color aplicado', [
            'from' => $from,
            'to' => $to,
            'item_name_filter' => $this->option('item-name'),
            'hospital' => $this->option('hospital'),
            'delivered_from' => $this->option('delivered-from'),
            'delivered_to' => $this->option('delivered-to'),
            'include_returns' => $this->option('include-returns'),
            'delivery_items_updated' => $updatedDeliveries,
            'return_items_updated' => $updatedReturns,
        ]);

        $this->info("Listo: {$updatedDeliveries} ítem(s) de entrega, {$updatedReturns} ítem(s) de devolución.");

        return Command::SUCCESS;
    }

    private function baseDeliveryItemQuery(string $from): Builder
    {
        $query = SstDeliveryItem::query()
            ->where('variant_color', $from)
            ->with('delivery');

        if ($name = $this->option('item-name')) {
            $needle = '%'.mb_strtolower((string) $name).'%';
            $query->whereRaw('LOWER(item_name) LIKE ?', [$needle]);
        }

        if ($hospital = $this->option('hospital')) {
            $query->whereHas('delivery', fn (Builder $q) => $q->where('affiliate_hospital', $hospital));
        }

        $fromDate = $this->option('delivered-from');
        $toDate = $this->option('delivered-to');

        if ($fromDate || $toDate) {
            $start = $fromDate
                ? Carbon::parse($fromDate, 'America/Bogota')->startOfDay()
                : Carbon::parse('1970-01-01', 'America/Bogota')->startOfDay();
            $end = $toDate
                ? Carbon::parse($toDate, 'America/Bogota')->endOfDay()
                : Carbon::now('America/Bogota')->endOfDay();

            $query->whereHas('delivery', fn (Builder $q) => $q->whereBetween('delivered_at', [$start, $end]));
        }

        return $query;
    }

    private function baseReturnItemQuery(string $from): Builder
    {
        $query = SstReturnItem::query()
            ->where('variant_color', $from)
            ->with('returnRecord');

        if ($name = $this->option('item-name')) {
            $needle = '%'.mb_strtolower((string) $name).'%';
            $query->whereRaw('LOWER(item_name) LIKE ?', [$needle]);
        }

        if ($hospital = $this->option('hospital')) {
            $query->whereHas('returnRecord', fn (Builder $q) => $q->where('affiliate_hospital', $hospital));
        }

        $fromDate = $this->option('delivered-from');
        $toDate = $this->option('delivered-to');

        if ($fromDate || $toDate) {
            $start = $fromDate
                ? Carbon::parse($fromDate, 'America/Bogota')->startOfDay()
                : Carbon::parse('1970-01-01', 'America/Bogota')->startOfDay();
            $end = $toDate
                ? Carbon::parse($toDate, 'America/Bogota')->endOfDay()
                : Carbon::now('America/Bogota')->endOfDay();

            $query->whereHas('returnRecord', fn (Builder $q) => $q->whereBetween('returned_at', [$start, $end]));
        }

        return $query;
    }

    private function persistItemColorChange(SstDeliveryItem $item, string $from, string $to): void
    {
        $item->variant_color = $to;
        $payload = $item->variant_payload;
        if (is_array($payload) && array_key_exists('color', $payload) && $payload['color'] === $from) {
            $payload['color'] = $to;
            $item->variant_payload = $payload;
        }
        $item->save();
    }

    private function persistReturnItemColorChange(SstReturnItem $item, string $from, string $to): void
    {
        $item->variant_color = $to;
        $payload = $item->variant_payload;
        if (is_array($payload) && array_key_exists('color', $payload) && $payload['color'] === $from) {
            $payload['color'] = $to;
            $item->variant_payload = $payload;
        }
        $item->save();
    }
}
