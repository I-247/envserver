<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    /**
     * Show the team's audit trail.
     */
    public function __invoke(Team $currentTeam): Response
    {
        Gate::authorize('viewAudit', $currentTeam);

        return Inertia::render('audit/index', [
            'events' => $currentTeam->auditEvents()
                ->latest('id')
                ->limit(200)
                ->get()
                ->map(fn (AuditEvent $event) => [
                    'id' => $event->id,
                    'action' => $event->action->value,
                    'label' => $event->action->label(),
                    'actor' => $event->actor_name,
                    'metadata' => $event->metadata,
                    'ipAddress' => $event->ip_address,
                    'createdAt' => $event->created_at?->toISOString(),
                ]),
        ]);
    }
}
