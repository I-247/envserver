<?php

namespace App\Actions\Variables;

use App\Actions\Releases\PublishAutomaticReleases;
use App\Models\Environment;
use App\Models\Variable;
use App\Models\VariableAssignment;
use InvalidArgumentException;

class AttachVariableToEnvironment
{
    public function __construct(private readonly PublishAutomaticReleases $publishAutomatically) {}

    /**
     * Assign a variable to an environment, or update an existing assignment.
     */
    public function handle(
        Variable $variable,
        Environment $environment,
        ?string $aliasKey = null,
        int $sortOrder = 0,
    ): VariableAssignment {
        $this->assertSameTeam($variable, $environment);

        $assignment = VariableAssignment::updateOrCreate(
            [
                'variable_id' => $variable->id,
                'environment_id' => $environment->id,
            ],
            [
                'alias_key' => $aliasKey,
                'sort_order' => $sortOrder,
            ],
        );

        $this->publishAutomatically->handle($variable->refresh());

        return $assignment;
    }

    /**
     * A variable is encrypted with its team's data key, so assigning it to
     * another team's environment would produce a value nobody can read.
     */
    private function assertSameTeam(Variable $variable, Environment $environment): void
    {
        if ($variable->team_id !== $environment->project->team_id) {
            throw new InvalidArgumentException(
                'A variable can only be assigned to an environment within its own team.'
            );
        }
    }
}
