<?php

namespace App\Actions\Releases;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Variables\WriteVariableVersion;
use App\Enums\AuditAction;
use App\Models\Environment;
use App\Models\Release;
use App\Models\ReleaseItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Restores an environment to an earlier release.
 *
 * Implemented as a revert rather than a pin: the old values are written back
 * as new versions, so the portal, the API and the .env all agree afterwards.
 * Pinning the old versions in a new release instead would leave the portal
 * showing one value while the environment served another, and the very next
 * automatic publish would quietly undo the rollback.
 *
 * The cost of revert semantics is reach: restoring a shared variable restores
 * it everywhere. sharedImpact() exists so the caller can say so out loud
 * before going ahead.
 */
class RollbackToRelease
{
    public function __construct(
        private readonly WriteVariableVersion $writeVersion,
        private readonly PublishRelease $publish,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * Roll the release's environment back to the state of the given release.
     */
    public function handle(Release $release, ?User $author = null): ?Release
    {
        return DB::transaction(function () use ($release, $author) {
            // Measured before anything is rewritten: once the old values are
            // back, nothing differs any more and the impact would read zero.
            $impact = $this->sharedImpact($release);

            $release->loadMissing('items.version', 'items.variable');

            foreach ($release->items as $item) {
                $current = $item->variable->currentVersion();

                if ($current && $current->id === $item->variable_version_id) {
                    continue;
                }

                $this->writeVersion->handle(
                    $item->variable,
                    $item->version->reveal(),
                    $author,
                    "Teruggedraaid naar release {$release->version}",
                );
            }

            $restored = $this->publish->handle(
                $release->environment,
                $author,
                "Teruggedraaid naar release {$release->version}",
            );

            // Recorded here rather than in the controller so a rollback is
            // logged no matter who triggers it: the portal, the API, or a
            // console command.
            $this->audit->handle(
                $release->environment->project->team,
                AuditAction::ReleaseRolledBack,
                $author,
                $release,
                [
                    'project' => $release->environment->project->slug,
                    'environment' => $release->environment->slug,
                    'to' => $release->version,
                    'restored_as' => $restored?->version,
                    'other_environments_affected' => $impact->count(),
                ],
            );

            return $restored;
        });
    }

    /**
     * List the other environments a rollback would also change.
     *
     * @return Collection<int, Environment>
     */
    public function sharedImpact(Release $release): Collection
    {
        $release->loadMissing('items.variable.assignments.environment');

        return $release->items
            ->reject(fn (ReleaseItem $item) => $item->variable->currentVersion()?->id === $item->variable_version_id)
            ->flatMap(fn (ReleaseItem $item) => $item->variable->assignments->pluck('environment'))
            ->reject(fn (Environment $environment) => $environment->id === $release->environment_id)
            ->unique('id')
            ->sortBy('id')
            ->values();
    }
}
