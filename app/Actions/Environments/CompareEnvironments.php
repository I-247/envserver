<?php

namespace App\Actions\Environments;

use App\Actions\Variables\ResolveEnvironmentVariables;
use App\Data\DriftEntry;
use App\Data\ResolvedVariable;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Lines a project's environments up next to each other, key by key.
 *
 * DiffReleases answers "what changed here since last time"; this answers the
 * other question a team actually asks daily: "what does production have that
 * staging does not, and where are we running the same credentials twice".
 */
class CompareEnvironments
{
    public function __construct(private readonly ResolveEnvironmentVariables $resolve) {}

    /**
     * Compare every environment of the project.
     *
     * @return Collection<int, DriftEntry>
     */
    public function handle(Project $project): Collection
    {
        $environments = $this->environments($project);

        /** @var Collection<string, array<string, string>> $checksums environment slug => key => checksum */
        $checksums = $environments->mapWithKeys(fn (Environment $environment) => [
            $environment->slug => $this->resolve->handle($environment)
                // The checksum is an HMAC keyed with the team's data key, so
                // comparing them tells us two environments hold the same
                // value without decrypting either one.
                ->mapWithKeys(fn (ResolvedVariable $entry) => [$entry->key => $entry->version->checksum])
                ->all(),
        ]);

        $guarded = array_values($environments
            ->filter(fn (Environment $environment) => ! $environment->auto_publish)
            ->pluck('slug')
            ->all());

        return $this->keys($checksums)
            ->map(fn (string $key) => $this->entry($key, $checksums, $guarded))
            ->values();
    }

    /**
     * Get the environments to compare, in the order the project shows them.
     *
     * @return EloquentCollection<int, Environment>
     */
    public function environments(Project $project): EloquentCollection
    {
        return $project->environments()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * Get every key any environment exposes, sorted by name.
     *
     * @param  Collection<string, array<string, string>>  $checksums
     * @return Collection<int, string>
     */
    private function keys(Collection $checksums): Collection
    {
        return $checksums
            ->flatMap(fn (array $keys) => array_keys($keys))
            ->unique()
            ->sort(SORT_NATURAL)
            ->values();
    }

    /**
     * Build the row for one key.
     *
     * @param  Collection<string, array<string, string>>  $checksums
     * @param  list<string>  $guarded
     */
    private function entry(string $key, Collection $checksums, array $guarded): DriftEntry
    {
        $groups = [];
        $seen = [];

        foreach ($checksums as $slug => $keys) {
            $checksum = $keys[$key] ?? null;

            if ($checksum === null) {
                $groups[$slug] = null;

                continue;
            }

            $seen[$checksum] ??= count($seen) + 1;
            $groups[$slug] = $seen[$checksum];
        }

        return new DriftEntry($key, $groups, $this->reusedIn($groups, $guarded));
    }

    /**
     * Work out which environments run the same value as a guarded one.
     *
     * "Guarded" means auto_publish is off, which is the flag the application
     * already uses for "this environment is promoted on purpose" and which
     * production carries by default. Reusing an existing decision beats
     * matching on the name "production", which a team is free not to use.
     *
     * Every other duplicate is left alone: APP_NAME being identical in three
     * environments is not a finding, and a warning nobody can act on trains
     * people to ignore the ones they can.
     *
     * @param  array<string, int|null>  $groups
     * @param  list<string>  $guarded
     * @return list<string>
     */
    private function reusedIn(array $groups, array $guarded): array
    {
        $guardedGroups = array_unique(array_filter(
            array_intersect_key($groups, array_flip($guarded)),
            fn (?int $group) => $group !== null,
        ));

        $reused = [];

        foreach ($guardedGroups as $group) {
            $sharing = array_keys($groups, $group, true);

            if (count($sharing) > 1) {
                $reused = array_merge($reused, $sharing);
            }
        }

        return array_values(array_unique($reused));
    }
}
