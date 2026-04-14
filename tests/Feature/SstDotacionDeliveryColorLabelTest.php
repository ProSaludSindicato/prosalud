<?php

namespace Tests\Feature;

use App\Models\InventoryColor;
use App\Models\SstDeliveryItem;
use App\Models\SstDeliveryRecord;
use App\Services\SstDotacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SstDotacionDeliveryColorLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_deliveries_includes_color_label_from_inventory_colors_table(): void
    {
        InventoryColor::query()->insert([
            'id' => 'GRIS',
            'label' => 'Gris Claro',
            'hex' => '#6B7280',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deliveryId = (string) Str::uuid();

        SstDeliveryRecord::query()->create([
            'id' => $deliveryId,
            'affiliate_id' => 'aff-test',
            'affiliate_document_type' => 'CC',
            'affiliate_document_number' => '123',
            'signed_document_type' => 'CC',
            'signed_document_number' => '123',
            'delivery_type' => 'periodic',
        ]);

        SstDeliveryItem::query()->create([
            'id' => (string) Str::uuid(),
            'delivery_id' => $deliveryId,
            'item_id' => 'prod-1',
            'item_name' => 'Pijama',
            'item_category' => 'Dotación',
            'unit' => 'unidad',
            'variant_color' => 'GRIS',
            'variant_size' => 'M',
            'variant_payload' => ['color' => 'GRIS', 'size' => 'M'],
            'quantity' => 1,
        ]);

        $service = app(SstDotacionService::class);
        $result = $service->getDeliveries(['affiliateId' => 'aff-test']);

        $this->assertCount(1, $result['items']);
        $variant = $result['items'][0]['items'][0]['variant'];
        $this->assertSame('GRIS', $variant['color']);
        $this->assertSame('Gris Claro', $variant['colorLabel']);
        $this->assertSame('M', $variant['size']);
    }
}
