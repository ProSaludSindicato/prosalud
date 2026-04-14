<?php

namespace Database\Seeders;

use App\Models\{InventoryCategory, InventoryLocation, InventoryProduct, InventorySubcategory, InventoryVariant, InventoryVariantStock};
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InventoryProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $typeCategoryMap = [
            'DOTACION' => ['name' => 'Dotación', 'icon' => 'Shirt'],
            'EPP' => ['name' => 'EPP', 'icon' => 'Shield'],
            'BIENESTAR' => ['name' => 'Bienestar', 'icon' => 'Gift'],
            'OTROS' => ['name' => 'Otros', 'icon' => 'Package'],
        ];

        $categoryRecords = [];
        foreach ($typeCategoryMap as $type => $categoryData) {
            $categoryRecords[$type] = InventoryCategory::query()->updateOrCreate(
                ['name' => $categoryData['name']],
                ['description' => null, 'icon' => $categoryData['icon']]
            );
        }

        $sizeOptions = [
            'XS', 'S', 'M', 'L', 'XL',
            '2XL', '3XL', '4XL', '5XL',
            '35', '36', '37', '38', '39',
            '37-38', '39-40', '41-42', '43-44',
        ];

        $colorAliases = [
            'AGUAMA' => ['id' => 'AGUAMA', 'label' => 'Aguamarina'],
            'AZUL OSCURO' => ['id' => 'AZUL_OSCURO', 'label' => 'Azul Oscuro'],
            'AZUL REY' => ['id' => 'AZUL_REY', 'label' => 'Azul Rey'],
            'AZUL CLARO' => ['id' => 'AZUL_CLARO', 'label' => 'Azul Claro'],
            'AZUL' => ['id' => 'AZUL', 'label' => 'Azul'],
            'BLANCO' => ['id' => 'BLANCO', 'label' => 'Blanco'],
            'NEGRO' => ['id' => 'NEGRO', 'label' => 'Negro'],
            'NEGRA' => ['id' => 'NEGRO', 'label' => 'Negro'],
            'GRIS RATON' => ['id' => 'GRIS_RATON', 'label' => 'Gris Ratón'],
            'GRIS REFLECTIVO' => ['id' => 'GRIS_REFLECTIVO', 'label' => 'Gris Reflectivo'],
            'GRIS' => ['id' => 'GRIS', 'label' => 'Gris Claro'],
            'VERDE' => ['id' => 'VERDE', 'label' => 'Verde'],
            'PETROLEO' => ['id' => 'PETROLEO', 'label' => 'Petróleo'],
            'GRIS OSCURO' => ['id' => 'GRIS_OSCURO', 'label' => 'Gris Oscuro'],
        ];

        $colorKeys = array_keys($colorAliases);
        usort($colorKeys, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        $rawData = <<<'DATA'
BIENESTAR AGENDA CUERO 4
OTROS ALCOHOL 74
BIENESTAR AUDIFONOS 25
DOTACION BATA AGUAMA XS 0
DOTACION BATA AGUAMA S 5
DOTACION BATA AGUAMA M 5
DOTACION BATA AGUAMA L 5
DOTACION BATA AGUAMA XL 5
DOTACION BATA AGUAMA 2XL 0
DOTACION BATA AGUAMA 3XL 0
DOTACION BATA AGUAMA 4XL 0
DOTACION BATA AGUAMA 5XL 0
DOTACION BATA BLANCO XS 1
DOTACION BATA BLANCO S 0
DOTACION BATA BLANCO M 1
DOTACION BATA BLANCO L 0
DOTACION BATA BLANCO XL 5
DOTACION BATA BLANCO 2XL 0
DOTACION BATA BLANCO 3XL 0
DOTACION BATA BLANCO 4XL 0
DOTACION BATA BLANCO 5XL 0
DOTACION BATA LARGA BOTON BLANCO XS 0
DOTACION BATA LARGA BOTON BLANCO S 0
DOTACION BATA LARGA BOTON BLANCO M 1
DOTACION BATA LARGA BOTON BLANCO L 0
DOTACION BATA LARGA BOTON BLANCO XL 0
DOTACION BATA LARGA BOTON BLANCO 2XL 0
DOTACION BATA LARGA BOTON BLANCO 3XL 0
DOTACION BATA LARGA BOTON BLANCO 4XL 0
DOTACION BATA LARGA BOTON BLANCO 5XL 0
DOTACION BATA LARGA CIERRE BLANCO XS 1
DOTACION BATA LARGA CIERRE BLANCO S 1
DOTACION BATA LARGA CIERRE BLANCO M 2
DOTACION BATA LARGA CIERRE BLANCO L 2
DOTACION BATA LARGA CIERRE BLANCO XL 8
DOTACION BATA LARGA CIERRE BLANCO 2XL 0
DOTACION BATA LARGA CIERRE BLANCO 3XL 0
DOTACION BATA LARGA CIERRE BLANCO 4XL 0
DOTACION BATA LARGA CIERRE BLANCO 5XL 0
BIENESTAR BOLSAS PROSALUD ANTIFLUIDO 174
BIENESTAR BOLSAS PROSALUD TELA CAMBRE 6
BIENESTAR BOLSO VIAJERO NEGRO 56
BIENESTAR BOLSO VIAJERO AZUL OSCURO 26
BIENESTAR BOLSO VIAJERO AZUL REY 1
BIENESTAR BOLSO VIAJERO GRIS 65
OTROS BOQUILLA ALCOHOL 34
OTROS BOQUILLA JABON PEQUEÑO 110
DOTACION BUZO AZUL XS 0
DOTACION BUZO AZUL S 8
DOTACION BUZO AZUL M 0
DOTACION BUZO AZUL L 7
DOTACION BUZO AZUL XL 12
DOTACION BUZO AZUL 2XL 0
DOTACION BUZO AZUL 3XL 4
DOTACION BUZO AZUL 4XL 6
DOTACION BUZO AZUL 5XL 5
BIENESTAR CALENDARIO 2025 ARGOLLADO 69
DOTACION CAMISETA POLO HOMBRE GRIS XS 0
DOTACION CAMISETA POLO HOMBRE GRIS S 7
DOTACION CAMISETA POLO HOMBRE GRIS M 1
DOTACION CAMISETA POLO HOMBRE GRIS L 20
DOTACION CAMISETA POLO HOMBRE GRIS XL 13
DOTACION CAMISETA POLO HOMBRE GRIS 2XL 0
DOTACION CAMISETA POLO HOMBRE GRIS 3XL 0
DOTACION CAMISETA POLO HOMBRE GRIS 4XL 0
DOTACION CAMISETA POLO HOMBRE GRIS 5XL 0
DOTACION CAMISETA POLO MUJER BLANCO XS 0
DOTACION CAMISETA POLO MUJER BLANCO S 2
DOTACION CAMISETA POLO MUJER BLANCO M 1
DOTACION CAMISETA POLO MUJER BLANCO L 7
DOTACION CAMISETA POLO MUJER BLANCO XL 0
DOTACION CAMISETA POLO MUJER BLANCO 2XL 0
DOTACION CAMISETA POLO MUJER BLANCO 3XL 0
DOTACION CAMISETA POLO MUJER BLANCO 4XL 0
DOTACION CAMISETA POLO MUJER BLANCO 5XL 0
DOTACION CAMISETA POLO MUJER GRIS XS 0
DOTACION CAMISETA POLO MUJER GRIS S 0
DOTACION CAMISETA POLO MUJER GRIS M 0
DOTACION CAMISETA POLO MUJER GRIS L 2
DOTACION CAMISETA POLO MUJER GRIS XL 5
DOTACION CAMISETA POLO MUJER GRIS 2XL 2
DOTACION CAMISETA POLO MUJER GRIS 3XL 0
DOTACION CAMISETA POLO MUJER GRIS 4XL 0
DOTACION CAMISETA POLO MUJER GRIS 5XL 0
BIENESTAR CARTUCHERA ASAMBLEA 13
BIENESTAR CHAQUETA ROMPE VIENTO XS 10
BIENESTAR CHAQUETA ROMPE VIENTO S 17
BIENESTAR CHAQUETA ROMPE VIENTO M 58
BIENESTAR CHAQUETA ROMPE VIENTO L 7
BIENESTAR CHAQUETA ROMPE VIENTO XL 5
BIENESTAR CHAQUETA ROMPE VIENTO 2XL 1
OTROS CIERRE VERDE 50
DOTACION CONJUNTO CIERRE AZUL XS 7
DOTACION CONJUNTO CIERRE AZUL S 8
DOTACION CONJUNTO CIERRE AZUL XL 5
BIENESTAR CUADERNOS ARGOLLADO AZUL 25
EPP GORROS QUIRURGICOS 300
OTROS GUARDIAN GRANDE 72
OTROS GUARDIAN PEQUEÑO 110
BIENESTAR JABONES PEQUEÑO 0
BIENESTAR KIT VIAJERO HOMBRE 0
BIENESTAR LAPICEROS PROSALUD 1571
BIENESTAR LIBRETAS 145
BIENESTAR LONCHERAS 248
BIENESTAR MANTA PROSALUD AZUL 64
BIENESTAR PELOTAS ANTIESTRES COLMENA AZUL 11
DOTACION PIJAMA NEGRA XS 2
DOTACION PIJAMA NEGRA S 1
DOTACION PIJAMA NEGRA M 1
DOTACION PIJAMA NEGRA L 0
DOTACION PIJAMA NEGRA XL 0
DOTACION PIJAMA NEGRA 2XL 4
DOTACION PIJAMA NEGRA 3XL 0
DOTACION PIJAMA NEGRA 4XL 2
DOTACION PIJAMA NEGRA 5XL 0
DOTACION PIJAMA GRIS RATON XS 15
DOTACION PIJAMA GRIS RATON S 8
DOTACION PIJAMA GRIS RATON M 11
DOTACION PIJAMA GRIS RATON L 8
DOTACION PIJAMA GRIS RATON XL 11
DOTACION PIJAMA GRIS RATON 2XL 7
DOTACION PIJAMA GRIS RATON 3XL 5
DOTACION PIJAMA GRIS RATON 4XL 4
DOTACION PIJAMA GRIS RATON 5XL 8
DOTACION PIJAMA GRIS REFLECTIVO XS 0
DOTACION PIJAMA GRIS REFLECTIVO S 0
DOTACION PIJAMA GRIS REFLECTIVO M 2
DOTACION PIJAMA GRIS REFLECTIVO L 13
DOTACION PIJAMA GRIS REFLECTIVO XL 10
DOTACION PIJAMA GRIS REFLECTIVO 2XL 12
DOTACION PIJAMA GRIS REFLECTIVO 3XL 6
DOTACION PIJAMA GRIS REFLECTIVO 4XL 0
DOTACION PIJAMA GRIS REFLECTIVO 5XL 0
DOTACION PIJAMA AZUL REY XS 0
DOTACION PIJAMA AZUL REY S 0
DOTACION PIJAMA AZUL REY M 10
DOTACION PIJAMA AZUL REY L 21
DOTACION PIJAMA AZUL REY XL 16
DOTACION PIJAMA AZUL REY 2XL 2
DOTACION PIJAMA AZUL REY 3XL 2
DOTACION PIJAMA AZUL REY 4XL 9
DOTACION PIJAMA AZUL REY 5XL 0
DOTACION PIJAMA AZUL CLARO XS 0
DOTACION PIJAMA AZUL CLARO S 0
DOTACION PIJAMA AZUL CLARO M 0
DOTACION PIJAMA AZUL CLARO L 0
DOTACION PIJAMA AZUL CLARO XL 0
DOTACION PIJAMA AZUL CLARO 2XL 0
DOTACION PIJAMA AZUL CLARO 3XL 0
DOTACION PIJAMA AZUL CLARO 4XL 1
DOTACION PIJAMA AZUL CLARO 5XL 0
DOTACION PIJAMA AZUL OSCURO XS 15
DOTACION PIJAMA AZUL OSCURO S 16
DOTACION PIJAMA AZUL OSCURO M 22
DOTACION PIJAMA AZUL OSCURO L 27
DOTACION PIJAMA AZUL OSCURO XL 15
DOTACION PIJAMA AZUL OSCURO 2XL 8
DOTACION PIJAMA AZUL OSCURO 3XL 10
DOTACION PIJAMA AZUL OSCURO 4XL 5
DOTACION PIJAMA AZUL OSCURO 5XL 4
DOTACION PIJAMA VERDE XS 2
DOTACION PIJAMA VERDE S 0
DOTACION PIJAMA VERDE M 0
DOTACION PIJAMA VERDE L 0
DOTACION PIJAMA VERDE XL 5
DOTACION PIJAMA VERDE 2XL 0
DOTACION PIJAMA VERDE 3XL 4
DOTACION PIJAMA VERDE 4XL 0
DOTACION PIJAMA VERDE 5XL 0
DOTACION PIJAMA PETROLEO XS 0
DOTACION PIJAMA PETROLEO S 0
DOTACION PIJAMA PETROLEO M 0
DOTACION PIJAMA PETROLEO L 2
DOTACION PIJAMA PETROLEO XL 0
DOTACION PIJAMA PETROLEO 2XL 0
DOTACION PIJAMA PETROLEO 3XL 0
DOTACION PIJAMA PETROLEO 4XL 0
DOTACION PIJAMA PETROLEO 5XL 0
DOTACION PIJAMA AUXILIAR BLANCO XS 26
DOTACION PIJAMA AUXILIAR BLANCO S 49
DOTACION PIJAMA AUXILIAR BLANCO M 46
DOTACION PIJAMA AUXILIAR BLANCO L 7
DOTACION PIJAMA AUXILIAR BLANCO XL 80
DOTACION PIJAMA AUXILIAR BLANCO 2XL 16
DOTACION PIJAMA AUXILIAR BLANCO 3XL 0
DOTACION PIJAMA AUXILIAR BLANCO 4XL 16
DOTACION PIJAMA AUXILIAR BLANCO 5XL 11
DOTACION PIJAMA JEFE BLANCO XS 15
DOTACION PIJAMA JEFE BLANCO S 30
DOTACION PIJAMA JEFE BLANCO M 2
DOTACION PIJAMA JEFE BLANCO L 0
DOTACION PIJAMA JEFE BLANCO XL 36
DOTACION PIJAMA JEFE BLANCO 2XL 3
DOTACION PIJAMA JEFE BLANCO 3XL 6
DOTACION PIJAMA JEFE BLANCO 4XL 4
DOTACION PIJAMA JEFE BLANCO 5XL 7
BIENESTAR PORTA CELULAR 11
BIENESTAR PORTA COMIDA COLMENA 29
BIENESTAR PORTA LAPIZ 0
BIENESTAR SANDALIA HOMBRE 37-38 2
BIENESTAR SANDALIA HOMBRE 39-40 3
BIENESTAR SANDALIA HOMBRE 41-42 0
BIENESTAR SANDALIA HOMBRE 43-44 0
BIENESTAR SANDALIA MUJER 35 3
BIENESTAR SANDALIA MUJER 36 3
BIENESTAR SANDALIA MUJER 37 8
BIENESTAR SANDALIA MUJER 38 0
BIENESTAR SANDALIA MUJER 39-40 0
EPP TAPABOCAS N95 CAJA X 20 0
EPP TAPABOCAS QUIRURGICO CAJA X 32 2
EPP TAPABOCAS QUIRURGICO CAJA X 30 12
EPP TAPABOCAS QUIRURGICO CAJA X 40 47
OTROS TARRO ALCOHOL VACIO 14
OTROS TARRO JABON PEQUEÑO VACIO 1
OTROS TUBITOS 134
BIENESTAR TULAS COLMENA 225
BIENESTAR VELAS AROMATICAS 0
EPP VISORES 23
BIENESTAR ZAPATOS AUXILIAR BLANCO 39 1
BIENESTAR PELOTAS ANTIESTRES COLMENA BLANCO 94
BIENESTAR LAPICEROS COLMENA AZUL 0
BIENESTAR KIT DE ESCRITORIO COLMENA 48
DOTACION CAMISETA POLO MUJER NEGRA S 0
DOTACION CAMISETA POLO MUJER NEGRA M 0
DOTACION CAMISETA POLO MUJER NEGRA L 1
DOTACION CAMISETA POLO MUJER NEGRA XL 0
DOTACION CAMISETA POLO MUJER NEGRA 2XL 0
DOTACION CAMISETA POLO HOMBRE NEGRA M 0
DOTACION CAMISETA POLO HOMBRE NEGRA XL 0
DOTACION CAMISETA POLO HOMBRE NEGRA 2XL 0
DOTACION CAMISETA POLO HOMBRE NEGRA 3XL 0
DATA;

        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $rawData)));

        $products = [];
        $generatedSkus = [];

        foreach ($lines as $line) {
            if ('' === $line) {
                continue;
            }

            $tokens = preg_split('/\s+/', $line);
            if (count($tokens) < 3) {
                continue;
            }

            $type = strtoupper(array_shift($tokens));
            $stockToken = array_pop($tokens);
            if (!is_numeric($stockToken)) {
                continue;
            }

            $stock = (int) $stockToken;
            $nameTokens = array_values($tokens);

            $size = null;
            if (!empty($nameTokens)) {
                $lastToken = strtoupper(end($nameTokens));
                if (in_array($lastToken, $sizeOptions, true)) {
                    $size = array_pop($nameTokens);
                    $size = strtoupper($size);
                }
            }

            $gender = null;
            foreach ($nameTokens as $index => $token) {
                $upperToken = strtoupper($token);
                if ('HOMBRE' === $upperToken) {
                    $gender = 'hombre';
                    unset($nameTokens[$index]);
                } elseif ('MUJER' === $upperToken) {
                    $gender = 'mujer';
                    unset($nameTokens[$index]);
                }
            }
            $nameTokens = array_values($nameTokens);

            $colorId = null;
            $colorLabel = null;
            if (!empty($nameTokens)) {
                $upperName = strtoupper(implode(' ', $nameTokens));
                foreach ($colorKeys as $colorKey) {
                    if (Str::endsWith($upperName, $colorKey)) {
                        $colorInfo = $colorAliases[$colorKey];
                        $colorId = $colorInfo['id'];
                        $colorLabel = $colorInfo['label'];
                        $colorTokenCount = count(explode(' ', $colorKey));
                        $nameTokens = array_slice($nameTokens, 0, count($nameTokens) - $colorTokenCount);
                        break;
                    }
                }
            }

            $nameTokens = array_values($nameTokens);
            $baseName = trim(implode(' ', $nameTokens));
            if ('' === $baseName) {
                $baseName = $colorLabel ?? 'Producto Sin Nombre';
            }

            $productName = Str::title(Str::lower($baseName));
            $subcategoryToken = $nameTokens[0] ?? ($colorLabel ?? 'General');
            $subcategoryName = Str::title(Str::lower($subcategoryToken));

            $keyParts = [
                $type,
                $gender ?? 'unisex',
                Str::upper($baseName),
            ];
            $productKey = implode('|', $keyParts);

            if (!isset($products[$productKey])) {
                $products[$productKey] = [
                    'type' => $type,
                    'name' => $productName,
                    'subcategory' => $subcategoryName,
                    'gender' => $gender,
                    'variants' => [],
                    'has_size' => false,
                    'has_color' => false,
                ];
            }

            $typeCode = strtoupper(substr(Str::slug($type, ''), 0, 3)) ?: 'CAT';

            $baseSlug = Str::slug($baseName, ' ');
            $baseWords = array_filter(explode(' ', $baseSlug));
            if (empty($baseWords)) {
                $baseWords = [Str::slug($baseName) ?: 'prd'];
            }

            $baseCode = strtoupper(implode('', array_map(
                fn (string $word) => substr($word, 0, 2),
                $baseWords
            )));
            $baseCode = substr($baseCode, 0, 8);
            if ('' === $baseCode) {
                $baseCode = 'PRD';
            }

            $colorCode = $colorId
                ? strtoupper(substr(str_replace(['_', '-'], '', $colorId), 0, 3))
                : 'STD';

            $sizeCode = $size
                ? strtoupper(str_replace([' ', '-'], '', $size))
                : 'ONE';

            $skuBase = implode('-', array_filter([$typeCode, $baseCode, $colorCode, $sizeCode]));

            $sku = $skuBase;
            $counter = 2;
            while (in_array($sku, $generatedSkus, true)) {
                $sku = $skuBase . '-' . $counter;
                ++$counter;
            }
            $generatedSkus[] = $sku;

            $products[$productKey]['variants'][] = [
                'size' => $size,
                'color_id' => $colorId,
                'stock' => $stock,
                'sku' => $sku,
            ];

            if ($size) {
                $products[$productKey]['has_size'] = true;
            }

            if ($colorId) {
                $products[$productKey]['has_color'] = true;
            }
        }

        $primaryLocation = InventoryLocation::query()->where('is_primary', true)->first()
            ?? InventoryLocation::query()->first();

        if (!$primaryLocation) {
            throw new \RuntimeException('Debe existir al menos una ubicación de inventario para sembrar productos.');
        }

        foreach ($products as $productData) {
            $category = $categoryRecords[$productData['type']] ?? $categoryRecords['OTROS'];

            $subcategory = InventorySubcategory::query()->updateOrCreate(
                [
                    'category_id' => $category->id,
                    'name' => $productData['subcategory'],
                ],
                [
                    'description' => null,
                ]
            );

            $variantMode = 'simple';
            if ($productData['has_size'] && $productData['has_color']) {
                $variantMode = 'size_color';
            } elseif ($productData['has_size']) {
                $variantMode = 'size';
            } elseif ($productData['has_color']) {
                $variantMode = 'color';
            }

            $productGender = $productData['gender'] ?? null;

            $product = InventoryProduct::query()->updateOrCreate(
                [
                    'category_id' => $category->id,
                    'subcategory_id' => $subcategory->id,
                    'name' => $productData['name'],
                    'gender' => $productGender,
                ],
                [
                    'description' => null,
                    'variant_mode' => $variantMode,
                ]
            );

            $currentSkus = [];
            foreach ($productData['variants'] as $variantData) {
                $currentSkus[] = $variantData['sku'];

                $variant = InventoryVariant::query()->updateOrCreate(
                    ['sku' => $variantData['sku']],
                    [
                        'product_id' => $product->id,
                        'size' => $variantData['size'],
                        'color_id' => $variantData['color_id'],
                        'stock' => $variantData['stock'],
                        'min_stock' => 0,
                        'max_stock' => null,
                    ]
                );

                InventoryVariantStock::query()->updateOrCreate(
                    [
                        'variant_id' => $variant->id,
                        'location_id' => $primaryLocation->id,
                    ],
                    [
                        'stock' => max(0, (int) $variantData['stock']),
                        'reserved' => 0,
                        'min_stock' => $variant->min_stock ?? 0,
                        'max_stock' => $variant->max_stock,
                    ]
                );
            }

            if (!empty($currentSkus)) {
                $product->variants()
                    ->whereNotIn('sku', $currentSkus)
                    ->delete();
            }
        }
    }
}
