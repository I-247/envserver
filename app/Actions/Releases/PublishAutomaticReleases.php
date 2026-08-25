<?php

namespace App\Actions\Releases;

use App\Models\Environment;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableAssignment;
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
    public function __construct(private readonly PublishRelease $publish) {}

    /**
     * Publish a release for every opted in environment using the variable.
     *
     * @return Collection<int, Environment>
     */
    public function handle(Variable $variable, ?User $publisher = null, ?string $message = null): Collection
    {
        return $variable->assignments()
            ->with('environment')
            ->get()
            ->map(fn (VariableAssignment $assignment) => $assignment->environment)
            ->filter(fn (Environment $environment) => $environment->auto_publish)
            ->each(fn (Environment $environment) => $this->publish->handle($environment, $publisher, $message))
            ->values();
    }
}
