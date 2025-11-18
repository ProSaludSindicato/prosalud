<?php

namespace App\Services;

use App\Models\{Hospital, InventoryLocation, InventoryStockMovement, InventoryVariant, InventoryVariantStock};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryStockService
{
    public function getPrimaryLocation(): InventoryLocation
    {
        return InventoryLocation::query()->where('is_primary', true)->firstOrFail();
    }

    public function findOrCreateStock(InventoryVariant $variant, InventoryLocation $location): InventoryVariantStock
    {
        return InventoryVariantStock::query()->firstOrCreate(
            [
                'variant_id' => $variant->id,
                'location_id' => $location->id,
            ],
            [
                'stock' => 0,
                'reserved' => 0,
                'min_stock' => $variant->min_stock ?? 0,
                'max_stock' => $variant->max_stock,
            ]
        );
    }

    public function adjustStock(
        InventoryVariant $variant,
        InventoryLocation $location,
        int $quantity,
        string $reason,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $notes = null,
    ): InventoryVariantStock {
        if (0 === $quantity) {
            return $this->findOrCreateStock($variant, $location);
        }

        $stock = $this->findOrCreateStock($variant, $location);
        $newValue = $stock->stock + $quantity;

        if ($newValue < 0) {
            throw new \RuntimeException("Stock insuficiente en la ubicación {$location->name}");
        }

        $stock->stock = $newValue;
        $stock->save();

        $this->updateVariantTotalStock($variant);

        $this->createMovement(
            variant: $variant,
            from: $quantity < 0 ? $location : null,
            to: $quantity > 0 ? $location : null,
            quantity: abs($quantity),
            reason: $reason,
            referenceType: $referenceType,
            referenceId: $referenceId,
            notes: $notes
        );

        return $stock;
    }

    public function adjustReserved(
        InventoryVariant $variant,
        InventoryLocation $location,
        int $quantity,
    ): InventoryVariantStock {
        if (0 === $quantity) {
            return $this->findOrCreateStock($variant, $location);
        }

        $stock = $this->findOrCreateStock($variant, $location);
        $newReserved = $stock->reserved + $quantity;

        if ($newReserved < 0) {
            throw new \RuntimeException("Reservas insuficientes en la ubicación {$location->name}");
        }

        $stock->reserved = $newReserved;
        $stock->save();

        return $stock;
    }

    public function transferStock(
        InventoryVariant $variant,
        InventoryLocation $from,
        InventoryLocation $to,
        int $quantity,
        string $reason,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $notes = null,
    ): void {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('La cantidad de transferencia debe ser mayor que cero.');
        }

        DB::transaction(function () use ($variant, $from, $to, $quantity, $reason, $referenceType, $referenceId, $notes) {
            $fromStock = $this->findOrCreateStock($variant, $from);

            if ($fromStock->stock < $quantity) {
                throw new \RuntimeException("Stock insuficiente en {$from->name} para transferir {$quantity} unidades.");
            }

            $fromStock->decrement('stock', $quantity);
            $toStock = $this->findOrCreateStock($variant, $to);
            $toStock->increment('stock', $quantity);

            $this->updateVariantTotalStock($variant);

            $this->createMovement(
                variant: $variant,
                from: $from,
                to: $to,
                quantity: $quantity,
                reason: $reason,
                referenceType: $referenceType,
                referenceId: $referenceId,
                notes: $notes
            );
        });
    }

    public function updateVariantTotalStock(InventoryVariant $variant): void
    {
        $total = $variant->stocks()->sum('stock');
        $variant->update(['stock' => $total]);
    }

    public function setVariantTotalStock(InventoryVariant $variant, int $desiredTotal): void
    {
        $primaryLocation = $this->getPrimaryLocation();
        $primaryStock = $this->findOrCreateStock($variant, $primaryLocation);

        $currentTotal = $variant->stocks()->sum('stock');
        $difference = $desiredTotal - $currentTotal;

        if (0 === $difference) {
            $this->updateVariantTotalStock($variant);

            return;
        }

        if ($difference > 0) {
            $primaryStock->stock += $difference;
        } else {
            $reductionNeeded = abs($difference);
            $available = $primaryStock->stock - $primaryStock->reserved;

            if ($available < $reductionNeeded) {
                throw new \RuntimeException("No hay stock disponible en {$primaryLocation->name} para ajustar a {$desiredTotal} unidades.");
            }

            $primaryStock->stock -= $reductionNeeded;
        }

        if ($primaryStock->stock < $primaryStock->reserved) {
            throw new \RuntimeException("El stock disponible en {$primaryLocation->name} no puede ser menor que lo reservado.");
        }

        $primaryStock->save();
        $this->updateVariantTotalStock($variant->fresh());
    }

    public function createMovement(
        InventoryVariant $variant,
        ?InventoryLocation $from,
        ?InventoryLocation $to,
        int $quantity,
        string $reason,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $notes = null,
    ): InventoryStockMovement {
        return InventoryStockMovement::query()->create([
            'id' => (string) Str::uuid(),
            'variant_id' => $variant->id,
            'from_location_id' => $from?->id,
            'to_location_id' => $to?->id,
            'quantity' => $quantity,
            'reason' => $reason,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => $notes,
            'moved_at' => now(),
        ]);
    }

    public function ensureHospitalLocation(string $hospitalNameOrId): InventoryLocation
    {
        if (is_numeric($hospitalNameOrId)) {
            $hospital = Hospital::query()->find((int) $hospitalNameOrId);
        } else {
            $hospital = Hospital::query()->where('name', $hospitalNameOrId)->first();
        }

        if (!$hospital) {
            $hospital = Hospital::query()->create([
                'name' => is_string($hospitalNameOrId) ? $hospitalNameOrId : 'Hospital',
                'type' => 'hospital',
            ]);
        }

        $location = $hospital->locations()->first();

        if ($location) {
            return $location;
        }

        return InventoryLocation::query()->create([
            'id' => (string) Str::uuid(),
            'hospital_id' => $hospital->id,
            'name' => $hospital->name,
            'type' => 'hospital',
            'is_primary' => false,
        ]);
    }
}
