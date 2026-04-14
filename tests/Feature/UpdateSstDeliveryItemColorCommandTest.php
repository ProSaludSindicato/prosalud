<?php

namespace Tests\Feature;

use App\Models\InventoryColor;
use App\Models\SstDeliveryItem;
use App\Models\SstDeliveryRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateSstDeliveryItemColorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_delivery_item_color_and_payload(): void
    {
        $deliveryId = (string) Str::uuid();

        SstDeliveryRecord::query()->create([
            'id' => $deliveryId,
            'affiliate_id' => 'aff-test',
            'affiliate_document_type' => 'CC',
            'affiliate_document_number' => '1005981479',
            'affiliate_first_name' => 'Test',
            'affiliate_last_name' => 'User',
            'affiliate_hospital' => 'HMFS - BELLO',
            'signed_document_type' => 'CC',
            'signed_document_number' => '1005981479',
            'delivery_type' => 'periodic',
        ]);

        $itemId = (string) Str::uuid();

        SstDeliveryItem::query()->create([
            'id' => $itemId,
            'delivery_id' => $deliveryId,
            'item_id' => 'pid-1',
            'item_name' => 'Pijama',
            'item_category' => 'Dotación',
            'unit' => 'unidad',
            'variant_color' => 'GRIS_REFLECTIVO',
            'variant_size' => 'M',
            'variant_payload' => ['color' => 'GRIS_REFLECTIVO', 'size' => 'M'],
            'quantity' => 1,
        ]);

        $this->artisan('dotacion-epp:update-delivery-item-color', [
            '--from' => 'GRIS_REFLECTIVO',
            '--to' => 'GRIS',
            '--item-name' => 'Pijama',
            '--force' => true,
        ])->assertSuccessful();

        $item = SstDeliveryItem::query()->findOrFail($itemId);

        $this->assertSame('GRIS', $item->variant_color);
        $this->assertSame('GRIS', $item->variant_payload['color']);
    }

    public function test_item_name_filter_excludes_non_matching_rows(): void
    {
        $deliveryId = (string) Str::uuid();

        SstDeliveryRecord::query()->create([
            'id' => $deliveryId,
            'affiliate_id' => 'aff-test',
            'affiliate_document_type' => 'CC',
            'affiliate_document_number' => '1',
            'signed_document_type' => 'CC',
            'signed_document_number' => '1',
            'delivery_type' => 'periodic',
        ]);

        $otherId = (string) Str::uuid();

        SstDeliveryItem::query()->create([
            'id' => $otherId,
            'delivery_id' => $deliveryId,
            'item_id' => 'x',
            'item_name' => 'Otro artículo',
            'item_category' => 'Dotación',
            'unit' => 'unidad',
            'variant_color' => 'GRIS_REFLECTIVO',
            'variant_size' => 'M',
            'variant_payload' => ['color' => 'GRIS_REFLECTIVO', 'size' => 'M'],
            'quantity' => 1,
        ]);

        $this->artisan('dotacion-epp:update-delivery-item-color', [
            '--from' => 'GRIS_REFLECTIVO',
            '--to' => 'GRIS',
            '--item-name' => 'Pijama',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame('GRIS_REFLECTIVO', SstDeliveryItem::query()->findOrFail($otherId)->variant_color);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $deliveryId = (string) Str::uuid();

        SstDeliveryRecord::query()->create([
            'id' => $deliveryId,
            'affiliate_id' => 'aff-test',
            'affiliate_document_type' => 'CC',
            'affiliate_document_number' => '1',
            'signed_document_type' => 'CC',
            'signed_document_number' => '1',
            'delivery_type' => 'periodic',
        ]);

        $itemId = (string) Str::uuid();

        SstDeliveryItem::query()->create([
            'id' => $itemId,
            'delivery_id' => $deliveryId,
            'item_id' => 'pid-1',
            'item_name' => 'Pijama',
            'item_category' => 'Dotación',
            'unit' => 'unidad',
            'variant_color' => 'GRIS_REFLECTIVO',
            'variant_size' => 'M',
            'variant_payload' => ['color' => 'GRIS_REFLECTIVO', 'size' => 'M'],
            'quantity' => 1,
        ]);

        $this->artisan('dotacion-epp:update-delivery-item-color', [
            '--from' => 'GRIS_REFLECTIVO',
            '--to' => 'GRIS',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame('GRIS_REFLECTIVO', SstDeliveryItem::query()->findOrFail($itemId)->variant_color);
    }

    public function test_inventory_update_color_label_command(): void
    {
        InventoryColor::query()->insert([
            'id' => 'TEST_GRIS_LABEL',
            'label' => 'Gris',
            'hex' => '#6B7280',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('inventory:update-color-label', [
            'color-id' => 'TEST_GRIS_LABEL',
            'new-label' => 'Gris Claro',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame('Gris Claro', InventoryColor::query()->find('TEST_GRIS_LABEL')->label);
    }
}
