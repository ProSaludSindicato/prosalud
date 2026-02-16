<?php

namespace Tests\Unit;

use App\Http\Requests\Inventory\StoreProductRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Illuminate\Support\Facades\Validator;

class InventorySizeValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that traditional sizes are accepted.
     */
    public function test_traditional_sizes_are_accepted(): void
    {
        $traditionalSizes = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'];
        
        foreach ($traditionalSizes as $size) {
            $request = new StoreProductRequest();
            
            $validator = Validator::make([
                'name' => 'Test Product',
                'category_id' => $this->createCategory()->id,
                'variant_mode' => 'size',
                'variants' => [
                    [
                        'size' => $size,
                        'stock' => 10,
                        'min_stock' => 5,
                        'sku' => 'TEST-' . uniqid(),
                    ]
                ]
            ], $request->rules());

            $this->assertTrue($validator->passes(), "Traditional size {$size} should be valid");
        }
    }

    /**
     * Test that numeric sizes for men's pants are accepted.
     */
    public function test_men_pants_numeric_sizes_are_accepted(): void
    {
        $menSizes = [28, 30, 32, 34, 36, 38, 40, 42];
        
        foreach ($menSizes as $size) {
            $request = new StoreProductRequest();
            
            $validator = Validator::make([
                'name' => 'Test Product',
                'category_id' => $this->createCategory()->id,
                'variant_mode' => 'size',
                'variants' => [
                    [
                        'size' => (string)$size,
                        'stock' => 10,
                        'min_stock' => 5,
                        'sku' => 'TEST-' . uniqid(),
                    ]
                ]
            ], $request->rules());

            $this->assertTrue($validator->passes(), "Men's pants size {$size} should be valid");
        }
    }

    /**
     * Test that numeric sizes for women's pants are accepted.
     */
    public function test_women_pants_numeric_sizes_are_accepted(): void
    {
        $womenSizes = [6, 8, 10, 12, 14, 16, 18];
        
        foreach ($womenSizes as $size) {
            $request = new StoreProductRequest();
            
            $validator = Validator::make([
                'name' => 'Test Product',
                'category_id' => $this->createCategory()->id,
                'variant_mode' => 'size',
                'variants' => [
                    [
                        'size' => (string)$size,
                        'stock' => 10,
                        'min_stock' => 5,
                        'sku' => 'TEST-' . uniqid(),
                    ]
                ]
            ], $request->rules());

            $this->assertTrue($validator->passes(), "Women's pants size {$size} should be valid");
        }
    }

    /**
     * Test that numeric sizes for shoes are accepted.
     */
    public function test_shoes_numeric_sizes_are_accepted(): void
    {
        $shoesSizes = [40, 41, 42, 43, 44];
        
        foreach ($shoesSizes as $size) {
            $request = new StoreProductRequest();
            
            $validator = Validator::make([
                'name' => 'Test Product',
                'category_id' => $this->createCategory()->id,
                'variant_mode' => 'size',
                'variants' => [
                    [
                        'size' => (string)$size,
                        'stock' => 10,
                        'min_stock' => 5,
                        'sku' => 'TEST-' . uniqid(),
                    ]
                ]
            ], $request->rules());

            $this->assertTrue($validator->passes(), "Shoes size {$size} should be valid");
        }
    }

    /**
     * Test that invalid sizes are rejected.
     */
    public function test_invalid_sizes_are_rejected(): void
    {
        $invalidSizes = ['XXS', '6XL', '7XL', '20', '25', '45', '46', 'XXX', 'SMALL', 'MEDIUM'];
        
        foreach ($invalidSizes as $size) {
            $request = new StoreProductRequest();
            
            $validator = Validator::make([
                'name' => 'Test Product',
                'category_id' => $this->createCategory()->id,
                'variant_mode' => 'size',
                'variants' => [
                    [
                        'size' => $size,
                        'stock' => 10,
                        'min_stock' => 5,
                        'sku' => 'TEST-' . uniqid(),
                    ]
                ]
            ], $request->rules());

            $this->assertFalse($validator->passes(), "Invalid size '{$size}' should be rejected");
            
            $errors = $validator->errors();
            $this->assertTrue($errors->has('variants.0.size'), "Should have size validation error for '{$size}'");
        }
    }

    /**
     * Test that null/empty sizes are accepted (nullable field).
     */
    public function test_null_sizes_are_accepted(): void
    {
        $request = new StoreProductRequest();
        
        $validator = Validator::make([
            'name' => 'Test Product',
            'category_id' => $this->createCategory()->id,
            'variant_mode' => 'simple',
            'variants' => [
                [
                    'stock' => 10,
                    'min_stock' => 5,
                    'sku' => 'TEST-' . uniqid(),
                ]
            ]
        ], $request->rules());

        $this->assertTrue($validator->passes(), 'Null size should be valid for simple variant mode');
    }

    /**
     * Test case insensitive validation for traditional sizes.
     */
    public function test_case_insensitive_traditional_sizes(): void
    {
        $caseVariations = ['xs', 's', 'm', 'l', 'xl', '2xl', '3xl', '4xl', '5xl'];
        
        foreach ($caseVariations as $size) {
            $request = new StoreProductRequest();
            
            $validator = Validator::make([
                'name' => 'Test Product',
                'category_id' => $this->createCategory()->id,
                'variant_mode' => 'size',
                'variants' => [
                    [
                        'size' => $size,
                        'stock' => 10,
                        'min_stock' => 5,
                        'sku' => 'TEST-' . uniqid(),
                    ]
                ]
            ], $request->rules());

            $this->assertTrue($validator->passes(), "Case variation '{$size}' should be valid");
        }
    }

    private function createCategory()
    {
        return \App\Models\InventoryCategory::create([
            'name' => 'Test Category ' . uniqid(),
        ]);
    }
}
