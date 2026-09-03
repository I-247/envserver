<?php

namespace App\Actions\Teams;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Team;
use App\Models\User;

class SetTeamRotationPolicy
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * Set how long the team's secrets may go unchanged.
     *
     * Null turns the policy off. That is worth recording just as loudly as
     * turning it on: it makes every overdue secret disappear from the
     * dashboard without a single one of them having been rotated.
     */
    public function handle(Team $team, ?int $days, ?User $actor = null): Team
    {
        if ($team->default_rotate_after_days === $days) {
            return $team;
        }

        $team->update(['default_rotate_after_days' => $days]);

        $this->audit->handle(
            team: $team,
            action: AuditAction::TeamRotationPolicyUpdated,
            actor: $actor,
            subject: $team,
            metadata: ['days' => $days],
        );

        return $team;
    }
}
