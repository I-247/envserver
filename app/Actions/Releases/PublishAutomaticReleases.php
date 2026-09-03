<?php

namespace App\Actions\Releases;

use App\Models\Environment;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableAssignment;
use Closure;
use Illuminate\Support\Collection;

/**
 * Rolls a variable change out to every environment that opted in.
 *
 * This is what makes "change it once, change it everywhere" real. Environments
 * that did not opt in are deliberately left behind with pending changes, so a
 * shared variable can be updated for development without touching production.
 */
class PublishAutomaticReleases
{
    /**
     * The variables collected while a batch is open, or null when there is none.
     *
     * @var Collection<int, Variable>|null
     */
    private ?Collection $withheld = null;

    public function __construct(private readonly PublishRelease $publish) {}

    /**
     * Publish a release for every opted in environment using the variable.
     *
     * @return Collection<int, Environment>
     */
    public function handle(Variable $variable, ?User $publisher = null, ?string $message = null): Collection
    {
        if ($this->withheld !== null) {
            $this->withheld->push($variable);

            return collect();
        }

        return $this->environmentsFor(collect([$variable]))
            ->each(fn (Environment $environment) => $this->publish->handle($environment, $publisher, $message));
    }

    /**
     * Treat everything the callback changes as one change.
     *
     * Callers that write many variables in a row -- a CLI push, an .env import --
     * would otherwise publish a release per key, burying the actual change in
     * dozens of near identical releases. Inside a batch the automatic publishes
     * are held back and replayed once at the end, so one push is one release.
     *
     * Nothing is published when the callback throws: the flush lives after the
     * try block, not in the finally.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function batch(Closure $callback, ?User $publisher = null, ?string $message = null): mixed
    {
        // An inner batch hands its variables to the outer one, which flushes.
        if ($this->withheld !== null) {
            return $callback();
        }

        $this->withheld = collect();

        try {
            $result = $callback();
            $collected = $this->withheld;
        } finally {
            $this->withheld = null;
        }

        $this->environmentsFor($collected)
            ->each(fn (Environment $environment) => $this->publish->handle($environment, $publisher, $message));

        return $result;
    }

    /**
     * The opted in environments the given variables reach, each listed once.
     *
     * @param  Collection<int, Variable>  $variables
     * @return Collection<int, Environment>
     */
    private function environmentsFor(Collection $variables): Collection
    {
        return $variables
            ->unique(fn (Variable $variable) => $variable->id)
            ->flatMap(fn (Variable $variable) => $variable->assignments()->with('environment')->get())
            ->map(fn (VariableAssignment $assignment) => $assignment->environment)
            ->filter(fn (Environment $environment) => $environment->auto_publish)
            ->unique(fn (Environment $environment) => $environment->id)
            ->values();
    }
}
