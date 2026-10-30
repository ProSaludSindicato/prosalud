<?php

namespace Database\Factories;

use App\Models\WellnessDeliveryListEntry;
use App\Models\WellnessDeliveryType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WellnessDeliveryListEntry>
 */
class WellnessDeliveryListEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $documento = (string) fake()->unique()->numberBetween(10000000, 1999999999);
        $beneficiario = mb_strtoupper(fake()->name());

        return [
            'wellness_delivery_type_id' => WellnessDeliveryType::factory(),
            'documento_afiliado' => $documento,
            'clave' => $documento.'|'.mb_strtolower($beneficiario),
            'nombre_afiliado' => mb_strtoupper(fake()->name()),
            'hospital' => 'HLM - GRUPO 1',
            'beneficiario' => $beneficiario,
            'parentesco' => 'HIJO',
            'edad' => (string) fake()->numberBetween(0, 12),
            'cantidad' => 1,
        ];
    }
}
