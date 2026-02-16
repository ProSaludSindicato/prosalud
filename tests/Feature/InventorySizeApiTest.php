<?php

namespace Tests\Feature;

use App\Http\Requests\Inventory\StoreProductRequest;
use App\Models\InventoryCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class InventorySizeApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test API request validation accepts traditional sizes.
     */
    public function test_api_request_accepts_traditional_sizes(): void
    {
        $category = \App\Models\InventoryCategory::create([
            'name' => 'Test Category ' . uniqid(),
        ]);
        
        $request = new StoreProductRequest();
        
        $validator = Validator::make([
            'name' => 'Test T-Shirt',
            'category_id' => $category->id,
            'variant_mode' => 'size',
            'variants' => [
                [
                    'size' => 'XS',
                    'stock' => 10,
                    'min_stock' => 5,
                    'sku' => 'TSHIRT-XS-001',
                ],
                [
                    'size' => 'XL',
                    'stock' => 15,
                    'min_stock' => 5,
                    'sku' => 'TSHIRT-XL-001',
                ],
                [
                    'size' => '3XL',
                    'stock' => 8,
                    'min_stock' => 3,
                    'sku' => 'TSHIRT-3XL-001',
                ],
            ]
        ], $request->rules());

        $this->assertTrue($validator->passes(), 'Traditional sizes should be valid');
        $this->assertEmpty($validator->errors());
    }

    /**
     * Test API request validation accepts numeric sizes for pants.
     */
    public function test_api_request_accepts_numeric_pants_sizes(): void
    {
        $category = \App\Models\InventoryCategory::create([
            'name' => 'Test Category ' . uniqid(),
        ]);
        
        $request = new StoreProductRequest();
        
        $validator = Validator::make([
            'name' => 'Test Pants',
            'category_id' => $category->id,
            'variant_mode' => 'size',
            'variants' => [
                [
                    'size' => '30',
                    'stock' => 10,
                    'min_stock' => 5,
                    'sku' => 'PANTS-30-001',
                ],
                [
                    'size' => '8',
                    'stock' => 15,
                    'min_stock' => 5,
                    'sku' => 'PANTS-8-001',
                ],
                [
                    'size' => '42',
                    'stock' => 8,
                    'min_stock' => 3,
                    'sku' => 'PANTS-42-001',
                ],
            ]
        ], $request->rules());

        $this->assertTrue($validator->passes(), 'Numeric pants sizes should be valid');
        $this->assertEmpty($validator->errors());
    }

    /**
     * Test API request validation rejects invalid sizes.
     */
    public function test_api_request_rejects_invalid_sizes(): void
    {
        $category = \App\Models\InventoryCategory::create([
            'name' => 'Test Category ' . uniqid(),
        ]);
        
        $request = new StoreProductRequest();
        
        $validator = Validator::make([
            'name' => 'Test Product',
            'category_id' => $category->id,
            'variant_mode' => 'size',
            'variants' => [
                [
                    'size' => 'XXS',
                    'stock' => 10,
                    'min_stock' => 5,
                    'sku' => 'INVALID-XXS-001',
                ],
            ]
        ], $request->rules());

        $this->assertFalse($validator->passes(), 'Invalid sizes should be rejected');
        $this->assertTrue($validator->errors()->has('variants.0.size'));
        
        $errorMessages = $validator->errors()->get('variants.0.size');
        $this->assertContains('La talla debe ser una de las siguientes: XS, S, M, L, XL, 2XL, 3XL, 4XL, 5XL, o un número entre 6-18, 28-42, o 40-44.', $errorMessages);
    }

    /**
     * Test API request validation accepts numeric sizes for shoes.
     */
    public function test_api_request_accepts_shoes_sizes(): void
    {
        $category = \App\Models\InventoryCategory::create([
            'name' => 'Test Category ' . uniqid(),
        ]);
        
        $request = new StoreProductRequest();
        
        $validator = Validator::make([
            'name' => 'Test Shoes',
            'category_id' => $category->id,
            'variant_mode' => 'size',
            'variants' => [
                [
                    'size' => '40',
                    'stock' => 10,
                    'min_stock' => 5,
                    'sku' => 'SHOES-40-001',
                ],
                [
                    'size' => '43',
                    'stock' => 8,
                    'min_stock' => 3,
                    'sku' => 'SHOES-43-001',
                ],
                [
                    'size' => '44',
                    'stock' => 12,
                    'min_stock' => 4,
                    'sku' => 'SHOES-44-001',
                ],
            ]
        ], $request->rules());

        $this->assertTrue($validator->passes(), 'Shoes sizes should be valid');
        $this->assertEmpty($validator->errors());
    }

    /**
     * Test API request validation accepts mixed traditional and numeric sizes.
     */
    public function test_api_request_accepts_mixed_sizes(): void
    {
        $category = \App\Models\InventoryCategory::create([
            'name' => 'Test Category ' . uniqid(),
        ]);
        
        $request = new StoreProductRequest();
        
        $validator = Validator::make([
            'name' => 'Mixed Size Product',
            'category_id' => $category->id,
            'variant_mode' => 'size',
            'variants' => [
                [
                    'size' => 'S',
                    'stock' => 10,
                    'min_stock' => 5,
                    'sku' => 'MIXED-S-001',
                ],
                [
                    'size' => '32',
                    'stock' => 15,
                    'min_stock' => 5,
                    'sku' => 'MIXED-32-001',
                ],
                [
                    'size' => '14',
                    'stock' => 8,
                    'min_stock' => 3,
                    'sku' => 'MIXED-14-001',
                ],
            ]
        ], $request->rules());

        $this->assertTrue($validator->passes(), 'Mixed sizes should be valid');
        $this->assertEmpty($validator->errors());
    }

    /**
     * Test API request validation accepts case insensitive traditional sizes.
     */
    public function test_api_request_accepts_case_insensitive_sizes(): void
    {
        $category = \App\Models\InventoryCategory::create([
            'name' => 'Test Category ' . uniqid(),
        ]);
        
        $request = new StoreProductRequest();
        
        $validator = Validator::make([
            'name' => 'Case Test Product',
            'category_id' => $category->id,
            'variant_mode' => 'size',
            'variants' => [
                [
                    'size' => 'xs',
                    'stock' => 10,
                    'min_stock' => 5,
                    'sku' => 'CASE-XS-001',
                ],
                [
                    'size' => 'xl',
                    'stock' => 15,
                    'min_stock' => 5,
                    'sku' => 'CASE-XL-001',
                ],
                [
                    'size' => '2xl',
                    'stock' => 8,
                    'min_stock' => 3,
                    'sku' => 'CASE-2XL-001',
                ],
            ]
        ], $request->rules());

        $this->assertTrue($validator->passes(), 'Case insensitive sizes should be valid');
        $this->assertEmpty($validator->errors());
    }
}
