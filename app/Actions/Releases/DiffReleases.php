<?php

namespace App\Actions\Releases;

use App\Actions\Variables\ResolveEnvironmentVariables;
use App\Data\ResolvedVariable;
use App\Data\VariableChange;
use App\Enums\ChangeType;
use App\Models\Environment;
use App\Models\Release;
use App\Models\ReleaseItem;
use App\Models\VariableVersion;

class DiffReleases
{
    public function __construct(private readonly ResolveEnvironmentVariables $resolve) {}

    /**
     * Compare two releases.
     *
     * @return list<VariableChange>
     */
    public function handle(Release $before, Release $after, bool $reveal = true): array
    {
        return $this->compare(
            $this->versionsOf($before),
            $this->versionsOf($after),
            $reveal,
        );
    }

    /**
     * Compare what an environment currently resolves to against its last
     * release: the "waiting to be promoted" view for a manual environment.
     *
     * @return list<VariableChange>
     */
    public function pending(Environment $environment, bool $reveal = true): array
    {
        $release = $environment->latestRelease();

        return $this->compare(
            $release ? $this->versionsOf($release) : [],
            $this->resolve->handle($environment)
                ->mapWithKeys(fn (ResolvedVariable $entry) => [$entry->key => $entry->version])
                ->all(),
            $reveal,
        );
    }

    /**
     * Map a release to its pinned versions, keyed by exposed name.
     *
     * @return array<string, VariableVersion>
     */
    private function versionsOf(Release $release): array
    {
        return $release->items()
            ->with('version.variable.team')
            ->get()
            ->mapWithKeys(fn (ReleaseItem $item) => [$item->key => $item->version])
            ->all();
    }

    /**
     * Diff two maps of key to version.
     *
     * @param  array<string, VariableVersion>  $before
     * @param  array<string, VariableVersion>  $after
     * @return list<VariableChange>
     */
    private function compare(array $before, array $after, bool $reveal): array
    {
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($keys);

        $changes = [];

        foreach ($keys as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            $type = match (true) {
                $old === null => ChangeType::Added,
                $new === null => ChangeType::Removed,
                // Comparing checksums rather than plaintext: two versions of
                // the same variable can hold the same value, and that is not
                // a change worth showing.
                ! hash_equals($old->checksum, $new->checksum) => ChangeType::Changed,
                default => null,
            };

            if ($type === null) {
                continue;
            }

            $changes[] = new VariableChange(
                key: $key,
                type: $type,
                before: $reveal ? $old?->reveal() : null,
                after: $reveal ? $new?->reveal() : null,
            );
        }

        return $changes;
    }
}
