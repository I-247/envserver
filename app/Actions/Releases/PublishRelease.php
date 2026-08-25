<?php

namespace App\Actions\Releases;

use App\Actions\Variables\ResolveEnvironmentVariables;
use App\Data\ResolvedVariable;
use App\Models\Environment;
use App\Models\Release;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PublishRelease
{
    public function __construct(private readonly ResolveEnvironmentVariables $resolve) {}

    /**
     * Snapshot the environment's current variables as a new release.
     *
     * Publishing an unchanged environment returns the existing release rather
     * than adding a duplicate: with shared variables in play, a save that
     * changed nothing would otherwise fan out into a release per environment.
     */
    public function handle(Environment $environment, ?User $publisher = null, ?string $message = null): ?Release
    {
        return DB::transaction(function () use ($environment, $publisher, $message) {
            $resolved = $this->resolve->handle($environment);
            $latest = $environment->latestRelease();

            if ($latest && $latest->fingerprint() === $this->fingerprint($resolved)) {
                return $latest;
            }

            $release = $environment->releases()->create([
                'version' => (int) $environment->releases()->max('version') + 1,
                'message' => $message,
                'published_by' => $publisher?->id,
            ]);

            $resolved->each(fn (ResolvedVariable $entry) => $release->items()->create([
                'variable_id' => $entry->variable->id,
                'variable_version_id' => $entry->version->id,
                'key' => $entry->key,
            ]));

            return $release->load('items');
        });
    }

    /**
     * Fingerprint a resolved set the same way a stored release is fingerprinted.
     *
     * @param  Collection<int, ResolvedVariable>  $resolved
     * @return array<string, int>
     */
    private function fingerprint(Collection $resolved): array
    {
        return $resolved
            ->mapWithKeys(fn (ResolvedVariable $entry) => [$entry->key => $entry->version->id])
            ->all();
    }
}
