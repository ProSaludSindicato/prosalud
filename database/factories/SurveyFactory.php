<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Survey>
 */
class SurveyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'status' => 'draft',
            'access_type' => 'public',
            'requires_signature' => false,
            'allows_multiple_responses' => true,
            'start_date' => null,
            'end_date' => null,
            'created_by' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => 'active']);
    }

    public function closed(): static
    {
        return $this->state(['status' => 'closed']);
    }

    public function authenticated(): static
    {
        return $this->state(['access_type' => 'authenticated']);
    }

    public function restricted(): static
    {
        return $this->state(['access_type' => 'restricted']);
    }

    public function withSignature(): static
    {
        return $this->state(['requires_signature' => true]);
    }

    public function noMultipleResponses(): static
    {
        return $this->state(['allows_multiple_responses' => false]);
    }
}
