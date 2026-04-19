<?php

namespace Database\Factories;

use App\Models\ConvenioEmailTracking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConvenioEmailTracking>
 */
class ConvenioEmailTrackingFactory extends Factory
{
    protected $model = ConvenioEmailTracking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'documento' => fake()->numerify('##########'),
            'nombre_afiliado' => fake()->name(),
            'email_afiliado' => fake()->safeEmail(),
            'nombre_convenio' => 'TEST',
            'nombre_archivo' => 'test.pdf',
            'ruta_archivo_pdf' => '/tmp/missing.pdf',
            'estado' => 'pendiente',
            'intentos' => 0,
        ];
    }
}
