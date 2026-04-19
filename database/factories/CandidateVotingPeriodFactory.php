<?php

namespace Database\Factories;

use App\Models\CandidateVotingPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CandidateVotingPeriod>
 */
class CandidateVotingPeriodFactory extends Factory
{
    protected $model = CandidateVotingPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = $this->faker->year();
        $semester = $this->faker->randomElement(['1', '2']);

        return [
            'election_key' => $year.'-'.$semester.'-'.$this->faker->unique()->numberBetween(100, 999),
            'name' => $year.'-'.$semester,
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }
}
