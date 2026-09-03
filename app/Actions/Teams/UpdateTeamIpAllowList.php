<?php

namespace App\Actions\Teams;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Team;
use App\Models\User;
use App\Support\IpAllowList;

class UpdateTeamIpAllowList
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * Set the addresses the team may be reached from.
     *
     * Narrowing or widening who can reach a vault is exactly the kind of
     * change the audit trail exists for, so the old and the new list are
     * both recorded. Neither is a secret.
     */
    public function handle(Team $team, IpAllowList $allowList, ?User $actor = null): Team
    {
        $before = $team->ipAllowList()->toArray();

        $team->update(['ip_allowlist' => $allowList->toStorage()]);

        if (! $team->wasChanged('ip_allowlist')) {
            return $team;
        }

        $this->audit->handle(
            team: $team,
            action: AuditAction::TeamIpAllowListUpdated,
            actor: $actor,
            subject: $team,
            metadata: [
                'from' => $before,
                'to' => $allowList->toArray(),
            ],
        );

        return $team;
    }
}
