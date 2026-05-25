<?php

namespace Database\Factories;

use App\Constants\RequestStatuses;
use App\Constants\RequestTypes;
use App\Models\RequestForm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequestForm>
 */
class RequestFormFactory extends Factory
{
    protected $model = RequestForm::class;

    public function definition(): array
    {
        return [
            'id' => str_pad((string) fake()->unique()->numberBetween(1, 9999999999), 10, '0', STR_PAD_LEFT),
            'request_type' => RequestTypes::CERTIFICADO_CONVENIO,
            'document_type' => 'CC',
            'document_number' => fake()->numerify('##########'),
            'name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->safeEmail(),
            'phone_number' => fake()->numerify('3#########'),
            'payload' => [
                'proceso' => 'Proceso de prueba',
                'dondeRealizaProceso' => 'Hospital Test',
            ],
            'files' => null,
            'status' => RequestStatuses::PENDING,
            'created_at' => now(),
            'processed_at' => null,
            'validated_at' => null,
            'validated_by' => null,
        ];
    }
}
