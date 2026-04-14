<?php

namespace Tests\Feature;

use App\Models\InventoryCategory;
use App\Models\InventoryColor;
use App\Models\InventoryProduct;
use App\Models\InventoryVariant;
use App\Services\SstDotacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class DotacionInventoryCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_clear_inventory_cache_refreshes_dotacion_inventory_payload(): void
    {
        Cache::flush();

        InventoryColor::query()->insert([
            ['id' => 'TEST_DOT_BLUE', 'label' => 'Azul', 'hex' => '#0000FF', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'TEST_DOT_GRAY', 'label' => 'Gris', 'hex' => '#808080', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $category = InventoryCategory::query()->create([
            'name' => 'Dotación',
            'description' => 'Categoría de prueba',
            'icon' => null,
        ]);

        $product = InventoryProduct::query()->create([
            'name' => 'Pijama de prueba cache',
            'category_id' => $category->id,
            'subcategory_id' => null,
            'description' => null,
            'variant_mode' => 'size_color',
            'gender' => 'mixto',
        ]);

        InventoryVariant::query()->create([
            'product_id' => $product->id,
            'size' => 'M',
            'color_id' => 'TEST_DOT_BLUE',
            'stock' => 1,
            'min_stock' => 0,
            'max_stock' => 10,
            'sku' => 'TEST-CACHE-M-BLUE-'.Str::upper(Str::random(8)),
        ]);

        $service = app(SstDotacionService::class);

        $first = $service->getInventoryItems();
        $this->assertTrue(Cache::has(SstDotacionService::INVENTORY_ITEMS_CACHE_KEY));

        $row = collect($first)->firstWhere('name', 'Pijama de prueba cache');
        $this->assertNotNull($row);
        $this->assertCount(1, $row['variants']);

        InventoryVariant::query()->create([
            'product_id' => $product->id,
            'size' => 'M',
            'color_id' => 'TEST_DOT_GRAY',
            'stock' => 1,
            'min_stock' => 0,
            'max_stock' => 10,
            'sku' => 'TEST-CACHE-M-GRAY-'.Str::upper(Str::random(8)),
        ]);

        $cached = $service->getInventoryItems();
        $cachedRow = collect($cached)->firstWhere('name', 'Pijama de prueba cache');
        $this->assertNotNull($cachedRow);
        $this->assertCount(1, $cachedRow['variants'], 'Sin invalidar caché, el listado de dotación no debe reflejar la nueva variante.');

        SstDotacionService::clearInventoryCache();
        $this->assertFalse(Cache::has(SstDotacionService::INVENTORY_ITEMS_CACHE_KEY));

        $afterClear = $service->getInventoryItems();
        $freshRow = collect($afterClear)->firstWhere('name', 'Pijama de prueba cache');
        $this->assertNotNull($freshRow);
        $this->assertCount(2, $freshRow['variants']);
    }
}
