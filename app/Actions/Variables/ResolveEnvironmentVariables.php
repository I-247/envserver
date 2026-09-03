<?php

namespace App\Actions\Variables;

use App\Data\ResolvedVariable;
use App\Models\Environment;
use App\Models\VariableAssignment;
use App\Support\EnvFileRenderer;
use Illuminate\Support\Collection;

/**
 * Works out which variables an environment actually exposes.
 *
 * Two things can collide here: a variable's own key and another variable's
 * alias. Whichever wins, exactly one entry per key ends up in the .env.
 */
class ResolveEnvironmentVariables
{
    public function __construct(private readonly EnvFileRenderer $renderer) {}

    /**
     * Resolve the environment's variables, keyed by the name they are
     * exposed under and sorted by that name.
     *
     * @return Collection<int, ResolvedVariable>
     */
    public function handle(Environment $environment): Collection
    {
        return $environment->assignments()
            ->with(['variable.versions', 'variable.assignments'])
            ->get()
            ->groupBy(fn (VariableAssignment $assignment) => $assignment->effectiveKey())
            ->map(fn (Collection $candidates) => $this->winner($candidates))
            ->filter()
            ->sortKeys()
            ->values();
    }

    /**
     * Render the environment's variables as a .env file.
     */
    public function render(Environment $environment, ?string $header = null): string
    {
        return $this->renderer->render(
            $this->handle($environment)
                ->mapWithKeys(fn (ResolvedVariable $resolved) => [$resolved->key => $resolved->value()])
                ->all(),
            $header,
        );
    }

    /**
     * Build the tuple a candidate is ordered by. Compared element by element,
     * so the first difference decides.
     *
     * @return array{int, int, int}
     */
    private function rank(VariableAssignment $assignment): array
    {
        return [
            $assignment->variable->assignments->count() > 1 ? 1 : 0,
            $assignment->sort_order,
            $assignment->id,
        ];
    }

    /**
     * Pick the assignment that owns a key.
     *
     * A variable used by only this environment beats one shared with others:
     * a project specific override is almost always the more deliberate of the
     * two. Ties fall back to the explicit sort order, then to insertion
     * order, so the outcome never depends on how the database felt today.
     *
     * @param  Collection<int, VariableAssignment>  $candidates
     */
    private function winner(Collection $candidates): ?ResolvedVariable
    {
        $assignment = $candidates
            ->sort(fn (VariableAssignment $a, VariableAssignment $b) => $this->rank($a) <=> $this->rank($b))
            ->first();

        $version = $assignment->variable->versions->first();

        if ($version === null) {
            return null;
        }

        return new ResolvedVariable(
            key: $assignment->effectiveKey(),
            variable: $assignment->variable,
            version: $version,
            shared: $assignment->variable->assignments->count() > 1,
        );
    }
}
