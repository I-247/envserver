<?php

namespace App\Actions\Variables;

use App\Data\SecretAge;
use App\Models\Team;
use App\Models\Variable;
use App\Models\VariableVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out which of a team's secrets are past their rotation interval.
 */
class ReviewSecretAge
{
    /**
     * Get the age of every variable the team owns.
     *
     * The date of the newest version comes back as a correlated subquery
     * rather than through the versions relation: the dashboard asks this for
     * a whole team at once, and loading every version of every variable to
     * read one timestamp off each is the expensive way to learn the same
     * thing.
     *
     * @return Collection<int, SecretAge>
     */
    public function handle(Team $team): Collection
    {
        return $team->variables()
            ->with('ownerProject')
            ->select('variables.*')
            ->addSelect(['rotated_at' => VariableVersion::query()
                ->select('created_at')
                ->whereColumn('variable_id', 'variables.id')
                ->orderByDesc('version')
                ->limit(1),
            ])
            ->get()
            ->map(function (Variable $variable) use ($team) {
                // Read through getAttribute: rotated_at is grafted on by the
                // subquery above and is not a column on the model, so it has
                // no cast and no property to declare.
                $rotatedAt = $variable->getAttribute('rotated_at');

                return SecretAge::for(
                    $variable,
                    $rotatedAt === null ? null : Carbon::parse($rotatedAt),
                    $team->default_rotate_after_days,
                );
            });
    }

    /**
     * Get the team's overdue secrets, the most overdue first.
     *
     * @return Collection<int, SecretAge>
     */
    public function overdue(Team $team): Collection
    {
        return $this->handle($team)
            ->filter(fn (SecretAge $age) => $age->isOverdue())
            ->sortByDesc(fn (SecretAge $age) => $age->overdueByDays())
            ->values();
    }
}
