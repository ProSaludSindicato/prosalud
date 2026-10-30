<?php

namespace Database\Factories;

use App\Models\WellnessDeliveryType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WellnessDeliveryType>
 */
class WellnessDeliveryTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'Campaña '.fake()->words(2, true),
            'activo' => true,
            'modo_acceso' => 'abierto',
            'fecha_desde' => null,
            'fecha_hasta' => null,
            'created_by' => null,
        ];
    }

    /**
     * Campaña de entrega directa validada contra el listado cargado.
     */
    public function entregaListado(): static
    {
        return $this->state(fn (array $attributes) => [
            'modo_acceso' => WellnessDeliveryType::MODO_ENTREGA_LISTADO,
        ]);
    }

    /**
     * Campaña con rango de fechas.
     */
    public function vigente(string $desde, string $hasta): static
    {
        return $this->state(fn (array $attributes) => [
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
        ]);
    }
}
