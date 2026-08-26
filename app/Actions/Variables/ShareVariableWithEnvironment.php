<?php

namespace App\Actions\Variables;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Environment;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableAssignment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Borrows a variable that another project already owns.
 *
 * Nothing is copied: the environment is pointed at the very same variable, so
 * a later change to the value reaches every project sharing it at once. That
 * is the whole point of sharing, and also its sharpest edge.
 */
class ShareVariableWithEnvironment
{
    public function __construct(
        private readonly AttachVariableToEnvironment $attach,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * Assign an existing variable to an environment of another project.
     */
    public function handle(
        Variable $variable,
        Environment $environment,
        ?User $actor = null,
        ?string $aliasKey = null,
    ): VariableAssignment {
        $this->assertOffered($variable);

        return DB::transaction(function () use ($variable, $environment, $actor, $aliasKey) {
            $assignment = $this->attach->handle($variable, $environment, $aliasKey);

            $this->audit->handle(
                $environment->project->team,
                AuditAction::VariableShared,
                $actor,
                $variable,
                [
                    'key' => $variable->key,
                    'alias' => $aliasKey,
                    'owner' => $variable->ownerProject?->slug,
                    'project' => $environment->project->slug,
                    'environment' => $environment->slug,
                ],
            );

            return $assignment;
        });
    }

    /**
     * Refuse a variable its owner never put up for sharing.
     *
     * The request validates this too; repeating it here means the CLI, a
     * seeder or a future caller cannot route around the opt in.
     */
    private function assertOffered(Variable $variable): void
    {
        if (! $variable->isOfferedToOtherProjects()) {
            throw new InvalidArgumentException(
                "The owning project has not made {$variable->key} shareable."
            );
        }
    }
}
