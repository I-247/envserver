<?php

namespace App\Http\Controllers\Audit;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\FilterAuditEventsRequest;
use App\Models\AuditEvent;
use App\Models\Team;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    /**
     * How many events one page of the trail holds.
     */
    private const PER_PAGE = 50;

    /**
     * Show the team's audit trail.
     */
    public function __invoke(FilterAuditEventsRequest $request, Team $currentTeam): Response
    {
        Gate::authorize('viewAudit', $currentTeam);

        $filters = $request->filters();

        $events = $currentTeam->auditEvents()
            ->when($filters['actor'], fn (Builder $query, string $actor) => $actor === FilterAuditEventsRequest::SYSTEM_ACTOR
                ? $query->whereNull('actor_id')
                : $query->where('actor_id', $actor))
            ->when($filters['action'], fn (Builder $query, AuditAction $action) => $query->where('action', $action))
            /**
             * The details column is the JSON metadata, so searching it is a
             * text match on the raw document. Lowercased on both sides
             * because keys like DB_PASSWORD are stored as typed.
             */
            ->when($filters['search'], fn (Builder $query, string $search) => $query->whereRaw(
                'lower(metadata) like ?',
                ['%'.mb_strtolower($search).'%'],
            ))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('audit/index', [
            'events' => collect($events->items())
                ->map(fn (AuditEvent $event) => [
                    'id' => $event->id,
                    'action' => $event->action->value,
                    'label' => $event->action->label(),
                    'actor' => $event->actor_name,
                    'metadata' => $event->metadata,
                    'ipAddress' => $event->ip_address,
                    'createdAt' => $event->created_at?->toISOString(),
                ]),
            'pagination' => $this->pagination($events),
            'filters' => [
                'actor' => $filters['actor'],
                'action' => $filters['action']?->value,
                'search' => $filters['search'],
            ],
            'actors' => $this->actorOptions($currentTeam),
            'actions' => $this->actionOptions($currentTeam),
        ]);
    }

    /**
     * Describe the page the table is showing, so the footer can say where in
     * the trail you are without the client counting anything itself.
     *
     * @param  LengthAwarePaginator<int, AuditEvent>  $events
     * @return array{currentPage: int, lastPage: int, perPage: int, total: int, from: int|null, to: int|null}
     */
    private function pagination(LengthAwarePaginator $events): array
    {
        return [
            'currentPage' => $events->currentPage(),
            'lastPage' => $events->lastPage(),
            'perPage' => $events->perPage(),
            'total' => $events->total(),
            'from' => $events->firstItem(),
            'to' => $events->lastItem(),
        ];
    }

    /**
     * List everyone who appears in this team's trail, so the filter only ever
     * offers a choice that can return rows.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function actorOptions(Team $currentTeam): array
    {
        return $currentTeam->auditEvents()
            ->select('actor_id', 'actor_name')
            ->distinct()
            ->orderBy('actor_name')
            ->get()
            ->map(fn (AuditEvent $event) => [
                'value' => (string) ($event->actor_id ?? FilterAuditEventsRequest::SYSTEM_ACTOR),
                'label' => $event->actor_name ?? 'System',
            ])
            ->unique('value')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function actionOptions(Team $currentTeam): array
    {
        return $currentTeam->auditEvents()
            ->select('action')
            ->distinct()
            ->pluck('action')
            ->map(fn (AuditAction $action) => [
                'value' => $action->value,
                'label' => $action->label(),
            ])
            ->sortBy('label')
            ->values()
            ->all();
    }
}
