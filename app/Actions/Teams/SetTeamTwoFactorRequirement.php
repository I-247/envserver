<?php

namespace App\Actions\Teams;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Team;
use App\Models\User;

class SetTeamTwoFactorRequirement
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * Set whether members need a second factor to reach this team's vault.
     *
     * Turning the requirement off is the interesting half for a reader of the
     * trail: it silently widens access for everyone who never enrolled, so it
     * is recorded with the same weight as turning it on.
     */
    public function handle(Team $team, bool $required, ?User $actor = null): Team
    {
        $team->update(['two_factor_required' => $required]);

        if (! $team->wasChanged('two_factor_required')) {
            return $team;
        }

        $this->audit->handle(
            team: $team,
            action: AuditAction::TeamTwoFactorRequirementUpdated,
            actor: $actor,
            subject: $team,
            metadata: [
                'required' => $required,
                'members_without_second_factor' => $team->membersWithoutSecondFactor()->count(),
            ],
        );

        return $team;
    }
}
