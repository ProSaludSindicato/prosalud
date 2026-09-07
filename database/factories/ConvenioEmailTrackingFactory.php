<?php

namespace Database\Factories;

use App\Models\ConvenioEmailTracking;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
            'is_test' => false,
        ];
    }

    public function test(): static
    {
        return $this->state(fn (): array => [
            'is_test' => true,
        ]);
    }

    public function pendienteFirma(): static
    {
        return $this->state(fn (): array => [
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_PENDIENTE_FIRMA,
            'signing_token_hash' => hash('sha256', Str::random(64)),
            'token_expires_at' => now()->addDays(30),
        ]);
    }

    public function firmadoAfiliado(): static
    {
        return $this->state(fn (): array => [
            'estado' => 'enviado',
            'signing_estado' => ConvenioEmailTracking::SIGNING_FIRMADO_AFILIADO,
            'firmado_afiliado_at' => now(),
        ]);
    }
}
