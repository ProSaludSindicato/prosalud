<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WellnessRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WellnessRequest>
 */
class WellnessRequestFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = WellnessRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_name' => $this->faker->sentence(3),
            'activity_description' => $this->faker->paragraph(),
            'cost_center' => $this->faker->randomElement([
                'Bello',
                'Rionegro',
                'La Maria asistencial',
                'Carisma',
                'Admon',
            ]),
            'locations' => $this->faker->randomElements(['Sede Principal', 'Sede Norte', 'Sede Sur'], $this->faker->numberBetween(1, 3)),
            'proposed_date' => $this->faker->dateTimeBetween('+1 week', '+1 month'),
            'start_time' => $this->faker->time('H:i'),
            'end_time' => $this->faker->time('H:i'),
            'participant_count' => $this->faker->numberBetween(10, 100),
            'requires_details' => $this->faker->boolean(70), // 70% chance of requiring details
            'requester_id' => User::factory(),
            'status' => $this->faker->randomElement(['pending', 'in_progress', 'resolved', 'rejected']),
        ];
    }
}
