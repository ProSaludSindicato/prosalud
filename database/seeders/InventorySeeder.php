<?php

namespace Database\Seeders;

use App\Models\InventoryColor;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Seed colors necesarias para las variantes del inventario
        $colors = [
            ['id' => 'BLANCO', 'label' => 'Blanco', 'hex' => '#FFFFFF'],
            ['id' => 'NEGRO', 'label' => 'Negro', 'hex' => '#000000'],
            ['id' => 'AZUL', 'label' => 'Azul', 'hex' => '#2563EB'],
            ['id' => 'AZUL_REY', 'label' => 'Azul Rey', 'hex' => '#1E3A8A'],
            ['id' => 'AZUL_OSCURO', 'label' => 'Azul Oscuro', 'hex' => '#0B1D4D'],
            ['id' => 'AZUL_CLARO', 'label' => 'Azul Claro', 'hex' => '#93C5FD'],
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
            ['id' => 'GRIS_RATON', 'label' => 'Gris Ratón', 'hex' => '#4B5563'],
            ['id' => 'GRIS_REFLECTIVO', 'label' => 'Gris Reflectivo', 'hex' => '#9CA3AF'],
            ['id' => 'BEIGE', 'label' => 'Beige', 'hex' => '#D4C4A8'],
            ['id' => 'CAFE', 'label' => 'Café', 'hex' => '#92400E'],
            ['id' => 'AGUAMA', 'label' => 'Aguamarina', 'hex' => '#14B8A6'],
            ['id' => 'PETROLEO', 'label' => 'Petróleo', 'hex' => '#0D9488'],
        ];

        foreach ($colors as $color) {
            InventoryColor::query()->updateOrCreate(
                ['id' => $color['id']],
                $color
            );
        }
    }
}
