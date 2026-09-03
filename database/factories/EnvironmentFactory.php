<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Environment>
 */
class EnvironmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'project_id' => Project::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'auto_publish' => true,
            'sort_order' => 1,
        ];
    }

    /**
     * Indicate that the environment is a production environment.
     */
    public function production(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Production',
            'slug' => 'production',
            'auto_publish' => false,
            'sort_order' => 3,
        ]);
    }
}
