<?php

namespace App\Actions\Audit;

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * Writes an entry to a team's audit trail.
 *
 * The actor's name is copied in alongside the foreign key. Deleting a user
 * nulls the key, and a trail that forgets who did something the moment they
 * leave the company is exactly the trail you needed.
 */
class RecordAuditEvent
{
    /**
     * Record an action.
     *
     * @param  array<string, mixed>  $metadata  names, versions and counts only,
     *                                          never a secret's value
     */
    public function handle(
        Team $team,
        AuditAction $action,
        ?User $actor = null,
        ?Model $subject = null,
        array $metadata = [],
    ): AuditEvent {
        $event = new AuditEvent([
            'team_id' => $team->id,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'action' => $action,
            'metadata' => $metadata ?: null,
            'ip_address' => Request::ip(),
        ]);

        if ($subject) {
            $event->subject()->associate($subject);
        }

        $event->save();

        return $event;
    }
}
