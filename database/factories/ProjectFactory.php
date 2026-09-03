<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->word();

        return [
            'team_id' => Team::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => null,
        ];
    }

    /**
     * Give the project the default set of environments.
     */
    public function withDefaultEnvironments(): static
    {
        return $this->afterCreating(function (Project $project) {
            foreach (Environment::DEFAULTS as $index => $environment) {
                $project->environments()->create([
                    ...$environment,
                    'sort_order' => $index + 1,
                ]);
            }
        });
    }
}
