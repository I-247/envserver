<?php

namespace App\Actions\Projects;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class CreateProject
{
    /**
     * Create a project for the team, seeded with the default environments.
     */
    public function handle(Team $team, string $name, ?string $description = null): Project
    {
        return DB::transaction(function () use ($team, $name, $description) {
            $project = $team->projects()->create([
                'name' => $name,
                'slug' => Project::generateUniqueSlug($team, $name),
                'description' => $description,
            ]);

            foreach (Environment::DEFAULTS as $index => $environment) {
                $project->environments()->create([
                    ...$environment,
                    'sort_order' => $index + 1,
                ]);
            }

            return $project;
        });
    }
}
