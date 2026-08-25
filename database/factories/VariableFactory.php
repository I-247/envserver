<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\Variable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Variable>
 */
class VariableFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'key' => Str::upper(Str::snake(fake()->unique()->word().'_key')),
            'description' => null,
        ];
    }
}
