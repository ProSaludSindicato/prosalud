<?php

namespace Database\Seeders;

use App\Models\InventoryCategory;
use App\Models\InventoryColor;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Seed colors
        $colors = [
            ['id' => 'BLANCO', 'label' => 'Blanco', 'hex' => '#FFFFFF'],
            ['id' => 'NEGRO', 'label' => 'Negro', 'hex' => '#000000'],
            ['id' => 'AZUL', 'label' => 'Azul', 'hex' => '#2563EB'],
            ['id' => 'AZUL_REY', 'label' => 'Azul Rey', 'hex' => '#1E3A8A'],
            ['id' => 'AZUL_CIELO', 'label' => 'Azul Cielo', 'hex' => '#38BDF8'],
            ['id' => 'AZUL_MARINO', 'label' => 'Azul Marino', 'hex' => '#1E40AF'],
            ['id' => 'VERDE', 'label' => 'Verde', 'hex' => '#22C55E'],
            ['id' => 'VERDE_QUIRURGICO', 'label' => 'Verde Quirúrgico', 'hex' => '#065F46'],
            ['id' => 'VERDE_AGUA', 'label' => 'Verde Agua', 'hex' => '#5EEAD4'],
            ['id' => 'ROJO', 'label' => 'Rojo', 'hex' => '#EF4444'],
            ['id' => 'VINO_TINTO', 'label' => 'Vino Tinto', 'hex' => '#881337'],
            ['id' => 'ROSA', 'label' => 'Rosa', 'hex' => '#F472B6'],
            ['id' => 'MORADO', 'label' => 'Morado', 'hex' => '#A855F7'],
            ['id' => 'AMARILLO', 'label' => 'Amarillo', 'hex' => '#FACC15'],
            ['id' => 'NARANJA', 'label' => 'Naranja', 'hex' => '#FB923C'],
            ['id' => 'GRIS', 'label' => 'Gris', 'hex' => '#6B7280'],
            ['id' => 'GRIS_OSCURO', 'label' => 'Gris Oscuro', 'hex' => '#374151'],
            ['id' => 'BEIGE', 'label' => 'Beige', 'hex' => '#D4C4A8'],
            ['id' => 'CAFE', 'label' => 'Café', 'hex' => '#92400E'],
        ];

        foreach ($colors as $color) {
            InventoryColor::updateOrCreate(
                ['id' => $color['id']],
                $color
            );
        }

        $this->command->info('✓ Colores creados exitosamente');

        // Seed example categories
        $categories = [
            [
                'name' => 'Uniformes',
                'description' => 'Uniformes médicos y administrativos',
                'icon' => 'Shirt',
                'subcategories' => [
                    ['name' => 'Quirúrgicos', 'description' => 'Uniformes para área quirúrgica'],
                    ['name' => 'Administrativos', 'description' => 'Uniformes para personal administrativo'],
                    ['name' => 'Enfermería', 'description' => 'Uniformes para personal de enfermería'],
                    ['name' => 'Médicos', 'description' => 'Uniformes para personal médico'],
                ],
            ],
            [
                'name' => 'Calzado',
                'description' => 'Calzado de seguridad y uso hospitalario',
                'icon' => 'FootPrints',
                'subcategories' => [
                    ['name' => 'Zapatos', 'description' => 'Zapatos de uso hospitalario'],
                    ['name' => 'Botas', 'description' => 'Botas de seguridad'],
                    ['name' => 'Zuecos', 'description' => 'Zuecos médicos'],
                ],
            ],
            [
                'name' => 'Equipo de Protección Personal (EPP)',
                'description' => 'Equipos de protección para el personal',
                'icon' => 'Shield',
                'subcategories' => [
                    ['name' => 'Guantes', 'description' => 'Guantes de protección'],
                    ['name' => 'Mascarillas', 'description' => 'Mascarillas y respiradores'],
                    ['name' => 'Gafas', 'description' => 'Gafas de protección'],
                    ['name' => 'Batas', 'description' => 'Batas de protección'],
                ],
            ],
            [
                'name' => 'Accesorios',
                'description' => 'Accesorios complementarios',
                'icon' => 'Package',
                'subcategories' => [
                    ['name' => 'Gorros', 'description' => 'Gorros quirúrgicos'],
                    ['name' => 'Tapabocas', 'description' => 'Tapabocas desechables'],
                    ['name' => 'Identificaciones', 'description' => 'Carnets y identificaciones'],
                ],
            ],
        ];

        foreach ($categories as $categoryData) {
            $subcategories = $categoryData['subcategories'];
            unset($categoryData['subcategories']);

            $category = InventoryCategory::updateOrCreate(
                ['name' => $categoryData['name']],
                $categoryData
            );

            foreach ($subcategories as $subcategoryData) {
                $category->subcategories()->updateOrCreate(
                    ['name' => $subcategoryData['name'], 'category_id' => $category->id],
                    $subcategoryData
                );
            }

            $this->command->info("✓ Categoría '{$category->name}' creada con " . count($subcategories) . ' subcategorías');
        }

        $this->command->info('');
        $this->command->info('✓ Seeder de inventario ejecutado exitosamente');
        $this->command->info('  - ' . count($colors) . ' colores creados');
        $this->command->info('  - ' . count($categories) . ' categorías creadas');
    }
}
