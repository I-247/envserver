<?php

namespace App\Actions\Variables;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Offers a variable to the rest of the team, or withdraws that offer.
 *
 * Sharing is opt in. Every variable starts private to the project that
 * created it, and becomes reusable only when its owner says so, so a secret
 * never leaks into another project because someone went looking for it.
 */
class SetVariableShareable
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * Mark the variable as offered to other projects, or stop offering it.
     *
     * Withdrawing the offer is deliberately not retroactive: projects that
     * already borrowed the variable keep it. Pulling a live secret out from
     * under a running environment would break a deploy that had every reason
     * to trust it, and the way to end a share is for the borrower to remove
     * it, or for ownership to move.
     */
    public function handle(
        Variable $variable,
        Project $project,
        bool $shareable,
        ?User $actor = null,
    ): Variable {
        $this->assertOwnedBy($variable, $project);

        return DB::transaction(function () use ($variable, $project, $shareable, $actor) {
            $variable->update(['shareable' => $shareable]);

            $this->audit->handle(
                $project->team,
                $shareable ? AuditAction::VariableOffered : AuditAction::VariableWithdrawn,
                $actor,
                $variable,
                [
                    'key' => $variable->key,
                    'project' => $project->slug,
                    'still_borrowed_by' => $variable->usingProjectIds()
                        ->reject(fn (int $id) => $id === $project->id)
                        ->count(),
                ],
            );

            return $variable;
        });
    }

    /**
     * A borrowing project must not be able to pass the variable along: the
     * owner would lose track of where its secret ended up.
     */
    private function assertOwnedBy(Variable $variable, Project $project): void
    {
        if (! $variable->isOwnedBy($project)) {
            throw new InvalidArgumentException(
                'Only the project that owns a variable can offer it to others.'
            );
        }
    }
}
